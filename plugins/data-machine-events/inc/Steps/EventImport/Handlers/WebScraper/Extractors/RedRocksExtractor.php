<?php
/**
 * Red Rocks Amphitheatre extractor.
 *
 * Extracts event data from redrocksonline.com by parsing the event card HTML.
 * Red Rocks uses a custom Clique Studios WordPress build with consistent card patterns.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RedRocksExtractor extends BaseExtractor {

	private const VENUE_NAME    = 'Red Rocks Amphitheatre';
	private const VENUE_ADDRESS = '18300 W Alameda Pkwy';
	private const VENUE_CITY    = 'Morrison';
	private const VENUE_STATE   = 'CO';
	private const VENUE_ZIP     = '80465';
	private const VENUE_COUNTRY = 'US';

	public function canExtract( string $html ): bool {
		if ( strpos( $html, 'redrocksonline.com' ) === false ) {
			return false;
		}

		return strpos( $html, 'card-event' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath       = new \DOMXPath( $dom );
		$event_nodes = $xpath->query( "//*[contains(@class, 'card-event')]" );

		if ( $event_nodes->length === 0 ) {
			return array();
		}

		$current_year = $this->detectYear( $xpath );
		$events       = array();

		foreach ( $event_nodes as $event_node ) {
			if ( ! ( $event_node instanceof \DOMElement ) ) {
				continue;
			}

			$normalized = $this->normalizeEvent( $xpath, $event_node, $current_year, $source_url );
			if ( ! empty( $normalized['title'] ) ) {
				$events[] = $normalized;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'red_rocks';
	}

	private function detectYear( \DOMXPath $xpath ): int {
		$month_headers = $xpath->query( "//*[contains(@class, 'month-header')] | //h2[contains(@class, 'month')]" );

		foreach ( $month_headers as $header ) {
			$text = trim( $header->textContent );
			if ( preg_match( '/\b(20\d{2})\b/', $text, $matches ) ) {
				return (int) $matches[1];
			}
		}

		return (int) date( 'Y' );
	}

	private function normalizeEvent( \DOMXPath $xpath, \DOMElement $node, int $year, string $source_url ): array {
		$event = array(
			'title'        => $this->extractTitle( $xpath, $node ),
			'description'  => $this->extractDescription( $xpath, $node ),
			'venue'        => self::VENUE_NAME,
			'venueAddress' => self::VENUE_ADDRESS,
			'venueCity'    => self::VENUE_CITY,
			'venueState'   => self::VENUE_STATE,
			'venueZip'     => self::VENUE_ZIP,
			'venueCountry' => self::VENUE_COUNTRY,
		);

		$this->parseEventDateTime( $event, $xpath, $node, $year );
		$this->parseImage( $event, $xpath, $node );
		$this->parseTicketUrl( $event, $xpath, $node );
		$this->parseEventUrl( $event, $xpath, $node, $source_url );
		$this->parseCategory( $event, $node );

		return $event;
	}

	private function extractTitle( \DOMXPath $xpath, \DOMElement $node ): string {
		$title_node = $xpath->query( ".//*[contains(@class, 'card-title')]", $node )->item( 0 );
		if ( $title_node ) {
			return $this->sanitizeText( $title_node->textContent );
		}

		return '';
	}

	private function extractDescription( \DOMXPath $xpath, \DOMElement $node ): string {
		$selectors = array(
			".//*[contains(@class, 'hide-mobile')]//p",
			".//*[contains(@class, 'card-text')]",
			".//p[contains(@class, 'supporting')]",
		);

		foreach ( $selectors as $selector ) {
			$desc_node = $xpath->query( $selector, $node )->item( 0 );
			if ( $desc_node ) {
				$text = $this->sanitizeText( $desc_node->textContent );
				if ( ! empty( $text ) ) {
					return $text;
				}
			}
		}

		return '';
	}

	/**
	 * Parse date and time from card element.
	 *
	 * Red Rocks displays dates like "Wed, Apr 15, 7:30 pm" in the .date element.
	 */
	private function parseEventDateTime( array &$event, \DOMXPath $xpath, \DOMElement $node, int $year ): void {
		$date_node = $xpath->query( ".//*[contains(@class, 'date')]", $node )->item( 0 );
		if ( ! $date_node ) {
			return;
		}

		$date_text = trim( $date_node->textContent );

		// Pattern: "Wed, Apr 15, 7:30 pm" or "Wed, Apr 15"
		if ( preg_match( '/(\w+),?\s*(\w+)\s+(\d{1,2})(?:,?\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)))?/i', $date_text, $matches ) ) {
			$month = $matches[2];
			$day   = $matches[3];
			$time  = $matches[4] ?? '';

			$date_string = "{$month} {$day}, {$year}";
			$timestamp   = strtotime( $date_string );

			if ( false !== $timestamp ) {
				if ( $timestamp < strtotime( '-1 day' ) ) {
					$timestamp = strtotime( "{$month} {$day}, " . ( $year + 1 ) );
				}
				$event['startDate'] = date( 'Y-m-d', $timestamp );
			}

			if ( ! empty( $time ) ) {
				$event['startTime'] = $this->normalizeTime( $time );
			}
		}
	}

	private function normalizeTime( string $time ): string {
		$time = strtolower( trim( $time ) );

		if ( strpos( $time, ':' ) === false ) {
			$time = preg_replace( '/(\d+)\s*(am|pm)/i', '$1:00 $2', $time );
		}

		$timestamp = strtotime( $time );
		if ( false !== $timestamp ) {
			return date( 'H:i', $timestamp );
		}

		return '';
	}

	private function parseImage( array &$event, \DOMXPath $xpath, \DOMElement $node ): void {
		$img_node = $xpath->query( './/img[@data-image]', $node )->item( 0 );
		if ( $img_node && $img_node instanceof \DOMElement ) {
			$image_url = $img_node->getAttribute( 'data-image' );
			if ( ! empty( $image_url ) ) {
				$event['imageUrl'] = esc_url_raw( $image_url );
				return;
			}
		}

		$img_node = $xpath->query( './/img[@src]', $node )->item( 0 );
		if ( $img_node && $img_node instanceof \DOMElement ) {
			$src = $img_node->getAttribute( 'src' );
			if ( ! empty( $src ) && strpos( $src, 'data:' ) !== 0 ) {
				$event['imageUrl'] = esc_url_raw( $src );
			}
		}
	}

	private function parseTicketUrl( array &$event, \DOMXPath $xpath, \DOMElement $node ): void {
		$selectors = array(
			".//a[contains(@class, 'btn-white')]",
			".//a[contains(@href, 'axs.com')]",
			".//a[contains(@href, 'ticket')]",
			".//a[contains(text(), 'Ticket')]",
		);

		foreach ( $selectors as $selector ) {
			$link_node = $xpath->query( $selector, $node )->item( 0 );
			if ( $link_node && $link_node instanceof \DOMElement && $link_node->hasAttribute( 'href' ) ) {
				$href = $link_node->getAttribute( 'href' );
				if ( ! empty( $href ) && '#' !== $href ) {
					$event['ticketUrl'] = esc_url_raw( $href );
					return;
				}
			}
		}
	}

	private function parseEventUrl( array &$event, \DOMXPath $xpath, \DOMElement $node, string $source_url ): void {
		$link_node = $xpath->query( ".//a[contains(@class, 'card-link')] | .//a[contains(@href, '/event/')]", $node )->item( 0 );
		if ( $link_node && $link_node instanceof \DOMElement && $link_node->hasAttribute( 'href' ) ) {
			$href = $link_node->getAttribute( 'href' );
			if ( ! empty( $href ) && '#' !== $href ) {
				if ( strpos( $href, 'http' ) !== 0 ) {
					$parsed = parse_url( $source_url );
					$base   = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? 'www.redrocksonline.com' );
					$href   = $base . '/' . ltrim( $href, '/' );
				}
				$event['eventUrl'] = esc_url_raw( $href );
			}
		}
	}

	private function parseCategory( array &$event, \DOMElement $node ): void {
		if ( $node->hasAttribute( 'data-category' ) ) {
			$category_id       = $node->getAttribute( 'data-category' );
			$category_map      = array(
				'4' => 'Concert',
				'5' => 'Film',
				'6' => 'Fitness',
				'7' => 'Other',
			);
			$event['category'] = $category_map[ $category_id ] ?? '';
		}
	}
}
