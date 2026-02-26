<?php
/**
 * Flow Scheduling Logic
 *
 * Dedicated class for handling flow scheduling operations.
 * Extracted from Flows.php for better maintainability.
 *
 * @package DataMachine\Api\Flows
 */

namespace DataMachine\Api\Flows;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowScheduling {

	/**
	 * Maximum stagger offset in seconds.
	 *
	 * Caps the spread so flows don't wait unreasonably long for their first run,
	 * even with large intervals like weekly or monthly.
	 */
	private const MAX_STAGGER_SECONDS = 3600;

	/**
	 * Calculate a deterministic stagger offset for a flow.
	 *
	 * Uses the flow ID as a seed to produce a consistent offset so the same
	 * flow always lands on the same position within the interval window.
	 * This prevents all flows with the same interval from firing simultaneously.
	 *
	 * @param int $flow_id          Flow ID used as seed.
	 * @param int $interval_seconds Interval in seconds.
	 * @return int Offset in seconds (0 to min(interval, MAX_STAGGER_SECONDS)).
	 */
	public static function calculate_stagger_offset( int $flow_id, int $interval_seconds ): int {
		$max_offset = min( $interval_seconds, self::MAX_STAGGER_SECONDS );
		if ( $max_offset <= 0 ) {
			return 0;
		}

		// Deterministic hash based on flow ID — same flow always gets same offset.
		return absint( crc32( 'dm_stagger_' . $flow_id ) ) % $max_offset;
	}

	/**
	 * Handle scheduling configuration updates for a flow.
	 *
	 * scheduling_config now only contains scheduling data (interval, timestamps).
	 * Execution status (last_run, status, counters) is derived from jobs table.
	 *
	 * @param int   $flow_id Flow ID
	 * @param array $scheduling_config Scheduling configuration
	 * @return bool|\WP_Error True on success, WP_Error on failure
	 */
	public static function handle_scheduling_update( $flow_id, $scheduling_config ) {
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();

		// Validate flow exists
		$flow = $db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return new \WP_Error(
				'flow_not_found',
				"Flow {$flow_id} not found",
				array( 'status' => 404 )
			);
		}

		$interval = $scheduling_config['interval'] ?? null;

		// Handle manual scheduling (unschedule)
		if ( 'manual' === $interval || null === $interval ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
			}

			$db_flows->update_flow_scheduling( $flow_id, array( 'interval' => 'manual' ) );
			return true;
		}

		// Handle one-time scheduling
		if ( 'one_time' === $interval ) {
			$timestamp = $scheduling_config['timestamp'] ?? null;
			if ( ! $timestamp ) {
				return new \WP_Error(
					'missing_timestamp',
					'Timestamp required for one-time scheduling',
					array( 'status' => 400 )
				);
			}

			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				return new \WP_Error(
					'scheduler_unavailable',
					'Action Scheduler not available',
					array( 'status' => 500 )
				);
			}

			as_schedule_single_action(
				$timestamp,
				'datamachine_run_flow_now',
				array( $flow_id ),
				'data-machine'
			);

			$db_flows->update_flow_scheduling(
				$flow_id,
				array(
					'interval'       => 'one_time',
					'timestamp'      => $timestamp,
					'scheduled_time' => wp_date( 'c', $timestamp ),
				)
			);
			return true;
		}

		// Handle recurring scheduling
		$intervals        = apply_filters( 'datamachine_scheduler_intervals', array() );
		$interval_seconds = $intervals[ $interval ]['seconds'] ?? null;

		if ( ! $interval_seconds ) {
			return new \WP_Error(
				'invalid_interval',
				"Invalid interval: {$interval}",
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return new \WP_Error(
				'scheduler_unavailable',
				'Action Scheduler not available',
				array( 'status' => 500 )
			);
		}

		// Clear any existing schedule first
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
		}

		// Stagger the first run so flows with the same interval don't all fire at once.
		$stagger_offset = self::calculate_stagger_offset( $flow_id, $interval_seconds );
		$first_run_time = time() + $stagger_offset;

		as_schedule_recurring_action(
			$first_run_time,
			$interval_seconds,
			'datamachine_run_flow_now',
			array( $flow_id ),
			'data-machine'
		);

		$db_flows->update_flow_scheduling(
			$flow_id,
			array(
				'interval'         => $interval,
				'interval_seconds' => $interval_seconds,
				'first_run'        => wp_date( 'c', $first_run_time ),
			)
		);
		return true;
	}
}
