<?php
/**
 * OpenDate extractor.
 *
 * Extracts event data from OpenDate.io calendar pages by parsing listing HTML
 * to find event detail URLs, then fetching each detail page to extract complete
 * Schema.org JSON-LD event data including full venue address and coordinates.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenDateExtractor extends BaseExtractor {

	public function canExtract( string $html ): bool {
		return strpos( $html, 'confirm-card' ) !== false
			|| strpos( $html, 'ODEmbed' ) !== false;
	}

	public function extract( string $html, string $source_url ): array {
		$event_urls = $this->parseListingPage( $html );
		if ( empty( $event_urls ) ) {
			return array();
		}

		$events = array();
		foreach ( $event_urls as $event_url ) {
			$detail_html = $this->fetchDetailPage( $event_url );
			if ( empty( $detail_html ) ) {
				continue;
			}

			$jsonld = $this->extractJsonLd( $detail_html );
			if ( empty( $jsonld ) ) {
				continue;
			}

			$react_json  = $this->extractReactJson( $detail_html );
			$coordinates = $this->extractCoordinates( $detail_html );
			$event       = $this->normalizeEvent( $jsonld, $react_json, $coordinates, $event_url );

			if ( ! empty( $event['title'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'opendate';
	}

	/**
	 * Parse listing page HTML to extract event detail page URLs.
	 *
	 * @param string $html Listing page HTML
	 * @return array Event detail page URLs
	 */
	private function parseListingPage( string $html ): array {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<meta charset="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );
		$urls  = array();

		$cards = $xpath->query( '//*[contains(@class, "confirm-card")]//a[contains(@class, "stretched-link")]' );
		if ( false === $cards ) {
			return array();
		}

		foreach ( $cards as $card ) {
			if ( ! ( $card instanceof \DOMElement ) ) {
				continue;
			}

			$href = $card->getAttribute( 'href' );
			if ( empty( $href ) ) {
				continue;
			}

			if ( strpos( $href, 'http' ) !== 0 ) {
				$href = 'https://app.opendate.io' . $href;
			}

			$urls[] = $href;
		}

		return $urls;
	}

	/**
	 * Fetch event detail page HTML.
	 *
	 * @param string $url Detail page URL
	 * @return string|null HTML content or null on failure
	 */
	private function fetchDetailPage( string $url ): ?string {
		$result = HttpClient::get(
			$url,
			array(
				'timeout'      => 30,
				'browser_mode' => true,
				'headers'      => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.5',
				),
				'context'      => 'OpenDate Extractor',
			)
		);

		if ( ! $result['success'] || empty( $result['data'] ) ) {
			return null;
		}

		return $result['data'];
	}

	/**
	 * Extract JSON-LD Event data from detail page HTML.
	 *
	 * @param string $html Detail page HTML
	 * @return array|null Parsed JSON-LD Event data or null
	 */
	private function extractJsonLd( string $html ): ?array {
		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return null;
		}

		foreach ( $matches[1] as $json_content ) {
			$data = json_decode( trim( $json_content ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || empty( $data ) ) {
				continue;
			}

			if ( isset( $data['@type'] ) && 'Event' === $data['@type'] ) {
				return $data;
			}
		}

		return null;
	}

	/**
	 * Extract React component JSON with accurate datetime from detail page.
	 *
	 * OpenDate embeds a React component with ISO 8601 datetime values that
	 * include time and timezone, unlike the JSON-LD which only has dates.
	 *
	 * @param string $html Detail page HTML
	 * @return array|null Parsed React JSON confirm data or null
	 */
	private function extractReactJson( string $html ): ?array {
		if ( ! preg_match( '/<script[^>]*class=["\']js-react-on-rails-component["\'][^>]*data-component-name=["\']AddConfirmToCalendar["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return null;
		}

		$data = json_decode( trim( $matches[1] ), true );
		if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['confirm'] ) ) {
			return null;
		}

		return $data['confirm'];
	}

	/**
	 * Extract coordinates from Google Static Maps URL in HTML.
	 *
	 * @param string $html Detail page HTML
	 * @return string|null Coordinates as "lat,lng" or null
	 */
	private function extractCoordinates( string $html ): ?string {
		if ( preg_match( '/maps\.googleapis\.com\/maps\/api\/staticmap\?center=([0-9.-]+),([0-9.-]+)/', $html, $matches ) ) {
			return $matches[1] . ',' . $matches[2];
		}

		return null;
	}

	/**
	 * Normalize JSON-LD Event to standard format.
	 *
	 * @param array $jsonld JSON-LD Event data
	 * @param array|null $react_json React component confirm data with accurate times
	 * @param string|null $coordinates Extracted coordinates
	 * @param string $source_url Event detail page URL
	 * @return array Normalized event data
	 */
	private function normalizeEvent( array $jsonld, ?array $react_json, ?string $coordinates, string $source_url ): array {
		$event = array(
			'title'       => $jsonld['name'] ?? '',
			'description' => $jsonld['description'] ?? '',
		);

		$this->parseDates( $event, $jsonld, $react_json );
		$this->parseLocation( $event, $jsonld, $coordinates );
		$this->parseOffers( $event, $jsonld, $source_url );
		$this->parsePerformers( $event, $jsonld );
		$this->parseImage( $event, $jsonld );

		return $event;
	}

	/**
	 * Parse date/time from React JSON (preferred) or JSON-LD (fallback).
	 *
	 * React JSON contains accurate ISO 8601 datetime with timezone.
	 * JSON-LD only contains date without time on OpenDate pages.
	 */
	private function parseDates( array &$event, array $jsonld, ?array $react_json ): void {
		if ( ! empty( $react_json['start_time'] ) ) {
			$parsed = $this->parseDatetime( $react_json['start_time'] );
			if ( ! empty( $parsed['date'] ) ) {
				$event['startDate'] = $parsed['date'];
				$event['startTime'] = $parsed['time'];
			}
		}

		if ( empty( $event['startDate'] ) && ! empty( $jsonld['startDate'] ) ) {
			$parsed             = $this->parseDatetime( $jsonld['startDate'] );
			$event['startDate'] = $parsed['date'];
			$event['startTime'] = '00:00' !== $parsed['time'] ? $parsed['time'] : '';
		}

		if ( ! empty( $react_json['end_time_for_calendar'] ) ) {
			$parsed = $this->parseDatetime( $react_json['end_time_for_calendar'] );
			if ( ! empty( $parsed['date'] ) ) {
				$event['endDate'] = $parsed['date'];
				$event['endTime'] = $parsed['time'];
			}
		}

		if ( empty( $event['endDate'] ) && ! empty( $jsonld['endDate'] ) ) {
			$parsed           = $this->parseDatetime( $jsonld['endDate'] );
			$event['endDate'] = $parsed['date'];
			$event['endTime'] = '00:00' !== $parsed['time'] ? $parsed['time'] : '';
		}
	}

	/**
	 * Parse location from JSON-LD event.
	 */
	private function parseLocation( array &$event, array $jsonld, ?string $coordinates ): void {
		if ( empty( $jsonld['location'] ) ) {
			return;
		}

		$location       = $jsonld['location'];
		$event['venue'] = $location['name'] ?? '';

		if ( ! empty( $location['address'] ) ) {
			$address               = $location['address'];
			$event['venueAddress'] = $address['streetAddress'] ?? '';
			$event['venueCity']    = $address['addressLocality'] ?? '';
			$event['venueState']   = $address['addressRegion'] ?? '';
			$event['venueZip']     = $address['postalCode'] ?? '';
			$event['venueCountry'] = $address['addressCountry'] ?? '';
		}

		if ( ! empty( $location['geo'] ) ) {
			$geo = $location['geo'];
			$lat = $geo['latitude'] ?? '';
			$lng = $geo['longitude'] ?? '';
			if ( $lat && $lng ) {
				$event['venueCoordinates'] = $lat . ',' . $lng;
			}
		} elseif ( $coordinates ) {
			$event['venueCoordinates'] = $coordinates;
		}
	}

	/**
	 * Parse offers/pricing from JSON-LD event.
	 */
	private function parseOffers( array &$event, array $jsonld, string $source_url ): void {
		if ( empty( $jsonld['offers'] ) ) {
			$event['ticketUrl'] = $source_url;
			return;
		}

		$offers = $jsonld['offers'];
		if ( is_array( $offers ) && isset( $offers[0] ) ) {
			$offers = $offers[0];
		}

		$event['price']     = $offers['price'] ?? '';
		$event['ticketUrl'] = $offers['url'] ?? $source_url;
	}

	/**
	 * Parse performers from JSON-LD event.
	 */
	private function parsePerformers( array &$event, array $jsonld ): void {
		if ( empty( $jsonld['performer'] ) ) {
			return;
		}

		$performers = $jsonld['performer'];
		if ( ! is_array( $performers ) ) {
			$event['performer'] = $performers;
			return;
		}

		if ( isset( $performers['name'] ) ) {
			$event['performer'] = $performers['name'];
			return;
		}

		$names = array();
		foreach ( $performers as $performer ) {
			if ( isset( $performer['name'] ) ) {
				$names[] = $performer['name'];
			}
		}

		if ( ! empty( $names ) ) {
			$event['performer'] = implode( ', ', $names );
		}
	}

	/**
	 * Parse image from JSON-LD event.
	 */
	private function parseImage( array &$event, array $jsonld ): void {
		if ( empty( $jsonld['image'] ) ) {
			return;
		}

		$image = $jsonld['image'];
		if ( is_array( $image ) ) {
			$event['imageUrl'] = $image[0] ?? '';
		} else {
			$event['imageUrl'] = $image;
		}
	}
}
