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
			if ( function_exists( 'as_unschedule_action' ) ) {
				as_unschedule_action( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
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
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
		}

		as_schedule_recurring_action(
			time() + $interval_seconds,
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
				'first_run'        => wp_date( 'c', time() + $interval_seconds ),
			)
		);
		return true;
	}
}
