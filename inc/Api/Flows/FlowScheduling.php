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
	 * Check if the incoming scheduling config matches what's already set
	 * AND that the Action Scheduler action actually exists.
	 *
	 * Compares the meaningful scheduling fields (interval, cron_expression,
	 * timestamp) to determine if rescheduling would be a no-op. This prevents
	 * flow updates from resetting the Action Scheduler timer when the schedule
	 * hasn't actually changed.
	 *
	 * Also verifies the AS action is actually pending — if the DB config says
	 * "daily" but no AS action exists (e.g. AS wasn't loaded during initial
	 * creation in CLI context), this returns false to force re-scheduling.
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
		// The DB config can claim "daily" while no AS action was persisted
		// (e.g. Action Scheduler wasn't fully loaded during CLI creation).
		$config_matches = false;

		// Recurring interval comparison.
		if ( $current_interval === $interval && 'cron' !== $interval && 'one_time' !== $interval ) {
			$config_matches = true;
		}

		// Cron expression comparison.
		if ( 'cron' === $current_interval && 'cron' === $interval ) {
			$current_cron   = $current['cron_expression'] ?? '';
			$config_matches = ( $current_cron === $cron_expression );
		}

		// One-time timestamp comparison.
		if ( 'one_time' === $current_interval && 'one_time' === $interval ) {
			$current_ts     = $current['timestamp'] ?? null;
			$incoming_ts    = $incoming['timestamp'] ?? null;
			$config_matches = ( $current_ts === $incoming_ts );
		}

		if ( ! $config_matches ) {
			return false;
		}

		// Config matches — verify the AS action is actually pending.
		if ( $flow_id > 0 && function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
			if ( false === $next ) {
				// DB says scheduled but no AS action exists — force re-schedule.
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a cron expression string.
	 *
	 * Uses Action Scheduler's bundled CronExpression library — no external dependency.
	 *
	 * @param string $expression Cron expression to validate.
	 * @return bool True if valid.
	 */
	public static function is_valid_cron_expression( string $expression ): bool {
		if ( ! class_exists( 'CronExpression' ) ) {
			return false;
		}

		try {
			$cron = \CronExpression::factory( $expression );
			$cron->getNextRunDate();
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Detect if a string looks like a cron expression.
	 *
	 * Cron expressions have 5-6 space-separated parts (minute hour day month weekday [year])
	 * or start with @ (e.g. @daily, @hourly).
	 *
	 * @param string $value Value to check.
	 * @return bool True if it looks like a cron expression.
	 */
	public static function looks_like_cron_expression( string $value ): bool {
		// @ shortcuts: yearly, monthly, weekly, daily, hourly.
		if ( str_starts_with( $value, '@' ) ) {
			return true;
		}

		// Standard cron: 5-6 space-separated parts containing digits, *, /, -, or comma.
		$parts = preg_split( '/\s+/', trim( $value ) );
		if ( count( $parts ) < 5 || count( $parts ) > 6 ) {
			return false;
		}

		// Each part should only contain valid cron characters.
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^[\d\*\/\-\,\?LW#A-Za-z]+$/', $part ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get a human-readable description of a cron expression.
	 *
	 * Provides a basic description for common patterns. Falls back to
	 * showing the next run time for complex expressions.
	 *
	 * @param string $expression Cron expression.
	 * @return string Human-readable description.
	 */
	public static function describe_cron_expression( string $expression ): string {
		// @ shortcut descriptions.
		$shortcuts = array(
			'@yearly'   => 'Once a year (Jan 1, midnight)',
			'@annually' => 'Once a year (Jan 1, midnight)',
			'@monthly'  => 'Once a month (1st, midnight)',
			'@weekly'   => 'Once a week (Sunday, midnight)',
			'@daily'    => 'Once a day (midnight)',
			'@hourly'   => 'Once an hour',
		);

		if ( isset( $shortcuts[ $expression ] ) ) {
			return $shortcuts[ $expression ];
		}

		// Compute next run for description.
		if ( ! class_exists( 'CronExpression' ) ) {
			return $expression;
		}

		try {
			$cron     = \CronExpression::factory( $expression );
			$next_run = $cron->getNextRunDate();
			return sprintf( 'Next: %s', $next_run->format( 'Y-m-d H:i:s' ) );
		} catch ( \Exception $e ) {
			return $expression;
		}
	}

	/**
	 * Handle scheduling configuration updates for a flow.
	 *
	 * scheduling_config now only contains scheduling data (interval, timestamps, cron).
	 * Execution status (last_run, status, counters) is derived from jobs table.
	 *
	 * Supports four scheduling types:
	 * - manual: no schedule (unschedules any existing)
	 * - one_time: single execution at a timestamp
	 * - cron: cron expression via as_schedule_cron_action()
	 * - recurring: interval key from datamachine_scheduler_intervals filter
	 *
	 * @param int   $flow_id Flow ID
	 * @param array $scheduling_config Scheduling configuration
	 * @param bool  $force Skip the unchanged guard. Use when creating a new flow
	 *                     whose DB row already has the target interval (avoids the
	 *                     old "save as manual first" workaround).
	 * @return bool|\WP_Error True on success, WP_Error on failure
	 */
	public static function handle_scheduling_update( $flow_id, $scheduling_config, bool $force = false ) {
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();

		// Validate flow exists.
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

		// Resolve aliases (e.g. every_6_hours → qtrdaily) before any comparison.
		if ( null !== $interval && function_exists( 'datamachine_resolve_interval_alias' ) ) {
			$interval = datamachine_resolve_interval_alias( $interval );
		}

		// Skip re-scheduling if the configuration hasn't actually changed.
		// Without this guard, any flow update that includes scheduling_config
		// (even identical to what's already set) would unschedule/reschedule,
		// resetting the timer and triggering an immediate run.
		// Skipped when $force is true (new flow creation).
		if ( ! $force ) {
			$current_scheduling = $flow['scheduling_config'] ?? array();
			if ( is_string( $current_scheduling ) ) {
				$current_scheduling = json_decode( $current_scheduling, true ) ?? array();
			}
			if ( self::scheduling_unchanged( $current_scheduling, $interval, $cron_expression, $scheduling_config, (int) $flow_id ) ) {
				return true;
			}
		}

		// Handle manual scheduling (unschedule).
		if ( 'manual' === $interval || null === $interval ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
			}

			$db_flows->update_flow_scheduling( $flow_id, array( 'interval' => 'manual' ) );
			return true;
		}

		// Handle one-time scheduling.
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

			// Clear any existing schedule first (prevents duplicate actions on re-schedule).
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
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

		// Handle cron expression scheduling.
		if ( 'cron' === $interval && $cron_expression ) {
			return self::schedule_cron( $flow_id, $cron_expression, $db_flows );
		}

		// Auto-detect cron expression passed as the interval value.
		if ( self::looks_like_cron_expression( $interval ) ) {
			return self::schedule_cron( $flow_id, $interval, $db_flows );
		}

		// Handle recurring scheduling (interval key lookup).
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

		// Clear any existing schedule first.
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

	/**
	 * Schedule a flow using a cron expression.
	 *
	 * Uses Action Scheduler's native as_schedule_cron_action() which handles
	 * cron expression parsing via its bundled CronExpression library.
	 *
	 * @param int                                   $flow_id         Flow ID.
	 * @param string                                $cron_expression Cron expression string.
	 * @param \DataMachine\Core\Database\Flows\Flows $db_flows        Flows database instance.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	private static function schedule_cron( int $flow_id, string $cron_expression, $db_flows ) {
		if ( ! self::is_valid_cron_expression( $cron_expression ) ) {
			return new \WP_Error(
				'invalid_cron_expression',
				sprintf( 'Invalid cron expression: "%s"', $cron_expression ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'as_schedule_cron_action' ) ) {
			return new \WP_Error(
				'scheduler_unavailable',
				'Action Scheduler not available',
				array( 'status' => 500 )
			);
		}

		// Clear any existing schedule first.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
		}

		$action_id = as_schedule_cron_action(
			time(),
			$cron_expression,
			'datamachine_run_flow_now',
			array( $flow_id ),
			'data-machine'
		);

		// Compute next run for storage.
		$next_run = null;
		try {
			$cron     = \CronExpression::factory( $cron_expression );
			$next_run = $cron->getNextRunDate()->format( 'c' );
		} catch ( \Exception $e ) {
			// Non-fatal — next run display is informational.
			unset( $e );
		}

		$db_flows->update_flow_scheduling(
			$flow_id,
			array(
				'interval'        => 'cron',
				'cron_expression' => $cron_expression,
				'first_run'       => $next_run,
			)
		);

		do_action(
			'datamachine_log',
			'info',
			'Flow scheduled with cron expression',
			array(
				'flow_id'         => $flow_id,
				'cron_expression' => $cron_expression,
				'next_run'        => $next_run,
				'action_id'       => $action_id,
			)
		);

		return true;
	}
}
