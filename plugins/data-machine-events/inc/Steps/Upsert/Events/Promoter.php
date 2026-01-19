<?php
/**
 * Data Machine Events Promoter Handler
 *
 * Centralized promoter taxonomy handling for Data Machine Events.
 * Maps to Schema.org "organizer" property for structured data output.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachineEvents\Core\Promoter_Taxonomy;
use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Promoter {

	/**
	 * Assign pre-existing promoter to event
	 *
	 * @param int $post_id Event post ID for promoter assignment
	 * @param array $settings Handler settings containing 'promoter' term_id
	 * @return array Assignment result with success status and error details
	 */
	public static function assign_promoter_to_event( $post_id, $settings = array() ) {
		if ( ! $post_id ) {
			return array(
				'success' => false,
				'error'   => 'Post ID is required',
			);
		}

		$promoter_term_id = $settings['promoter'] ?? null;

		if ( ! $promoter_term_id && ! empty( $settings['promoter_data'] ) ) {
			$promoter_name = $settings['promoter_data']['name'] ?? '';
			if ( ! empty( $promoter_name ) ) {
				$result = Promoter_Taxonomy::find_or_create_promoter( $promoter_name, $settings['promoter_data'] );
				if ( ! empty( $result['term_id'] ) ) {
					$promoter_term_id = $result['term_id'];
				}
			}
		}

		if ( ! $promoter_term_id ) {
			do_action(
				'datamachine_log',
				'debug',
				'No promoter term_id in settings, skipping promoter assignment',
				array(
					'post_id' => $post_id,
				)
			);
			return array(
				'success' => true,
				'error'   => null,
				'skipped' => true,
			);
		}

		if ( ! term_exists( $promoter_term_id, 'promoter' ) ) {
			$error_msg = 'Promoter term does not exist';
			do_action(
				'datamachine_log',
				'error',
				$error_msg,
				array(
					'post_id'          => $post_id,
					'promoter_term_id' => $promoter_term_id,
				)
			);

			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$assignment_result = wp_set_post_terms( $post_id, array( (int) $promoter_term_id ), 'promoter' );

		if ( is_wp_error( $assignment_result ) ) {
			$error_msg = 'Promoter assignment failed: ' . $assignment_result->get_error_message();
			do_action(
				'datamachine_log',
				'error',
				$error_msg,
				array(
					'post_id'          => $post_id,
					'promoter_term_id' => $promoter_term_id,
					'wp_error'         => $assignment_result->get_error_message(),
				)
			);

			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		do_action(
			'datamachine_log',
			'debug',
			'Promoter successfully assigned to event',
			array(
				'post_id'          => $post_id,
				'promoter_term_id' => $promoter_term_id,
			)
		);

		return array(
			'success' => true,
			'error'   => null,
		);
	}

	/**
	 * Get promoter assignment statistics for monitoring
	 *
	 * @return array Promoter operation statistics
	 */
	public static function get_promoter_stats() {
		global $wpdb;

		$promoters_with_meta = $wpdb->get_var(
			"
            SELECT COUNT(DISTINCT tm.term_id) 
            FROM {$wpdb->termmeta} tm
            INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
            WHERE tt.taxonomy = 'promoter'
            AND tm.meta_key LIKE '_promoter_%'
        "
		);

		$total_promoters = wp_count_terms( array( 'taxonomy' => 'promoter' ) );

		$events_with_promoters = $wpdb->get_var(
			"
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE p.post_type = '" . esc_sql( Event_Post_Type::POST_TYPE ) . "' 
            AND p.post_status = 'publish'
            AND tt.taxonomy = 'promoter'
        "
		);

		return array(
			'total_promoters'         => (int) $total_promoters,
			'promoters_with_metadata' => (int) $promoters_with_meta,
			'events_with_promoters'   => (int) $events_with_promoters,
			'metadata_coverage'       => $total_promoters > 0 ? round( ( $promoters_with_meta / $total_promoters ) * 100, 2 ) : 0,
		);
	}
}
