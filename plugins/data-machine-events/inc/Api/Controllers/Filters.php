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
	 * Get filter options with real-time cross-filtering and archive context support
	 *
	 * @param WP_REST_Request $request Request object with optional active filters, date context, and archive context.
	 * @return \WP_REST_Response
	 */
	public function get( WP_REST_Request $request ) {
		$active_filters = $request->get_param( 'active' ) ?? array();
		$context        = $request->get_param( 'context' ) ?? 'modal';

		$date_context = array(
			'date_start' => $request->get_param( 'date_start' ) ?? '',
			'date_end'   => $request->get_param( 'date_end' ) ?? '',
			'past'       => $request->get_param( 'past' ) ?? '',
		);

		$archive_taxonomy = sanitize_key( $request->get_param( 'archive_taxonomy' ) ?? '' );
		$archive_term_id  = absint( $request->get_param( 'archive_term_id' ) ?? 0 );

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

		$taxonomies_data = Taxonomy_Helper::get_all_taxonomies_with_counts( $active_filters, $date_context, $tax_query_override );

		return rest_ensure_response(
			array(
				'success'         => true,
				'taxonomies'      => $taxonomies_data,
				'archive_context' => $archive_context,
				'meta'            => array(
					'context'        => $context,
					'active_filters' => $active_filters,
					'date_context'   => $date_context,
				),
			)
		);
	}
}
