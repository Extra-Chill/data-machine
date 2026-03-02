<?php
/**
 * Structured data processor.
 *
 * Handles common processing for structured event data from any extractor:
 * venue config override, engine data storage, and DataPacket creation.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StructuredDataProcessor {

	private EventImportHandler $handler;

	public function __construct( EventImportHandler $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Process structured events and return all eligible items as raw arrays.
	 *
	 * @param array            $events            Array of normalized event data from extractor
	 * @param string           $extraction_method Extraction method identifier
	 * @param string           $source_url        Source URL
	 * @param array            $config            Handler configuration
	 * @param ExecutionContext $context           Execution context
	 * @return array|null Array with 'items' key, or null if no eligible events
	 */
	public function process(
		array $events,
		string $extraction_method,
		string $source_url,
		array $config,
		ExecutionContext $context
	): ?array {
		$eligible_items = array();

		foreach ( $events as $raw_event ) {
			$event = $raw_event;

			if ( empty( $event['title'] ) ) {
				continue;
			}

			if ( $this->handler->shouldSkipEventTitle( $event['title'] ) ) {
				continue;
			}

			if ( ! empty( $event['startDate'] ) && $this->handler->isPastEvent( $event['startDate'] ) ) {
				continue;
			}

			$search_text = ( $event['title'] ?? '' ) . ' ' . ( $event['description'] ?? '' );
			if ( ! $this->handler->applyKeywordSearch( $search_text, $config['search'] ?? '' ) ) {
				continue;
			}
			if ( $this->handler->applyExcludeKeywords( $search_text, $config['exclude_keywords'] ?? '' ) ) {
				continue;
			}

			$event_identifier = \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
				$event['title'],
				$event['startDate'] ?? '',
				$event['venue'] ?? ''
			);

			$this->applyVenueConfigOverride( $event, $config );

			$venue_from_config = ! empty( $config['venue'] ) || ! empty( $config['venue_name'] );
			if ( ! $venue_from_config && empty( trim( (string) ( $event['venue'] ?? '' ) ) ) ) {
				$context->log(
					'warning',
					'Universal Web Scraper: Missing venue; configure venue override',
					array(
						'source_url'        => $source_url,
						'extraction_method' => $extraction_method,
						'title'             => $event['title'] ?? '',
						'startDate'         => $event['startDate'] ?? '',
					)
				);
			}

			$venue_metadata = $this->handler->extractVenueMetadata( $event );
			$engine_data    = EventEngineData::buildEngineData( $event, $venue_metadata );

			// Add scraper-specific fields to engine data.
			$scraper_fields = array_filter(
				array(
					'title'     => $event['title'] ?? '',
					'image_url' => $event['imageUrl'] ?? '',
				),
				static function ( $value ) {
					return '' !== $value && null !== $value;
				}
			);
			if ( ! empty( $scraper_fields ) ) {
				$engine_data = array_merge( $engine_data, $scraper_fields );
			}

			$this->handler->stripVenueMetadataFromEvent( $event );

			$eligible_items[] = array(
				'title'    => $event['title'],
				'content'  => wp_json_encode(
					array(
						'event'             => $event,
						'raw_source'        => $raw_event,
						'venue_metadata'    => $venue_metadata,
						'import_source'     => 'universal_web_scraper',
						'extraction_method' => $extraction_method,
					),
					JSON_PRETTY_PRINT
				),
				'metadata' => array(
					'source_type'       => 'universal_web_scraper',
					'extraction_method' => $extraction_method,
					'pipeline_id'       => $context->getPipelineId(),
					'flow_id'           => $context->getFlowId(),
					'original_title'    => $event['title'],
					'event_identifier'  => $event_identifier,
					'dedup_key'         => $event_identifier,
					'import_timestamp'  => time(),
					'_engine_data'      => $engine_data,
				),
			);
		}

		if ( empty( $eligible_items ) ) {
			return null;
		}

		return array( 'items' => $eligible_items );
	}

	/**
	 * Apply static venue config override from handler settings.
	 *
	 * @param array &$event Event data (modified in place)
	 * @param array $config Handler configuration
	 */
	private function applyVenueConfigOverride( array &$event, array $config ): void {
		if ( ! empty( $config['venue'] ) && is_numeric( $config['venue'] ) ) {
			$term = get_term( (int) $config['venue'], 'venue' );
			if ( $term && ! is_wp_error( $term ) ) {
				$event['venue'] = $term->name;
				$venue_meta     = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data( (int) $config['venue'] );
				$this->applyVenueMeta( $event, $venue_meta );
			}
		} elseif ( ! empty( $config['venue_name'] ) ) {
			$event['venue'] = sanitize_text_field( $config['venue_name'] );
			$this->applyVenueConfigFields( $event, $config );
		}
	}

	/**
	 * Apply venue metadata from taxonomy term.
	 */
	private function applyVenueMeta( array &$event, array $venue_meta ): void {
		$field_map = array(
			'address'     => 'venueAddress',
			'city'        => 'venueCity',
			'state'       => 'venueState',
			'zip'         => 'venueZip',
			'country'     => 'venueCountry',
			'phone'       => 'venuePhone',
			'website'     => 'venueWebsite',
			'coordinates' => 'venueCoordinates',
		);

		foreach ( $field_map as $meta_key => $event_key ) {
			if ( ! empty( $venue_meta[ $meta_key ] ) ) {
				$event[ $event_key ] = $venue_meta[ $meta_key ];
			}
		}
	}

	/**
	 * Apply venue fields from handler config.
	 */
	private function applyVenueConfigFields( array &$event, array $config ): void {
		$field_map = array(
			'venue_address' => 'venueAddress',
			'venue_city'    => 'venueCity',
			'venue_state'   => 'venueState',
			'venue_zip'     => 'venueZip',
			'venue_country' => 'venueCountry',
			'venue_phone'   => 'venuePhone',
			'venue_website' => 'venueWebsite',
		);

		foreach ( $field_map as $config_key => $event_key ) {
			if ( ! empty( $config[ $config_key ] ) ) {
				$value               = $config[ $config_key ];
				$event[ $event_key ] = 'venue_website' === $config_key
					? esc_url_raw( $value )
					: sanitize_text_field( $value );
			}
		}
	}


}
