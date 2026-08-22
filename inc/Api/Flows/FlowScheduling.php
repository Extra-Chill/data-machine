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
	private const RECONCILIATION_KEY       = 'schedule_reconciliation';
	public const GENERATION_ARGUMENT_INDEX = 2;

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

		// Both manual — only unchanged when no stale schedule still owns coverage.
		if ( ( 'manual' === $current_interval || null === $current_interval )
			&& ( 'manual' === $interval || null === $interval ) ) {
			return $flow_id <= 0 || ! RecurringScheduler::hasLogicalCoverage( self::FLOW_HOOK, array( $flow_id ) );
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
		if ( $flow_id > 0 && ! RecurringScheduler::hasLogicalCoverage( self::FLOW_HOOK, array( $flow_id ) ) ) {
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
	public static function handle_scheduling_update( $flow_id, $scheduling_config, bool $force = false, bool $legacy_adoption = false ) {
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();

		$flow = $db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return new \WP_Error(
				'flow_not_found',
				"Flow {$flow_id} not found",
				array( 'status' => 404 )
			);
		}

		$interval           = $scheduling_config['interval'] ?? null;
		$cron_expression    = $scheduling_config['cron_expression'] ?? null;
		$current_scheduling = $flow['scheduling_config'] ?? array();
		if ( is_string( $current_scheduling ) ) {
			$current_scheduling = json_decode( $current_scheduling, true ) ?? array();
		}

		// Resolve aliases before any comparison.
		if ( null !== $interval ) {
			$interval = RecurringScheduler::resolveIntervalAlias( $interval );
		}

		// Skip re-scheduling if unchanged (prevents timer resets on flow updates).
		if ( ! $force ) {
			if ( self::scheduling_unchanged( $current_scheduling, $interval, $cron_expression, $scheduling_config, (int) $flow_id ) ) {
				return true;
			}
		}

		$desired             = $scheduling_config;
		$desired['interval'] = $interval ?? 'manual';
		foreach ( array( 'interval_seconds', 'first_run', 'scheduled_time', 'action_id' ) as $derived_key ) {
			unset( $desired[ $derived_key ] );
		}

		$effective_interval = false === ( $desired['enabled'] ?? true ) ? 'manual' : $interval;
		$operation_token    = wp_generate_uuid4();

		$options = array(
			'stagger_seed'              => (int) $flow_id,
			'force_reschedule'          => $force,
			'generation_argument_index' => self::GENERATION_ARGUMENT_INDEX,
			'legacy_adoption'           => $legacy_adoption,
		);
		if ( 'one_time' === $interval ) {
			$options['timestamp'] = $scheduling_config['timestamp'] ?? null;
		}
		if ( 'cron' === $interval ) {
			$options['cron_expression'] = $cron_expression;
		}

		$result = RecurringScheduler::commitDesiredSchedule(
			self::FLOW_HOOK,
			array( (int) $flow_id ),
			$effective_interval,
			$options,
			true,
			static function () use ( $db_flows, $flow_id, $desired, $operation_token, $legacy_adoption, $scheduling_config ) {
				if ( $legacy_adoption && $db_flows->get_flow_scheduling( (int) $flow_id ) !== $scheduling_config ) {
					return new \WP_Error(
						'legacy_schedule_generation_conflict',
						'Legacy action adoption is blocked by newer desired schedule state.',
						array( 'status' => 409 )
					);
				}
				$pending                             = $desired;
				$pending[ self::RECONCILIATION_KEY ] = array(
					'status'     => 'pending',
					'token'      => $operation_token,
					'updated_at' => time(),
				);
				return $db_flows->update_flow_scheduling( (int) $flow_id, $pending );
			},
			static function ( $schedule_result ) use ( $db_flows, $flow_id, $operation_token ): bool|\WP_Error {
				$stored = $db_flows->get_flow_scheduling( (int) $flow_id );
				if ( ! is_array( $stored ) || ( $stored[ self::RECONCILIATION_KEY ]['token'] ?? '' ) !== $operation_token ) {
					return new \WP_Error(
						'schedule_desired_state_superseded',
						'Desired schedule state changed before reconciliation completed.',
						array(
							'status'         => 409,
							'retryable'      => true,
							'retry_after_ms' => 250,
						)
					);
				}
				if ( is_wp_error( $schedule_result ) ) {
					$error_metadata                = RecurringScheduler::errorMetadata( $schedule_result );
					$error_metadata['http_status'] = $error_metadata['status'];
					unset( $error_metadata['status'] );
					$updates = array(
						self::RECONCILIATION_KEY => array_merge(
							$error_metadata,
							array(
								'status'     => 'drift',
								'token'      => $operation_token,
								'updated_at' => time(),
							)
						),
					);
					$remove  = array();
				} else {
					$updates = self::scheduleMetadataUpdates( $stored, $schedule_result );
					$remove  = array( self::RECONCILIATION_KEY );
				}

				if ( ! $db_flows->update_flow_scheduling_metadata( (int) $flow_id, $updates, $remove ) ) {
					return new \WP_Error(
						'schedule_reconciliation_record_failed',
						'Desired schedule was committed but reconciliation status could not be recorded.',
						array(
							'status'         => 503,
							'retryable'      => true,
							'retry_after_ms' => 250,
						)
					);
				}

				return true;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'cron' === $result['interval'] ) {
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
		}

		return true;
	}

	/**
	 * Transition one legacy Action Scheduler execution to generated identity.
	 *
	 * The legacy action may run once only when no generated owner exists. Forced
	 * reconciliation installs the generated successor, and native repeat cleanup
	 * cancels the legacy successor exactly.
	 *
	 * @return bool|\WP_Error
	 */
	public static function adopt_legacy_action( int $flow_id ) {
		$flows      = new \DataMachine\Core\Database\Flows\Flows();
		$scheduling = $flows->get_flow_scheduling( $flow_id );
		if ( ! is_array( $scheduling ) ) {
			return new \WP_Error( 'flow_not_found', "Flow {$flow_id} not found", array( 'status' => 404 ) );
		}
		if ( ! empty( $scheduling[ self::RECONCILIATION_KEY ] ) ) {
			return new \WP_Error(
				'legacy_schedule_generation_conflict',
				'Legacy action adoption is blocked by a newer desired schedule generation.',
				array( 'status' => 409 )
			);
		}

		return self::handle_scheduling_update( $flow_id, $scheduling, true, true );
	}

	private static function scheduleMetadataUpdates( array $desired, array $result ): array {
		$updates = array();
		if ( false !== ( $desired['enabled'] ?? true ) ) {
			$updates['interval'] = $result['interval'];
		}

		foreach ( array( 'timestamp', 'scheduled_time', 'cron_expression', 'interval_seconds', 'first_run', 'action_id' ) as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				$updates[ $key ] = $result[ $key ];
			}
		}

		return $updates;
	}
}
