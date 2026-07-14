<?php
/**
 * WordPress Generic extractor.
 *
 * Handles WordPress sites that DO NOT use The Events Calendar (Tribe) plugin
 * — the largest unhandled `extraction_gap` cluster per the network-wide
 * qualify-stats histogram (issue #268).
 *
 * The existing {@see WordPressExtractor} only knows the Tribe REST shape
 * (`/wp-json/tribe/events/v1/events`). Many indie venue WP installs register
 * a custom event post type instead (e.g. `lpr_events`, `mec_event`, plain
 * `events`) or just list events as regular blog posts. Schema.org Event
 * JSON-LD and microdata are already handled upstream by `JsonLdExtractor` /
 * `MicrodataExtractor`, so this extractor focuses on the remaining gap.
 *
 * Cascade (per #268):
 *
 *   Tier 1 — Schema.org Event JSON-LD on the page
 *     Handled by JsonLdExtractor (earlier in cascade). If it produces events,
 *     this extractor never runs.
 *
 *   Tier 2 — Probe /wp-json/wp/v2/types for an events CPT, then GET that
 *     collection with `_embed`. Highest-yield path because WP REST is
 *     consistent across themes.
 *
 *   Tier 3 — Microdata schema.org/Event
 *     Handled by MicrodataExtractor (earlier in cascade). Same skip rule.
 *
 *   Tier 4 — Theme-specific generic post listing (FALLBACK only)
 *     Parses `<article class="...event|gig|show|concert|performance...">`
 *     blocks. Conservative: only runs when the page itself looks
 *     event-listing-shaped (URL path or page title/description signal).
 *
 * Detection mirrors `PlatformDetector`'s `wordpress_generic` fingerprint:
 *
 *   HTML contains `wp-content/` OR `wp-json/` AND
 *   HTML does NOT contain `/wp-json/tribe/events/v1/`
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.36.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WordPressGenericExtractor extends BaseExtractor {

	/**
	 * Substring-based rest_base / post-type name patterns that almost
	 * certainly identify an event-shaped CPT.
	 *
	 * Matched as a single regex against `name` and `rest_base` returned by
	 * /wp-json/wp/v2/types. Anchored with word boundaries (start of string
	 * or `_-` separator) to avoid catching `event_category`, `eventbrite`,
	 * etc. while still matching real-world cases like `lpr_events`,
	 * `mec-events`, `eventon_event`, `tour-dates`.
	 */
	private const CPT_PATTERN = '/(?:^|[_\-])(?:events?|gigs|shows|concerts|performances|tour[_\-]?dates?|schedule|calendar[_\-]?events?|tribe[_\-]events|mec[_\-]?event[s]?|eventon(?:[_\-]event)?)(?:$|[_\-])/i';

	/**
	 * Allowed external ticket-vendor host substrings for Tier 2 ticket-URL
	 * fishing in `content.rendered`.
	 */
	private const TICKET_VENDOR_DOMAINS = array(
		'eventbrite',
		'dice.fm',
		'ticketweb',
		'ticketmaster',
		'seetickets',
		'tixr',
		'etix',
		'showclix',
		'aftontickets',
		'prekindle',
		'freshtix',
		'opentable',
		'venuepilot',
		'showare',
	);

	public function canExtract( string $html ): bool {
		if ( '' === $html ) {
			return false;
		}

		// Reject if Tribe is present — that's WordPressExtractor's job.
		if ( false !== strpos( $html, '/wp-json/tribe/events/v1/' ) ) {
			return false;
		}
		if ( false !== strpos( $html, 'tribe_events' ) ) {
			return false;
		}

		// Positive WP signal — any of these is sufficient.
		$wp_markers = array(
			'wp-content/themes/',
			'wp-content/plugins/',
			'/wp-json/',
			'wp-content/uploads/',
		);

		foreach ( $wp_markers as $marker ) {
			if ( false !== strpos( $html, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	public function extract( string $html, string $source_url ): array {
		// Tier 2: probe WP REST for an event CPT.
		$events = $this->extractViaRestApi( $html, $source_url );
		if ( ! empty( $events ) ) {
			return $events;
		}

		// Tier 4: theme-specific generic post listing (fallback).
		if ( ! $this->pageLooksEventShaped( $html, $source_url ) ) {
			return array();
		}

		return $this->extractViaThemeListing( $html, $source_url );
	}

	public function getMethod(): string {
		return 'wordpress_generic';
	}

	/**
	 * Tier 2 — discover an events CPT via /wp-json/wp/v2/types and pull its
	 * collection with `_embed`.
	 *
	 * Returns an empty array if no matching CPT is found, the request fails,
	 * or no parseable events are present.
	 */
	private function extractViaRestApi( string $html, string $source_url ): array {
		$base_url = $this->resolveSiteBaseUrl( $html, $source_url );
		if ( '' === $base_url ) {
			return array();
		}

		$types_url = $base_url . '/wp-json/wp/v2/types';
		$types_raw = $this->fetchUrl( $types_url, array( 'timeout' => 15 ), 'WordPressGenericExtractor types' );
		if ( null === $types_raw ) {
			return array();
		}

		$types = json_decode( $types_raw, true );
		if ( ! is_array( $types ) || JSON_ERROR_NONE !== json_last_error() ) {
			return array();
		}

		$rest_base = $this->findEventRestBase( $types );
		if ( '' === $rest_base ) {
			return array();
		}

		$collection_url = $base_url . '/wp-json/wp/v2/' . rawurlencode( $rest_base ) . '?per_page=50&_embed';
		$raw            = $this->fetchUrl( $collection_url, array( 'timeout' => 20 ), 'WordPressGenericExtractor collection' );
		if ( null === $raw ) {
			return array();
		}

		$items = json_decode( $raw, true );
		if ( ! is_array( $items ) || JSON_ERROR_NONE !== json_last_error() ) {
			return array();
		}

		// WP REST can return either a bare array or a wrapped object on error.
		if ( ! isset( $items[0] ) ) {
			return array();
		}

		$page_venue = PageVenueExtractor::extract( $html, $source_url );
		$events     = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$event = $this->mapRestItem( $item, $source_url, $page_venue );
			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Find the first registered post type that looks like an events CPT.
	 *
	 * Returns the `rest_base` (preferred) or post-type slug (fallback)
	 * suitable for use as the REST collection URL path segment.
	 */
	private function findEventRestBase( array $types ): string {
		// Built-in WP post types we must never use, even if their names
		// happen to match (unlikely, but defensive).
		$builtins = array( 'post', 'page', 'attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

		foreach ( $types as $slug => $type ) {
			if ( ! is_array( $type ) ) {
				continue;
			}
			if ( in_array( $slug, $builtins, true ) ) {
				continue;
			}

			$rest_base = isset( $type['rest_base'] ) && is_string( $type['rest_base'] ) ? $type['rest_base'] : '';
			$candidate = '' !== $rest_base ? $rest_base : $slug;

			// Skip if the rest_base looks like a nested route (e.g.
			// `wp_font_family/(?P<font_family_id>[\d]+)/font-faces`).
			if ( false !== strpos( $candidate, '/' ) || false !== strpos( $candidate, '(' ) ) {
				continue;
			}

			if ( preg_match( self::CPT_PATTERN, $slug ) || preg_match( self::CPT_PATTERN, $rest_base ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Map a single /wp-json/wp/v2/<cpt> item to a normalized event record.
	 *
	 * Date resolution walks a fallback chain because no two themes agree:
	 *   acf.event_date / acf.start_date / acf.startDate
	 *     -> meta.start_date / meta.event_date / meta._event_start_date
	 *     -> top-level start_date / event_date
	 *     -> the WP `date` field as last-resort (only when nothing else
	 *        resolves; this is the post-publish date, not the event date,
	 *        and is intentionally last because it's wrong more often than
	 *        right).
	 *
	 * If none of the above yields a date, the event is rejected by the
	 * caller (require startDate to be non-empty).
	 */
	private function mapRestItem( array $item, string $source_url, array $page_venue ): array {
		$title       = $this->extractRendered( $item, 'title' );
		$description = '';

		$excerpt_raw = $this->extractRendered( $item, 'excerpt' );
		$content_raw = $this->extractRendered( $item, 'content' );
		if ( '' !== $excerpt_raw ) {
			$description = $excerpt_raw;
		} elseif ( '' !== $content_raw ) {
			$description = $content_raw;
		}

		$acf  = is_array( $item['acf'] ?? null ) ? $item['acf'] : array();
		$meta = is_array( $item['meta'] ?? null ) ? $item['meta'] : array();

		$start_date = '';
		$start_time = '';

		$date_candidates = array(
			$acf['event_date'] ?? null,
			$acf['start_date'] ?? null,
			$acf['startDate'] ?? null,
			$meta['start_date'] ?? null,
			$meta['event_date'] ?? null,
			$meta['_event_start_date'] ?? null,
			$meta['_EventStartDate'] ?? null,
			$item['start_date'] ?? null,
			$item['event_date'] ?? null,
		);

		foreach ( $date_candidates as $candidate ) {
			if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
				continue;
			}
			$parsed = $this->parseDatetime( $candidate );
			if ( ! empty( $parsed['date'] ) ) {
				$start_date = $parsed['date'];
				$start_time = $parsed['time'];
				break;
			}
		}

		// Time-only fallback: some themes split date + time.
		if ( '' === $start_time ) {
			$time_candidates = array(
				$acf['start_time'] ?? null,
				$acf['event_time'] ?? null,
				$meta['start_time'] ?? null,
				$meta['_event_start_time'] ?? null,
			);
			foreach ( $time_candidates as $tc ) {
				if ( is_string( $tc ) && '' !== trim( $tc ) ) {
					$parsed_time = $this->parseTimeString( $tc );
					if ( '' !== $parsed_time ) {
						$start_time = $parsed_time;
						break;
					}
				}
			}
		}

		// Image — _embedded featured media (preferred), else top-level image.
		$image_url = $item['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';
		if ( '' === $image_url && isset( $item['featured_image_url'] ) && is_string( $item['featured_image_url'] ) ) {
			$image_url = $item['featured_image_url'];
		}

		// Venue — ACF/meta first, then PageVenueExtractor fallback.
		$venue = '';
		foreach ( array( $acf['venue'] ?? null, $meta['venue'] ?? null, $meta['_event_venue'] ?? null, $meta['_VenueName'] ?? null ) as $v ) {
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$venue = $v;
				break;
			}
		}

		// Ticket URL — ACF/meta first, then scrape content for a vendor link.
		$ticket_url = '';
		foreach ( array( $acf['ticket_url'] ?? null, $acf['tickets_url'] ?? null, $meta['ticket_url'] ?? null, $meta['_event_ticket_url'] ?? null, $meta['_EventURL'] ?? null ) as $tu ) {
			if ( is_string( $tu ) && '' !== trim( $tu ) ) {
				$ticket_url = $tu;
				break;
			}
		}
		if ( '' === $ticket_url && '' !== $content_raw ) {
			$ticket_url = $this->findVendorLinkInHtml( $content_raw );
		}

		$event = array(
			'title'         => $this->sanitizeText( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) ),
			'description'   => $this->cleanHtml( $description ),
			'startDate'     => $start_date,
			'endDate'       => '',
			'startTime'     => $start_time,
			'endTime'       => '',
			'venue'         => $this->sanitizeText( $venue ),
			'venueAddress'  => '',
			'venueCity'     => '',
			'venueState'    => '',
			'venueZip'      => '',
			'venueCountry'  => '',
			'venueTimezone' => '',
			'price'         => '',
			'ticketUrl'     => esc_url_raw( $ticket_url ),
			'imageUrl'      => esc_url_raw( $image_url ),
			'eventType'     => 'Event',
			'source_url'    => esc_url_raw( isset( $item['link'] ) && is_string( $item['link'] ) ? $item['link'] : $source_url ),
		);

		return $this->mergePageVenueData( $event, $page_venue );
	}

	/**
	 * Pull `.rendered` from a WP REST field, or fall back to a plain string.
	 */
	private function extractRendered( array $item, string $field ): string {
		if ( ! isset( $item[ $field ] ) ) {
			return '';
		}
		$value = $item[ $field ];
		if ( is_array( $value ) && isset( $value['rendered'] ) && is_string( $value['rendered'] ) ) {
			return $value['rendered'];
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Find a vendor ticket link inside an HTML fragment.
	 */
	private function findVendorLinkInHtml( string $html ): string {
		if ( ! preg_match_all( '/href=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return '';
		}
		foreach ( $m[1] as $href ) {
			$decoded = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
			foreach ( self::TICKET_VENDOR_DOMAINS as $domain ) {
				if ( false !== stripos( $decoded, $domain ) ) {
					return $decoded;
				}
			}
		}
		return '';
	}

	/**
	 * Resolve the WP site base URL from REST API discovery link or the
	 * source URL host.
	 */
	private function resolveSiteBaseUrl( string $html, string $source_url ): string {
		// Prefer the discovery link if present — it's authoritative even
		// when WP is in a subdirectory install.
		if ( preg_match( '#<link[^>]+rel=["\']https://api\.w\.org/?["\'][^>]+href=["\']([^"\']+)["\']#i', $html, $m ) ) {
			$api_root = rtrim( $m[1], '/' );
			// api_root looks like https://example.com/wp-json — strip /wp-json.
			$stripped = preg_replace( '#/wp-json/?$#', '', $api_root );
			return is_string( $stripped ) ? $stripped : '';
		}

		$parts = wp_parse_url( $source_url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
	}

	/**
	 * Decide whether the page itself looks event-listing-shaped enough to
	 * justify running Tier 4 (theme-specific post listing).
	 *
	 * This guards against false positives from blog homepages that happen
	 * to have a few `<article class="event">` style elements.
	 */
	private function pageLooksEventShaped( string $html, string $source_url ): bool {
		$path = wp_parse_url( $source_url, PHP_URL_PATH );
		if ( is_string( $path ) && preg_match( '#/(events?|shows?|calendar|schedule|gigs|concerts|performances)(/|$)#i', $path ) ) {
			return true;
		}

		$keywords = '(events?|calendar|schedule|shows?|concerts?|gigs?|tour|performances)';

		if ( preg_match( '#<title>([^<]+)</title>#i', $html, $m ) && preg_match( '/\b' . $keywords . '\b/i', $m[1] ) ) {
			return true;
		}

		if ( preg_match( '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m ) && preg_match( '/\b' . $keywords . '\b/i', $m[1] ) ) {
			return true;
		}

		// og:title / og:description as a secondary signal.
		if ( preg_match( '#<meta[^>]+property=["\']og:(?:title|description)["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m ) && preg_match( '/\b' . $keywords . '\b/i', $m[1] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Tier 4 — extract events from `<article>` blocks tagged with
	 * event-shaped class names.
	 *
	 * Conservative parser: skips any article without BOTH a title and a
	 * resolvable date. Date resolution priority:
	 *   1. `<time datetime="...">` inside the article
	 *   2. "Month DD, YYYY" or "Mon DD" in the article text
	 *   3. ISO date in the article's URL slug (`/event/2026-05-20-foo/`)
	 */
	private function extractViaThemeListing( string $html, string $source_url ): array {
		$page_venue = PageVenueExtractor::extract( $html, $source_url );

		// Match <article ... class="...event|gig|show|concert|performance...">...</article>.
		// We use a non-greedy match for the closing </article>; nested
		// articles inside articles are vanishingly rare for event lists.
		if ( ! preg_match_all(
			'#<article\b[^>]*class=["\'][^"\']*\b(?:event|gig|show|concert|performance)s?\b[^"\']*["\'][^>]*>(.*?)</article>#is',
			$html,
			$matches
		) ) {
			return array();
		}

		$events = array();
		foreach ( $matches[1] as $idx => $inner ) {
			// Pull the opening tag too so we can mine href / class for
			// URL-based date fallback.
			$opening_tag = '';
			if ( preg_match_all( '#<article\b[^>]*>#i', $html, $opens ) && isset( $opens[0][ $idx ] ) ) {
				$opening_tag = $opens[0][ $idx ];
			}

			$event = $this->parseThemeListingArticle( $opening_tag, $inner, $source_url, $page_venue );
			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	private function parseThemeListingArticle( string $opening_tag, string $inner, string $source_url, array $page_venue ): array {
		$event = array(
			'title'         => '',
			'description'   => '',
			'startDate'     => '',
			'endDate'       => '',
			'startTime'     => '',
			'endTime'       => '',
			'venue'         => $page_venue['venue'] ?? '',
			'venueAddress'  => $page_venue['venueAddress'] ?? '',
			'venueCity'     => $page_venue['venueCity'] ?? '',
			'venueState'    => $page_venue['venueState'] ?? '',
			'venueZip'      => $page_venue['venueZip'] ?? '',
			'venueCountry'  => $page_venue['venueCountry'] ?? '',
			'venueTimezone' => $page_venue['venueTimezone'] ?? '',
			'price'         => '',
			'ticketUrl'     => '',
			'imageUrl'      => '',
			'eventType'     => 'Event',
			'source_url'    => '',
		);

		// Title from first <h1>/<h2>/<h3> inside the article.
		if ( preg_match( '#<h[123][^>]*>(.*?)</h[123]>#is', $inner, $tm ) ) {
			$raw_title      = trim( wp_strip_all_tags( $tm[1] ) );
			$event['title'] = $this->sanitizeText( html_entity_decode( $raw_title, ENT_QUOTES, 'UTF-8' ) );
		}

		// Detail-page URL (first <a href> in title, else first non-anchor link).
		if ( preg_match( '#<h[123][^>]*>.*?<a[^>]+href=["\']([^"\']+)["\'][^>]*>#is', $inner, $um ) ) {
			$event['source_url'] = esc_url_raw( $this->resolveUrl( html_entity_decode( $um[1], ENT_QUOTES, 'UTF-8' ), $source_url ) );
		} elseif ( preg_match( '#<a[^>]+href=["\']([^"\']+)["\'][^>]*>#i', $inner, $um ) ) {
			$event['source_url'] = esc_url_raw( $this->resolveUrl( html_entity_decode( $um[1], ENT_QUOTES, 'UTF-8' ), $source_url ) );
		}

		// Date resolution chain.
		$start_date = '';
		$start_time = '';

		// 1) <time datetime="...">
		if ( preg_match( '#<time[^>]+datetime=["\']([^"\']+)["\'][^>]*>#i', $inner, $dm ) ) {
			$parsed = $this->parseDatetime( $dm[1] );
			if ( ! empty( $parsed['date'] ) ) {
				$start_date = $parsed['date'];
				$start_time = $parsed['time'];
			}
		}

		// 2) "Month DD, YYYY" or "Mon DD" pattern in body text.
		if ( '' === $start_date ) {
			$text = trim( wp_strip_all_tags( $inner ) );
			if ( preg_match( '/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t)?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2})(?:,\s*(\d{4}))?\b/i', $text, $dm ) ) {
				$month_name = $dm[1];
				$day        = $dm[2];
				$year       = isset( $dm[3] ) && '' !== $dm[3] ? (int) $dm[3] : 0;
				if ( $year > 0 ) {
					try {
						$dt         = new \DateTime( sprintf( '%s %d %d', $month_name, (int) $day, $year ) );
						$start_date = $dt->format( 'Y-m-d' );
					} catch ( \Exception $e ) {
						$start_date = '';
					}
				} else {
					$start_date = $this->inferDateFromMonthDay( $month_name, $day );
				}
			}
		}

		// 3) ISO date in URL slug (e.g. /event/2026-05-20-foo/).
		if ( '' === $start_date && '' !== $event['source_url'] ) {
			if ( preg_match( '#/(\d{4})-(\d{2})-(\d{2})(?:[-/]|$)#', $event['source_url'], $sm ) ) {
				$start_date = $sm[1] . '-' . $sm[2] . '-' . $sm[3];
			}
		}

		// Time fallback from descriptive text.
		if ( '' === $start_time ) {
			$text       = trim( wp_strip_all_tags( $inner ) );
			$start_time = (string) ( $this->extractTimeFromText( $text ) ?? '' );
		}

		$event['startDate'] = $start_date;
		$event['endDate']   = '';
		$event['startTime'] = $start_time;

		// Image — first <img src> in the article.
		if ( preg_match( '#<img[^>]+src=["\']([^"\']+)["\']#i', $inner, $im ) ) {
			$event['imageUrl'] = esc_url_raw( $this->resolveUrl( html_entity_decode( $im[1], ENT_QUOTES, 'UTF-8' ), $source_url ) );
		}

		// Ticket URL — first known vendor link inside the article.
		$ticket = $this->findVendorLinkInHtml( $inner );
		if ( '' !== $ticket ) {
			$event['ticketUrl'] = esc_url_raw( $ticket );
		} elseif ( '' !== $event['source_url'] ) {
			$event['ticketUrl'] = $event['source_url'];
		}

		return $event;
	}
}
