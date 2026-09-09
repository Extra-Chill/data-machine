<?php
/**
 * Flow and system-task scheduling adapter on Agents API Routines.
 *
 * Replaces the bespoke RecurringScheduler stack: every scheduled flow is a
 * routine (`flow-<id>`) whose wake target is the `datamachine/run-flow`
 * ability, and every built-in recurring system schedule is a routine
 * (`system-<schedule_id>`) targeting `datamachine/dispatch-system-task`.
 * One-time flow runs stay as plain Action Scheduler single actions under
 * `datamachine_run_flow_once` — routines are recurring by definition.
 *
 * Identity contract (old → new):
 *  - hook `datamachine_run_flow_now`, args `[flow_id, null, generation]`,
 *    group `data-machine`  →  hook `wp_agent_routine_run_scheduled`,
 *    args `['routine_id' => 'flow-N']`, group `agents-api`.
 *
 * `boot()` runs on every request that loads the full runtime: the routine
 * registry is in-memory by design, so persisted flows and schedules are
 * re-declared each boot. The {@see HashGatedRoutineBackend} decorator keeps
 * that cheap — registration only reaches Action Scheduler when the routine's
 * schedule fingerprint is new or changed.
 *
 * @package DataMachine\Engine\Scheduling
 * @since   1.0.0
 */

namespace DataMachine\Engine\Scheduling;

use AgentsAPI\AI\Routines\WP_Agent_Routine;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Engine\Tasks\RecurringScheduleRegistry;

defined( 'ABSPATH' ) || exit;

final class FlowRoutines {

	/**
	 * Action Scheduler hook the Agents API backend fires for routine wakes.
	 */
	public const ROUTINE_HOOK = 'wp_agent_routine_run_scheduled';

	/**
	 * Action Scheduler group the Agents API backend schedules under.
	 */
	public const ROUTINE_GROUP = 'agents-api';

	/**
	 * Legacy recurring hook; still a live execution path for queue
	 * backpressure deferrals and straggler actions until one release after
	 * this migration.
	 *
	 * TODO(3458): retire this hook's remaining uses one release after the
	 * routines migration ships.
	 */
	public const LEGACY_HOOK = 'datamachine_run_flow_now';

	/**
	 * Action Scheduler hook for one-time (timestamp) flow runs.
	 */
	public const ONE_TIME_HOOK = 'datamachine_run_flow_once';

	/**
	 * Data Machine's own Action Scheduler group (legacy actions + one_time).
	 */
	public const LEGACY_GROUP = 'data-machine';

	/**
	 * Option marking the one-shot legacy-action migration as done.
	 */
	public const MIGRATED_OPTION = 'datamachine_routines_migrated_v1';

	/**
	 * Wake target for flow routines.
	 */
	private const FLOW_ABILITY = 'datamachine/run-flow';

	/**
	 * Wake target for system-schedule routines.
	 */
	private const SYSTEM_DISPATCH_ABILITY = 'datamachine/dispatch-system-task';

	/**
	 * Upper bound for the direct Action Scheduler coverage query.
	 */
	private const MAX_COVERAGE_ROWS = 5000;

	/**
	 * Cached availability of the Agents API routines substrate.
	 */
	private static ?bool $available = null;

	/**
	 * Whether the backend decorator filter has been installed.
	 */
	private static bool $backend_installed = false;

	/**
	 * Whether the ability-permission filter has been installed.
	 */
	private static bool $permission_installed = false;

	/**
	 * Whether the unavailable-substrate warning has fired this request.
	 */
	private static bool $unavailable_logged = false;

	/**
	 * Whether the routines substrate (Agents API v0.11.0+) is loadable.
	 */
	public static function available(): bool {
		if ( null === self::$available ) {
			self::$available = class_exists( WP_Agent_Routine_Registry::class )
				&& interface_exists( '\AgentsAPI\AI\Routines\WP_Agent_Routine_Backend' );
		}

		return self::$available;
	}

	/**
	 * Log once per request when the substrate is missing, then skip.
	 */
	private static function log_unavailable(): void {
		if ( self::$unavailable_logged ) {
			return;
		}

		self::$unavailable_logged = true;
		do_action(
			'datamachine_log',
			'error',
			'Agents API routines substrate unavailable; flow scheduling skipped',
			array( 'error_code' => 'routines_substrate_missing' )
		);
	}

	/**
	 * The routine id for a flow.
	 *
	 * @param int $flow_id Flow ID.
	 */
	public static function routine_id( int $flow_id ): string {
		return 'flow-' . $flow_id;
	}

