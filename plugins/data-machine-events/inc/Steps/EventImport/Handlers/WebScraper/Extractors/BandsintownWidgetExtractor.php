<?php
/**
 * Read tour dates published through an artist site's Bandsintown widget.
 *
 * @package DataMachineEvents
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Extract events using the artist's own widget configuration. */
class BandsintownWidgetExtractor extends BaseExtractor {

	/**
	 * Detect a configured widget.
	 *
	 * @param string $html Page HTML.
	 * @return bool Whether a usable initializer exists.
	 */
	public function canExtract( string $html ): bool {
		return ! empty( $this->findWidget( $html ) );
	}

	/**
	 * Get the extraction method identifier.
	 *
	 * @return string Method identifier.
	 */
	public function getMethod(): string {
		return 'bandsintown_widget';
	}

	/**
	 * Match attributes on the initializer itself.
	 *
	 * Site builders embed the widget three ways: as raw markup, as
	 * HTML-escaped text in a code/embed block, or inside an iframe `srcdoc`
	 * document (GoDaddy Website Builder). Each nested layer is searched with
	 * a bounded depth.
	 *
	 * @param string $html  Page or Wix HtmlComponent HTML.
	 * @param int    $depth Nesting depth for srcdoc/escaped layers.
	 * @return array Widget attributes, or an empty array.
	 */
	private function findWidget( string $html, int $depth = 0 ): array {
		if ( $depth > 2 || false === stripos( $html, 'bit-widget-initializer' ) ) {
			return array();
		}

		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( $tags->has_class( 'bit-widget-initializer' ) ) {
				$artist = $tags->get_attribute( 'data-artist-name' );
				if ( is_string( $artist ) && '' !== trim( $artist ) ) {
					$app_id = $tags->get_attribute( 'data-app-id' );
					return array(
						'artist' => trim( $artist ),
						'app_id' => is_string( $app_id ) ? trim( $app_id ) : '',
					);
				}
			}

			$srcdoc = $tags->get_attribute( 'srcdoc' );
			if ( is_string( $srcdoc ) && '' !== $srcdoc ) {
				$widget = $this->findWidget( $srcdoc, $depth + 1 );
				if ( ! empty( $widget ) ) {
					return $widget;
				}
			}
		}

		$decoded = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return $decoded !== $html ? $this->findWidget( $decoded, $depth + 1 ) : array();
	}

	/**
	 * Fetch events using the widget app id or its page-host fallback.
	 *
	 * @param string $html       Page HTML.
	 * @param string $source_url Original artist page URL.
	 * @return array Normalized events.
	 */
	public function extract( string $html, string $source_url ): array {
		$widget = $this->findWidget( $html );
		if ( empty( $widget ) ) {
			return array();
		}

		$app_id = $widget['app_id'];
		if ( '' === $app_id ) {
			$host = wp_parse_url( $source_url, PHP_URL_HOST );
			if ( empty( $host ) ) {
				return array();
			}
			$app_id = 'js_' . $host;
		}

		$url    = 'https://rest.bandsintown.com/V4/artists/' . rawurlencode( $widget['artist'] ) . '/events/?app_id=' . rawurlencode( $app_id );
		$result = HttpClient::get(
			$url,
			array(
				'timeout' => 20,
				'context' => 'Bandsintown Widget Extractor — events API',
			)
		);
		// HttpClient logs transport and HTTP failures with the context above.
		if ( empty( $result['success'] ) || 200 !== ( $result['status_code'] ?? 0 ) ) {
			return array();
		}

		$records = json_decode( $result['data'] ?? '', true );
		if ( ! is_array( $records ) || array_values( $records ) !== $records ) {
			return array();
		}

		$events = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['id'] ) ) {
				continue;
			}
			$event = $this->normalizeEvent( $record, $widget['artist'] );
			if ( '' !== $event['title'] && '' !== $event['startDate'] ) {
				$events[] = $event;
			}
		}
		return $events;
	}

	/**
	 * Normalize local wall-clock dates without timezone conversion.
	 *
	 * @param array  $record API event.
	 * @param string $artist Widget artist name or id.
	 * @return array Standard scraper event packet.
	 */
	private function normalizeEvent( array $record, string $artist ): array {
		$lineup = array_values( array_filter( $record['lineup'] ?? array(), 'is_string' ) );
		$title  = trim( (string) ( $record['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = $record['artist']['name'] ?? $lineup[0] ?? ( preg_match( '/^id_\d+$/', $artist ) ? '' : $artist );
		}
		$start = $this->parseIsoDatetime( $record['starts_at'] ?? $record['datetime'] ?? '' );
		$end   = $this->parseIsoDatetime( $record['ends_at'] ?? '' );
		$event = array(
			'title'      => $this->sanitizeText( $title ),
			'startDate'  => $start['date'],
			'startTime'  => $start['time'],
			'endDate'    => $end['date'],
			'endTime'    => $end['time'],
			'sourceId'   => (string) $record['id'],
			'source_url' => 'https://www.bandsintown.com/e/' . rawurlencode( (string) $record['id'] ),
			'ticketUrl'  => esc_url_raw( $record['offers'][0]['url'] ?? $record['url'] ?? '' ),
		);
		$venue = $record['venue'] ?? array();
		foreach ( array(
			'name'           => 'venue',
			'street_address' => 'venueAddress',
			'city'           => 'venueCity',
			'region'         => 'venueState',
			'postal_code'    => 'venueZip',
			'country'        => 'venueCountry',
		) as $key => $field ) {
			$event[ $field ] = rtrim( $this->sanitizeText( (string) ( $venue[ $key ] ?? '' ) ), ', ' );
		}
		if ( isset( $venue['latitude'], $venue['longitude'] ) && is_numeric( $venue['latitude'] ) && is_numeric( $venue['longitude'] ) ) {
			$event['venueCoordinates'] = $venue['latitude'] . ',' . $venue['longitude'];
		}
		$event['artists'] = array_map(
			fn ( string $name ): array => array( 'title' => $this->sanitizeText( $name ) ),
			$lineup
		);
		return $event;
	}
}
