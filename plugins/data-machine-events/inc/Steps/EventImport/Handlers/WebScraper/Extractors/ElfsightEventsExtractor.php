<?php
/**
 * Elfsight Events Calendar extractor.
 *
 * Extracts event data from pages with Elfsight Events Calendar widgets by
 * detecting the widget embed, extracting the widget ID, and fetching event data
 * from the Elfsight API.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElfsightEventsExtractor extends BaseExtractor {

	private const API_BASE_URL = 'https://shy.elfsight.com/p/boot/';

	public function canExtract( string $html ): bool {
		return preg_match( '/elfsight-sapp-[a-f0-9-]{36}/i', $html ) === 1;
	}

	public function extract( string $html, string $source_url ): array {
		$widget_id = $this->extractWidgetId( $html );

		if ( empty( $widget_id ) ) {
			return array();
		}

		$widget_data = $this->fetchElfsightData( $widget_id, $html );

		if ( empty( $widget_data ) ) {
			return array();
		}

		$events    = $widget_data['events'] ?? array();
		$locations = $widget_data['locations'] ?? array();

		if ( empty( $events ) ) {
			return array();
		}

		$page_venue = PageVenueExtractor::extract( $html, $source_url );
		$timezone   = $page_venue['venueTimezone'] ? $page_venue['venueTimezone'] : 'America/Chicago';

		$normalized_events = array();
		foreach ( $events as $event ) {
			$normalized = $this->normalizeEvent( $event, $locations, $page_venue, $timezone );
			if ( ! empty( $normalized['title'] ) ) {
				$normalized_events[] = $normalized;
			}
		}

		return $normalized_events;
	}

	public function getMethod(): string {
		return 'elfsight_events';
	}

	/**
	 * Extract widget ID from elfsight-sapp-{uuid} class name.
	 */
	private function extractWidgetId( string $html ): ?string {
		if ( preg_match( '/elfsight-sapp-([a-f0-9-]{36})/i', $html, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Fetch widget data from Elfsight API.
	 */
	private function fetchElfsightData( string $widget_id, string $html ): ?array {
		$shop_domain = $this->extractShopifyDomain( $html );

		$params = array(
			'callback' => 'jsonp',
			'w'        => $widget_id,
		);

		if ( $shop_domain ) {
			$params['shop'] = $shop_domain;
		}

		$url = self::API_BASE_URL . '?' . http_build_query( $params );

		$result = HttpClient::get(
			$url,
			array(
				'timeout'      => 30,
				'browser_mode' => false,
				'context'      => 'Elfsight Events Extractor',
			)
		);

		if ( ! $result['success'] || empty( $result['data'] ) ) {
			return null;
		}

		return $this->parseJsonpResponse( $result['data'], $widget_id );
	}

	/**
	 * Parse JSONP response to extract widget settings.
	 */
	private function parseJsonpResponse( string $response, string $widget_id ): ?array {
		$json = preg_replace( '/^[^(]+\(|\);\s*$/', '', $response );

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			return null;
		}

		$widget_data = $data['data']['widgets'][ $widget_id ]['data']['settings'] ?? null;

		if ( ! is_array( $widget_data ) ) {
			return null;
		}

		return $widget_data;
	}

	/**
	 * Extract Shopify domain from page HTML.
	 */
	private function extractShopifyDomain( string $html ): ?string {
		if ( preg_match( '/Shopify\.shop\s*=\s*["\']([^"\']+)["\']/', $html, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Normalize Elfsight event to standard format.
	 */
	private function normalizeEvent( array $event, array $locations, array $page_venue, string $timezone ): array {
		$start_parsed = $this->parseUtcTimestamp( $event['start'] ?? 0, $timezone );
		$end_parsed   = $this->parseUtcTimestamp( $event['end'] ?? 0, $timezone );

		$location_data = $this->resolveLocation( $event['location'] ?? '', $locations );

		$normalized = array(
			'title'         => $this->sanitizeText( $event['name'] ?? '' ),
			'description'   => $this->cleanHtml( $event['description'] ?? '' ),
			'startDate'     => $start_parsed['date'],
			'endDate'       => $end_parsed['date'],
			'startTime'     => $start_parsed['time'],
			'endTime'       => $end_parsed['time'],
			'venue'         => '',
			'venueAddress'  => '',
			'venueCity'     => '',
			'venueState'    => '',
			'venueZip'      => '',
			'venueCountry'  => 'US',
			'venueTimezone' => $timezone,
			'image'         => esc_url_raw( $event['media'] ?? '' ),
			'ticketUrl'     => esc_url_raw( $event['buttonLink'] ?? '' ),
			'source_url'    => esc_url_raw( $event['buttonLink'] ?? '' ),
		);

		if ( ! empty( $location_data['name'] ) ) {
			$normalized['venue']        = $this->sanitizeText( $location_data['name'] );
			$normalized['venueAddress'] = $this->sanitizeText( $location_data['address'] ?? '' );
		} else {
			$normalized['venue']        = $page_venue['venue'] ?? '';
			$normalized['venueAddress'] = $page_venue['venueAddress'] ?? '';
			$normalized['venueCity']    = $page_venue['venueCity'] ?? '';
			$normalized['venueState']   = $page_venue['venueState'] ?? '';
			$normalized['venueZip']     = $page_venue['venueZip'] ?? '';
			$normalized['venueCountry'] = $page_venue['venueCountry'] ?? 'US';
		}

		return $normalized;
	}

	/**
	 * Resolve location ID to location data.
	 */
	private function resolveLocation( string $location_id, array $locations ): array {
		if ( empty( $location_id ) || empty( $locations ) ) {
			return array();
		}

		foreach ( $locations as $location ) {
			if ( ( $location['id'] ?? '' ) === $location_id ) {
				return array(
					'name'    => $location['name'] ?? '',
					'address' => $location['address'] ?? '',
				);
			}
		}

		return array();
	}
}
