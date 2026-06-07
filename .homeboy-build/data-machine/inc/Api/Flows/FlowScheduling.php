<?php
/**
 * Flow Scheduling Logic
 *
 * Persists flow-specific scheduling config to the flows table and delegates
 * all Action Scheduler plumbing to the shared RecurringScheduler primitive.
 *
 * @package DataMachine\Api\Flows
 */

namespace DataMachine\Api\Flows;

use DataMachine\Engine\Tasks\RecurringScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowScheduling {

	/**
	 * Action Scheduler hook fired when a flow is due to run.
	 */
	public const FLOW_HOOK = 'datamachine_run_flow_now';

	/**
	 * Check if the incoming scheduling config matches what's already set
	 * AND that the Action Scheduler action actually exists.
	 *
	 * Flow-specific no-op guard. Lives here (not on RecurringScheduler)
	 * because it compares against DB-persisted flow config, which is a
	 * flow concern, not a scheduling-primitive concern.
	 *
	 * @param array       $current         Current scheduling_config from DB.
	 * @param string|null $interval        Incoming interval key.
	 * @param string|null $cron_expression Incoming cron expression.
	 * @param array       $incoming        Full incoming scheduling_config.
	 * @param int         $flow_id         Flow ID for AS action verification.
	 * @return bool True if scheduling hasn't changed and can be skipped.
	 */
	private static function scheduling_unchanged( array $current, ?string $interval, ?string $cron_expression, array $incoming, int $flow_id = 0 ): bool {
		$current_interval = $current['interval'] ?? null;

		// If current is empty/unset and incoming is non-manual, it's a change.
		if ( empty( $current_interval ) && null !== $interval && 'manual' !== $interval ) {
			return false;
		}

		// Both manual — no change.
		if ( ( 'manual' === $current_interval || null === $current_interval )
			&& ( 'manual' === $interval || null === $interval ) ) {
			return true;
		}

		// Config matches — but verify the AS action actually exists.
		$config_matches = false;

		if ( $current_interval === $interval && 'cron' !== $interval && 'one_time' !== $interval ) {
			$config_matches = true;
		}

		if ( 'cron' === $current_interval && 'cron' === $interval ) {
			$current_cron   = $current['cron_expression'] ?? '';
			$config_matches = ( $current_cron === $cron_expression );
		}

		if ( 'one_time' === $current_interval && 'one_time' === $interval ) {
			$current_ts     = $current['timestamp'] ?? null;
			$incoming_ts    = $incoming['timestamp'] ?? null;
			$config_matches = ( $current_ts === $incoming_ts );
		}

		if ( ! $config_matches ) {
			return false;
		}

		// Verify AS action is actually pending.
		if ( $flow_id > 0 && ! RecurringScheduler::isScheduled( self::FLOW_HOOK, array( $flow_id ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Handle scheduling configuration updates for a flow.
	 *
	 * Delegates all Action Scheduler plumbing to RecurringScheduler while
	 * keeping flow-specific persistence (flows table) here.
	 *
	 * @param int   $flow_id           Flow ID
	 * @param array $scheduling_config Scheduling configuration
	 * @param bool  $force             Skip the unchanged guard.
	 * @return bool|\WP_Error True on success, WP_Error on failure
	 */
	public static function handle_scheduling_update( $flow_id, $scheduling_config, bool $force = false ) {
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();

		$flow = $db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return new \WP_Error(
				'flow_not_found',
				"Flow {$flow_id} not found",
				array( 'status' => 404 )
			);
		}

		$interval        = $scheduling_config['interval'] ?? null;
		$cron_expression = $scheduling_config['cron_expression'] ?? null;

		// Resolve aliases before any comparison.
		if ( null !== $interval ) {
			$interval = RecurringScheduler::resolveIntervalAlias( $interval );
		}

		// Skip re-scheduling if unchanged (prevents timer resets on flow updates).
		if ( ! $force ) {
			$current_scheduling = $flow['scheduling_config'] ?? array();
			if ( is_string( $current_scheduling ) ) {
				$current_scheduling = json_decode( $current_scheduling, true ) ?? array();
			}
			if ( self::scheduling_unchanged( $current_scheduling, $interval, $cron_expression, $scheduling_config, (int) $flow_id ) ) {
				return true;
			}
		}

		// Delegate AS scheduling to the primitive.
		$options = array(
			'stagger_seed' => (int) $flow_id,
		);
		if ( 'one_time' === $interval ) {
			$options['timestamp'] = $scheduling_config['timestamp'] ?? null;
		}
		if ( 'cron' === $interval ) {
			$options['cron_expression'] = $cron_expression;
		}

		$result = RecurringScheduler::ensureSchedule(
			self::FLOW_HOOK,
			array( (int) $flow_id ),
			$interval,
			$options,
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Persist to flows table based on what was actually scheduled.
		$storage = array( 'interval' => $result['interval'] );
		switch ( $result['interval'] ) {
			case 'manual':
				$db_flows->update_flow_scheduling( $flow_id, $storage );
				break;

			case 'one_time':
				$storage['timestamp']      = $result['timestamp'];
				$storage['scheduled_time'] = $result['scheduled_time'];
				$db_flows->update_flow_scheduling( $flow_id, $storage );
				break;

			case 'cron':
				$storage['cron_expression'] = $result['cron_expression'];
				$storage['first_run']       = $result['first_run'];
				$db_flows->update_flow_scheduling( $flow_id, $storage );

				do_action(
					'datamachine_log',
					'info',
					'Flow scheduled with cron expression',
					array(
						'flow_id'         => $flow_id,
						'cron_expression' => $result['cron_expression'],
						'next_run'        => $result['first_run'],
						'action_id'       => $result['action_id'] ?? null,
					)
				);
				break;

			default:
				// Recurring by interval key.
				$storage['interval_seconds'] = $result['interval_seconds'];
				$storage['first_run']        = $result['first_run'];
				$db_flows->update_flow_scheduling( $flow_id, $storage );
				break;
		}

		return true;
	}
}
