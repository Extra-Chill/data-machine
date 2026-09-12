<?php
/**
 * Action Scheduler backend for routines.
 *
 * The default {@see WP_Agent_Routine_Backend} implementation: agents-api
 * does not require Action Scheduler. When AS is available we register one
 * recurring (or cron-expression) action per routine with a stable logical
 * args array so the listener can resolve the routine on wake. The registry
 * resolves this backend through `WP_Agent_Routine_Registry::backend()`.
 *
 * Deprecated statics: the pre-0.11.0 static facade for the interface
 * methods (register/unregister/pause/resume/run_now/is_available/
 * is_paused/current_generation) had to be removed — PHP cannot carry a
 * static and an instance method of the same name, and the instance forms
 * are required by the interface. Resolve the backend through
 * `WP_Agent_Routine_Registry::backend()` instead. The AS-specific statics
 * that do not collide with the interface (`pending_routine_actions()`,
 * `cancel_action_by_id()`, the option helpers, and the fence installer)
 * remain, the first two as thin deprecation shims.
 *
 * Durability behaviors layered on top:
 *
 *  - Idempotent register(): every call is cheap to make on every plugin
 *    boot, even for hundreds of persisted routines. A request-scoped cache
 *    (populated by one bulk, hook+group+status-filtered
 *    `as_get_scheduled_actions()` call — bounded by the number of *live*
 *    pending actions, never by however large the group's canceled-action
 *    history has grown) answers "what, if anything, is already pending for
 *    this routine" from memory after the first register() call in a
 *    request. `register()` only unschedules and reschedules when no
 *    pending action exists or its recurrence (interval seconds / cron
 *    expression) no longer matches the routine; an unchanged routine is a
 *    read-only no-op.
 *  - Generation + watermark fencing: a schedule change mints a fresh
 *    generation and records the new chain's first action id as a
 *    *watermark* in the routine's single option row
 *    (`agents_routine_generation_<id>`, holding
 *    `['generation' => string, 'watermark' => int]`). Action Scheduler
 *    action ids are a global, strictly increasing sequence, so every
 *    action in the new chain (the initial insert and every recurrence
 *    successor AS spawns) has an id at or above that watermark, and every
 *    action belonging to a superseded chain — including one already
 *    claimed by an in-flight worker when the supersede happened — has an
 *    id below it. `action_scheduler_before_execute` cancels a fetched
 *    routine action whose id is below the routine's current watermark;
 *    Action Scheduler's own pending-status re-check then skips it. This
 *    needs **zero per-action bookkeeping** — no option row is written when
 *    an action is stored, closing the unbounded
 *    `agents_routine_action_generation_<action_id>` option leak of
 *    pre-fix versions (one row per stored action, deleted only by
 *    `cancel()`, which `register()`'s prior unconditional
 *    `as_unschedule_all_actions()` path never reached).
 *    {@see purge_legacy_action_generation_options()} is the one-shot
 *    cleanup for rows a pre-fix version already left behind.
 *  - Stagger: interval routines offset their first run by a deterministic,
 *    id-derived offset so co-scheduled routines do not all fire at the
 *    same second. The offset only affects the first-run timestamp, so it
 *    never causes an unchanged routine to be treated as a schedule
 *    mismatch on re-register.
 *  - Paused state: pause()/resume() maintain a durable paused-id list so
 *    {@see WP_Agent_Routine_Registry::reconcile()} can tell "unscheduled
 *    on purpose" from "missing by drift".
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Routine_Action_Scheduler_Bridge implements WP_Agent_Routine_Backend {

	public const SCHEDULED_HOOK = 'wp_agent_routine_run_scheduled';

	public const GROUP = 'agents-api';

	private const GENERATION_OPTION_PREFIX = 'agents_routine_generation_';
	private const PAUSED_OPTION            = 'agents_routine_paused';

	/**
	 * Prefix of the pre-fix per-action option rows
	 * (`agents_routine_action_generation_<action_id>`). No longer written;
	 * kept only so {@see purge_legacy_action_generation_options()} can find
	 * and delete rows a pre-fix version already left behind.
	 */
	private const LEGACY_ACTION_GENERATION_OPTION_PREFIX = 'agents_routine_action_generation_';

	/**
	 * Marker option gating the one-shot legacy purge to a single run per
	 * site (see {@see maybe_purge_legacy_action_generation_options()}).
	 */
	private const LEGACY_PURGE_MARKER_OPTION = 'agents_routine_action_generation_purged';

	private static ?self $instance = null;

	private static bool $fence_registered = false;

	/**
	 * Request-scoped cache of pending routine actions, grouped by logical
	 * routine id. Populated lazily by one bulk `as_get_scheduled_actions()`
	 * call; kept in sync by register()/cancel()/unregister()/pause() rather
	 * than invalidated wholesale, so a request that (re-)registers many
	 * routines pays for the bulk fetch once.
	 *
	 * @var array<string, array<int,\ActionScheduler_Action>>|null
	 */
	private ?array $pending_cache = null;

	private function __construct() {}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Test-only: forget the request-scoped pending-actions cache so the
	 * next read re-fetches from the (fake or real) Action Scheduler store.
	 * Production code never needs this — the cache's lifetime is correctly
	 * bounded by the PHP process/request, which a persistent test-file
	 * singleton spanning multiple simulated "requests" is not.
	 */
	public function reset_pending_cache_for_tests(): void {
		$this->pending_cache = null;
	}

	public function is_available(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_schedule_cron_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Stable wp_option name holding the routine's current schedule state
	 * (`['generation' => string, 'watermark' => int]`).
	 */
	public static function generation_option_name( string $routine_id ): string {
		return self::GENERATION_OPTION_PREFIX . $routine_id;
	}

	/**
	 * The routine's current schedule generation, or null when none has been
	 * minted (or the option layer is absent).
	 */
	public function current_generation( string $routine_id ): ?string {
		$generation = $this->routine_state( $routine_id )['generation'] ?? null;
		return is_string( $generation ) && '' !== $generation ? $generation : null;
	}

	/**
	 * The action-id watermark fencing the routine's current chain, or null
	 * when none has been recorded (no fence — legacy or never-registered
	 * actions are allowed to run).
	 */
	private function current_watermark( string $routine_id ): ?int {
		$watermark = $this->routine_state( $routine_id )['watermark'] ?? null;
		return is_numeric( $watermark ) ? (int) $watermark : null;
	}

	/**
	 * @return array<array-key,mixed> Recognised keys: `generation` (string),
	 *                                `watermark` (int) — validated by the
	 *                                narrow readers
	 *                                ({@see current_generation()},
	 *                                {@see current_watermark()}), not here.
	 */
	private function routine_state( string $routine_id ): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$value = get_option( self::generation_option_name( $routine_id ), null );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Whether the routine was durably paused via {@see pause()}.
	 */
	public function is_paused( string $routine_id ): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$paused = get_option( self::PAUSED_OPTION, array() );
		return is_array( $paused ) && in_array( $routine_id, $paused, true );
	}

	/**
	 * Register the routine's schedule with Action Scheduler.
	 *
	 * Idempotent and cheap to call on every plugin boot: when a pending
	 * action for this routine already matches the routine's trigger
	 * (interval seconds or cron expression), this is a read-only no-op —
	 * no unschedule, no reschedule, no new generation or watermark. Only a
	 * missing or mismatched schedule mints a fresh chain.
	 *
	 * @return bool True when a schedule is in place (freshly registered or
	 *              already matching); false on no-op due to Action
	 *              Scheduler being unavailable or the schedule call failing.
	 */
	public function register( WP_Agent_Routine $routine ): bool {
		/**
		 * Fires whenever the backend would schedule a routine, regardless
		 * of whether Action Scheduler is loaded. Custom schedulers can
		 * hook this to take over.
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_schedule_requested', $routine );

		// Registration implies the routine is active.
		self::set_paused( $routine->get_id(), false );

		if ( ! $this->is_available() ) {
			return false;
		}

		$routine_id   = $routine->get_id();
		$logical_args = array( 'routine_id' => $routine_id );

		$existing = $this->pending_map()[ $routine_id ] ?? array();
		if ( null !== self::matching_action_id( $existing, $routine ) ) {
			return true; // Unchanged: nothing to do.
		}

		if ( WP_Agent_Routine::TRIGGER_EXPRESSION === $routine->get_trigger_type() ) {
			$new_id = as_schedule_cron_action(
				time(),
				$routine->get_expression(),
				self::SCHEDULED_HOOK,
				$logical_args,
				self::GROUP
			);
		} else {
			$new_id = as_schedule_recurring_action(
				time() + $routine->stagger_offset(),
				$routine->get_interval_seconds(),
				self::SCHEDULED_HOOK,
				$logical_args,
				self::GROUP
			);
		}

		if ( empty( $new_id ) ) {
			return false;
		}
		$new_id = (int) $new_id;

		// Advance the watermark to the new chain's first action id before
		// tearing down the superseded chain: every id in the new chain
		// (this one and every recurrence successor AS spawns) is at or
		// above it, and every id in the old chain — including one already
		// claimed by an in-flight worker — is below it.
		if ( self::has_option_layer() ) {
			update_option(
				self::generation_option_name( $routine_id ),
				array(
					'generation' => self::mint_generation(),
					'watermark'  => $new_id,
				),
				false
			);
		}

		foreach ( array_keys( $existing ) as $old_id ) {
			if ( $old_id !== $new_id ) {
				$this->cancel( $old_id );
			}
		}

		$this->remember_pending_action( $routine_id, $new_id );

		return true;
	}

	/**
	 * Cancel every scheduled action this backend owns for the given routine,
	 * remove its generation tombstone, and clear any paused marker.
	 */
	public function unregister( string $routine_id ): void {
		if ( self::has_option_layer() ) {
			delete_option( self::generation_option_name( $routine_id ) );
		}
		self::set_paused( $routine_id, false );

		if ( ! $this->is_available() ) {
			return;
		}
		as_unschedule_all_actions( self::SCHEDULED_HOOK, array( 'routine_id' => $routine_id ), self::GROUP );
		$this->forget_pending_routine( $routine_id );
	}

	/**
	 * Cancel the recurring/cron schedule without removing the routine from
	 * the registry. The pause is recorded durably so
	 * {@see WP_Agent_Routine_Registry::reconcile()} does not re-enqueue a
	 * deliberately-paused routine.
	 */
	public function pause( string $routine_id ): void {
		self::set_paused( $routine_id, true );
		if ( ! $this->is_available() ) {
			return;
		}
		as_unschedule_all_actions( self::SCHEDULED_HOOK, array( 'routine_id' => $routine_id ), self::GROUP );
		$this->forget_pending_routine( $routine_id );
	}

	/**
	 * Re-establish the recurring/cron schedule for a previously-paused
	 * routine. Idempotent — calling on a routine whose schedule is still
	 * active simply re-registers (the underlying register call is itself a
	 * no-op when the schedule is unchanged).
	 */
	public function resume( WP_Agent_Routine $routine ): bool {
		return $this->register( $routine );
	}

	/**
	 * Enqueue a single-shot action for the routine, in addition to its
	 * recurring schedule. Its id is necessarily higher than the routine's
	 * current watermark (Action Scheduler ids only increase), so it runs
	 * normally unless a later register() call supersedes the chain before
	 * it fires — the same fence the recurring chain is subject to.
	 */
	public function run_now( WP_Agent_Routine $routine ): bool {
		if ( ! $this->is_available() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		as_enqueue_async_action(
			self::SCHEDULED_HOOK,
			array( 'routine_id' => $routine->get_id() ),
			self::GROUP
		);
		return true;
	}

	/**
	 * All pending routine actions, hydrated fresh from the store (never the
	 * request-scoped register() cache — see {@see pending_by_routine()}).
	 *
	 * @deprecated 0.11.0 Use WP_Agent_Routine_Registry::backend()->pending_by_routine().
	 *
	 * @return array<int,\ActionScheduler_Action> Pending actions keyed by action id.
	 */
	public static function pending_routine_actions(): array {
		$flat = array();
		foreach ( self::instance()->fetch_pending_map() as $actions ) {
			foreach ( $actions as $action_id => $action ) {
				$flat[ $action_id ] = $action;
			}
		}
		return $flat;
	}

	/**
	 * Pending backend handles grouped by logical routine id.
	 *
	 * Always forces a fresh bulk fetch rather than reading the request-scoped
	 * cache {@see register()} maintains: this is the one read
	 * {@see WP_Agent_Routine_Registry::reconcile()} uses, and reconcile's
	 * entire purpose is authoritative drift detection — trusting a cache
	 * populated before the drift happened (a pruned Action Scheduler table,
	 * a restored backup, a manually deleted row) would defeat the point.
	 * The fresh read also refreshes the shared cache, so any register()
	 * calls later in the same request see current state too.
	 *
	 * @return array<string, list<int>> routine_id => pending action ids.
	 */
	public function pending_by_routine(): array {
		$this->pending_cache = $this->fetch_pending_map();

		$out = array();
		foreach ( $this->pending_cache as $routine_id => $actions ) {
			$out[ $routine_id ] = array_keys( $actions );
		}
		return $out;
	}

	/**
	 * The request-scoped pending-actions cache, grouped by routine id.
	 * Populated by exactly one bulk `as_get_scheduled_actions()` call
	 * (hook + group + status = pending only — bounded by the number of
	 * *live* pending actions, not by however large the group's canceled
	 * history has grown) the first time anything needs it in a request.
	 *
	 * @return array<string, array<int,\ActionScheduler_Action>>
	 */
	private function pending_map(): array {
		if ( null === $this->pending_cache ) {
			$this->pending_cache = $this->fetch_pending_map();
		}
		return $this->pending_cache;
	}

	/**
	 * @return array<string, array<int,\ActionScheduler_Action>>
	 */
	private function fetch_pending_map(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return array();
		}

		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::SCHEDULED_HOOK,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$map = array();
		foreach ( $ids as $id ) {
			$action_id = is_numeric( $id ) ? (int) $id : 0;
			if ( $action_id <= 0 ) {
				continue;
			}

			try {
				$action = \ActionScheduler_Store::instance()->fetch_action( $action_id );
			} catch ( \Throwable $error ) {
				unset( $error );
				continue;
			}

			$args       = $action->get_args();
			$routine_id = $args['routine_id'] ?? ( $args[0] ?? '' );
			if ( ! is_string( $routine_id ) || '' === $routine_id ) {
				continue;
			}

			$map[ $routine_id ][ $action_id ] = $action;
		}

		return $map;
	}

	/**
	 * Fold a freshly-scheduled action into the cache directly, avoiding a
	 * full re-fetch on the next register() call in this request.
	 */
	private function remember_pending_action( string $routine_id, int $action_id ): void {
		if ( null === $this->pending_cache ) {
			return; // Not populated; the next read fetches fresh.
		}

		try {
			$action = class_exists( '\ActionScheduler_Store' ) ? \ActionScheduler_Store::instance()->fetch_action( $action_id ) : null;
		} catch ( \Throwable $error ) {
			unset( $error );
			$action = null;
		}

		if ( null === $action ) {
			unset( $this->pending_cache[ $routine_id ] );
			return;
		}

		$this->pending_cache[ $routine_id ] = array( $action_id => $action );
	}

	private function forget_pending_routine( string $routine_id ): void {
		if ( null !== $this->pending_cache ) {
			unset( $this->pending_cache[ $routine_id ] );
		}
	}

	private function forget_pending_action( int $action_id ): void {
		if ( null === $this->pending_cache ) {
			return;
		}
		foreach ( $this->pending_cache as $routine_id => $actions ) {
			if ( isset( $actions[ $action_id ] ) ) {
				unset( $this->pending_cache[ $routine_id ][ $action_id ] );
				if ( array() === $this->pending_cache[ $routine_id ] ) {
					unset( $this->pending_cache[ $routine_id ] );
				}
				return;
			}
		}
	}

	/**
	 * The pending action, among those already known for a routine, whose
	 * recurrence matches the routine's current trigger — or null when none
	 * does (including when the only pending actions are one-shot `run_now`
	 * enqueues, which are never recurring and therefore never "the"
	 * schedule).
	 *
	 * @param array<int,\ActionScheduler_Action> $actions_by_id
	 */
	private static function matching_action_id( array $actions_by_id, WP_Agent_Routine $routine ): ?int {
		foreach ( $actions_by_id as $action_id => $action ) {
			if ( self::recurrence_matches( $action, $routine ) ) {
				return (int) $action_id;
			}
		}
		return null;
	}

	/**
	 * Whether a pending action's own Action Scheduler schedule already
	 * matches the routine's trigger. Deliberately ignores the first-run
	 * timestamp (and therefore the stagger offset) — only the recurrence
	 * itself (interval seconds, or cron expression) is schedule-significant
	 * for idempotency.
	 */
	private static function recurrence_matches( \ActionScheduler_Action $action, WP_Agent_Routine $routine ): bool {
		$schedule = $action->get_schedule();
		if ( ! $schedule->is_recurring() ) {
			return false;
		}
		$recurrence = $schedule->get_recurrence();

		if ( WP_Agent_Routine::TRIGGER_EXPRESSION === $routine->get_trigger_type() ) {
			return is_string( $recurrence ) && hash_equals( $routine->get_expression(), $recurrence );
		}

		return is_numeric( $recurrence ) && (int) $recurrence === $routine->get_interval_seconds();
	}

	/**
	 * Cancel one stored action by id.
	 *
	 * @deprecated 0.11.0 Use WP_Agent_Routine_Registry::backend()->cancel().
	 *
	 * @param int $action_id Action Scheduler action id.
	 * @return bool
	 */
	public static function cancel_action_by_id( int $action_id ): bool {
		return self::instance()->cancel( $action_id );
	}

	/**
	 * Cancel one pending action handle. Returns true when the cancel call
	 * succeeded (or at least did not throw).
	 *
	 * @param int $handle The opaque backend handle to cancel.
	 * @return bool
	 */
	public function cancel( int $handle ): bool {
		if ( $handle <= 0 || ! class_exists( '\ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			\ActionScheduler_Store::instance()->cancel_action( $handle );
		} catch ( \Throwable $error ) {
			unset( $error );
			return false;
		}

		$this->forget_pending_action( $handle );

		return true;
	}

	/**
	 * Install the generation fence hook once per request, and schedule the
	 * one-shot legacy-option purge.
	 *
	 * `action_scheduler_before_execute` ($action_id) fires before the
	 * runner re-checks that the action is still pending. When the action's
	 * id is below its routine's current watermark, it belongs to a
	 * superseded chain: we cancel it there, so the runner's own status
	 * check skips it and no recurrence successor is spawned.
	 */
	public static function register_generation_fence(): void {
		if ( self::$fence_registered ) {
			return;
		}
		self::$fence_registered = true;
		add_action( 'action_scheduler_before_execute', array( self::class, 'fence_before_execute' ), 0, 1 );
		add_action( 'init', array( self::class, 'maybe_purge_legacy_action_generation_options' ), 20 );
	}

	/**
	 * Cancel a routine action whose id is below the routine's current
	 * watermark (i.e. it belongs to a chain superseded by a later
	 * register() call, including one already claimed by an in-flight
	 * worker when the supersede happened).
	 *
	 * Runs at priority 0 on `action_scheduler_before_execute`; the runner
	 * then observes the non-pending status and ignores the action.
	 *
	 * @param int|string $action_id Action Scheduler action id.
	 */
	public static function fence_before_execute( $action_id ): void {
		if ( ! is_numeric( $action_id ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return;
		}
		$action_id  = (int) $action_id;
		$routine_id = self::routine_id_for_action( $action_id );
		if ( '' === $routine_id ) {
			return;
		}
		$watermark = self::instance()->current_watermark( $routine_id );
		if ( null === $watermark || $action_id >= $watermark ) {
			return; // No fence recorded (legacy/never re-registered this process) or current/newer chain: let it run.
		}
		if ( self::instance()->cancel( $action_id ) ) {
			do_action( 'agents_routine_action_fenced', $routine_id, self::instance()->current_generation( $routine_id ) ?? '', $action_id );
		}
	}

	/**
	 * Resolve the routine id for a stored action, or '' when it is not ours.
	 *
	 * @param int $action_id Action Scheduler action id.
	 */
	private static function routine_id_for_action( int $action_id ): string {
		try {
			$action = \ActionScheduler_Store::instance()->fetch_action( $action_id );
		} catch ( \Throwable $error ) {
			unset( $error );
			return '';
		}
		if ( self::SCHEDULED_HOOK !== $action->get_hook() || self::GROUP !== $action->get_group() ) {
			return '';
		}
		$args = $action->get_args();
		return isset( $args['routine_id'] ) && is_string( $args['routine_id'] ) ? $args['routine_id'] : '';
	}

	/**
	 * One-shot cleanup of the unbounded per-action option rows written by
	 * pre-fix versions of this bridge
	 * (`agents_routine_action_generation_<action_id>`, one row per stored
	 * action, deleted only by `cancel()` — a path `register()`'s prior
	 * unconditional `as_unschedule_all_actions()` call never reached).
	 * Deletes every matching row in a single query.
	 *
	 * A targeted per-key cache flush is not viable at the observed scale
	 * (hundreds of thousands of rows in production); this flushes the
	 * whole `options` object-cache group when the active object cache
	 * supports group flushing (as Redis Object Cache does), and falls back
	 * to clearing `alloptions` otherwise.
	 *
	 * Safe to call more than once — a repeat call deletes zero rows.
	 * Callers should gate repeat calls behind
	 * {@see maybe_purge_legacy_action_generation_options()} rather than
	 * calling this directly on every request.
	 *
	 * @return int Number of option rows deleted, or 0 when $wpdb is
	 *             unavailable (pure-PHP test harnesses, or WordPress not
	 *             yet bootstrapped).
	 */
	public static function purge_legacy_action_generation_options(): int {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		$like = $wpdb->esc_like( self::LEGACY_ACTION_GENERATION_OPTION_PREFIX ) . '%';

		$query = $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $like );
		if ( ! is_string( $query ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is built by $wpdb->prepare() above.
		$deleted = $wpdb->query( $query );

		if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'options' );
		} elseif ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Run {@see purge_legacy_action_generation_options()} at most once per
	 * site, gated by a persisted marker option rather than a plugin
	 * activation hook.
	 *
	 * agents-api ships far more often as a Composer-embedded library inside
	 * a consumer plugin than as its own activatable WordPress plugin — it
	 * has no versioned db-upgrade routine of its own for the same reason —
	 * so a plugin-activation-hook approach would never fire for most real
	 * installs. A marker-option gate checked on `init` self-heals
	 * regardless of how the substrate is loaded, at the cost of one cheap
	 * `get_option()` read per request after the purge has run.
	 */
	public static function maybe_purge_legacy_action_generation_options(): void {
		if ( ! self::has_option_layer() ) {
			return;
		}
		if ( get_option( self::LEGACY_PURGE_MARKER_OPTION, false ) ) {
			return;
		}
		self::purge_legacy_action_generation_options();
		update_option( self::LEGACY_PURGE_MARKER_OPTION, time(), false );
	}

	private static function has_option_layer(): bool {
		return function_exists( 'add_option' ) && function_exists( 'get_option' ) && function_exists( 'update_option' ) && function_exists( 'delete_option' );
	}

	private static function mint_generation(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return uniqid( 'routine_gen_', true );
		}
	}

	private static function set_paused( string $routine_id, bool $pause ): void {
		if ( ! self::has_option_layer() ) {
			return;
		}

		$current = get_option( self::PAUSED_OPTION, array() );
		$current = is_array( $current ) ? $current : array();

		$normalized = array();
		foreach ( $current as $id ) {
			if ( is_string( $id ) && '' !== $id && ! in_array( $id, $normalized, true ) ) {
				$normalized[] = $id;
			}
		}

		$has = in_array( $routine_id, $normalized, true );
		if ( $pause === $has ) {
			return;
		}

		if ( $pause ) {
			$normalized[] = $routine_id;
		} else {
			$normalized = array_values( array_diff( $normalized, array( $routine_id ) ) );
		}

		update_option( self::PAUSED_OPTION, $normalized, false );
	}
}
