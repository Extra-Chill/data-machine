<?php
/**
 * Recurring Scheduler — the shared scheduling primitive.
 *
 * Handles all Action Scheduler plumbing for recurring, cron, one-time, and
 * manual schedules for any (hook, args) tuple. This is the single primitive
 * used by FlowScheduling (to schedule flows) and by the system task runtime
 * (to schedule cron-type system tasks) and by any extension that wants a
 * recurring schedule without reinventing the glue.
 *
 * Responsibilities:
 *   - Resolve interval aliases.
 *   - Detect and validate cron expressions.
 *   - Look up interval seconds from the datamachine_scheduler_intervals filter.
 *   - Compute a deterministic stagger offset so co-scheduled actions don't
 *     all fire at the same moment.
 *   - Preserve matching pending actions and only reschedule changed slots.
 *   - Verify persistence after scheduling (AS can silently drop actions if
 *     its tables aren't ready during CLI activation).
 *   - Unschedule cleanly when a schedule is disabled.
 *
 * This class has no knowledge of flows, jobs, tasks, or settings. It only
 * knows about Action Scheduler and interval/cron semantics.
 *
 * @package DataMachine\Engine\Tasks
 * @since   0.71.0
 */

namespace DataMachine\Engine\Tasks;

defined( 'ABSPATH' ) || exit;

class RecurringScheduler {

	/**
	 * Default Action Scheduler group for all DM-managed schedules.
	 */
	public const GROUP = 'data-machine';

	/**
	 * Maximum stagger offset in seconds.
	 *
	 * Caps the spread so recurring actions don't wait unreasonably long for
	 * their first run, even with large intervals like weekly or monthly.
	 */
	public const MAX_STAGGER_SECONDS = 3600;

