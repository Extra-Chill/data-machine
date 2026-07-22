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

use DataMachine\Core\OptionLeaseStore;

defined( 'ABSPATH' ) || exit;

class RecurringScheduler {
	/** @var array<string,array{generation_option:string,expected_generation:string,legacy:bool}> */
	private static array $executed_recurrence_generations = array();
	private static ?int $executing_action_id              = null;
	private static bool $executing_recurring_action       = false;

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
	 * Maximum explicit fleet distribution window in seconds.
	 */
	public const MAX_DISTRIBUTION_WINDOW_SECONDS = 86400;

	private const SCHEDULE_LOCK_PREFIX = 'datamachine_schedule_lock_';
	/**
	 * Durable, non-autoloaded tombstones keyed by schedule identity.
	 *
	 * They are intentionally retained after manual/delete transitions: a fetched
	 * running recurrence can repeat after its callback, so deleting its generation
	 * would remove the only resurrection fence. Retention is therefore unbounded in
	 * time, while storage is bounded to one non-autoloaded scalar per distinct
	 * schedule signature rather than one row per execution. Do not bulk-delete these
	 * options without first proving no pending, running, or fetched action for the
	 * signature can repeat.
	 */
	private const SCHEDULE_GENERATION_PREFIX   = 'datamachine_schedule_generation_';
	private const GENERATION_ARGUMENT_KEY      = '_datamachine_schedule_generation';
	private const SIGNATURE_ARGUMENT_COUNT_KEY = '_datamachine_signature_argument_count';
	private const SCHEDULE_LOCK_TTL            = 30;
	private const SCHEDULE_LOCK_RETRY_ATTEMPTS = 5;
	private const SCHEDULE_LOCK_RETRY_DELAY_US = 25000;

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
	 *     @type int|null    $distribution_window_seconds Explicit first-run distribution
	 *                                            window for fleet recovery. Capped at
	 *                                            24 hours. Ordinary scheduling ignores
	 *                                            this and retains the one-hour stagger.
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
		$lock = self::acquireScheduleLock( $hook, $args, (string) ( $options['group'] ?? self::GROUP ) );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return self::ensureScheduleUnlocked( $hook, $args, $interval, $options, $enabled, $lock );
		} finally {
			OptionLeaseStore::release( $lock['option_name'], $lock['token'] );
		}
	}

	/**
	 * Persist desired state and reconcile its schedule under one signature lease.
	 *
	 * The commit runs before any Action Scheduler mutation. The recorder runs after
	 * reconciliation, while the same lease is still held, and may persist drift or
	 * derived scheduling metadata.
	 *
	 * @param callable $commit Desired-state commit. Return true or WP_Error.
	 * @param callable $record Reconciliation recorder receiving array|WP_Error.
	 * @return array|\WP_Error
	 */
	public static function commitDesiredSchedule(
		string $hook,
		array $args,
		?string $interval,
		array $options,
		bool $enabled,
		callable $commit,
		callable $record
	) {
		$lock = self::acquireScheduleLock( $hook, $args, (string) ( $options['group'] ?? self::GROUP ) );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$lock['generation_argument_index'] = isset( $options['generation_argument_index'] )
				? max( count( $args ), (int) $options['generation_argument_index'] )
				: null;
			if ( ! empty( $options['legacy_adoption'] )
				&& self::getCoveringAction( $hook, self::scheduleActionArgs( $args, $lock ), (string) ( $options['group'] ?? self::GROUP ) ) ) {
				return self::error(
					'stale_legacy_schedule_generation',
					'A generated schedule already owns this flow; the legacy action is stale.',
					array( 'status' => 409 )
				);
			}

			$committed = $commit();
			if ( is_wp_error( $committed ) ) {
				return $committed;
			}
			if ( true !== $committed ) {
				return self::retryableError( 'schedule_state_commit_failed', 'Failed to persist desired schedule state.' );
			}

			$result   = self::ensureScheduleUnlocked( $hook, $args, $interval, $options, $enabled, $lock );
			$recorded = $record( $result );
			if ( is_wp_error( $recorded ) ) {
				return $recorded;
			}

			return $result;
		} finally {
			OptionLeaseStore::release( $lock['option_name'], $lock['token'] );
		}
	}

	/**
	 * Reconcile one schedule while its signature lease is held.
	 *
	 * @param string      $hook     Action Scheduler hook.
	 * @param array       $args     Action arguments.
	 * @param string|null $interval Requested interval.
	 * @param array       $options  Scheduling options.
	 * @param bool        $enabled  Whether the schedule is enabled.
	 * @param array       $lock     Owned schedule lease.
	 * @return array|\WP_Error Schedule metadata or an error.
	 */
	private static function ensureScheduleUnlocked(
		string $hook,
		array $args,
		?string $interval,
		array $options,
		bool $enabled,
		array $lock
	) {
		$group                             = $options['group'] ?? self::GROUP;
		$lock['generation_argument_index'] = isset( $options['generation_argument_index'] )
			? max( count( $args ), (int) $options['generation_argument_index'] )
			: null;
		$schedule_args                     = self::scheduleActionArgs( $args, $lock );

		// Disabled / manual / null → unschedule and return.
		if ( ! $enabled || null === $interval || 'manual' === $interval ) {
			$lock = self::beginScheduleMutation( $lock );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
			$unscheduled = self::mutateUnschedule( $lock, $hook, $args, $group );
			if ( is_wp_error( $unscheduled ) ) {
				return $unscheduled;
			}
			return array(
				'interval'  => 'manual',
				'scheduled' => false,
			);
		}

		// One-time scheduling.
		if ( 'one_time' === $interval ) {
			$timestamp = $options['timestamp'] ?? null;
			if ( ! $timestamp ) {
				return self::error(
					'missing_timestamp',
					'Timestamp required for one-time scheduling',
					array( 'status' => 400 )
				);
			}

			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				return self::retryableError(
					'scheduler_unavailable',
					'Action Scheduler not available'
				);
			}

			// Datastore-readiness guard. If we cannot confirm AS is ready to
			// answer queries, do NOT proceed to unschedule()+schedule(): the
			// unschedule would be a no-op while the schedule later succeeds,
			// creating a duplicate chain. Bail so the caller retries later.
			if ( ! self::isActionSchedulerDataStoreReady() ) {
				return self::retryableError(
					'datastore_not_ready',
					'Action Scheduler datastore not ready; deferring schedule to avoid creating a duplicate chain.'
				);
			}

			if ( empty( $options['force_reschedule'] ) && self::hasMatchingSingleAction( $hook, $schedule_args, $group, (int) $timestamp ) ) {
				return array(
					'interval'       => 'one_time',
					'scheduled'      => true,
					'timestamp'      => $timestamp,
					'scheduled_time' => wp_date( 'c', $timestamp ),
					'preserved'      => true,
				);
			}

			$lock = self::beginScheduleMutation( $lock );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
			$unscheduled = self::mutateUnschedule( $lock, $hook, $args, $group );
			if ( is_wp_error( $unscheduled ) ) {
				return $unscheduled;
			}

			$action_id = self::mutateScheduleSingle( $lock, (int) $timestamp, $hook, $args, $group );
			if ( is_wp_error( $action_id ) ) {
				return $action_id;
			}

			return array(
				'interval'       => 'one_time',
				'scheduled'      => true,
				'timestamp'      => $timestamp,
				'scheduled_time' => wp_date( 'c', $timestamp ),
				'action_id'      => $action_id,
			);
		}

		// Explicit cron with cron_expression option.
		if ( 'cron' === $interval ) {
			$cron_expression = $options['cron_expression'] ?? null;
			if ( empty( $cron_expression ) ) {
				return self::error(
					'missing_cron_expression',
					'cron_expression required when interval is cron',
					array( 'status' => 400 )
				);
			}
			return self::scheduleCron( $hook, $args, $cron_expression, $group, $options, $lock );
		}

		// Auto-detect cron expression passed as the interval value.
		if ( self::looksLikeCronExpression( $interval ) ) {
			return self::scheduleCron( $hook, $args, $interval, $group, $options, $lock );
		}

		// Recurring by interval key (with alias resolution).
		$resolved_interval = self::resolveIntervalAlias( $interval );
		$intervals         = apply_filters( 'datamachine_scheduler_intervals', array() );
		$interval_seconds  = $intervals[ $resolved_interval ]['seconds'] ?? null;

		if ( ! $interval_seconds ) {
			return self::error(
				'invalid_interval',
				"Invalid interval: {$interval}",
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return self::retryableError(
				'scheduler_unavailable',
				'Action Scheduler not available'
			);
		}

		// Datastore-readiness guard. If we cannot confirm AS is ready to answer
		// queries, do NOT proceed to unschedule()+schedule(): the unschedule
		// would be a no-op (AS not ready) while as_schedule_recurring_action
		// later succeeds in the same request — creating a SECOND parallel
		// recurring chain. Bail so the caller retries when AS is ready.
		if ( ! self::isActionSchedulerDataStoreReady() ) {
			return self::retryableError(
				'datastore_not_ready',
				'Action Scheduler datastore not ready; deferring schedule to avoid creating a duplicate chain.'
			);
		}

		// Determine first-run timestamp. Caller override wins (e.g. "tomorrow
		// midnight UTC" for daily memory). Otherwise compute from stagger seed.
		if ( isset( $options['first_run_timestamp'] ) ) {
			$first_run_time = (int) $options['first_run_timestamp'];
		} else {
			$stagger_seed        = (int) ( $options['stagger_seed'] ?? 0 );
			$distribution_window = min(
				self::MAX_DISTRIBUTION_WINDOW_SECONDS,
				max( 0, (int) ( $options['distribution_window_seconds'] ?? 0 ) )
			);
			if ( $stagger_seed > 0 && $distribution_window > 0 ) {
				$stagger_offset = self::calculateDistributionOffset( $stagger_seed, $distribution_window );
			} else {
				$stagger_offset = $stagger_seed > 0
					? self::calculateStaggerOffset( $stagger_seed, (int) $interval_seconds )
					: 0;
			}
			$first_run_time = time() + $stagger_offset;
		}
		// Action Scheduler unique actions are keyed by hook and group, not args.
		// Preserve independent schedules that share a hook but have distinct args.
		$unique = empty( $args );

		if ( empty( $options['force_reschedule'] ) && self::hasMatchingRecurringAction( $hook, $schedule_args, $group, (int) $interval_seconds ) ) {
			// Self-healing dedup: if a previous call already created MORE THAN
			// ONE matching pending chain for this signature, collapse them to a
			// single chain now instead of preserving the duplicates.
			if ( self::countPendingActionsForSignature( $hook, $args, $schedule_args, $group, $lock ) > 1 ) {
				$lock = self::beginScheduleMutation( $lock );
				if ( is_wp_error( $lock ) ) {
					return $lock;
				}
				$unscheduled = self::mutateUnschedule( $lock, $hook, $args, $group );
				if ( is_wp_error( $unscheduled ) ) {
					return $unscheduled;
				}
				$scheduled = self::mutateScheduleRecurring( $lock, $first_run_time, (int) $interval_seconds, $hook, $args, $group, $unique );
				if ( is_wp_error( $scheduled ) ) {
					return $scheduled;
				}

				if ( ! self::isExactScheduled( $hook, self::scheduleActionArgs( $args, $lock ), $group ) ) {
					return self::retryableError(
						'schedule_not_persisted',
						'Action Scheduler accepted the schedule but no pending action was found. AS tables may not be ready.'
					);
				}

				return array(
					'interval'         => $resolved_interval,
					'scheduled'        => true,
					'interval_seconds' => (int) $interval_seconds,
					'first_run'        => wp_date( 'c', $first_run_time ),
					'deduplicated'     => true,
				);
			}

			return array(
				'interval'         => $resolved_interval,
				'scheduled'        => true,
				'interval_seconds' => (int) $interval_seconds,
				'first_run'        => wp_date( 'c', $first_run_time ),
				'preserved'        => true,
			);
		}

		$lock = self::beginScheduleMutation( $lock );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$unscheduled = self::mutateUnschedule( $lock, $hook, $args, $group );
		if ( is_wp_error( $unscheduled ) ) {
			return $unscheduled;
		}

		$scheduled = self::mutateScheduleRecurring( $lock, $first_run_time, (int) $interval_seconds, $hook, $args, $group, $unique );
		if ( is_wp_error( $scheduled ) ) {
			return $scheduled;
		}

		// Verify persistence. AS can silently drop actions when its tables
		// aren't ready (e.g. CLI context during plugin activation).
		if ( ! self::isExactScheduled( $hook, self::scheduleActionArgs( $args, $lock ), $group ) ) {
			return self::retryableError(
				'schedule_not_persisted',
				'Action Scheduler accepted the schedule but no pending action was found. AS tables may not be ready.'
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
	 * Register the fetched-action generation fence.
	 */
	public static function registerGenerationFence(): void {
		add_filter( 'action_scheduler_stored_action_instance', array( self::class, 'fenceStoredAction' ), 10, 6 );
		add_action( 'action_scheduler_before_execute', array( self::class, 'clearExecutedRecurrenceGenerations' ), 0, 1 );
		add_action( 'action_scheduler_after_execute', array( self::class, 'captureExecutedRecurrenceGeneration' ), PHP_INT_MAX, 2 );
		add_action( 'action_scheduler_stored_action', array( self::class, 'reconcileStoredRecurrenceSuccessor' ), PHP_INT_MAX );
	}

	public static function clearExecutedRecurrenceGenerations( int $action_id = 0 ): void {
		self::$executed_recurrence_generations = array();
		self::$executing_action_id             = $action_id > 0 ? $action_id : null;
		self::$executing_recurring_action      = false;
		if ( $action_id <= 0 || ! class_exists( '\\ActionScheduler_Store' ) ) {
			return;
		}

		try {
			$action                           = \ActionScheduler_Store::instance()->fetch_action( $action_id );
			self::$executing_recurring_action = is_object( $action )
				&& method_exists( $action, 'get_schedule' )
				&& $action->get_schedule()->is_recurring();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	public static function isExecutingRecurringAction(): bool {
		return null !== self::$executing_action_id && self::$executing_recurring_action;
	}

	/**
	 * Capture the generation that native Action Scheduler repeat() is about to clone.
	 */
	public static function captureExecutedRecurrenceGeneration( int $action_id, $action ): void {
		unset( $action_id );
		self::$executing_action_id        = null;
		self::$executing_recurring_action = false;
		if ( ! $action instanceof GenerationFencedAction || ! $action->get_schedule()->is_recurring() ) {
			return;
		}

		$key = self::scheduleSignatureKey( $action->get_hook(), $action->get_args(), $action->get_group() );
		if ( null === $key ) {
			return;
		}

		self::$executed_recurrence_generations[ $key ] = array(
			'generation_option'   => $action->getGenerationOption(),
			'expected_generation' => $action->getExpectedGeneration(),
			'legacy'              => null === self::generationFromActionArgs( $action->get_args() ),
		);
	}

	/**
	 * Cancel a successor stored from a stale schedule cached inside repeat().
	 */
	public static function reconcileStoredRecurrenceSuccessor( int $action_id ): void {
		if ( empty( self::$executed_recurrence_generations ) ) {
			return;
		}

		try {
			$action = \ActionScheduler_Store::instance()->fetch_action( $action_id );
		} catch ( \Throwable $exception ) {
			return;
		}

		$key = self::scheduleSignatureKey( $action->get_hook(), $action->get_args(), $action->get_group() );
		if ( null === $key || ! isset( self::$executed_recurrence_generations[ $key ] ) ) {
			return;
		}

		$context = self::$executed_recurrence_generations[ $key ];
		unset( self::$executed_recurrence_generations[ $key ] );
		$current = get_option( $context['generation_option'], '' );
		if ( empty( $context['legacy'] ) && is_string( $current ) && hash_equals( $context['expected_generation'], $current ) ) {
			return;
		}

		self::cancelExactAction( $action_id );
	}

	/**
	 * Wrap a managed fetched recurrence with its current generation snapshot.
	 *
	 * @param object $action   Stored Action Scheduler action.
	 * @param string $hook     Action hook.
	 * @param array  $args     Action arguments.
	 * @param object $schedule Action Scheduler schedule.
	 * @param string $group    Action group.
	 * @param int    $priority Action priority.
	 * @return object Original or generation-fenced action.
	 */
	public static function fenceStoredAction( $action, string $hook, array $args, $schedule, string $group, int $priority ) {
		if ( $action instanceof GenerationFencedAction
			|| ! $schedule instanceof \ActionScheduler_Schedule
			|| ! $schedule->is_recurring()
		) {
			return $action;
		}

		$logical_args = self::logicalArgsFromActionArgs( $args );
		$options      = self::scheduleOptionNames( $hook, $logical_args, $group );
		if ( is_wp_error( $options ) ) {
			return $action;
		}

		$generation = self::generationFromActionArgs( $args );
		if ( null === $generation ) {
			$generation = get_option( $options['generation_option'], false );
		}
		if ( false === $generation && self::GROUP === $group ) {
			$generation = wp_generate_uuid4();
			if ( ! add_option( $options['generation_option'], $generation, '', false ) ) {
				$generation = get_option( $options['generation_option'], false );
			}
		}

		if ( ! is_string( $generation ) || '' === $generation ) {
			return $action;
		}

		$fenced = new GenerationFencedAction( $hook, $args, $schedule, $group, $options['generation_option'], $generation );
		$fenced->set_priority( $priority );
		return $fenced;
	}

	/**
	 * Acquire the option-row lease for one hook/args/group signature.
	 *
	 * @param string $hook  Action Scheduler hook.
	 * @param array  $args  Action arguments.
	 * @param string $group Action Scheduler group.
	 * @return array{option_name:string,generation_option:string,token:string,lease_payload:array<string,mixed>}|\WP_Error
	 */
	private static function acquireScheduleLock( string $hook, array $args, string $group ) {
		$options = self::scheduleOptionNames( $hook, $args, $group );
		if ( is_wp_error( $options ) ) {
			return $options;
		}

		$generation = get_option( $options['generation_option'], false );
		if ( false === $generation ) {
			for ( $attempt = 0; $attempt < self::SCHEDULE_LOCK_RETRY_ATTEMPTS && false === $generation; ++$attempt ) {
				$initial_generation = wp_generate_uuid4();
				if ( add_option( $options['generation_option'], $initial_generation, '', false ) ) {
					$generation = $initial_generation;
					break;
				}
				$generation = get_option( $options['generation_option'], false );
			}
		}
		if ( ! is_string( $generation ) || '' === $generation ) {
			return self::error( 'schedule_generation_unavailable', 'Unable to initialize schedule generation.', array( 'status' => 503 ) );
		}
		$option_name = $options['lock_option'];

		for ( $attempt = 0; $attempt < self::SCHEDULE_LOCK_RETRY_ATTEMPTS; ++$attempt ) {
			$now     = time();
			$token   = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'dm-schedule-', true );
			$payload = array(
				'token'      => $token,
				'started_at' => $now,
				'expires_at' => $now + self::SCHEDULE_LOCK_TTL,
			);
			$lease   = OptionLeaseStore::acquire( $option_name, $payload, self::SCHEDULE_LOCK_TTL, $now, null, true );

			if ( $lease['acquired'] ) {
				return array(
					'option_name'       => $option_name,
					'generation_option' => $options['generation_option'],
					'generation'        => $generation,
					'token'             => $token,
					'lease_payload'     => $payload,
				);
			}

			if ( $attempt + 1 < self::SCHEDULE_LOCK_RETRY_ATTEMPTS ) {
				usleep( self::SCHEDULE_LOCK_RETRY_DELAY_US );
			}
		}

		return self::error(
			'schedule_lock_timeout',
			'Another request is updating this schedule; retry shortly.',
			array(
				'status'         => 409,
				'retryable'      => true,
				'retry_after_ms' => ( self::SCHEDULE_LOCK_RETRY_ATTEMPTS - 1 ) * (int) ( self::SCHEDULE_LOCK_RETRY_DELAY_US / 1000 ),
			)
		);
	}

	/**
	 * Resolve stable option names for one schedule signature.
	 *
	 * @return array{lock_option:string,generation_option:string}|\WP_Error
	 */
	private static function scheduleOptionNames( string $hook, array $args, string $group ) {
		$hash = self::scheduleSignatureKey( $hook, $args, $group );
		if ( null === $hash ) {
			return self::error( 'invalid_schedule_signature', 'Schedule arguments must be JSON-serializable.', array( 'status' => 400 ) );
		}

		return array(
			'lock_option'       => self::SCHEDULE_LOCK_PREFIX . $hash,
			'generation_option' => self::SCHEDULE_GENERATION_PREFIX . $hash,
		);
	}

	/**
	 * Invalidate only the cached generation value for a logical signature.
	 *
	 * Transaction rollback can remove an option row while leaving WordPress's
	 * option cache populated. Deleting the cache entry forces the compensation
	 * path to reread the database without risking deletion of a concurrent row.
	 */
	public static function invalidateGenerationCache( string $hook, array $args, string $group = self::GROUP ): bool {
		$options = self::scheduleOptionNames( $hook, $args, $group );
		if ( is_wp_error( $options ) ) {
			return false;
		}

		return wp_cache_delete( $options['generation_option'], 'options' );
	}

	/**
	 * Check whether a persisted action generation still owns its signature.
	 *
	 * Missing markers identify legacy actions and are deliberately not adopted
	 * here; the flow handler performs the bounded legacy transition.
	 */
	public static function isActionGenerationCurrent( string $hook, array $args, string $group, $generation_argument ): bool {
		$generation = self::generationFromArgument( $generation_argument );
		if ( null === $generation ) {
			return false;
		}

		$options = self::scheduleOptionNames( $hook, $args, $group );
		if ( is_wp_error( $options ) ) {
			return false;
		}

		$current = get_option( $options['generation_option'], '' );
		return is_string( $current ) && hash_equals( $generation, $current );
	}

	/**
	 * Build Action Scheduler args carrying the owned mutation generation.
	 */
	private static function scheduleActionArgs( array $args, array $lock ): array {
		if ( ! isset( $lock['generation_argument_index'] ) || null === $lock['generation_argument_index'] ) {
			return $args;
		}

		$logical_count = count( $args );
		$target_count  = (int) $lock['generation_argument_index'];
		for ( $argument_count = $logical_count; $argument_count < $target_count; ++$argument_count ) {
			$args[] = null;
		}
		$args[] = array(
			self::GENERATION_ARGUMENT_KEY      => (string) $lock['generation'],
			self::SIGNATURE_ARGUMENT_COUNT_KEY => $logical_count,
		);

		return $args;
	}

	private static function generationFromArgument( $argument ): ?string {
		if ( ! is_array( $argument ) || empty( $argument[ self::GENERATION_ARGUMENT_KEY ] ) || ! is_string( $argument[ self::GENERATION_ARGUMENT_KEY ] ) ) {
			return null;
		}

		return $argument[ self::GENERATION_ARGUMENT_KEY ];
	}

	private static function generationFromActionArgs( array $args ): ?string {
		return empty( $args ) ? null : self::generationFromArgument( end( $args ) );
	}

	private static function logicalArgsFromActionArgs( array $args ): array {
		if ( empty( $args ) ) {
			return $args;
		}

		$marker = end( $args );
		if ( null === self::generationFromArgument( $marker ) ) {
			return $args;
		}

		$count = isset( $marker[ self::SIGNATURE_ARGUMENT_COUNT_KEY ] ) ? (int) $marker[ self::SIGNATURE_ARGUMENT_COUNT_KEY ] : count( $args ) - 1;
		return array_slice( $args, 0, max( 0, $count ) );
	}

	private static function scheduleSignatureKey( string $hook, array $args, string $group ): ?string {
		$signature = wp_json_encode( array( $hook, $args, $group ) );
		return false === $signature ? null : md5( $signature );
	}

	/**
	 * Advance the persisted generation before the first schedule mutation.
	 *
	 * @param array $lock Owned lease, updated with the mutation generation.
	 * @return array|\WP_Error Updated lease or ownership error.
	 */
	private static function beginScheduleMutation( array $lock ) {
		$lease_payload = self::refreshScheduleLock( $lock );
		if ( false === $lease_payload ) {
			return self::lockLostError();
		}

		$current_generation = get_option( $lock['generation_option'], '' );
		$generation         = wp_generate_uuid4();
		if ( ! OptionLeaseStore::compareAndSwapWhileOwned(
			$lock['generation_option'],
			$current_generation,
			$generation,
			$lock['option_name'],
			$lease_payload
		) ) {
			return self::lockLostError();
		}
		$lock['generation']    = $generation;
		$lock['lease_payload'] = $lease_payload;

		return false !== self::refreshScheduleLock( $lock ) && self::isMutationGenerationCurrent( $lock )
			? $lock
			: self::lockLostError();
	}

	/**
	 * Refresh and verify ownership immediately before a destructive mutation.
	 */
	private static function assertMutationOwner( array $lock ): bool {
		return false !== self::refreshScheduleLock( $lock ) && self::isMutationGenerationCurrent( $lock );
	}

	/**
	 * @return array<string,mixed>|false
	 */
	private static function refreshScheduleLock( array $lock ) {
		return OptionLeaseStore::refreshOwned( $lock['option_name'], $lock['token'], self::SCHEDULE_LOCK_TTL );
	}

	private static function isMutationGenerationCurrent( array $lock ): bool {
		$current = get_option( $lock['generation_option'], '' );
		return isset( $lock['generation'] ) && is_string( $current ) && hash_equals( (string) $lock['generation'], $current );
	}

	private static function lockLostError(): \WP_Error {
		return self::error(
			'schedule_lock_lost',
			'Schedule ownership changed during reconciliation; retry without mutating the existing schedule.',
			array(
				'status'         => 409,
				'retryable'      => true,
				'retry_after_ms' => 100,
			)
		);
	}

	/**
	 * Unschedule pending actions only while the mutation generation is owned.
	 *
	 * @return true|\WP_Error
	 */
	private static function mutateUnschedule( array $lock, string $hook, array $args, string $group ) {
		if ( ! self::assertMutationOwner( $lock ) ) {
			return self::lockLostError();
		}
		$generation_aware = isset( $lock['generation_argument_index'] );
		$unscheduled      = self::rawUnschedule( $hook, $args, $group, $generation_aware );
		if ( is_wp_error( $unscheduled ) ) {
			return $unscheduled;
		}
		if ( ! self::assertMutationOwner( $lock ) ) {
			return self::lockLostError();
		}
		return true;
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function mutateScheduleSingle( array $lock, int $timestamp, string $hook, array $args, string $group ) {
		if ( ! self::assertMutationOwner( $lock ) ) {
			return self::lockLostError();
		}
		$action_id = as_schedule_single_action( $timestamp, $hook, self::scheduleActionArgs( $args, $lock ), $group );
		return self::assertMutationOwner( $lock ) ? $action_id : self::cleanupAfterLostScheduleOwnership( (int) $action_id );
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function mutateScheduleRecurring( array $lock, int $timestamp, int $interval, string $hook, array $args, string $group, bool $unique ) {
		if ( ! self::assertMutationOwner( $lock ) ) {
			return self::lockLostError();
		}
		$action_id = as_schedule_recurring_action( $timestamp, $interval, $hook, self::scheduleActionArgs( $args, $lock ), $group, $unique );
		return self::assertMutationOwner( $lock ) ? $action_id : self::cleanupAfterLostScheduleOwnership( (int) $action_id );
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function mutateScheduleCron( array $lock, int $timestamp, string $expression, string $hook, array $args, string $group, bool $unique ) {
		if ( ! self::assertMutationOwner( $lock ) ) {
			return self::lockLostError();
		}
		$action_id = as_schedule_cron_action( $timestamp, $expression, $hook, self::scheduleActionArgs( $args, $lock ), $group, $unique );
		return self::assertMutationOwner( $lock ) ? $action_id : self::cleanupAfterLostScheduleOwnership( (int) $action_id );
	}

	/**
	 * Cancel only the action returned by a store call after ownership was lost.
	 */
	private static function cleanupAfterLostScheduleOwnership( int $action_id ): \WP_Error {
		if ( $action_id > 0 && self::cancelExactAction( $action_id ) ) {
			return self::lockLostError();
		}

		return self::error(
			'schedule_exact_cleanup_failed',
			'Schedule ownership changed during persistence and the exact stale action could not be canceled.',
			array(
				'status'         => 503,
				'retryable'      => true,
				'retry_after_ms' => 250,
				'action_id'      => $action_id,
			)
		);
	}

	private static function cancelExactAction( int $action_id ): bool {
		if ( $action_id <= 0 || ! class_exists( '\\ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			$store = \ActionScheduler_Store::instance();
			$store->cancel_action( $action_id );
			return \ActionScheduler_Store::STATUS_CANCELED === $store->get_status( $action_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Preserve the public unschedule API while routing through the fenced path.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args (signature).
	 * @param string $group AS group.
	 * @return array|\WP_Error Observable reconciliation result. Existing callers
	 *                         may continue ignoring the return value.
	 */
	public static function unschedule( string $hook, array $args, string $group = self::GROUP, array $options = array() ) {
		$options['group'] = $group;
		return self::ensureSchedule( $hook, $args, 'manual', $options );
	}

	/**
	 * Unschedule pending actions after ownership has already been fenced.
	 */
	private static function rawUnschedule( string $hook, array $args, string $group, bool $generation_aware = false ) {
		if ( ! $generation_aware ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, $args, $group );
			}
			return true;
		}

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\\ActionScheduler_Store' ) ) {
			return self::retryableError( 'scheduler_unavailable', 'Action Scheduler exact cleanup is unavailable.' );
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => $group,
				'status'   => 'pending',
				'per_page' => -1,
			),
			'OBJECT'
		);
		foreach ( $actions as $action_id => $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || self::logicalArgsFromActionArgs( $action->get_args() ) !== $args ) {
				continue;
			}
			if ( ! self::cancelExactAction( (int) $action_id ) ) {
				return self::error(
					'schedule_exact_cleanup_failed',
					'Unable to cancel an exact superseded schedule action.',
					array(
						'status'         => 503,
						'retryable'      => true,
						'retry_after_ms' => 250,
						'action_id'      => (int) $action_id,
					)
				);
			}
		}

		return true;
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
		return self::isExactScheduled( $hook, $args, $group );
	}

	private static function isExactScheduled( string $hook, array $args, string $group ): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		if ( ! self::isActionSchedulerDataStoreReady() ) {
			return false;
		}

		return false !== as_next_scheduled_action( $hook, $args, $group );
	}

	/**
	 * Check whether a pending or in-progress action covers a schedule slot.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args (signature).
	 * @param string $group AS group.
	 * @return bool True when matching work is pending or currently running.
	 */
	public static function hasCoverage( string $hook, array $args, string $group = self::GROUP ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! self::isReady() ) {
			return false;
		}

		foreach ( array( 'pending', 'in-progress' ) as $status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => $group,
					'status'   => $status,
					'per_page' => 1,
				),
				'ids'
			);

			if ( ! empty( $actions ) ) {
				return true;
			}
		}

		return self::hasLogicalCoverage( $hook, $args, $group );
	}

	/**
	 * Check coverage for a logical signature across legacy and generated args.
	 */
	public static function hasLogicalCoverage( string $hook, array $args, string $group = self::GROUP, bool $generated_only = false ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! self::isReady() ) {
			return false;
		}

		foreach ( array( 'pending', 'in-progress' ) as $status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'group'    => $group,
					'status'   => $status,
					'per_page' => -1,
				),
				'OBJECT'
			);
			foreach ( $actions as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}
				$action_args = $action->get_args();
				if ( self::logicalArgsFromActionArgs( $action_args ) === $args
					&& ( ! $generated_only || null !== self::generationFromActionArgs( $action_args ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Find the next pending timestamp across legacy and generated identities.
	 *
	 * @return int|false
	 */
	public static function nextLogicalScheduledAction( string $hook, array $args, string $group = self::GROUP ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! self::isReady() ) {
			return false;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => $group,
				'status'   => 'pending',
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => -1,
			),
			'OBJECT'
		);
		foreach ( $actions as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || self::logicalArgsFromActionArgs( $action->get_args() ) !== $args ) {
				continue;
			}
			$date = $action->get_schedule()->get_date();
			return $date ? $date->getTimestamp() : false;
		}

		return false;
	}

	/**
	 * Check whether Action Scheduler can safely answer datastore queries.
	 *
	 * @return bool True when Action Scheduler is initialized.
	 */
	public static function isReady(): bool {
		return self::isActionSchedulerDataStoreReady();
	}

	/**
	 * Normalize scheduler error metadata for ability and cleanup callers.
	 */
	public static function errorMetadata( \WP_Error $error ): array {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		return array(
			'error'          => $error->get_error_message(),
			'error_code'     => $error->get_error_code(),
			'status'         => (int) ( $data['status'] ?? 500 ),
			'retryable'      => (bool) ( $data['retryable'] ?? false ),
			'retry_after_ms' => (int) ( $data['retry_after_ms'] ?? 0 ),
		);
	}

	/**
	 * Check whether Action Scheduler's datastore is ready for procedural API reads.
	 *
	 * @return bool True when Action Scheduler can safely query scheduled actions.
	 */
	private static function isActionSchedulerDataStoreReady(): bool {
		if ( function_exists( 'did_action' ) && 0 === did_action( 'action_scheduler_init' ) ) {
			return false;
		}

		if ( ! class_exists( '\ActionScheduler' ) || ! method_exists( '\ActionScheduler', 'is_initialized' ) ) {
			return true;
		}

		return \ActionScheduler::is_initialized();
	}

	/**
	 * Create a WP_Error with status and retry metadata when supplied.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $data    Optional error data.
	 * @return \WP_Error Error object.
	 */
	private static function error( string $code, string $message, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, $data );
	}

	private static function retryableError( string $code, string $message, int $status = 503 ): \WP_Error {
		return self::error(
			$code,
			$message,
			array(
				'status'         => $status,
				'retryable'      => true,
				'retry_after_ms' => 250,
			)
		);
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
		return self::getActionByStatuses( $hook, $args, $group, array( 'pending' ) );
	}

	/**
	 * Find the next pending or running action that owns a schedule slot.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args.
	 * @param string $group AS group.
	 * @return object|null ActionScheduler_Action-like object, or null.
	 */
	private static function getCoveringAction( string $hook, array $args, string $group ): ?object {
		return self::getActionByStatuses( $hook, $args, $group, array( 'pending', 'in-progress' ) );
	}

	/**
	 * Find the next action matching one of the supplied statuses.
	 *
	 * @param string   $hook     Hook name.
	 * @param array    $args     Action args.
	 * @param string   $group    AS group.
	 * @param string[] $statuses Action Scheduler statuses in priority order.
	 * @return object|null ActionScheduler_Action-like object, or null.
	 */
	private static function getActionByStatuses( string $hook, array $args, string $group, array $statuses ): ?object {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		if ( ! self::isActionSchedulerDataStoreReady() ) {
			return null;
		}

		foreach ( $statuses as $status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => $group,
					'status'   => $status,
					'orderby'  => 'date',
					'order'    => 'ASC',
					'per_page' => 1,
				),
				'OBJECT'
			);

			$action = reset( $actions );
			if ( is_object( $action ) ) {
				return $action;
			}
		}

		return null;
	}

	/**
	 * Count ALL pending Action Scheduler actions matching (hook, args, group).
	 *
	 * Used to detect duplicate recurring/cron chains for the same signature so
	 * ensureSchedule can self-heal (collapse N>1 chains to exactly one) even on
	 * the preserve fast path. Unlike getPendingAction() (per_page=1), this asks
	 * for more than one row so duplicates are visible.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args.
	 * @param string $group AS group.
	 * @return int Number of matching pending actions (0 when AS is not ready).
	 */
	private static function countMatchingPendingActions( string $hook, array $args, string $group ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		if ( ! self::isActionSchedulerDataStoreReady() ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => 'pending',
				'per_page' => 100,
			),
			'ids'
		);

		return is_array( $actions ) ? count( $actions ) : 0;
	}

	private static function countPendingActionsForSignature( string $hook, array $logical_args, array $schedule_args, string $group, array $lock ): int {
		if ( null === ( $lock['generation_argument_index'] ?? null ) ) {
			return self::countMatchingPendingActions( $hook, $schedule_args, $group );
		}
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! self::isActionSchedulerDataStoreReady() ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => $group,
				'status'   => 'pending',
				'per_page' => -1,
			),
			'OBJECT'
		);
		$count   = 0;
		foreach ( $actions as $action ) {
			if ( is_object( $action ) && method_exists( $action, 'get_args' ) && self::logicalArgsFromActionArgs( $action->get_args() ) === $logical_args ) {
				++$count;
			}
		}

		return $count;
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
		$action = self::getCoveringAction( $hook, $args, $group );
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
		$action = self::getCoveringAction( $hook, $args, $group );
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
		$action = self::getCoveringAction( $hook, $args, $group );
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
		return absint( crc32( 'datamachine_stagger_' . $seed ) ) % $max_offset;
	}

	/**
	 * Calculate an offset inside an explicit fleet distribution window.
	 *
	 * Unlike ordinary staggering, this is not bounded by recurrence interval;
	 * callers opt into it only when recovering a fleet of missing schedules.
	 *
	 * @param int $seed           Stable schedule seed.
	 * @param int $window_seconds Requested distribution window in seconds.
	 * @return int Offset from zero up to the capped window.
	 */
	public static function calculateDistributionOffset( int $seed, int $window_seconds ): int {
		$window_seconds = min( self::MAX_DISTRIBUTION_WINDOW_SECONDS, max( 0, $window_seconds ) );
		if ( $window_seconds <= 0 ) {
			return 0;
		}

		return absint( crc32( 'datamachine_distribution_' . $seed ) ) % $window_seconds;
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
	 * @param array  $options         Scheduling options.
	 * @param array  $lock            Owned schedule lease.
	 * @return array{interval:'cron',scheduled:true,cron_expression:string,first_run?:string|null,action_id?:int,preserved?:bool}|\WP_Error
	 */
	private static function scheduleCron( string $hook, array $args, string $cron_expression, string $group, array $options, array $lock ) {
		$force_reschedule = ! empty( $options['force_reschedule'] );
		$schedule_args    = self::scheduleActionArgs( $args, $lock );
		if ( ! self::isValidCronExpression( $cron_expression ) ) {
			return self::error(
				'invalid_cron_expression',
				sprintf( 'Invalid cron expression: "%s"', $cron_expression ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'as_schedule_cron_action' ) ) {
			return self::retryableError(
				'scheduler_unavailable',
				'Action Scheduler not available'
			);
		}

		// Datastore-readiness guard — same asymmetry as the recurring/one_time
		// branches: a not-ready unschedule is a no-op but the schedule succeeds,
		// duplicating the chain. Bail and let the caller retry when AS is ready.
		if ( ! self::isActionSchedulerDataStoreReady() ) {
			return self::retryableError(
				'datastore_not_ready',
				'Action Scheduler datastore not ready; deferring cron schedule to avoid creating a duplicate chain.'
			);
		}

		// Action Scheduler unique actions are keyed by hook and group, not args.
		// Preserve independent schedules that share a hook but have distinct args.
		$unique = empty( $args );

		if ( ! $force_reschedule && self::hasMatchingCronAction( $hook, $schedule_args, $group, $cron_expression ) ) {
			// Self-healing dedup: collapse an already-duplicated signature to a
			// single chain instead of preserving the duplicates.
			if ( self::countPendingActionsForSignature( $hook, $args, $schedule_args, $group, $lock ) > 1 ) {
				$lock = self::beginScheduleMutation( $lock );
				if ( is_wp_error( $lock ) ) {
					return $lock;
				}
				$unscheduled = self::mutateUnschedule( $lock, $hook, $args, $group );
				if ( is_wp_error( $unscheduled ) ) {
					return $unscheduled;
				}
				$action_id = self::mutateScheduleCron( $lock, self::cronStartTimestamp( $options ), $cron_expression, $hook, $args, $group, $unique );
				if ( is_wp_error( $action_id ) ) {
					return $action_id;
				}

				if ( ! self::isExactScheduled( $hook, self::scheduleActionArgs( $args, $lock ), $group ) ) {
					return self::retryableError(
						'schedule_not_persisted',
						'Action Scheduler accepted the cron schedule but no pending action was found.'
					);
				}

				return array(
					'interval'        => 'cron',
					'scheduled'       => true,
					'cron_expression' => $cron_expression,
					'action_id'       => $action_id,
					'deduplicated'    => true,
				);
			}

			return array(
				'interval'        => 'cron',
				'scheduled'       => true,
				'cron_expression' => $cron_expression,
				'preserved'       => true,
			);
		}

		$lock = self::beginScheduleMutation( $lock );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$unscheduled = self::mutateUnschedule( $lock, $hook, $args, $group );
		if ( is_wp_error( $unscheduled ) ) {
			return $unscheduled;
		}

		$action_id = self::mutateScheduleCron( $lock, self::cronStartTimestamp( $options ), $cron_expression, $hook, $args, $group, $unique );
		if ( is_wp_error( $action_id ) ) {
			return $action_id;
		}

		if ( ! self::isExactScheduled( $hook, self::scheduleActionArgs( $args, $lock ), $group ) ) {
			return self::retryableError(
				'schedule_not_persisted',
				'Action Scheduler accepted the cron schedule but no pending action was found.'
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

	/**
	 * Resolve a cron chain's initial timing without changing ordinary behavior.
	 *
	 * @param array $options Scheduling options.
	 * @return int Cron schedule start timestamp.
	 */
	private static function cronStartTimestamp( array $options ): int {
		if ( isset( $options['first_run_timestamp'] ) ) {
			return (int) $options['first_run_timestamp'];
		}

		$seed   = (int) ( $options['stagger_seed'] ?? 0 );
		$window = (int) ( $options['distribution_window_seconds'] ?? 0 );
		if ( $seed > 0 && $window > 0 ) {
			return time() + self::calculateDistributionOffset( $seed, $window );
		}

		return time();
	}
}