	/**
	 * The routine id for a built-in system schedule.
	 *
	 * @param string $schedule_id Schedule id from RecurringScheduleRegistry.
	 */
	public static function system_routine_id( string $schedule_id ): string {
		$id = 'system-' . $schedule_id;
		// Routine ids pass through sanitize_title() in the substrate; mirror
		// that here so lookups match registered ids.
		return function_exists( 'sanitize_title' ) ? sanitize_title( $id ) : $id;
	}

	/**
	 * Resolve an interval alias to seconds via the scheduler-intervals table.
	 *
	 * @param string|null $alias Interval key or alias; `manual`, `one_time`,
	 *                          `cron`, and unknown keys resolve to null.
	 */
	public static function interval_seconds( ?string $alias ): ?int {
		if ( null === $alias || '' === $alias ) {
			return null;
		}

		if ( in_array( $alias, array( 'manual', 'one_time', 'cron' ), true ) ) {
			return null;
		}

		if ( self::looks_like_cron_expression( $alias ) ) {
			return null;
		}

		$resolved  = function_exists( 'datamachine_resolve_interval_alias' )
			? datamachine_resolve_interval_alias( $alias )
			: $alias;
		$intervals = apply_filters( 'datamachine_scheduler_intervals', array() );
		$seconds   = (int) ( is_array( $intervals[ $resolved ] ?? null ) ? ( $intervals[ $resolved ]['seconds'] ?? 0 ) : 0 );

		return $seconds > 0 ? $seconds : null;
	}

	/**
	 * Strip scheduler-owned derived keys, leaving the portable desired config.
	 *
	 * Pure function; used by export surfaces.
	 *
	 * @param array<string, mixed> $config Scheduling configuration.
	 * @return array<string, mixed>
	 */
	public static function portable_desired_config( array $config ): array {
		foreach ( array( 'interval_seconds', 'first_run', 'scheduled_time', 'action_id', 'schedule_reconciliation', 'datamachine_last_suppressed_run' ) as $runtime_key ) {
			unset( $config[ $runtime_key ] );
		}

		return $config;
	}

	/**
	 * The single scheduling entry point for flows.
	 *
	 * Persists the desired scheduling config on the flow row, then applies it:
	 * unregisters the routine for manual/disabled flows, registers an interval
	 * or cron-expression routine otherwise, and schedules a one-shot action
	 * for `one_time` runs. Idempotent: an unchanged desired config with live
	 * coverage is a no-op so unrelated flow updates never reset timers.
	 *
	 * @param int                  $flow_id           Flow ID.
	 * @param array<string, mixed> $scheduling_config Desired scheduling config.
	 * @param bool                 $force             Skip the unchanged guard.
	 * @return true|\WP_Error True on success.
	 */
	public static function sync( int $flow_id, array $scheduling_config, bool $force = false ): bool|\WP_Error {
		$flows_db = new Flows();

		$flow = $flows_db->get_flow( $flow_id );
		if ( ! $flow ) {
			return new \WP_Error( 'flow_not_found', "Flow {$flow_id} not found", array( 'status' => 404 ) );
		}

		$current = $flow['scheduling_config'] ?? array();
		if ( is_string( $current ) ) {
			$current = json_decode( $current, true ) ?? array();
		}
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$interval = isset( $scheduling_config['interval'] ) && is_string( $scheduling_config['interval'] )
			? ( function_exists( 'datamachine_resolve_interval_alias' )
				? datamachine_resolve_interval_alias( $scheduling_config['interval'] )
				: $scheduling_config['interval'] )
			: null;
		$enabled  = false !== ( $scheduling_config['enabled'] ?? true );

		if ( ! $force && self::scheduling_unchanged( $current, $scheduling_config, $interval, $enabled, $flow_id ) ) {
			return true;
		}

		$desired             = self::portable_desired_config( $scheduling_config );
		$desired['interval'] = $interval ?? 'manual';
		if ( ! $enabled ) {
			$desired['enabled'] = false;
		}

		if ( ! $flows_db->update_flow_scheduling( $flow_id, $desired ) ) {
			return new \WP_Error(
				'schedule_persist_failed',
				"Desired schedule for flow {$flow_id} could not be persisted.",
				array( 'status' => 503, 'retryable' => true, 'retry_after_ms' => 250 )
			);
		}

		if ( ! self::available() ) {
			if ( $enabled && ! in_array( $desired['interval'], array( 'manual' ), true ) ) {
				self::log_unavailable();
				return new \WP_Error(
					'routines_unavailable',
					'Agents API routines substrate is unavailable; the schedule was persisted but not registered.',
					array( 'status' => 503, 'retryable' => true )
				);
			}

			return true;
		}

		$routine_id = self::routine_id( $flow_id );
		$flow_name  = is_string( $flow['flow_name'] ?? null ) ? (string) $flow['flow_name'] : '';
		$result     = true;

		if ( ! $enabled || 'manual' === $desired['interval'] ) {
			self::unregister_routine( $routine_id );
			self::cancel_one_time( $flow_id );
		} elseif ( 'one_time' === $desired['interval'] ) {
			self::unregister_routine( $routine_id );
			$result = self::schedule_one_time( $flow_id, $scheduling_config );
		} else {
			self::cancel_one_time( $flow_id );
			$args = self::flow_routine_args( $flow_id, $desired, $flow_name );
			if ( null === $args ) {
				$result = new \WP_Error(
					'invalid_schedule',
					sprintf( "Flow %d has an invalid recurring interval '%s'.", $flow_id, (string) $desired['interval'] ),
					array( 'status' => 400 )
				);
			} else {
				$result = WP_Agent_Routine_Registry::register( $routine_id, $args );
			}
		}

		HashGatedRoutineBackend::persist();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'cron' === $desired['interval'] && $enabled ) {
			do_action(
				'datamachine_log',
				'info',
				'Flow scheduled with cron expression',
				array(
					'flow_id'         => $flow_id,
					'cron_expression' => (string) ( $desired['cron_expression'] ?? '' ),
					'next_run'        => self::next_run( $flow_id ),
					'routine_id'      => $routine_id,
				)
			);
		}

