<?php
/**
 * DoStuff Media API Extractor
 *
 * Parses DoStuff Media JSON feeds (Waterloo Records, Do512, etc.).
 * Extracts events from event_groups structure with complete venue metadata.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoStuffExtractor extends BaseExtractor {

	public function canExtract( string $content ): bool {
		$data = json_decode( $content, true );

		if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
			return false;
		}

		return isset( $data['event_groups'] ) && is_array( $data['event_groups'] );
	}

	public function extract( string $content, string $source_url ): array {
		$data = json_decode( $content, true );

		if ( ! is_array( $data ) || empty( $data['event_groups'] ) ) {
			return array();
		}

		$events = array();

		foreach ( $data['event_groups'] as $group ) {
			if ( ! empty( $group['events'] ) && is_array( $group['events'] ) ) {
				foreach ( $group['events'] as $event ) {
					$normalized = $this->normalizeEvent( $event, $source_url );

					if ( ! empty( $normalized['title'] ) ) {
						$events[] = $normalized;
					}
				}
			}
		}

		return $events;
	}

	public function getMethod(): string {
		return 'dostuff_media_api';
	}

	private function normalizeEvent( array $event, string $source_url ): array {
		$standardized_event = array(
			'title'            => sanitize_text_field( $event['title'] ?? '' ),
			'description'      => $this->cleanDescription( $event['description'] ?? '' ),
			'startDate'        => '',
			'endDate'          => '',
			'startTime'        => '',
			'endTime'          => '',
			'venue'            => '',
			'venueAddress'     => '',
			'venueCity'        => '',
			'venueState'       => '',
			'venueZip'         => '',
			'venueCountry'     => 'US',
			'venueCoordinates' => '',
			'ticketUrl'        => esc_url_raw( $event['buy_url'] ?? '' ),
			'image'            => '',
			'price'            => '',
			'source_url'       => '',
		);

		$this->parseDoStuffDateTime( $standardized_event, $event );
		$this->parseVenue( $standardized_event, $event );
		$this->parseImage( $standardized_event, $event );
		$this->parsePrice( $standardized_event, $event );
		$this->parseArtists( $standardized_event, $event );
		$this->parseSourceUrl( $standardized_event, $event );

		return $standardized_event;
	}

	private function parseDoStuffDateTime( array &$event, array $raw_event ): void {
		if ( ! empty( $raw_event['begin_time'] ) ) {
			$start_datetime = strtotime( $raw_event['begin_time'] );

			if ( $start_datetime ) {
				$event['startDate'] = date( 'Y-m-d', $start_datetime );
				$event['startTime'] = date( 'H:i', $start_datetime );
			}
		}

		if ( ! empty( $raw_event['end_time'] ) ) {
			$end_datetime = strtotime( $raw_event['end_time'] );

			if ( $end_datetime ) {
				$event['endDate'] = date( 'Y-m-d', $end_datetime );
				$event['endTime'] = date( 'H:i', $end_datetime );
			}
		}
	}

	private function parseVenue( array &$event, array $raw_event ): void {
		if ( ! empty( $raw_event['venue'] ) && is_array( $raw_event['venue'] ) ) {
			$venue = $raw_event['venue'];

			$event['venue']        = sanitize_text_field( $venue['title'] ?? '' );
			$event['venueAddress'] = sanitize_text_field( $venue['address'] ?? '' );
			$event['venueCity']    = sanitize_text_field( $venue['city'] ?? '' );
			$event['venueState']   = sanitize_text_field( $venue['state'] ?? '' );
			$event['venueZip']     = sanitize_text_field( $venue['zip'] ?? '' );

			if ( ! empty( $venue['latitude'] ) && ! empty( $venue['longitude'] ) ) {
				$event['venueCoordinates'] = $venue['latitude'] . ',' . $venue['longitude'];
			}
		}
	}

	private function parseImage( array &$event, array $raw_event ): void {
		if ( ! empty( $raw_event['imagery']['aws']['cover_image_h_630_w_1200'] ) ) {
			$event['image'] = esc_url_raw( $raw_event['imagery']['aws']['cover_image_h_630_w_1200'] );
		} elseif ( ! empty( $raw_event['imagery']['aws']['cover_image_w_1200_h_450'] ) ) {
			$event['image'] = esc_url_raw( $raw_event['imagery']['aws']['cover_image_w_1200_h_450'] );
		} elseif ( ! empty( $raw_event['imagery']['aws']['poster_w_800'] ) ) {
			$event['image'] = esc_url_raw( $raw_event['imagery']['aws']['poster_w_800'] );
		}
	}

	private function parsePrice( array &$event, array $raw_event ): void {
		if ( ! empty( $raw_event['is_free'] ) ) {
			$event['price'] = 'Free';
		}
	}

	private function parseArtists( array &$event, array $raw_event ): void {
		if ( ! empty( $raw_event['artists'] ) && is_array( $raw_event['artists'] ) ) {
			$event['artists'] = array_map(
				function ( $artist ) {
					return array(
						'title'       => sanitize_text_field( $artist['title'] ?? '' ),
						'description' => sanitize_textarea_field( $artist['description'] ?? '' ),
						'hometown'    => sanitize_text_field( $artist['hometown'] ?? '' ),
						'spotify_id'  => sanitize_text_field( $artist['spotify_id'] ?? '' ),
						'youtube_id'  => sanitize_text_field( $artist['youtube_id'] ?? '' ),
					);
				},
				$raw_event['artists']
			);
		}
	}

	private function parseSourceUrl( array &$event, array $raw_event ): void {
		if ( ! empty( $raw_event['permalink'] ) ) {
			$event['source_url'] = 'https://do512.com' . $raw_event['permalink'];
		}
	}

	private function cleanDescription( string $description ): string {
		$description = wp_kses_post( $description );
		$description = preg_replace( '/<!--.*?-->/s', '', $description );
		$description = preg_replace( '/\s+/', ' ', $description );

		return trim( $description );
	}
}