	/**
	 * Ensure an Action Scheduler schedule matches the requested configuration.
	 *
	 * This is the single entry point for all recurring/cron/one-time scheduling
	 * in Data Machine. All callers — FlowScheduling, system task runtime,
	 * extensions — come through here.
	 *
	 * Handles five interval states:
	 *   - null / 'manual' / $enabled=false → unschedule; no action scheduled.
	 *   - 'one_time'                       → as_schedule_single_action at $options['timestamp'].
	 *   - 'cron' + cron_expression          → as_schedule_cron_action.
	 *   - cron expression passed as interval → auto-detected, same as above.
	 *   - interval key from the datamachine_scheduler_intervals filter
	 *     (or its aliases)                 → as_schedule_recurring_action.
	 *
	 * Preserves an existing pending action for (hook, args, group) when its
	 * schedule semantics already match the requested configuration. Clears and
	 * recreates the slot only when missing or changed, and verifies AS actually
	 * persisted newly-created actions before returning success.
	 *
	 * @param string      $hook    Action Scheduler hook name to schedule.
	 * @param array       $args    Arguments passed to the hook (also used as AS
	 *                             action signature — same (hook, args, group)
	 *                             identifies the same scheduled slot).
	 * @param string|null $interval One of: null, 'manual', 'one_time', 'cron',
	 *                              interval key, interval alias, or a raw cron
	 *                              expression.
	 * @param array       $options  {
	 *     @type string|null $cron_expression     Required when $interval === 'cron'.
	 *     @type int|null    $timestamp           Required when $interval === 'one_time'.
	 *     @type int         $stagger_seed        Deterministic seed used to spread
	 *                                            co-scheduled recurring actions.
	 *                                            0 disables stagger. Default 0.
	 *     @type int|null    $first_run_timestamp Override first-run timestamp for
	 *                                            recurring schedules. When set,
	 *                                            takes precedence over stagger.
	 *                                            Default: time() + stagger offset.
	 *     @type string      $group               AS group. Default self::GROUP.
	 *     @type bool        $force_reschedule    When true, replace existing matching
	 *                                            pending actions. Default false.
	 * }
	 * @param bool        $enabled  When false, unschedule the action and return.
	 *                              Default true.
	 * @return array{interval:'manual',scheduled:false}|array{interval:'one_time',scheduled:true,timestamp:int,scheduled_time:string,preserved?:bool}|array{interval:'cron',scheduled:true,cron_expression:string,first_run?:string|null,action_id?:int,preserved?:bool}|array{interval:string,scheduled:true,interval_seconds:int,first_run:string,preserved?:bool}|\WP_Error Metadata on success,
	 *                              or WP_Error on failure. Return shape includes
	 *                              any relevant computed fields (interval_seconds,
	 *                              first_run, cron_expression, timestamp) so
	 *                              callers can persist them if needed.
	 */
	public static function ensureSchedule(
		string $hook,
		array $args,
		?string $interval,
		array $options = array(),
		bool $enabled = true
	) {
		$group = $options['group'] ?? self::GROUP;

		// Disabled / manual / null → unschedule and return.
		if ( ! $enabled || null === $interval || 'manual' === $interval ) {
			self::unschedule( $hook, $args, $group );
			return array(
				'interval'  => 'manual',
				'scheduled' => false,
			);
		}

		// One-time scheduling.
		if ( 'one_time' === $interval ) {
			$timestamp = $options['timestamp'] ?? null;
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

			if ( empty( $options['force_reschedule'] ) && self::hasMatchingSingleAction( $hook, $args, $group, (int) $timestamp ) ) {
				return array(
					'interval'       => 'one_time',
					'scheduled'      => true,
					'timestamp'      => $timestamp,
					'scheduled_time' => wp_date( 'c', $timestamp ),
					'preserved'      => true,
				);
			}

			self::unschedule( $hook, $args, $group );

			as_schedule_single_action( $timestamp, $hook, $args, $group );

			return array(
				'interval'       => 'one_time',
				'scheduled'      => true,
				'timestamp'      => $timestamp,
				'scheduled_time' => wp_date( 'c', $timestamp ),
			);
		}

		// Explicit cron with cron_expression option.
		if ( 'cron' === $interval ) {
			$cron_expression = $options['cron_expression'] ?? null;
			if ( empty( $cron_expression ) ) {
				return new \WP_Error(
					'missing_cron_expression',
					'cron_expression required when interval is cron',
					array( 'status' => 400 )
				);
			}
			return self::scheduleCron( $hook, $args, $cron_expression, $group, ! empty( $options['force_reschedule'] ) );
		}

		// Auto-detect cron expression passed as the interval value.
		if ( self::looksLikeCronExpression( $interval ) ) {
			return self::scheduleCron( $hook, $args, $interval, $group );
		}

		// Recurring by interval key (with alias resolution).
		$resolved_interval = self::resolveIntervalAlias( $interval );
		$intervals         = apply_filters( 'datamachine_scheduler_intervals', array() );
		$interval_seconds  = $intervals[ $resolved_interval ]['seconds'] ?? null;

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

		// Determine first-run timestamp. Caller override wins (e.g. "tomorrow
		// midnight UTC" for daily memory). Otherwise compute from stagger seed.
		if ( isset( $options['first_run_timestamp'] ) ) {
			$first_run_time = (int) $options['first_run_timestamp'];
		} else {
			$stagger_seed   = (int) ( $options['stagger_seed'] ?? 0 );
			$stagger_offset = $stagger_seed > 0
				? self::calculateStaggerOffset( $stagger_seed, (int) $interval_seconds )
				: 0;
			$first_run_time = time() + $stagger_offset;
		}

		if ( empty( $options['force_reschedule'] ) && self::hasMatchingRecurringAction( $hook, $args, $group, (int) $interval_seconds ) ) {
			return array(
				'interval'         => $resolved_interval,
				'scheduled'        => true,
				'interval_seconds' => (int) $interval_seconds,
				'first_run'        => wp_date( 'c', $first_run_time ),
				'preserved'        => true,
			);
		}

		self::unschedule( $hook, $args, $group );

		as_schedule_recurring_action( $first_run_time, $interval_seconds, $hook, $args, $group );

		// Verify persistence. AS can silently drop actions when its tables
		// aren't ready (e.g. CLI context during plugin activation).
		if ( ! self::isScheduled( $hook, $args, $group ) ) {
			return new \WP_Error(
				'schedule_not_persisted',
				'Action Scheduler accepted the schedule but no pending action was found. AS tables may not be ready.',
				array( 'status' => 500 )
			);
		}

		return array(
			'interval'         => $resolved_interval,
			'scheduled'        => true,
			'interval_seconds' => (int) $interval_seconds,
			'first_run'        => wp_date( 'c', $first_run_time ),
		);
	}