		return true;
	}

	/**
	 * Cancel every schedule this adapter owns for a flow.
	 *
	 * @param int $flow_id Flow ID.
	 */
	public static function unschedule( int $flow_id ): void {
		if ( ! self::available() ) {
			return;
		}

		self::unregister_routine( self::routine_id( $flow_id ) );
		self::cancel_one_time( $flow_id );
		HashGatedRoutineBackend::persist();
	}

	/**
	 * Pause a flow's routine without losing the persisted schedule definition.
	 *
	 * @param int $flow_id Flow ID.
	 * @return true|\WP_Error
	 */
	public static function pause( int $flow_id ): bool|\WP_Error {
		$flows_db   = new Flows();
		$scheduling = $flows_db->get_flow_scheduling( $flow_id );
		if ( null === $scheduling ) {
			return new \WP_Error( 'flow_not_found', "Flow {$flow_id} not found", array( 'status' => 404 ) );
		}

		$scheduling['enabled'] = false;

		return self::sync( $flow_id, $scheduling, true );
	}

	/**
	 * Resume a paused flow by re-registering its persisted schedule.
	 *
	 * @param int $flow_id Flow ID.
	 * @return true|\WP_Error
	 */
	public static function resume( int $flow_id ): bool|\WP_Error {
		$flows_db   = new Flows();
		$scheduling = $flows_db->get_flow_scheduling( $flow_id );
		if ( null === $scheduling ) {
			return new \WP_Error( 'flow_not_found', "Flow {$flow_id} not found", array( 'status' => 404 ) );
		}

		unset( $scheduling['enabled'] );

		return self::sync( $flow_id, $scheduling, true );
	}

	/**
	 * Next scheduled run for one flow, as a UTC datetime string.
	 *
	 * @param int $flow_id Flow ID.
	 */
	public static function next_run( int $flow_id ): ?string {
		if ( $flow_id <= 0 || ! self::as_ready() ) {
			return null;
		}

		$timestamp = as_next_scheduled_action(
			self::ROUTINE_HOOK,
			array( 'routine_id' => self::routine_id( $flow_id ) ),
			self::ROUTINE_GROUP
		);

		if ( ! is_int( $timestamp ) || $timestamp <= 0 ) {
			$timestamp = as_next_scheduled_action(
				self::ONE_TIME_HOOK,
				array( 'flow_id' => $flow_id ),
				self::LEGACY_GROUP
			);
		}

		if ( ! is_int( $timestamp ) || $timestamp <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Bulk next-run resolution for list views: one query for the whole set.
	 *
	 * @param array<int, int> $flow_ids Flow IDs.
	 * @return array<int, string|null> Flow ID → UTC datetime, or null.
	 */
	public static function next_runs( array $flow_ids ): array {
		$result = array_fill_keys( array_map( 'intval', $flow_ids ), null );

		if ( array() === $flow_ids || ! self::as_ready() ) {
			return $result;
		}

		$direct = self::next_runs_direct( $result );
		if ( null !== $direct ) {
			return $direct;
		}

		// Portable fallback: exact-match lookups for the requested flows only.
		foreach ( array_keys( $result ) as $flow_id ) {
			$result[ $flow_id ] = self::next_run( $flow_id );
		}

		return $result;
	}

	/**
	 * Register every persisted flow and system schedule as a routine.
	 *
	 * Runs on `init` of every full-runtime request. Registration is cheap:
	 * the hash-gated backend stops unchanged routines from reaching Action
	 * Scheduler. Also performs the one-shot legacy-action migration.
	 */
	public static function boot(): void {
		if ( ! self::available() ) {
			self::log_unavailable();
			return;
		}

		self::install_backend();
		self::install_permission_filter();

		$flows_db = new Flows();
		foreach ( $flows_db->get_flow_schedules() as $row ) {
			$flow_id    = (int) ( $row['flow_id'] ?? 0 );
			$scheduling = $row['scheduling_config'] ?? array();
			if ( ! is_array( $scheduling ) ) {
				$scheduling = json_decode( (string) $scheduling, true ) ?? array();
			}

			if ( $flow_id <= 0 ) {
				continue;
			}

			$flow_name = is_string( $row['flow_name'] ?? null ) ? (string) $row['flow_name'] : '';
			$args      = self::flow_routine_args( $flow_id, $scheduling, $flow_name );
			if ( null === $args ) {
				continue;
			}

			$registered = WP_Agent_Routine_Registry::register( self::routine_id( $flow_id ), $args );
			if ( is_wp_error( $registered ) ) {
				do_action(
					'datamachine_log',
					'error',
					'Flow routine registration failed',
					array(
						'flow_id'    => $flow_id,
						'routine_id' => self::routine_id( $flow_id ),
						'error'      => $registered->get_error_message(),
					)
				);
			}
		}

		foreach ( RecurringScheduleRegistry::all() as $schedule ) {
			$schedule_id = (string) ( $schedule['schedule_id'] ?? '' );
			if ( '' === $schedule_id ) {
				continue;
			}

			if ( ! self::system_schedule_active( $schedule ) ) {
				self::unregister_routine( self::system_routine_id( $schedule_id ) );
				continue;
			}

			$args = self::system_routine_args( $schedule );
			if ( null === $args ) {
				continue;
			}

			$registered = WP_Agent_Routine_Registry::register( self::system_routine_id( $schedule_id ), $args );
			if ( is_wp_error( $registered ) ) {
				do_action(
					'datamachine_log',
					'error',
					'System schedule routine registration failed',
					array(
						'schedule_id' => $schedule_id,
						'routine_id'  => self::system_routine_id( $schedule_id ),
						'error'       => $registered->get_error_message(),
					)
				);
			}
		}

		self::maybe_migrate();
		HashGatedRoutineBackend::persist();
	}

	/**
	 * Reconcile the routine registry against the scheduling backend.
	 *
	 * Repairs missing routine coverage and cancels orphaned routine actions.
	 * Boot registration is forced first so the registry is never empty when
	 * the reconcile algorithm runs — an empty registry would classify every
	 * pending routine action as an orphan.
	 *
	 * @param bool $apply Repair missing coverage when true; dry-run otherwise.
	 * @return array<string, mixed> Reconciliation report.
	 */
	public static function reconcile( bool $apply = false ): array {
		if ( ! self::available() ) {
			self::log_unavailable();
			return array(
				'success' => false,
				'applied' => $apply,
				'covered' => 0,
				'missing' => 0,
				'removed' => 0,
				'errors'  => array( '_substrate' => 'Agents API routines substrate is unavailable.' ),
			);
		}

		self::boot();

		HashGatedRoutineBackend::set_verification_mode( true );
		try {
			$report = WP_Agent_Routine_Registry::reconcile( array( 'dry_run' => ! $apply ) );
		} finally {
			HashGatedRoutineBackend::set_verification_mode( false );
			HashGatedRoutineBackend::persist();
		}

		$enqueued = is_array( $report['enqueued'] ?? null ) ? $report['enqueued'] : array();
		$removed  = is_array( $report['removed'] ?? null ) ? $report['removed'] : array();
		$covered  = is_array( $report['unchanged'] ?? null ) ? $report['unchanged'] : array();
		$errors   = is_array( $report['errors'] ?? null ) ? $report['errors'] : array();

		return array(
			'success' => array() === $errors,
			'applied' => $apply,
			'covered' => count( $covered ),
			'missing' => count( $enqueued ),
			'removed' => count( $removed ),
			'routine_ids' => array(
				'missing' => $enqueued,
				'removed' => $removed,
			),
			'errors'  => $errors,
		);
	}

	/**
	 * Build the migration/cancellation plan for legacy generated actions.
	 *
	 * Legacy recurring chains live under `datamachine_run_flow_now` with the
	 * generated three-element args shape `[flow_id, null, {generation}]`.
	 * Single-argument actions (queue backpressure deferrals) are never
	 * touched — they are transient execution ticks, not recurring chains.
	 *
	 * @param bool $dry_run Report the plan without cancelling anything.
	 * @return array<string, mixed> Per-flow plan.
	 */
	public static function migrate_legacy_schedules( bool $dry_run = false ): array {
		$plan = array(
			'dry_run'         => $dry_run,
			'legacy_actions'  => 0,
			'cancelled'       => 0,
			'flows'           => array(),
			'details_truncated' => false,
			'errors'          => array(),
		);

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			$plan['errors']['_action_scheduler'] = 'Action Scheduler is unavailable.';
			return $plan;
		}

		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::LEGACY_HOOK,
				'group'    => self::LEGACY_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);

		$by_flow = array();
		foreach ( $ids as $id ) {
			$action_id = (int) $id;
			if ( $action_id <= 0 ) {
				continue;
			}

			try {
				$action = \ActionScheduler_Store::instance()->fetch_action( (string) $action_id );
			} catch ( \Throwable $error ) {
				unset( $error );
				continue;
			}

			$args    = $action->get_args();
			$flow_id = is_array( $args ) && isset( $args[0] ) ? (int) $args[0] : 0;

			if ( $flow_id <= 0 || ! is_array( $args ) || ! isset( $args[2] ) || ! is_array( $args[2] ) ) {
				// Not a generated recurring chain (e.g. a backpressure
				// deferral tick with args `[flow_id]`): leave it alone.
				continue;
			}

			$by_flow[ $flow_id ][] = $action_id;
		}

		$plan['legacy_actions'] = array_sum( array_map( 'count', $by_flow ) );

		foreach ( $by_flow as $flow_id => $action_ids ) {
			$entry = array(
				'flow_id'           => $flow_id,
				'routine_id'        => self::routine_id( $flow_id ),
				'legacy_action_ids' => $action_ids,
				'cancelled'         => false,
			);

			if ( ! $dry_run ) {
				foreach ( $action_ids as $action_id ) {
					try {
						\ActionScheduler_Store::instance()->cancel_action( (string) $action_id );
						++$plan['cancelled'];
					} catch ( \Throwable $error ) {
						unset( $error );
						$plan['errors'][ 'action_' . $action_id ] = 'Failed to cancel the legacy action.';
					}
				}

				$entry['cancelled'] = count( $action_ids ) === count(
					array_filter( $action_ids, static fn( $id ) => ! in_array( 'action_' . $id, array_keys( $plan['errors'] ), true ) )
				);
			}

			if ( count( $plan['flows'] ) < 2000 ) {
				$plan['flows'][] = $entry;
			} else {
				$plan['details_truncated'] = true;
			}
		}

		if ( ! $dry_run && array() === $plan['errors'] ) {
			update_option(
				self::MIGRATED_OPTION,
				array(
					'migrated_at'    => time(),
					'legacy_actions' => $plan['legacy_actions'],
					'flows'          => count( $plan['flows'] ),
				),
				false
			);
		}

		return $plan;
	}

	/**
	 * Detect whether a value looks like a cron expression.
	 *
	 * Cron expressions have 5-6 space-separated parts (minute hour day month
	 * weekday [year]) or start with @ (e.g. @daily).
	 *
	 * @param string $value Value to check.
	 */
	public static function looks_like_cron_expression( string $value ): bool {
		if ( str_starts_with( $value, '@' ) ) {
			return true;
		}

		$parts = preg_split( '/\s+/', trim( $value ) );
		if ( ! is_array( $parts ) || count( $parts ) < 5 || count( $parts ) > 6 ) {
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
	 * Validate a cron expression with Action Scheduler's bundled CronExpression.
	 *
	 * @param string $expression Cron expression.
	 */
	public static function is_valid_cron_expression( string $expression ): bool {
		if ( ! class_exists( 'CronExpression' ) ) {
			return false;
		}

		try {
			$cron = \CronExpression::factory( $expression );
			$cron->getNextRunDate();
			return true;
		} catch ( \Exception $error ) {
			unset( $error );
			return false;
		}
	}

	/**
	 * Human-readable description of a cron expression.
	 *
	 * @param string $expression Cron expression.
	 */
	public static function describe_cron_expression( string $expression ): string {
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
		} catch ( \Exception $error ) {
			unset( $error );
			return $expression;
		}
	}

	/**
	 * Extract the flow id from a flow routine id, or 0.
	 *
	 * @param string $routine_id Routine id.
	 */
	public static function flow_id_from_routine_id( string $routine_id ): int {
		if ( ! preg_match( '/^flow-(\d+)$/', $routine_id, $matches ) ) {
			return 0;
		}

		return (int) $matches[1];
	}

	/**
	 * Build registry args for a flow routine, or null when not schedulable.
	 *
	 * @param int                  $flow_id    Flow ID.
	 * @param array<string, mixed> $scheduling Scheduling config.
	 * @param string               $flow_name  Flow display name.
	 * @return array<string, mixed>|null
	 */
	private static function flow_routine_args( int $flow_id, array $scheduling, string $flow_name ): ?array {
		if ( false === ( $scheduling['enabled'] ?? true ) ) {
			return null;
		}

		$interval = (string) ( $scheduling['interval'] ?? 'manual' );
		if ( in_array( $interval, array( 'manual', 'one_time' ), true ) ) {
			return null;
		}

		$args = array(
			'ability' => self::FLOW_ABILITY,
			'input'   => array(
				'flow_id'        => $flow_id,
				'respect_paused' => true,
			),
			'label'   => '' !== $flow_name ? $flow_name : self::routine_id( $flow_id ),
		);

		if ( 'cron' === $interval || self::looks_like_cron_expression( $interval ) ) {
			$expression = 'cron' === $interval ? (string) ( $scheduling['cron_expression'] ?? '' ) : $interval;
			if ( '' === $expression || ! self::is_valid_cron_expression( $expression ) ) {
				return null;
			}

			$args['expression'] = $expression;
			$args['stagger']    = false;
			return $args;
		}

		$seconds = self::interval_seconds( $interval );
		if ( null === $seconds ) {
			return null;
		}

		$args['interval'] = $seconds;
		$args['stagger']  = true;
		return $args;
	}

	/**
	 * Whether a system schedule is active on the current site.
	 *
	 * @param array<string, mixed> $schedule Normalized schedule definition.
	 */
	private static function system_schedule_active( array $schedule ): bool {
		if ( ! empty( $schedule['network_only'] ) && function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( ! function_exists( 'is_main_site' ) || ! is_main_site() ) {
				return false;
			}
		}

		return RecurringScheduleRegistry::isEnabled( $schedule );
	}

	/**
	 * Build registry args for a system-schedule routine, or null.
	 *
	 * @param array<string, mixed> $schedule Normalized schedule definition.
	 * @return array<string, mixed>|null
	 */
	private static function system_routine_args( array $schedule ): ?array {
		$schedule_id = (string) ( $schedule['schedule_id'] ?? '' );
		if ( '' === $schedule_id ) {
			return null;
		}

		$args = array(
			'ability' => self::SYSTEM_DISPATCH_ABILITY,
			'input'   => array( 'schedule_id' => $schedule_id ),
			'label'   => (string) ( $schedule['label'] ?? $schedule_id ),
			'stagger' => true,
		);

		$interval = (string) ( $schedule['interval'] ?? 'daily' );
		if ( 'cron' === $interval || self::looks_like_cron_expression( $interval ) ) {
			$expression = 'cron' === $interval ? (string) ( $schedule['cron_expression'] ?? '' ) : $interval;
			if ( '' === $expression || ! self::is_valid_cron_expression( $expression ) ) {
				return null;
			}

			$args['expression'] = $expression;
			$args['stagger']    = false;
			return $args;
		}

		$seconds = self::interval_seconds( $interval );
		if ( null === $seconds ) {
			return null;
		}

		$args['interval'] = $seconds;
		return $args;
	}

	/**
	 * Unregister a routine, tolerating not-registered.
	 *
	 * @param string $routine_id Routine id.
	 */
	private static function unregister_routine( string $routine_id ): void {
		if ( ! self::available() ) {
			return;
		}

		$result = WP_Agent_Routine_Registry::unregister( $routine_id );
		unset( $result );
	}

	/**
	 * Cancel a pending one-time action for a flow, if any.
	 *
	 * @param int $flow_id Flow ID.
	 */
	private static function cancel_one_time( int $flow_id ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::ONE_TIME_HOOK, array( 'flow_id' => $flow_id ), self::LEGACY_GROUP );
	}

	/**
	 * Schedule the one-time execution action for a flow.
	 *
	 * @param int                  $flow_id   Flow ID.
	 * @param array<string, mixed> $scheduling Desired scheduling config.
	 * @return true|\WP_Error
	 */
	private static function schedule_one_time( int $flow_id, array $scheduling ): bool|\WP_Error {
		$timestamp = $scheduling['timestamp'] ?? null;

		if ( ! is_numeric( $timestamp ) || (int) $timestamp <= 0 ) {
			return new \WP_Error(
				'invalid_schedule',
				'A one-time schedule requires a numeric timestamp.',
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new \WP_Error(
				'routines_unavailable',
				'Action Scheduler is unavailable; the one-time run was not scheduled.',
				array( 'status' => 503, 'retryable' => true )
			);
		}

		self::cancel_one_time( $flow_id );
		as_schedule_single_action(
			(int) $timestamp,
			self::ONE_TIME_HOOK,
			array( 'flow_id' => $flow_id ),
			self::LEGACY_GROUP
		);

		return true;
	}

	/**
	 * The flow-scheduling unchanged guard.
	 *
	 * Compares the portable desired state and verifies live coverage so an
	 * unchanged flow update never resets timers, while a lost schedule is
	 * repaired on the next explicit sync.
	 *
	 * @param array<string, mixed> $current   Persisted scheduling config.
	 * @param array<string, mixed> $incoming  Incoming scheduling config.
	 * @param string|null          $interval  Resolved incoming interval.
	 * @param bool                 $enabled   Incoming enabled state.
	 * @param int                  $flow_id   Flow ID.
	 */
	private static function scheduling_unchanged( array $current, array $incoming, ?string $interval, bool $enabled, int $flow_id ): bool {
		$current_desired  = self::portable_desired_config( $current );
		$incoming_desired = self::portable_desired_config( $incoming );

		$incoming_desired['interval'] = $interval ?? 'manual';
		if ( ! $enabled ) {
			$incoming_desired['enabled'] = false;
		}

		if ( self::canonical_config_json( $current_desired ) !== self::canonical_config_json( $incoming_desired ) ) {
			return false;
		}

		// Manual desired state is converged only when no stale schedule remains.
		if ( 'manual' === $incoming_desired['interval'] || false === ( $incoming_desired['enabled'] ?? true ) ) {
			return ! self::has_live_coverage( $flow_id );
		}

		if ( 'one_time' === $incoming_desired['interval'] ) {
			return self::has_live_coverage( $flow_id );
		}

		return self::routine_pending( $flow_id );
	}

	/**
	 * Order-insensitive canonical JSON of a config for equality comparison.
	 *
	 * @param array<string, mixed> $config Config to canonicalize.
	 */
	private static function canonical_config_json( array $config ): string {
		self::ksort_recursive( $config );
		$encoded = wp_json_encode( $config );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @param array<string, mixed> $config Config sorted in place.
	 */
	private static function ksort_recursive( array &$config ): void {
		ksort( $config );
		foreach ( $config as &$value ) {
			if ( is_array( $value ) ) {
				self::ksort_recursive( $value );
			}
		}
		unset( $value );
	}

	/**
	 * Whether the flow's routine (or one-time action) has a pending wake.
	 *
	 * @param int $flow_id Flow ID.
	 */
	private static function has_live_coverage( int $flow_id ): bool {
		if ( ! self::as_ready() ) {
			return false;
		}

		$routine = as_next_scheduled_action(
			self::ROUTINE_HOOK,
			array( 'routine_id' => self::routine_id( $flow_id ) ),
			self::ROUTINE_GROUP
		);

		if ( is_int( $routine ) && $routine > 0 ) {
			return true;
		}

		$one_time = as_next_scheduled_action(
			self::ONE_TIME_HOOK,
			array( 'flow_id' => $flow_id ),
			self::LEGACY_GROUP
		);

		return is_int( $one_time ) && $one_time > 0;
	}

	/**
	 * Whether the flow's routine wake is currently pending.
	 *
	 * @param int $flow_id Flow ID.
	 */
	private static function routine_pending( int $flow_id ): bool {
		if ( ! self::as_ready() ) {
			return false;
		}

		$timestamp = as_next_scheduled_action(
			self::ROUTINE_HOOK,
			array( 'routine_id' => self::routine_id( $flow_id ) ),
			self::ROUTINE_GROUP
		);

		return is_int( $timestamp ) && $timestamp > 0;
	}

	/**
	 * Whether Action Scheduler's datastore is ready for reads.
	 */
	private static function as_ready(): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		if ( function_exists( 'did_action' ) && class_exists( '\ActionScheduler' ) ) {
			return \ActionScheduler::is_initialized();
		}

		return true;
	}

	/**
	 * Install the hash-gated backend decorator.
	 */
	private static function install_backend(): void {
		if ( self::$backend_installed ) {
			return;
		}

		self::$backend_installed = true;
		add_filter(
			'wp_agent_routine_backend',
			static function ( $default ) {
				if ( ! $default instanceof \AgentsAPI\AI\Routines\WP_Agent_Routine_Backend ) {
					return $default;
				}

				return new HashGatedRoutineBackend( $default );
			},
			10
		);
	}

	/**
	 * Install the routine ability-permission allow-list.
	 *
	 * Default deny at the substrate; this filter allows only routines this
	 * adapter actually registered this request, targeting DM-owned abilities.
	 */
	private static function install_permission_filter(): void {
		if ( self::$permission_installed ) {
			return;
		}

		self::$permission_installed = true;
		add_filter( 'wp_agent_routine_ability_permission', array( self::class, 'filter_ability_permission' ), 10, 3 );
	}

	/**
	 * Allow-list callback for scheduled routine ability execution.
	 *
	 * @param bool             $allowed Default decision (false).
	 * @param WP_Agent_Routine $routine The waking routine.
	 * @param mixed            $ability The resolved target ability.
	 */
	public static function filter_ability_permission( bool $allowed, WP_Agent_Routine $routine, $ability ): bool {
		unset( $allowed, $ability );

		$routine_id = $routine->get_id();
		$target     = $routine->get_ability();

		if ( preg_match( '/^flow-\d+$/', $routine_id ) === 1 && self::FLOW_ABILITY === $target ) {
			return null !== WP_Agent_Routine_Registry::find( $routine_id );
		}

		if ( preg_match( '/^system-[a-z0-9_-]+$/', $routine_id ) === 1 && self::SYSTEM_DISPATCH_ABILITY === $target ) {
			return null !== WP_Agent_Routine_Registry::find( $routine_id );
		}

		return false;
	}

	/**
	 * Run the one-shot legacy-action migration when it has not run yet.
	 */
	private static function maybe_migrate(): void {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$plan = self::migrate_legacy_schedules( false );

		do_action(
			'datamachine_log',
			array() === $plan['errors'] ? 'info' : 'warning',
			'Legacy flow schedule migration completed',
			array(
				'legacy_actions' => $plan['legacy_actions'],
				'cancelled'      => $plan['cancelled'],
				'flows'          => count( $plan['flows'] ),
				'errors'         => count( $plan['errors'] ),
			)
		);
	}

	/**
	 * Direct single-query next-run resolution over Action Scheduler tables.
	 *
	 * @param array<int, string|null> $result Result accumulator keyed by flow id.
	 * @return array<int, string|null>|null Null when the direct path is unavailable.
	 */
	private static function next_runs_direct( array $result ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! class_exists( '\ActionScheduler' ) || ! class_exists( '\ActionScheduler_DBStore' ) ) {
			return null;
		}

		$store = \ActionScheduler::store();
		if ( ! is_object( $store ) || 'ActionScheduler_DBStore' !== get_class( $store ) ) {
			return null;
		}

		if ( empty( $wpdb->actionscheduler_actions ) || empty( $wpdb->actionscheduler_groups ) ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One bounded bulk read of Action Scheduler-owned tables for the list path.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.scheduled_date_gmt, a.args, a.extended_args
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE a.hook = %s AND g.slug = %s AND a.status = %s
				ORDER BY a.scheduled_date_gmt ASC LIMIT %d',
				$wpdb->actionscheduler_actions,
				$wpdb->actionscheduler_groups,
				self::ROUTINE_HOOK,
				self::ROUTINE_GROUP,
				\ActionScheduler_Store::STATUS_PENDING,
				self::MAX_COVERAGE_ROWS
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return null;
		}

		foreach ( $rows as $row ) {
			$args       = json_decode( (string) ( ! empty( $row['extended_args'] ) ? $row['extended_args'] : $row['args'] ), true );
			$routine_id = is_array( $args ) && isset( $args['routine_id'] ) && is_string( $args['routine_id'] )
				? $args['routine_id']
				: '';
			$flow_id    = self::flow_id_from_routine_id( $routine_id );

			if ( $flow_id <= 0 || ! array_key_exists( $flow_id, $result ) || null !== $result[ $flow_id ] ) {
				continue;
			}

			$result[ $flow_id ] = (string) ( $row['scheduled_date_gmt'] ?? '' );
		}

		return $result;
	}
}
