<?php
/**
 * Squarespace extractor.
 *
 * Extracts event data from Squarespace platform websites by parsing the embedded
 * Static.SQUARESPACE_CONTEXT JSON structure.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SquarespaceExtractor extends BaseExtractor {

	public function canExtract( string $html ): bool {
		return strpos( $html, 'Static.SQUARESPACE_CONTEXT' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		$data = $this->fetchJsonData( $html, $source_url );

		// Improvement 3 (#272): single-event-detail pages. When the JSON payload
		// has no upcoming/past/items arrays but contains a top-level `item`
		// (singular) describing a single event, treat it as a one-event page.
		// We check this BEFORE the empty-data short-circuit so single-event
		// pages still extract even when nothing else fires.
		$single = $this->extractSingleItem( $data );
		if ( ! empty( $single ) ) {
			$page_venue = \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor::extract( $html, $source_url );
			$event      = $this->normalizeItem( $single, $page_venue );
			if ( ! empty( $event['title'] ) ) {
				return array( $event );
			}
		}

		// Defensive default so empty/missing $data still lets the HTML-based
		// strategies (4, 7, 8, 9) run. Previously this method bailed early on
		// empty $data, which prevented the new collection-deref strategies
		// from firing on pages where ?format=json returns nothing useful.
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$raw_items = $this->extractEventsFromCollection( $data );

		if ( empty( $raw_items ) ) {
			// 3. Try recursive search for items in collection structure
			$raw_items = $this->findItemsRecursive( $data );
		}

		if ( empty( $raw_items ) ) {
			// 4. Fallback to parsing HTML directly if JSON is empty
			$raw_items = $this->parseHtmlItems( $html );
		}

		if ( empty( $raw_items ) ) {
			// 5. Check for Summary Blocks or Gallery items in SQUARESPACE_CONTEXT
			$raw_items = $this->findBlockItems( $data );
		}

		if ( empty( $raw_items ) ) {
			// 6. Check for upcomingEvents in website (common in some templates)
			if ( isset( $data['website']['upcomingEvents'] ) && is_array( $data['website']['upcomingEvents'] ) ) {
				$raw_items = $data['website']['upcomingEvents'];
			}
		}

		if ( empty( $raw_items ) ) {
			// 7. Parse User Items List blocks (custom event listings without Events collection)
			$raw_items = $this->parseUserItemsList( $html );
		}

		if ( empty( $raw_items ) ) {
			// 8. Improvement 1 (#272): Summary Block collection-ID dereferencing.
			// Find any Summary Block on the page with a collectionId and fetch
			// that collection's JSON for its upcoming/items array.
			$raw_items = $this->parseSummaryBlockCollections( $html, $source_url );
		}

		if ( empty( $raw_items ) ) {
			// 9. Improvement 2 (#272): User Items List with data-collection-id.
			// Detect <div class="user-items-list" data-collection-id="..."> and
			// fetch the linked collection. Falls back to embedded
			// `data-current-context` userItems when no separate collection.
			$raw_items = $this->parseUserItemsListCollection( $html, $source_url );
		}

		if ( empty( $raw_items ) ) {
			return array();
		}

		// Extract venue info from page context as fallback
		$page_venue = \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor::extract( $html, $source_url );

		$events = array();
		foreach ( $raw_items as $raw_item ) {
			$normalized = $this->normalizeItem( $raw_item, $page_venue );
			if ( ! empty( $normalized['title'] ) ) {
				$events[] = $normalized;
			}
		}

		return $events;
	}

	/**
	 * Parse events from Squarespace HTML list view (e.g., eventlist-event).
	 *
	 * @param string $html Page HTML
	 * @return array Array of raw item-like structures
	 */
	private function parseHtmlItems( string $html ): array {
		$items = array();

		// Find all article tags with eventlist-event class
		if ( ! preg_match_all( '/<article[^>]+class="[^"]*eventlist-event[^"]*"[^>]*>(.*?)<\/article>/is', $html, $matches ) ) {
			return array();
		}

		foreach ( $matches[1] as $index => $article_html ) {
			$item = array(
				'title'       => '',
				'startDate'   => '',
				'fullUrl'     => '',
				'assetUrl'    => '',
				'description' => '',
			);

			// Title and Link
			if ( preg_match( '/<h1[^>]*class="eventlist-title"[^>]*>.*?<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $article_html, $title_matches ) ) {
				$item['fullUrl'] = $title_matches[1];
				$item['title']   = wp_strip_all_tags( $title_matches[2] );
			}

			// Date (from time tag)
			if ( preg_match( '/<time[^>]+datetime="([^"]+)"/i', $article_html, $date_matches ) ) {
				$item['startDate'] = $date_matches[1];
			}

			// Image
			if ( preg_match( '/<img[^>]+data-src="([^"]+)"/i', $article_html, $img_matches ) ) {
				$item['assetUrl'] = $img_matches[1];
			}

			if ( ! empty( $item['title'] ) ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Parse events from Squarespace User Items List blocks.
	 *
	 * Some Squarespace sites use User Items List blocks instead of the native Events
	 * collection. This parses li.list-item elements inside .user-items-list and extracts
	 * event data including times from description text.
	 *
	 * @since 0.9.17
	 * @param string $html Page HTML
	 * @return array Array of raw item-like structures
	 */
	private function parseUserItemsList( string $html ): array {
		if ( strpos( $html, 'user-items-list' ) === false ) {
			return array();
		}

		$items = array();

		if ( ! preg_match_all( '/<li[^>]+class="[^"]*list-item[^"]*"[^>]*>(.*?)<\/li>/is', $html, $matches ) ) {
			return array();
		}

		foreach ( $matches[1] as $item_html ) {
			$item = array(
				'title'       => '',
				'startDate'   => '',
				'startTime'   => '',
				'fullUrl'     => '',
				'assetUrl'    => '',
				'description' => '',
			);

			if ( preg_match( '/<h2[^>]+class="[^"]*list-item-content__title[^"]*"[^>]*>(.*?)<\/h2>/is', $item_html, $title_matches ) ) {
				$item['title'] = trim( wp_strip_all_tags( html_entity_decode( $title_matches[1], ENT_QUOTES, 'UTF-8' ) ) );
			}

			if ( preg_match( '/<div[^>]+class="[^"]*list-item-content__description[^"]*"[^>]*>(.*?)<\/div>/is', $item_html, $desc_matches ) ) {
				$description         = $desc_matches[1];
				$description_text    = wp_strip_all_tags( $description );
				$item['description'] = trim( $description_text );

				if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $description, $first_p ) ) {
					$date_text         = wp_strip_all_tags( $first_p[1] );
					$item['startDate'] = trim( $date_text );
				}

				$extracted_time = $this->extractTimeFromText( $description_text );
				if ( $extracted_time ) {
					$item['startTime'] = $extracted_time;
				}
			}

			if ( preg_match( '/<a[^>]+class="[^"]*list-item-content__button[^"]*"[^>]+href="([^"]+)"/i', $item_html, $link_matches ) ) {
				$item['fullUrl'] = $link_matches[1];
			}

			if ( preg_match( '/<img[^>]+class="[^"]*list-image[^"]*"[^>]+(?:data-src|src)="([^"]+)"/i', $item_html, $img_matches ) ) {
				$item['assetUrl'] = $img_matches[1];
			} elseif ( preg_match( '/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item_html, $img_matches ) ) {
				$item['assetUrl'] = $img_matches[1];
			}

			if ( ! empty( $item['title'] ) && ! empty( $item['startDate'] ) ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Parse Summary Block collection references and dereference their JSON.
	 *
	 * Improvement 1 (issue #272): when the host page contains one or more
	 * Summary Blocks (`<div class="sqs-block-summary-v2" data-block-json="...">`)
	 * pointing at an external collection via `collectionId`, this method
	 * resolves each collection's JSON via the Squarespace JSON API and
	 * returns the first non-empty events array.
	 *
	 * Strategy per block:
	 *   1. If the block declares an explicit `collectionUrlId` use
	 *      `/<urlId>?format=json` directly.
	 *   2. Otherwise map `collectionId` to the matching collection `fullUrl`
	 *      in the page's embedded Squarespace context.
	 *
	 * Returns an array of raw items (compatible with normalizeItem()) or an
	 * empty array on failure. Never throws.
	 *
	 * @since 0.15.x
	 * @param string $html       Host page HTML.
	 * @param string $source_url Source URL (used to derive collection origin).
	 * @return array
	 */
	private function parseSummaryBlockCollections( string $html, string $source_url ): array {
		if ( ! preg_match_all( '/data-block-json="([^"]+)"/i', $html, $matches ) ) {
			return array();
		}

		$parsed   = wp_parse_url( $source_url );
		$base_url = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );
		$seen_ids = array();

		foreach ( $matches[1] as $raw_attr ) {
			$decoded = html_entity_decode( $raw_attr, ENT_QUOTES, 'UTF-8' );
			$block   = json_decode( $decoded, true );

			if ( ! is_array( $block ) || empty( $block['collectionId'] ) ) {
				continue;
			}

			// Skip non-events Summary Blocks (galleries, products, blog posts).
			// We can't always tell from the block alone — the collection probe
			// itself will return nothing actionable in those cases, but we still
			// want to skip obvious gallery blocks to avoid wasted requests.
			if ( ! empty( $block['design'] ) && in_array( $block['design'], array( 'grid', 'list', 'carousel', 'wall' ), true )
				&& empty( $block['showPastOrUpcomingEvents'] )
				&& empty( $block['eventTime'] ) ) {
				// Probably a Summary widget — still worth probing IF the
				// referenced collection is an Events collection (type=10).
				// Skip only obvious gallery blocks that declare a transient
				// gallery id matching the collectionId (image galleries).
				if ( ! empty( $block['transientGalleryId'] ) && $block['transientGalleryId'] === $block['collectionId'] ) {
					continue;
				}
			}

			$collection_id = (string) $block['collectionId'];

			if ( isset( $seen_ids[ $collection_id ] ) ) {
				continue;
			}
			$seen_ids[ $collection_id ] = true;

			$items = $this->fetchCollectionItemsById( $collection_id, $base_url, $html, $block );
			if ( ! empty( $items ) ) {
				return $items;
			}
		}

		return array();
	}

	/**
	 * Parse User Items List blocks that reference a collection by ID, or
	 * fall back to inline `data-current-context` userItems embedded on the page.
	 *
	 * Improvement 2 (issue #272). Two shapes are handled here:
	 *
	 *   a) <div class="user-items-list" data-collection-id="ABC">  — fetch
	 *      the collection JSON like Summary Blocks (improvement 1).
	 *   b) Inline shape used by some 7.1 themes (e.g. Saint Vitus):
	 *      <div class="user-items-list-item-container"
	 *           data-current-context="{&quot;userItems&quot;:[...]}">
	 *      The items live entirely in the page HTML inside a giant
	 *      HTML-encoded JSON attribute.
	 *
	 * @since 0.15.x
	 * @param string $html       Host page HTML.
	 * @param string $source_url Source URL (used to derive collection origin).
	 * @return array
	 */
	private function parseUserItemsListCollection( string $html, string $source_url ): array {
		if ( strpos( $html, 'user-items-list' ) === false ) {
			return array();
		}

		$parsed   = wp_parse_url( $source_url );
		$base_url = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );

		// Shape (a): collection-backed user-items-list.
		if ( preg_match_all( '/<(?:div|section)[^>]+class="[^"]*user-items-list[^"]*"[^>]*data-collection-id="([^"]+)"/i', $html, $matches ) ) {
			$seen = array();
			foreach ( $matches[1] as $cid ) {
				$cid = trim( $cid );
				if ( '' === $cid || isset( $seen[ $cid ] ) ) {
					continue;
				}
				$seen[ $cid ] = true;
				$items        = $this->fetchCollectionItemsById( $cid, $base_url, $html );
				if ( ! empty( $items ) ) {
					return $items;
				}
			}
		}

		// Same attribute order can also appear with data-collection-id first.
		if ( preg_match_all( '/<(?:div|section)[^>]+data-collection-id="([^"]+)"[^>]*class="[^"]*user-items-list[^"]*"/i', $html, $matches ) ) {
			$seen = array();
			foreach ( $matches[1] as $cid ) {
				$cid = trim( $cid );
				if ( '' === $cid || isset( $seen[ $cid ] ) ) {
					continue;
				}
				$seen[ $cid ] = true;
				$items        = $this->fetchCollectionItemsById( $cid, $base_url, $html );
				if ( ! empty( $items ) ) {
					return $items;
				}
			}
		}

		// Shape (b): inline data-current-context with userItems.
		return $this->parseInlineUserItemsContext( $html );
	}

	/**
	 * Parse `data-current-context` attributes embedded on user-items-list
	 * containers to extract their inline `userItems` array.
	 *
	 * Some Squarespace 7.1 themes ship the full userItems payload inside an
	 * HTML-encoded JSON attribute on
	 * `.user-items-list-item-container[data-current-context]` rather than
	 * fetching from a separate collection. This parser pulls all such
	 * payloads, aggregates them, and returns them as raw items.
	 *
	 * @since 0.15.x
	 * @param string $html Host page HTML.
	 * @return array
	 */
	private function parseInlineUserItemsContext( string $html ): array {
		if ( ! preg_match_all( '/data-current-context="([^"]+)"/i', $html, $matches ) ) {
			return array();
		}

		$items = array();
		foreach ( $matches[1] as $raw_attr ) {
			$decoded = html_entity_decode( $raw_attr, ENT_QUOTES, 'UTF-8' );
			$ctx     = json_decode( $decoded, true );
			if ( ! is_array( $ctx ) ) {
				continue;
			}

			if ( ! empty( $ctx['userItems'] ) && is_array( $ctx['userItems'] ) ) {
				foreach ( $ctx['userItems'] as $item ) {
					if ( is_array( $item ) && ! empty( $item['title'] ) ) {
						$items[] = $item;
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Fetch a Squarespace collection's events array by collection ID.
	 *
	 * Resolves the collection path from the block's `collectionUrlId` or from
	 * the matching collection object in Static.SQUARESPACE_CONTEXT. Squarespace
	 * does not resolve arbitrary `?collectionId=` requests to collection JSON.
	 *
	 * @since 0.15.x
	 * @param string $collection_id Squarespace collection ID.
	 * @param string $base_url      Source origin (scheme + host, no trailing slash).
	 * @param string $html          Host page HTML containing Squarespace context.
	 * @param array  $block         Optional originating block payload (for urlId hints).
	 * @return array Raw event items, or empty array on failure.
	 */
	private function fetchCollectionItemsById( string $collection_id, string $base_url, string $html, array $block = array() ): array {
		$candidates = array();

		if ( ! empty( $block['collectionUrlId'] ) ) {
			$candidates[] = add_query_arg( 'format', 'json', $base_url . '/' . ltrim( (string) $block['collectionUrlId'], '/' ) );
		}

		$context_path = $this->findCollectionPathById( $html, $collection_id );
		if ( $context_path ) {
			$candidates[] = add_query_arg( 'format', 'json', $base_url . '/' . ltrim( $context_path, '/' ) );
		}

		foreach ( array_unique( $candidates ) as $url ) {
			$response = \DataMachine\Core\HttpClient::get(
				$url,
				array(
					'timeout' => 15,
					'context' => 'Squarespace Extractor Collection Deref',
				)
			);

			if ( empty( $response['success'] ) || empty( $response['data'] ) ) {
				continue;
			}

			$data = json_decode( $response['data'], true );
			if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
				continue;
			}

			$items = $this->extractEventsFromCollection( $data );
			if ( ! empty( $items ) ) {
				if ( ! empty( $data['items'] ) ) {
					$items = $this->fetchPaginatedCollectionItems( $items, $data, $base_url );
				}
				return $items;
			}
		}

		return array();
	}

	/**
	 * Find a collection's same-site path in embedded Squarespace context.
	 */
	private function findCollectionPathById( string $html, string $collection_id ): ?string {
		$context = $this->extractContextData( $html );
		if ( empty( $context ) ) {
			return null;
		}

		$find = static function ( array $value ) use ( &$find, $collection_id ): ?string {
			if ( isset( $value['id'] ) && $collection_id === (string) $value['id'] ) {
				$path = $value['fullUrl'] ?? $value['urlId'] ?? '';
				if ( is_string( $path ) && '' !== trim( $path ) ) {
					$parsed = wp_parse_url( $path );
					return $parsed['path'] ?? null;
				}
			}

			foreach ( $value as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}
				$path = $find( $child );
				if ( $path ) {
					return $path;
				}
			}

			return null;
		};

		return $find( $context );
	}

	/**
	 * Follow same-origin pagination for collection payloads using items[].
	 */
	private function fetchPaginatedCollectionItems( array $items, array $data, string $base_url ): array {
		$seen_urls = array();

		for ( $page = 0; $page < 10; ++$page ) {
			$next_path = $data['pagination']['nextPageUrl'] ?? '';
			if ( empty( $data['pagination']['nextPage'] ) || ! is_string( $next_path ) || '' === $next_path ) {
				break;
			}

			$parsed = wp_parse_url( $next_path );
			if ( ! empty( $parsed['host'] ) ) {
				break;
			}

			$next_url = add_query_arg( 'format', 'json', $base_url . '/' . ltrim( $next_path, '/' ) );
			if ( isset( $seen_urls[ $next_url ] ) ) {
				break;
			}
			$seen_urls[ $next_url ] = true;

			$response = \DataMachine\Core\HttpClient::get(
				$next_url,
				array(
					'timeout' => 15,
					'context' => 'Squarespace Extractor Collection Pagination',
				)
			);
			if ( empty( $response['success'] ) || empty( $response['data'] ) ) {
				break;
			}

			$data = json_decode( $response['data'], true );
			if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
				break;
			}

			$page_items = $this->filterEventItems( $data['items'] ?? array() );
			if ( empty( $page_items ) ) {
				break;
			}
			$items = array_merge( $items, $page_items );
		}

		return $items;
	}

	/**
	 * Pull the events array out of a fetched collection JSON payload.
	 *
	 * Squarespace events collections expose events via `upcoming`/`past`
	 * top-level arrays; some templates use `items[]` populated with
	 * `recordType:12` (event records). This method centralizes the lookup
	 * for both shapes.
	 *
	 * @since 0.15.x
	 * @param array $data Decoded collection JSON.
	 * @return array
	 */
	private function extractEventsFromCollection( array $data ): array {
		if ( ! empty( $data['upcoming'] ) && is_array( $data['upcoming'] ) ) {
			return $data['upcoming'];
		}

		if ( ! empty( $data['past'] ) && is_array( $data['past'] ) ) {
			return $data['past'];
		}

		if ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
			return $this->filterEventItems( $data['items'] );
		}

		return array();
	}

	/**
	 * Keep only records with explicit event evidence.
	 */
	private function filterEventItems( array $items ): array {
		$events = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			// recordType 12 = Squarespace event record. When recordType is
			// absent (older payloads) but the item carries event-ish
			// fields, accept it too.
			$is_event = ( isset( $item['recordType'] ) && 12 === (int) $item['recordType'] )
				|| ! empty( $item['startDate'] )
				|| ! empty( $item['endDate'] )
				|| ! empty( $item['eventDates'] );
			if ( $is_event ) {
				$events[] = $item;
			}
		}

		return $events;
	}

	/**
	 * Detect a single-event-detail payload and return its sole item.
	 *
	 * Improvement 3 (issue #272). When a Squarespace JSON payload has no
	 * `upcoming` / `past` / `items` arrays but carries a top-level `item`
	 * describing one event (recordType 12 or with event-shaped fields),
	 * treat the page as a single-event detail page and surface that one
	 * item to the caller.
	 *
	 * This supports consumers that classify an event page as `detail` when a
	 * single-event-detail URL extracts exactly one event.
	 *
	 * @since 0.15.x
	 * @param array $data Decoded Squarespace JSON payload.
	 * @return array Single raw item array, or empty array if not a detail page.
	 */
	private function extractSingleItem( array $data ): array {
		if ( empty( $data ) ) {
			return array();
		}

		// Bail when the payload is clearly a listing — don't shadow the
		// listing strategies. We only fire for true single-event payloads.
		if ( ! empty( $data['upcoming'] ) || ! empty( $data['past'] ) ) {
			return array();
		}

		if ( ! empty( $data['items'] ) && is_array( $data['items'] ) && count( $data['items'] ) > 1 ) {
			return array();
		}

		if ( empty( $data['item'] ) || ! is_array( $data['item'] ) ) {
			return array();
		}

		$item = $data['item'];

		// Accept any of: recordType=12, an @type containing "Event",
		// startDate/endDate/eventDates fields.
		$type     = $item['@type'] ?? $item['type'] ?? '';
		$is_event = false;
		if ( isset( $item['recordType'] ) && 12 === (int) $item['recordType'] ) {
			$is_event = true;
		} elseif ( is_string( $type ) && false !== stripos( $type, 'event' ) ) {
			$is_event = true;
		} elseif ( ! empty( $item['startDate'] ) || ! empty( $item['endDate'] ) || ! empty( $item['eventDates'] ) ) {
			$is_event = true;
		}

		if ( ! $is_event ) {
			return array();
		}

		return $item;
	}

	/**
	 * Fetch Squarespace data via JSON API or HTML context.
	 */
	private function fetchJsonData( string $html, string $source_url ): array {
		// 1. Try JSON API first (most reliable for large pages)
		$json_url = add_query_arg( 'format', 'json', $source_url );
		$response = \DataMachine\Core\HttpClient::get(
			$json_url,
			array(
				'timeout' => 30,
				'context' => 'Squarespace Extractor JSON API',
			)
		);

		if ( $response['success'] && ! empty( $response['data'] ) ) {
			$data = json_decode( $response['data'], true );
			if ( json_last_error() === JSON_ERROR_NONE && ! empty( $data ) ) {
				// Check if this is an events collection page (has upcoming/past arrays)
				if ( isset( $data['upcoming'] ) || isset( $data['past'] ) ) {
					return $data;
				}

				// 2. Probe common event collection paths via JSON API.
				// Squarespace events are often on a separate collection page
				// (e.g. /events, /shows) that the homepage doesn't reference
				// via Summary Blocks.
				$probed = $this->probeEventCollectionPaths( $source_url );
				if ( ! empty( $probed ) ) {
					return $probed;
				}

				return $data;
			}
		}

		// 3. Fallback to the embedded page context.
		return $this->extractContextData( $html );
	}

	/**
	 * Decode Static.SQUARESPACE_CONTEXT from a page.
	 */
	private function extractContextData( string $html ): array {
		$start_token = 'Static.SQUARESPACE_CONTEXT = ';
		$pos         = strpos( $html, $start_token );
		if ( false === $pos ) {
			return array();
		}

		$json_part = substr( $html, $pos + strlen( $start_token ) );
		if ( ! preg_match( '/^(\{.*?\});\s*(?:<\/script>|window)/s', $json_part, $matches ) ) {
			return array();
		}

		$data = json_decode( $matches[1], true );
		return JSON_ERROR_NONE === json_last_error() && is_array( $data ) ? $data : array();
	}

	/**
	 * Probe common Squarespace event collection paths via JSON API.
	 *
	 * When the source URL's JSON has no upcoming/past arrays, this method
	 * tries common paths like /events, /shows, /calendar to find the actual
	 * events collection. Returns the first collection that has events.
	 *
	 * @param string $source_url Original source URL.
	 * @return array JSON data from the first matching collection, or empty array.
	 */
	private function probeEventCollectionPaths( string $source_url ): array {
		$parsed   = wp_parse_url( $source_url );
		$base_url = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );
		$current  = rtrim( $parsed['path'] ?? '', '/' );

		$paths = array(
			'/events',
			'/shows',
			'/calendar',
			'/upcoming-events',
			'/upcoming-shows',
			'/live-events',
			'/live-music',
			'/event-listings',
			'/schedule',
			'/music',
		);

		foreach ( $paths as $path ) {
			if ( rtrim( $path, '/' ) === $current ) {
				continue;
			}

			$test_url = $base_url . $path . '?format=json';
			$response = \DataMachine\Core\HttpClient::get(
				$test_url,
				array(
					'timeout' => 10,
					'context' => 'Squarespace Extractor Collection Probe',
				)
			);

			if ( ! $response['success'] || empty( $response['data'] ) ) {
				continue;
			}

			$test_data = json_decode( $response['data'], true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				continue;
			}

			if ( ! empty( $test_data['upcoming'] ) || ! empty( $test_data['past'] ) ) {
				return $test_data;
			}
		}

		return array();
	}

	public function getMethod(): string {
		return 'squarespace';
	}

	/**
	 * Look for items inside blocks (Summary Blocks, etc) in the data structure.
	 */
	private function findBlockItems( array $data ): array {
		if ( isset( $data['website']['upcomingEvents'] ) && is_array( $data['website']['upcomingEvents'] ) ) {
			return $data['website']['upcomingEvents'];
		}

		// Search for blocks that might contain items
		if ( isset( $data['blocks'] ) && is_array( $data['blocks'] ) ) {
			foreach ( $data['blocks'] as $block ) {
				if ( isset( $block['items'] ) && is_array( $block['items'] ) ) {
					$events = $this->filterEventItems( $block['items'] );
					if ( ! empty( $events ) ) {
						return $events;
					}
				}
			}
		}

		return array();
	}

	/**
	 * Recursively search for Squarespace items array in JSON structure.
	 * Looks for 'userItems' or 'items' within collections.
	 *
	 * @param array $data JSON data structure
	 * @return array Items array or empty array
	 */
	private function findItemsRecursive( array $data ): array {
		// Specific Squarespace patterns
		if ( isset( $data['collection']['userItems'] ) && is_array( $data['collection']['userItems'] ) ) {
			return $data['collection']['userItems'];
		}

		if ( isset( $data['collection']['items'] ) && is_array( $data['collection']['items'] ) ) {
			return $this->filterEventItems( $data['collection']['items'] );
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				// If we find an array named 'items' or 'userItems' at any level, it might be what we want
				if ( 'items' === $key && ! empty( $value ) ) {
					$events = $this->filterEventItems( $value );
					if ( ! empty( $events ) ) {
						return $events;
					}
				}

				if ( 'userItems' === $key && ! empty( $value ) && isset( $value[0]['title'] ) ) {
					return $value;
				}

				$result = $this->findItemsRecursive( $value );
				if ( ! empty( $result ) ) {
					return $result;
				}
			}
		}

		return array();
	}

	/**
	 * Normalize Squarespace item to standardized format.
	 *
	 * @param array $item Raw Squarespace item object
	 * @param array $page_venue Venue info extracted from page context
	 * @return array Standardized event data
	 */
	private function normalizeItem( array $item, array $page_venue ): array {
		$event = array(
			'title'         => $this->sanitizeText( $item['title'] ?? '' ),
			'description'   => $this->cleanHtml( $item['description'] ?? $item['body'] ?? '' ),
			'venue'         => $page_venue['venue'] ?? '',
			'venueAddress'  => $page_venue['venueAddress'] ?? '',
			'venueCity'     => $page_venue['venueCity'] ?? '',
			'venueState'    => $page_venue['venueState'] ?? '',
			'venueZip'      => $page_venue['venueZip'] ?? '',
			'venueCountry'  => $page_venue['venueCountry'] ?? 'US',
			'venueTimezone' => $page_venue['venueTimezone'] ?? '',
			'source_url'    => '',
		);

		// Extract venue from event's location object (takes priority over page_venue)
		$this->parseEventLocation( $event, $item );

		// Set source URL
		if ( ! empty( $item['fullUrl'] ) ) {
			$event['source_url'] = $item['fullUrl'];
		}

		$this->parseScheduling( $event, $item );
		$this->parseTicketing( $event, $item );
		$this->parseImage( $event, $item );

		return $event;
	}

	/**
	 * Parse venue info from event's location object.
	 */
	private function parseEventLocation( array &$event, array $item ): void {
		if ( empty( $item['location'] ) || ! is_array( $item['location'] ) ) {
			return;
		}

		$location = $item['location'];

		if ( ! empty( $location['addressTitle'] ) ) {
			$event['venue'] = $this->sanitizeText( $location['addressTitle'] );
		}

		if ( ! empty( $location['addressLine1'] ) ) {
			$event['venueAddress'] = $this->sanitizeText( $location['addressLine1'] );
		}

		// Parse addressLine2 for city, state, zip (format: "City, ST, ZIPCODE")
		if ( ! empty( $location['addressLine2'] ) ) {
			$parts = array_map( 'trim', explode( ',', $location['addressLine2'] ) );
			if ( count( $parts ) >= 1 ) {
				$event['venueCity'] = $this->sanitizeText( $parts[0] );
			}
			if ( count( $parts ) >= 2 ) {
				$event['venueState'] = $this->sanitizeText( $parts[1] );
			}
			if ( count( $parts ) >= 3 ) {
				$event['venueZip'] = $this->sanitizeText( $parts[2] );
			}
		}

		if ( ! empty( $location['addressCountry'] ) ) {
			$event['venueCountry'] = $this->sanitizeText( $location['addressCountry'] );
		}
	}

	/**
	 * Parse scheduling data from Squarespace item.
	 *
	 * Squarespace stores timestamps in UTC. This method converts them to local
	 * timezone using the venueTimezone extracted from the page context.
	 *
	 * Also handles text date formats from User Items List (e.g., "January 16, 2026")
	 * and preserves pre-extracted startTime from description parsing.
	 */
	private function parseScheduling( array &$event, array $item ): void {
		$timezone = $event['venueTimezone'] ?? '';

		$pre_extracted_time = ! empty( $item['startTime'] ) ? $item['startTime'] : null;

		if ( ! empty( $item['startDate'] ) ) {
			if ( is_numeric( $item['startDate'] ) || preg_match( '/^\d{4}-\d{2}-\d{2}/', $item['startDate'] ) ) {
				$parsed             = $this->parseSquarespaceTimestamp( $item['startDate'], $timezone );
				$event['startDate'] = $parsed['date'];
				$event['startTime'] = $parsed['time'];
			} else {
				$this->parseTextDate( $event, $item['startDate'] );
			}
		} elseif ( ! empty( $item['publishOn'] ) ) {
			$parsed             = $this->parseSquarespaceTimestamp( $item['publishOn'], $timezone );
			$event['startDate'] = $parsed['date'];
			$event['startTime'] = $parsed['time'];
		}

		if ( $pre_extracted_time && ( empty( $event['startTime'] ) || '00:00' === $event['startTime'] ) ) {
			$event['startTime'] = $pre_extracted_time;
		}

		if ( ! empty( $item['endDate'] ) ) {
			$parsed           = $this->parseSquarespaceTimestamp( $item['endDate'], $timezone );
			$event['endDate'] = $parsed['date'];
			$event['endTime'] = $parsed['time'];
		}

		// Fallback: search description for dates if not found
		if ( empty( $event['startDate'] ) ) {
			$this->extractDateFromText( $event, $event['description'] );
		}
	}

	/**
	 * Parse Squarespace timestamp (milliseconds UTC or ISO string) to local timezone.
	 *
	 * @param mixed $value Timestamp in milliseconds, seconds, or ISO string
	 * @param string $timezone IANA timezone identifier
	 * @return array{date: string, time: string, timezone: string}
	 */
	private function parseSquarespaceTimestamp( $value, string $timezone ): array {
		if ( is_numeric( $value ) ) {
			return $this->parseUtcTimestamp( $value, $timezone );
		}

		return $this->parseDatetime( (string) $value, $timezone );
	}

	/**
	 * Parse ticketing data from Squarespace item.
	 */
	private function parseTicketing( array &$event, array $item ): void {
		// Check for buttonLink pattern
		if ( ! empty( $item['button']['buttonLink'] ) ) {
			$event['ticketUrl'] = esc_url_raw( $item['button']['buttonLink'] );
		} elseif ( ! empty( $item['clickthroughUrl'] ) ) {
			$event['ticketUrl'] = esc_url_raw( $item['clickthroughUrl'] );
		}
	}

	/**
	 * Parse image data from Squarespace item.
	 */
	private function parseImage( array &$event, array $item ): void {
		if ( ! empty( $item['assetUrl'] ) ) {
			$event['imageUrl'] = esc_url_raw( $item['assetUrl'] );
		} elseif ( ! empty( $item['image']['assetUrl'] ) ) {
			$event['imageUrl'] = esc_url_raw( $item['image']['assetUrl'] );
		}
	}

	/**
	 * Extract date from text description or date label.
	 *
	 * Handles formats like "January 15, 2026", "Jan 15", "January 15th".
	 * If no year is present and the date has passed, assumes next year.
	 *
	 * @param array  $event Event array to update.
	 * @param string $text  Text containing a date.
	 */
	private function extractDateFromText( array &$event, string $text ): void {
		if ( empty( $text ) ) {
			return;
		}

		$months = 'January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';

		if ( preg_match( '/(' . $months . ')\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?/i', $text, $matches ) ) {
			$month = $matches[1];
			$day   = $matches[2];

			if ( ! empty( $matches[3] ) ) {
				try {
					$dt                 = new \DateTime( "{$month} {$day} {$matches[3]}" );
					$event['startDate'] = $dt->format( 'Y-m-d' );
				} catch ( \Exception $e ) {
					return;
				}
			} else {
				$event['startDate'] = $this->inferDateFromMonthDay( $month, $day );
			}
		}
	}

	/**
	 * @deprecated Use extractDateFromText() instead.
	 */
	private function parseTextDate( array &$event, string $text ): void {
		$this->extractDateFromText( $event, $text );
	}
}
