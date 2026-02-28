<?php
/**
 * Filter Abilities
 *
 * Provides filter/taxonomy data via WordPress Abilities API.
 * Single source of truth for filter options with geo-filtering,
 * cross-filtering, and archive context support.
 *
 * Consumers: Filters REST controller, render.php (filter-bar visibility),
 * CLI, Chat, MCP — anything that needs to know "what filter options exist?"
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Blocks\Calendar\Taxonomy_Helper;
use DataMachineEvents\Blocks\Calendar\Geo_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/get-filter-options',
				array(
					'label'               => __( 'Get Filter Options', 'data-machine-events' ),
					'description'         => __( 'Get available taxonomy filter options with event counts, supporting geo-filtering, cross-filtering, and archive context', 'data-machine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'active_filters'   => array(
								'type'        => 'object',
								'description' => 'Active filter selections keyed by taxonomy slug [taxonomy => [term_ids]]',
							),
							'date_context'     => array(
								'type'       => 'object',
								'properties' => array(
									'date_start' => array( 'type' => 'string' ),
									'date_end'   => array( 'type' => 'string' ),
									'past'       => array( 'type' => 'string' ),
								),
							),
							'archive_taxonomy' => array(
								'type'        => 'string',
								'description' => 'Archive constraint taxonomy slug',
							),
							'archive_term_id'  => array(
								'type'        => 'integer',
								'description' => 'Archive constraint term ID',
							),
							'geo_lat'          => array(
								'type'        => 'number',
								'description' => 'Latitude for geo-filtering',
							),
							'geo_lng'          => array(
								'type'        => 'number',
								'description' => 'Longitude for geo-filtering',
							),
							'geo_radius'       => array(
								'type'        => 'number',
								'description' => 'Search radius (default: 25)',
							),
							'geo_radius_unit'  => array(
								'type'        => 'string',
								'description' => 'Radius unit: mi or km (default: mi)',
							),
							'context'          => array(
								'type'        => 'string',
								'description' => 'Filter context: modal, inline, badge (default: modal)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'taxonomies'      => array(
								'type'        => 'object',
								'description' => 'Taxonomy data with hierarchy and event counts, keyed by taxonomy slug',
							),
							'archive_context' => array(
								'type'       => 'object',
								'properties' => array(
									'taxonomy'  => array( 'type' => 'string' ),
									'term_id'   => array( 'type' => 'integer' ),
									'term_name' => array( 'type' => 'string' ),
								),
							),
							'geo_context'     => array(
								'type'       => 'object',
								'properties' => array(
									'active'       => array( 'type' => 'boolean' ),
									'venue_count'  => array( 'type' => 'integer' ),
									'lat'          => array( 'type' => 'number' ),
									'lng'          => array( 'type' => 'number' ),
									'radius'       => array( 'type' => 'number' ),
									'radius_unit'  => array( 'type' => 'string' ),
								),
							),
							'meta'            => array(
								'type'       => 'object',
								'properties' => array(
									'context'        => array( 'type' => 'string' ),
									'active_filters' => array( 'type' => 'object' ),
									'date_context'   => array( 'type' => 'object' ),
								),
							),
						),
					),
					'execute_callback'    => array( $this, 'executeGetFilterOptions' ),
					'permission_callback' => '__return_true',
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute get-filter-options ability
	 *
	 * Builds taxonomy filter options with event counts, applying:
	 * - Archive context constraint (taxonomy archive pages)
	 * - Geo constraint (nearby venues via haversine)
	 * - Cross-filtering (selecting one taxonomy recalculates others)
	 * - Date context (future/past/date range)
	 *
	 * @param array $input Input parameters.
	 * @return array Filter options data.
	 */
	public function executeGetFilterOptions( array $input ): array {
		$active_filters = is_array( $input['active_filters'] ?? null ) ? $input['active_filters'] : array();
		$context        = $input['context'] ?? 'modal';

		$date_context = array(
			'date_start' => $input['date_context']['date_start'] ?? '',
			'date_end'   => $input['date_context']['date_end'] ?? '',
			'past'       => $input['date_context']['past'] ?? '',
		);

		// Build archive constraint.
		$archive_taxonomy = sanitize_key( $input['archive_taxonomy'] ?? '' );
		$archive_term_id  = absint( $input['archive_term_id'] ?? 0 );

		$archive_context    = array();
		$tax_query_override = null;

		if ( $archive_taxonomy && $archive_term_id ) {
			$tax_query_override = array(
				array(
					'taxonomy' => $archive_taxonomy,
					'field'    => 'term_id',
					'terms'    => $archive_term_id,
				),
			);

			$term            = get_term( $archive_term_id, $archive_taxonomy );
			$archive_context = array(
				'taxonomy'  => $archive_taxonomy,
				'term_id'   => $archive_term_id,
				'term_name' => $term && ! is_wp_error( $term ) ? $term->name : '',
			);
		}

		// Build geo constraint.
		$geo_lat    = $input['geo_lat'] ?? '';
		$geo_lng    = $input['geo_lng'] ?? '';
		$geo_radius = $input['geo_radius'] ?? 25;
		$geo_unit   = $input['geo_radius_unit'] ?? 'mi';

		$geo_context = array(
			'active'      => false,
			'venue_count' => 0,
			'lat'         => 0,
			'lng'         => 0,
			'radius'      => (float) $geo_radius,
			'radius_unit' => $geo_unit,
		);

		if ( ! empty( $geo_lat ) && ! empty( $geo_lng ) ) {
			$geo_lat    = (float) $geo_lat;
			$geo_lng    = (float) $geo_lng;
			$geo_radius = (float) $geo_radius;

			if ( Geo_Query::validate_params( $geo_lat, $geo_lng, $geo_radius ) ) {
				$nearby_venue_ids = Geo_Query::get_venue_ids_within_radius( $geo_lat, $geo_lng, $geo_radius, $geo_unit );

				$venue_constraint = array(
					'taxonomy' => 'venue',
					'field'    => 'term_id',
					'terms'    => ! empty( $nearby_venue_ids ) ? $nearby_venue_ids : array( 0 ),
				);

				if ( is_array( $tax_query_override ) ) {
					$tax_query_override[] = $venue_constraint;
				} else {
					$tax_query_override = array( $venue_constraint );
				}

				$geo_context = array(
					'active'      => true,
					'venue_count' => count( $nearby_venue_ids ),
					'lat'         => $geo_lat,
					'lng'         => $geo_lng,
					'radius'      => $geo_radius,
					'radius_unit' => $geo_unit,
				);
			}
		}

		$taxonomies_data = Taxonomy_Helper::get_all_taxonomies_with_counts( $active_filters, $date_context, $tax_query_override );

		return array(
			'success'         => true,
			'taxonomies'      => $taxonomies_data,
			'archive_context' => $archive_context,
			'geo_context'     => $geo_context,
			'meta'            => array(
				'context'        => $context,
				'active_filters' => $active_filters,
				'date_context'   => $date_context,
			),
		);
	}
}