	/**
	 * Unschedule all Action Scheduler actions matching (hook, args, group).
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args (signature).
	 * @param string $group AS group.
	 * @return void
	 */
	public static function unschedule( string $hook, array $args, string $group = self::GROUP ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, $group );
		}
	}

	/**
	 * Check whether a pending Action Scheduler action exists for (hook, args, group).
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args (signature).
	 * @param string $group AS group.
	 * @return bool True if a pending action exists.
	 */
	public static function isScheduled( string $hook, array $args, string $group = self::GROUP ): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}
		return false !== as_next_scheduled_action( $hook, $args, $group );
	}

	/**
	 * Find the next pending action object for a schedule slot.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args.
	 * @param string $group AS group.
	 * @return object|null ActionScheduler_Action-like object, or null.
	 */
	private static function getPendingAction( string $hook, array $args, string $group ): ?object {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => 'pending',
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => 1,
			),
			'OBJECT'
		);

		$action = reset( $actions );
		return is_object( $action ) ? $action : null;
	}

	/**
	 * Check whether the existing pending action is a one-time action at timestamp.
	 *
	 * @param string $hook      Hook name.
	 * @param array  $args      Action args.
	 * @param string $group     AS group.
	 * @param int    $timestamp Expected timestamp.
	 * @return bool True when the existing pending action already matches.
	 */
	private static function hasMatchingSingleAction( string $hook, array $args, string $group, int $timestamp ): bool {
		$action = self::getPendingAction( $hook, $args, $group );
		if ( ! $action || ! method_exists( $action, 'get_schedule' ) ) {
			return false;
		}

		$schedule = $action->get_schedule();
		if ( ! is_object( $schedule ) || ! method_exists( $schedule, 'is_recurring' ) || $schedule->is_recurring() ) {
			return false;
		}

		return self::scheduleTimestampMatches( $schedule, $timestamp );
	}

	/**
	 * Check whether the existing pending action has the requested interval recurrence.
	 *
	 * @param string $hook             Hook name.
	 * @param array  $args             Action args.
	 * @param string $group            AS group.
	 * @param int    $interval_seconds Expected recurrence in seconds.
	 * @return bool True when the existing pending action already matches.
	 */
	private static function hasMatchingRecurringAction( string $hook, array $args, string $group, int $interval_seconds ): bool {
		$action = self::getPendingAction( $hook, $args, $group );
		if ( ! $action || ! method_exists( $action, 'get_schedule' ) ) {
			return false;
		}

		$schedule = $action->get_schedule();
		if ( ! is_object( $schedule ) || ! method_exists( $schedule, 'is_recurring' ) || ! $schedule->is_recurring() ) {
			return false;
		}
		if ( ! method_exists( $schedule, 'get_recurrence' ) ) {
			return false;
		}

		return (int) $schedule->get_recurrence() === $interval_seconds;
	}

	/**
	 * Check whether the existing pending action has the requested cron recurrence.
	 *
	 * @param string $hook            Hook name.
	 * @param array  $args            Action args.
	 * @param string $group           AS group.
	 * @param string $cron_expression Expected cron expression.
	 * @return bool True when the existing pending action already matches.
	 */
	private static function hasMatchingCronAction( string $hook, array $args, string $group, string $cron_expression ): bool {
		$action = self::getPendingAction( $hook, $args, $group );
		if ( ! $action || ! method_exists( $action, 'get_schedule' ) ) {
			return false;
		}

		$schedule = $action->get_schedule();
		if ( ! is_object( $schedule ) || ! method_exists( $schedule, 'is_recurring' ) || ! $schedule->is_recurring() ) {
			return false;
		}
		if ( ! method_exists( $schedule, 'get_recurrence' ) ) {
			return false;
		}

		return (string) $schedule->get_recurrence() === $cron_expression;
	}

	/**
	 * Check whether a schedule object's run date matches a timestamp.
	 *
	 * @param object $schedule  Action Scheduler schedule object.
	 * @param int    $timestamp Expected timestamp.
	 * @return bool True when the schedule date matches.
	 */
	private static function scheduleTimestampMatches( object $schedule, int $timestamp ): bool {
		if ( ! method_exists( $schedule, 'get_date' ) ) {
			return false;
		}

		$date = $schedule->get_date();
		return $date instanceof \DateTimeInterface && $date->getTimestamp() === $timestamp;
	}

	/**
	 * Calculate a deterministic stagger offset.
	 *
	 * Uses the seed (typically a flow ID or similar stable identifier) to
	 * produce a consistent offset so the same caller always lands on the
	 * same position within the interval window. Prevents all co-scheduled
	 * recurring actions from firing simultaneously.
	 *
	 * @param int $seed             Stable seed (e.g. flow ID).
	 * @param int $interval_seconds Interval in seconds.
	 * @return int Offset in seconds, 0 to min(interval, MAX_STAGGER_SECONDS).
	 */
	public static function calculateStaggerOffset( int $seed, int $interval_seconds ): int {
		$max_offset = min( $interval_seconds, self::MAX_STAGGER_SECONDS );
		if ( $max_offset <= 0 ) {
			return 0;
		}
		return absint( crc32( 'dm_stagger_' . $seed ) ) % $max_offset;
	}

	/**
	 * Validate a cron expression string.
	 *
	 * Uses Action Scheduler's bundled CronExpression library — no external
	 * dependency.
	 *
	 * @param string $expression Cron expression to validate.
	 * @return bool True if valid.
	 */
	public static function isValidCronExpression( string $expression ): bool {
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
	 * Detect whether a string looks like a cron expression.
	 *
	 * Cron expressions have 5-6 space-separated parts (minute hour day month
	 * weekday [year]) or start with @ (e.g. @daily, @hourly).
	 *
	 * @param string $value Value to check.
	 * @return bool True if it looks like a cron expression.
	 */
	public static function looksLikeCronExpression( string $value ): bool {
		// @ shortcuts.
		if ( str_starts_with( $value, '@' ) ) {
			return true;
		}

		$parts = preg_split( '/\s+/', trim( $value ) );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( count( $parts ) < 5 || count( $parts ) > 6 ) {
			return false;
		}

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
	 * @param string $expression Cron expression.
	 * @return string Human-readable description.
	 */
	public static function describeCronExpression( string $expression ): string {
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
	 * Resolve an interval alias to its canonical key.
	 *
	 * Wraps the global datamachine_resolve_interval_alias() for callers that
	 * don't want to depend on the global directly.
	 *
	 * @param string $interval Interval key or alias.
	 * @return string Canonical interval key.
	 */
	public static function resolveIntervalAlias( string $interval ): string {
		if ( function_exists( 'datamachine_resolve_interval_alias' ) ) {
			return datamachine_resolve_interval_alias( $interval );
		}
		return $interval;
	}

	/**
	 * Schedule a cron action and verify persistence.
	 *
	 * @param string $hook            Hook name.
	 * @param array  $args            Action args.
	 * @param string $cron_expression Cron expression.
	 * @param string $group           AS group.
	 * @return array{interval:'cron',scheduled:true,cron_expression:string,first_run?:string|null,action_id?:int,preserved?:bool}|\WP_Error
	 */
	private static function scheduleCron( string $hook, array $args, string $cron_expression, string $group, bool $force_reschedule = false ) {
		if ( ! self::isValidCronExpression( $cron_expression ) ) {
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

		if ( ! $force_reschedule && self::hasMatchingCronAction( $hook, $args, $group, $cron_expression ) ) {
			return array(
				'interval'        => 'cron',
				'scheduled'       => true,
				'cron_expression' => $cron_expression,
				'preserved'       => true,
			);
		}

		self::unschedule( $hook, $args, $group );

		$action_id = as_schedule_cron_action( time(), $cron_expression, $hook, $args, $group );

		if ( ! self::isScheduled( $hook, $args, $group ) ) {
			return new \WP_Error(
				'schedule_not_persisted',
				'Action Scheduler accepted the cron schedule but no pending action was found.',
				array( 'status' => 500 )
			);
		}

		// Compute next run (informational, non-fatal if it fails).
		$next_run = null;
		try {
			$cron     = \CronExpression::factory( $cron_expression );
			$next_run = $cron->getNextRunDate()->format( 'c' );
		} catch ( \Exception $e ) {
			unset( $e );
		}

		return array(
			'interval'        => 'cron',
			'scheduled'       => true,
			'cron_expression' => $cron_expression,
			'first_run'       => $next_run,
			'action_id'       => $action_id,
		);
	}
}
