<?php
/**
/**
 * Bandzoogle extractor.
 *
 * Extracts event data from Bandzoogle calendar sites by crawling month pages
 * and fetching individual event detail popups. Maintains legacy regex stability.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BandzoogleExtractor extends BaseExtractor {

	const MAX_PAGES = 12; // Limit pagination to 1 year forward

	public function canExtract( string $html ): bool {
		// Bandzoogle sites often have these characteristic identifiers
		return ( strpos( $html, 'class="month-name"' ) !== false && strpos( $html, '/go/calendar/' ) !== false )
			|| ( strpos( $html, 'class="event-title"' ) !== false && strpos( $html, 'class="event-notes"' ) !== false )
			|| strpos( $html, 'bandzoogle.com' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		$all_events  = array();
		$current_url = $source_url;
		$visited     = array();
		$page_count  = 1;

		// Start with the initial HTML provided
		$current_html = $html;

		while ( $page_count <= self::MAX_PAGES ) {
			$url_hash = md5( $current_url );
			if ( isset( $visited[ $url_hash ] ) ) {
				break;
			}
			$visited[ $url_hash ] = true;

			// 1. Get month context (Year/Month) for date resolution
			$context = $this->parseMonthContext( $current_html );
			if ( empty( $context ) ) {
				$context = $this->parseMonthContextFromUrl( $current_url );
			}

			// 2. Extract individual occurrence URLs (popups)
			$occurrence_urls = $this->extractOccurrenceUrls( $current_html, $current_url );

			foreach ( $occurrence_urls as $occurrence_url ) {
				$detail_html = $this->fetchHtml( $occurrence_url );
				if ( empty( $detail_html ) ) {
					continue;
				}

				$event = $this->parseOccurrenceHtml( $detail_html, $occurrence_url, $context );
				if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
					$all_events[] = $event;
				}
			}

			// 3. Find next month URL
			$next_url = $this->findNextMonthUrl( $current_html, $current_url );
			if ( empty( $next_url ) ) {
				break;
			}

			$current_url  = $next_url;
			$current_html = $this->fetchHtml( $current_url );
			if ( empty( $current_html ) ) {
				break;
			}

			++$page_count;
		}

		return $all_events;
	}

	public function getMethod(): string {
		return 'bandzoogle';
	}

	/**
	 * Parse month/year from the calendar header.
	 */
	private function parseMonthContext( string $html ): ?array {
		if ( ! preg_match( '#<span[^>]*class=["\']month-name["\'][^>]*>\s*([^<]+)\s*</span>#i', $html, $m ) ) {
			return null;
		}

		$label = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) );
		if ( ! preg_match( '/^([A-Za-z]+)\s+(\d{4})$/', $label, $mm ) ) {
			return null;
		}

		$month_num = (int) date( 'n', strtotime( $mm[1] . ' 1' ) );
		$year      = (int) $mm[2];

		return ( $month_num > 0 && $year > 0 ) ? array(
			'year'  => $year,
			'month' => $month_num,
		) : null;
	}

	/**
	 * Parse month/year from the URL if not found in HTML.
	 */
	private function parseMonthContextFromUrl( string $url ): ?array {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/go/calendar/\d+/(\d{4})/(\d{1,2})#', $path, $m ) ) {
			return array(
				'year'  => (int) $m[1],
				'month' => (int) $m[2],
			);
		}
		return null;
	}

	/**
	 * Extract event detail URLs from the calendar grid.
	 */
	private function extractOccurrenceUrls( string $html, string $current_url ): array {
		preg_match_all( '#href=["\'](/go/events/\d+\?[^"\']*occurrence_id=\d+[^"\']*)["\']#i', $html, $matches );

		$urls   = array();
		$parsed = parse_url( $current_url );
		$host   = $parsed['host'] ?? '';
		$scheme = $parsed['scheme'] ?? 'https';

		foreach ( ( $matches[1] ?? array() ) as $rel ) {
			$rel = html_entity_decode( $rel, ENT_QUOTES | ENT_HTML5 );
			if ( ! str_contains( $rel, 'popup=1' ) ) {
				$rel .= ( str_contains( $rel, '?' ) ? '&' : '?' ) . 'popup=1';
			}
			$urls[] = $scheme . '://' . $host . $rel;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Find the link to the next month's calendar.
	 */
	private function findNextMonthUrl( string $html, string $current_url ): string {
		if ( ! preg_match( '#<a[^>]*class=["\'][^"\']*\bnext\b[^"\']*["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m ) ) {
			return '';
		}

		$href = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		if ( empty( $href ) ) {
			return '';
		}

		if ( strpos( $href, '//' ) === 0 ) {
			return 'https:' . $href;
		}
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}

		$parsed = parse_url( $current_url );
		$host   = $parsed['host'] ?? '';
		$scheme = $parsed['scheme'] ?? 'https';

		if ( empty( $host ) ) {
			return '';
		}
		if ( '/' !== $href[0] ) {
			$href = '/' . $href;
		}

		return $scheme . '://' . $host . $href;
	}

	/**
	 * Parse the individual event popup HTML.
	 */
	private function parseOccurrenceHtml( string $html, string $occurrence_url, ?array $context ): array {
		$title       = '';
		$source_url  = $occurrence_url;
		$description = '';
		$image_url   = '';
		$date_text   = '';
		$time_text   = '';

		// Title and Source URL
		if ( preg_match( '#<h2[^>]*class=["\'][^"\']*\bevent-title\b[^"\']*["\'][^>]*>\s*<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m ) ) {
			$source_url = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
			$title      = trim( wp_strip_all_tags( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5 ) ) );
		} elseif ( preg_match( '#<h2[^>]*class=["\'][^"\']*\bevent-title\b[^"\']*["\'][^>]*>(.*?)</h2>#is', $html, $m ) ) {
			$title = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) ) );
		}

		// Date and Time
		if ( preg_match( '#<time[^>]*class=["\']from["\'][^>]*>.*?<span[^>]*class=["\'][^"\']*\bdate\b[^"\']*["\'][^>]*>(.*?)</span>\s*@\s*<span[^>]*class=["\'][^"\']*\btime\b[^"\']*["\'][^>]*>(.*?)</span>#is', $html, $m ) ) {
			$date_text = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) ) );
			$time_text = trim( wp_strip_all_tags( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5 ) ) );
		} else {
			if ( preg_match( '#<span[^>]*class=["\'][^"\']*\bdate\b[^"\']*["\'][^>]*>(.*?)</span>#is', $html, $m ) ) {
				$date_text = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) ) );
			}
			if ( preg_match( '#<span[^>]*class=["\'][^"\']*\btime\b[^"\']*["\'][^>]*>(.*?)</span>#is', $html, $m ) ) {
				$time_text = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) ) );
			}
		}

		// Description
		if ( preg_match( '#<div[^>]*class=["\'][^"\']*\bevent-notes\b[^"\']*["\'][^>]*>(.*?)</div>#is', $html, $m ) ) {
			$description = wp_kses_post( $m[1] );
		}

		// Image
		if ( preg_match( '#<div[^>]*class=["\'][^"\']*\bevent-image\b[^"\']*["\'][^>]*>.*?<img[^>]*src=["\']([^"\']+)["\']#is', $html, $m ) ) {
			$image_url = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
			if ( strpos( $image_url, '//' ) === 0 ) {
				$image_url = 'https:' . $image_url;
			}
		}

		$start_date = $this->resolveDate( $date_text, $context );
		$start_time = $this->parseTime( $time_text );

		return array(
			'title'       => sanitize_text_field( $title ),
			'description' => $description,
			'startDate'   => $start_date,
			'endDate'     => $start_date,
			'startTime'   => $start_time,
			'ticketUrl'   => esc_url_raw( $source_url ),
			'imageUrl'    => esc_url_raw( $image_url ),
			'eventType'   => 'Event',
		);
	}

	private function resolveDate( string $date_text, ?array $context ): string {
		if ( empty( $date_text ) || empty( $context ) ) {
			return '';
		}

		if ( ! preg_match( '/([A-Za-z]+)\s+(\d{1,2})$/', $date_text, $m ) ) {
			return '';
		}

		$month_name = $m[1];
		$day        = (int) $m[2];
		$month      = (int) date( 'n', strtotime( $month_name . ' 1' ) );
		if ( $month <= 0 || $day <= 0 ) {
			return '';
		}

		$year          = (int) $context['year'];
		$context_month = (int) $context['month'];

		// Handle year rollover (e.g. looking at January from December view or vice-versa)
		if ( 12 === $month && 1 === $context_month ) {
			$year -= 1;
		} elseif ( 1 === $month && 12 === $context_month ) {
			$year += 1;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	private function parseTime( string $time_text ): string {
		$ts = strtotime( trim( $time_text ) );
		return $ts ? date( 'H:i', $ts ) : '';
	}

	private function fetchHtml( string $url ): string {
		$result = HttpClient::get(
			$url,
			array(
				'timeout'      => 30,
				'browser_mode' => true,
				'context'      => 'Bandzoogle Extractor',
			)
		);
		return ( $result['success'] && 200 === $result['status_code'] ) ? $result['data'] : '';
	}
}
