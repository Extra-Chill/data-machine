<?php
/**
 * VenuePilot extractor.
 *
 * Extracts event data from venues using the VenuePilot platform by detecting
 * the widget configuration (accountIds) in page HTML and querying their
 * public GraphQL API directly.
 *
 * VenuePilot widgets are 100% client-rendered (Vue.js + Apollo GraphQL),
 * so static HTML fetching returns zero event data. This extractor bypasses
 * the client-side rendering by going straight to the data source.
 *
 * Detection: looks for venuepilot.co/widgets/ script tags or
 * window.venuepilotSettings configuration objects in the HTML.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.62.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenuePilotExtractor extends BaseExtractor {

	const GRAPHQL_ENDPOINT = 'https://www.venuepilot.co/graphql';

	/**
	 * GraphQL query for fetching paginated events.
	 *
	 * Exact field names and argument structure derived from VenuePilot's
	 * widget bundle (vp-widget.umd.js v2.0.0). The paginatedEvents field
	 * requires an `arguments` input object wrapper.
	 */
	const EVENTS_QUERY = '
		query (
			$accountIds: [Int!]!
			$startDate: String!
			$endDate: String
			$search: String
			$searchScope: String
			$page: Int
		) {
			paginatedEvents(
				arguments: {
					accountIds: $accountIds
					startDate: $startDate
					endDate: $endDate
					search: $search
					searchScope: $searchScope
					page: $page
				}
			) {
				collection {
					id
					name
					date
					doorTime
					startTime
					endTime
					minimumAge
					promoter
					support
					description
					ticketsUrl
					websiteUrl
					status
					announceArtists {
						name
					}
					announceImages {
						name
						highlighted
						versions {
							cover {
								src
							}
						}
					}
					venue {
						name
					}
				}
				metadata {
					currentPage
					totalPages
				}
			}
		}
	';

	/**
	 * Check if this page contains VenuePilot widget integration.
	 *
	 * Wix custom embeds are resolved by the universal scraper before extraction.
	 *
	 * @param string $html HTML content to check.
	 * @return bool True if VenuePilot markers are detected.
	 */
	public function canExtract( string $html ): bool {
		// Direct VenuePilot markers.
		if ( strpos( $html, 'venuepilot.co/widgets/' ) !== false
			|| strpos( $html, 'venuepilotSettings' ) !== false
			|| strpos( $html, 'widget.staging.venuepilot.com' ) !== false
			|| strpos( $html, 'venuepilot-app' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Extract events by parsing the widget config and querying the GraphQL API.
	 *
	 * @param string $html       HTML content containing VenuePilot widget.
	 * @param string $source_url Source URL for context.
	 * @return array Array of normalized event arrays.
	 */
	public function extract( string $html, string $source_url ): array {
		$account_ids = $this->extractAccountIds( $html, $source_url );

		if ( empty( $account_ids ) ) {
			return array();
		}

		$raw_events = $this->fetchAllEvents( $account_ids );

		if ( empty( $raw_events ) ) {
			return array();
		}

		$events = array();
		foreach ( $raw_events as $raw_event ) {
			$normalized = $this->normalizeEvent( $raw_event );
			if ( ! empty( $normalized['title'] ) && ! empty( $normalized['startDate'] ) ) {
				$events[] = $normalized;
			}
		}

		return $events;
	}

	/**
	 * Get the extraction method identifier.
	 *
	 * @return string Method identifier.
	 */
	public function getMethod(): string {
		return 'venuepilot';
	}

	/**
	 * Extract account IDs from the HTML.
	 *
	 * Handles two integration patterns:
	 * 1. Inline venuepilotSettings object with accountIds array.
	 * 2. Widget script URL that embeds settings when loaded.
	 *
	 * @param string $html       Page HTML.
	 * @param string $source_url Source URL.
	 * @return array Account ID integers, or empty array.
	 */
	private function extractAccountIds( string $html, string $source_url ): array {
		// Pattern 1: Inline settings — window.venuepilotSettings = { general: { accountIds: [148] } }
		if ( preg_match( '/venuepilotSettings\s*=\s*\{/', $html ) ) {
			if ( preg_match( '/["\']?accountIds["\']?\s*:\s*\[\s*([\d,\s]+)\s*\]/s', $html, $matches ) ) {
				$ids = array_map( 'intval', array_filter( explode( ',', $matches[1] ) ) );
				if ( ! empty( $ids ) ) {
					return $ids;
				}
			}
		}

		// Pattern 2: Widget script tag — fetch the JS to get the embedded settings.
		if ( preg_match( '#https?://www\.venuepilot\.co/widgets/([a-zA-Z0-9_-]+)\.js#', $html, $matches ) ) {
			$widget_url = $matches[0];
			$widget_js  = $this->fetchUrl( $widget_url, array(), 'VenuePilot widget JS' );

			if ( ! empty( $widget_js ) && preg_match( '/["\']?accountIds["\']?\s*:\s*\[\s*([\d,\s]+)\s*\]/s', $widget_js, $js_matches ) ) {
				$ids = array_map( 'intval', array_filter( explode( ',', $js_matches[1] ) ) );
				if ( ! empty( $ids ) ) {
					return $ids;
				}
			}
		}

		return array();
	}

	/**
	 * Fetch all events from VenuePilot GraphQL API with pagination.
	 *
	 * @param array $account_ids VenuePilot account IDs.
	 * @return array Raw event objects from the API.
	 */
	private function fetchAllEvents( array $account_ids ): array {
		$all_events = array();
		$page       = 1;
		$max_pages  = 10;
		$start_date = gmdate( 'Y-m-d' );

		do {
			$response = $this->queryGraphQL( $account_ids, $start_date, $page );

			if ( null === $response ) {
				break;
			}

			$paginated = $response['data']['paginatedEvents'] ?? null;
			if ( null === $paginated ) {
				break;
			}

			$collection = $paginated['collection'] ?? array();
			$metadata   = $paginated['metadata'] ?? array();

			if ( empty( $collection ) ) {
				break;
			}

			$all_events = array_merge( $all_events, $collection );

			$current_page = (int) ( $metadata['currentPage'] ?? $page );
			$total_pages  = (int) ( $metadata['totalPages'] ?? 1 );

			if ( $current_page >= $total_pages ) {
				break;
			}

			++$page;
		} while ( $page <= $max_pages );

		return $all_events;
	}

	/**
	 * Execute a GraphQL query against VenuePilot.
	 *
	 * @param array  $account_ids Account IDs.
	 * @param string $start_date  Start date (Y-m-d).
	 * @param int    $page        Page number.
	 * @return array|null Decoded JSON response or null on failure.
	 */
	private function queryGraphQL( array $account_ids, string $start_date, int $page ): ?array {
		$payload = wp_json_encode(
			array(
				'query'     => self::EVENTS_QUERY,
				'variables' => array(
					'accountIds' => $account_ids,
					'startDate'  => $start_date,
					'page'       => $page,
				),
			)
		);

		$result = HttpClient::post(
			self::GRAPHQL_ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $payload,
				'context' => 'VenuePilot GraphQL',
			)
		);

		if ( empty( $result['success'] ) || 200 !== ( $result['status_code'] ?? 0 ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'VenuePilotExtractor: GraphQL request failed',
				array(
					'status_code' => $result['status_code'] ?? 0,
					'account_ids' => $account_ids,
					'page'        => $page,
				)
			);
			return null;
		}

		$body = $result['data'] ?? ( $result['body'] ?? '' );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		if ( ! empty( $data['errors'] ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'VenuePilotExtractor: GraphQL returned errors',
				array(
					'errors'      => $data['errors'],
					'account_ids' => $account_ids,
				)
			);
			return null;
		}

		return $data;
	}

	/**
	 * Normalize a VenuePilot event to the standard event format.
	 *
	 * @param array $event Raw event from GraphQL response.
	 * @return array Normalized event array.
	 */
	private function normalizeEvent( array $event ): array {
		$title = $this->sanitizeText( $event['name'] ?? '' );

		$support = $this->sanitizeText( $event['support'] ?? '' );

		// Date comes as Y-m-d from the API.
		$start_date = $event['date'] ?? '';

		// Times come in various formats.
		$start_time = $this->parseTimeString( $event['startTime'] ?? '' );
		$door_time  = $this->parseTimeString( $event['doorTime'] ?? '' );
		$end_time   = $this->parseTimeString( $event['endTime'] ?? '' );

		// Prefer door time as start time.
		$display_time = ! empty( $door_time ) ? $door_time : $start_time;

		// Venue name (GraphQL only returns name via the widget query).
		$venue_name = $this->sanitizeText( $event['venue']['name'] ?? '' );

		// Ticket URL.
		$ticket_url = ! empty( $event['ticketsUrl'] ) ? esc_url_raw( $event['ticketsUrl'] ) : '';

		// Image URL — extract from announceImages (highlighted first, then first available).
		$image_url = $this->extractImageUrl( $event['announceImages'] ?? array() );

		// Description — clean HTML.
		$description = $this->cleanHtml( $event['description'] ?? '' );

		// Promoter / presented by.
		$promoter = $this->sanitizeText( $event['promoter'] ?? '' );

		// Build a richer description if we have extra data.
		$description_parts = array();
		if ( ! empty( $description ) ) {
			$description_parts[] = $description;
		}
		if ( ! empty( $support ) ) {
			$description_parts[] = 'with ' . $support;
		}
		if ( ! empty( $promoter ) ) {
			$description_parts[] = 'Presented by ' . $promoter;
		}

		$full_description = implode( "\n\n", $description_parts );

		return array(
			'title'       => $title,
			'description' => $full_description,
			'startDate'   => $start_date,
			'endDate'     => '',
			'startTime'   => $display_time,
			'endTime'     => $end_time,
			'venue'       => $venue_name,
			'ticketUrl'   => $ticket_url,
			'imageUrl'    => $image_url,
			'price'       => '',
		);
	}

	/**
	 * Extract the best image URL from announceImages.
	 *
	 * Prefers the highlighted image, falls back to the first available cover.
	 *
	 * @param array $images announceImages array from GraphQL.
	 * @return string Image URL or empty string.
	 */
	private function extractImageUrl( array $images ): string {
		if ( empty( $images ) ) {
			return '';
		}

		// Prefer highlighted image.
		foreach ( $images as $image ) {
			if ( ! empty( $image['highlighted'] ) ) {
				$src = $image['versions']['cover']['src'] ?? '';
				if ( ! empty( $src ) ) {
					return esc_url_raw( $src );
				}
			}
		}

		// Fall back to first image with a cover version.
		foreach ( $images as $image ) {
			$src = $image['versions']['cover']['src'] ?? '';
			if ( ! empty( $src ) ) {
				return esc_url_raw( $src );
			}
		}

		return '';
	}
}
