<?php
/**
 * Data Machine Events Venue Handler
 *
 * Centralized venue taxonomy handling for Data Machine Events.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\VenueService;
use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simplified venue assignment for Data Machine Events
 *
 * Assigns existing venue terms to events. Venue creation/update now happens
 * in handler settings (UniversalWebScraperSettings::save_settings()).
 */
class Venue {

	/**
	 * Assign pre-existing venue to event
	 *
	 * Venues are now created/updated when handler settings are saved.
	 * This method simply assigns the existing venue term_id to the event.
	 *
	 * @param int $post_id Event post ID for venue assignment
	 * @param array $settings Handler settings containing 'venue' term_id
	 * @return array Assignment result with success status and error details
	 */
	public static function assign_venue_to_event( $post_id, $settings = array() ) {
		if ( ! $post_id ) {
			return array(
				'success' => false,
				'error'   => 'Post ID is required',
			);
		}

		// Check if we have a specific venue ID from settings (manual override)
		$venue_term_id = $settings['venue'] ?? null;

		// If no manual venue, check if we have dynamic venue data from Engine
		// This allows the AI/Import step to pass venue info dynamically
		if ( ! $venue_term_id && ! empty( $settings['venue_data'] ) ) {
			$venue_data = VenueService::normalize_venue_data( $settings['venue_data'] );
			$result     = VenueService::get_or_create_venue( $venue_data );
			if ( ! is_wp_error( $result ) ) {
				$venue_term_id = $result;
			}
		}

		if ( ! $venue_term_id ) {
			do_action(
				'datamachine_log',
				'debug',
				'No venue term_id in settings, skipping venue assignment',
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

		// Validate venue term exists
		if ( ! term_exists( $venue_term_id, 'venue' ) ) {
			$error_msg = 'Venue term does not exist';
			do_action(
				'datamachine_log',
				'error',
				$error_msg,
				array(
					'post_id'       => $post_id,
					'venue_term_id' => $venue_term_id,
				)
			);

			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		// Assign venue to event
		$assignment_result = wp_set_post_terms( $post_id, array( (int) $venue_term_id ), 'venue' );

		if ( is_wp_error( $assignment_result ) ) {
			$error_msg = 'Venue assignment failed: ' . $assignment_result->get_error_message();
			do_action(
				'datamachine_log',
				'error',
				$error_msg,
				array(
					'post_id'       => $post_id,
					'venue_term_id' => $venue_term_id,
					'wp_error'      => $assignment_result->get_error_message(),
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
			'Venue successfully assigned to event',
			array(
				'post_id'       => $post_id,
				'venue_term_id' => $venue_term_id,
			)
		);

		return array(
			'success' => true,
			'error'   => null,
		);
	}

	/**
	 * Get venue assignment statistics for monitoring
	 *
	 * @return array Venue operation statistics
	 */
	public static function get_venue_stats() {
		global $wpdb;

		$venues_with_meta = $wpdb->get_var(
			"
            SELECT COUNT(DISTINCT tm.term_id) 
            FROM {$wpdb->termmeta} tm
            INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
            WHERE tt.taxonomy = 'venue'
            AND tm.meta_key LIKE '_venue_%'
        "
		);

		$total_venues = wp_count_terms( array( 'taxonomy' => 'venue' ) );

		$events_with_venues = $wpdb->get_var(
			"
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE p.post_type = '" . esc_sql( Event_Post_Type::POST_TYPE ) . "' 
            AND p.post_status = 'publish'
            AND tt.taxonomy = 'venue'
        "
		);

		return array(
			'total_venues'         => (int) $total_venues,
			'venues_with_metadata' => (int) $venues_with_meta,
			'events_with_venues'   => (int) $events_with_venues,
			'metadata_coverage'    => $total_venues > 0 ? round( ( $venues_with_meta / $total_venues ) * 100, 2 ) : 0,
		);
	}
}
