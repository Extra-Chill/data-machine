<?php
/**
 * Filters API controller for centralized taxonomy filter options
 *
 * @package DataMachineEvents\Api\Controllers
 */

namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DataMachineEvents\Blocks\Calendar\Taxonomy_Helper;

/**
 * REST controller for filter options endpoint
 */
class Filters {

	/**
	 * Get filter options with taxonomy dependencies and contextual date filtering
	 *
	 * @param WP_REST_Request $request Request object with optional active filters, context, and date params.
	 * @return \WP_REST_Response
	 */
	public function get( WP_REST_Request $request ) {
		$active_filters = $request->get_param( 'active' ) ?? [];
		$context        = $request->get_param( 'context' ) ?? 'modal';

		$date_context = [
			'date_start' => $request->get_param( 'date_start' ) ?? '',
			'date_end'   => $request->get_param( 'date_end' ) ?? '',
			'past'       => $request->get_param( 'past' ) ?? '',
		];

		$dependencies = apply_filters( 'datamachine_events_taxonomy_dependencies', [] );

		$taxonomies_data = Taxonomy_Helper::get_all_taxonomies_with_counts( $active_filters, $dependencies, $date_context );

		$filtered_taxonomies = [];
		foreach ( $taxonomies_data as $taxonomy_slug => $taxonomy_info ) {
			$is_filtered = false;

			if ( isset( $dependencies[ $taxonomy_slug ] ) ) {
				$parent_taxonomy = $dependencies[ $taxonomy_slug ];
				if ( ! empty( $active_filters[ $parent_taxonomy ] ) ) {
					$is_filtered = true;
				}
			}

			$filtered_taxonomies[ $taxonomy_slug ] = array_merge(
				$taxonomy_info,
				[ 'filtered' => $is_filtered ]
			);
		}

		return rest_ensure_response(
			[
				'success'      => true,
				'taxonomies'   => $filtered_taxonomies,
				'dependencies' => $dependencies,
				'meta'         => [
					'context'        => $context,
					'active_filters' => $active_filters,
					'date_context'   => $date_context,
				],
			]
		);
	}
}
