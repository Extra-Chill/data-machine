<?php
/**
 * Pure-PHP behavioral smoke for the deploy-time flow schedule reconcile
 * lifecycle in inc/setup/flow-schedules.php.
 *
 * Regression coverage for Extra-Chill/data-machine#3492: the `init` handler
 * used to call `FlowRoutines::reconcile(true)` directly on every web
 * request while the deploy marker was set, and treated lock contention as a
 * failure worth retrying on every subsequent request. This smoke proves:
 *
 *  (b) the `init` handler never calls FlowRoutines::reconcile() itself — it
 *      only enqueues a single Action Scheduler async action (deduplicated
 *      against an already-pending one) — and the deferred worker treats a
 *      `skipped` (locked) result as a silent no-op: the marker is left
 *      alone and nothing is logged as an error.
 *  (d) a genuine reconcile failure retains the marker and sets a short
 *      backoff transient so a persistently failing reconcile does not
 *      re-enqueue on every request.
 *  (e) a genuine reconcile success clears the marker and logs completion.
 *
 * Run with: php tests/flow-schedule-reconciliation-worker-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Engine\Scheduling {
	/**
	 * Fully controllable stand-in for the real adapter: the worker smoke
	 * only exercises inc/setup/flow-schedules.php, not FlowRoutines itself
	 * (see tests/flow-routines-reconcile-lock-before-boot-smoke.php for
	 * that coverage).
	 */
	class FlowRoutines {
		public static bool $available = true;
		/** @var array<string, mixed> */
		public static array $result = array();
		/** @var bool[] */
		public static array $reconcile_calls = array();

		public static function available(): bool {
			return self::$available;
		}

		public static function reconcile( bool $apply = false ): array {
			self::$reconcile_calls[] = $apply;
			return self::$result;
		}

		public static function reset(): void {
			self::$available       = true;
			self::$result          = array();
			self::$reconcile_calls = array();
		}
	}
}

// GroupRegistrar is required from its real file below (not stubbed): it is
// a plain constant holder plus DB-touching methods this smoke never calls,
// and requiring it avoids hand-duplicating its GROUP slug literal here.

namespace {

	define( 'ABSPATH', __DIR__ );
	define( 'MINUTE_IN_SECONDS', 60 );

	$GLOBALS['datamachine_smoke_options']    = array();
	$GLOBALS['datamachine_smoke_transients'] = array();
	$GLOBALS['datamachine_smoke_logs']       = array();
	$GLOBALS['datamachine_smoke_as_actions'] = array();

	function get_option( string $name, $default = false ) {
		return $GLOBALS['datamachine_smoke_options'][ $name ] ?? $default;
	}

	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['datamachine_smoke_options'][ $name ] = $value;
		return true;
	}

	function delete_option( string $name ): bool {
		unset( $GLOBALS['datamachine_smoke_options'][ $name ] );
		return true;
	}

	function get_transient( string $name ) {
		return $GLOBALS['datamachine_smoke_transients'][ $name ] ?? false;
	}

	function set_transient( string $name, $value, int $expiration ): bool {
		unset( $expiration );
		$GLOBALS['datamachine_smoke_transients'][ $name ] = $value;
		return true;
	}

	function do_action( string $tag, ...$args ): void {
		if ( 'datamachine_log' === $tag ) {
			$GLOBALS['datamachine_smoke_logs'][] = $args;
		}
	}

	function add_action( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $tag, $callback, $priority, $accepted_args );
		return true;
	}

	function as_has_scheduled_action( string $hook, array $args = array(), string $group = '' ): bool {
		foreach ( $GLOBALS['datamachine_smoke_as_actions'] as $action ) {
			if ( $action['hook'] === $hook && $action['args'] === $args && $action['group'] === $group ) {
				return true;
			}
		}
		return false;
	}

	function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
		$GLOBALS['datamachine_smoke_as_actions'][] = array(
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		);
		return count( $GLOBALS['datamachine_smoke_as_actions'] );
	}

	require_once __DIR__ . '/../inc/Core/ActionScheduler/GroupRegistrar.php';
	require_once __DIR__ . '/../inc/setup/flow-schedules.php';

	use DataMachine\Engine\Scheduling\FlowRoutines;

	function datamachine_smoke_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	function datamachine_smoke_reset(): void {
		FlowRoutines::reset();
		$GLOBALS['datamachine_smoke_logs']       = array();
		$GLOBALS['datamachine_smoke_as_actions'] = array();
		$GLOBALS['datamachine_smoke_transients'] = array();
	}

	echo "=== flow-schedule-reconciliation-worker-smoke ===\n";

	// --- No marker: the init handler is a complete no-op. ---
	datamachine_smoke_reset();
	datamachine_reconcile_marked_flow_schedules();
	datamachine_smoke_assert( array() === $GLOBALS['datamachine_smoke_as_actions'], 'no marker: nothing is enqueued' );

	// --- Marker set: the init handler enqueues once and never calls reconcile() itself. ---
	datamachine_smoke_reset();
	update_option( 'datamachine_flow_schedule_reconciliation_pending', array( 'marked_at' => time() ) );

	datamachine_reconcile_marked_flow_schedules();

	datamachine_smoke_assert( 1 === count( $GLOBALS['datamachine_smoke_as_actions'] ), 'marker set: the init handler enqueues exactly one Action Scheduler action' );
	datamachine_smoke_assert(
		DATAMACHINE_FLOW_SCHEDULE_RECONCILE_HOOK === $GLOBALS['datamachine_smoke_as_actions'][0]['hook'],
		'the enqueued action targets the deferred reconcile hook'
	);
	datamachine_smoke_assert( array() === FlowRoutines::$reconcile_calls, 'the init handler never calls FlowRoutines::reconcile() directly — a page view never pays the boot() cost' );

	// A second concurrent request must not enqueue a duplicate.
	datamachine_reconcile_marked_flow_schedules();
	datamachine_smoke_assert( 1 === count( $GLOBALS['datamachine_smoke_as_actions'] ), 'a concurrent request does not enqueue a duplicate action' );

	// --- (b) the deferred worker treats a locked/skipped result as a silent no-op. ---
	datamachine_smoke_reset();
	update_option( 'datamachine_flow_schedule_reconciliation_pending', array( 'marked_at' => time() ) );
	FlowRoutines::$result = array(
		'success' => true,
		'applied' => true,
		'skipped' => true,
		'reason'  => 'locked',
		'covered' => 0,
		'missing' => 0,
		'removed' => 0,
		'errors'  => array(),
	);

	datamachine_run_deferred_flow_schedule_reconciliation();

	datamachine_smoke_assert(
		false !== get_option( 'datamachine_flow_schedule_reconciliation_pending', false ),
		'a locked/skipped result leaves the marker in place'
	);
	datamachine_smoke_assert( array() === $GLOBALS['datamachine_smoke_logs'], 'a locked/skipped result logs nothing — contention is not an error' );
	datamachine_smoke_assert( false === get_transient( DATAMACHINE_FLOW_SCHEDULE_RECONCILE_BACKOFF ), 'a locked/skipped result does not arm the failure backoff' );

	// The marker is still live and no backoff is armed, so the next request re-enqueues.
	$GLOBALS['datamachine_smoke_as_actions'] = array();
	datamachine_reconcile_marked_flow_schedules();
	datamachine_smoke_assert( 1 === count( $GLOBALS['datamachine_smoke_as_actions'] ), 'after a skipped run, a later request retries by enqueuing again' );

	// --- (d) a genuine failure retains the marker and arms a backoff. ---
	datamachine_smoke_reset();
	update_option( 'datamachine_flow_schedule_reconciliation_pending', array( 'marked_at' => time() ) );
	FlowRoutines::$result = array(
		'success' => false,
		'applied' => true,
		'covered' => 0,
		'missing' => 3,
		'removed' => 0,
		'errors'  => array( '_substrate' => 'synthetic failure' ),
	);

	datamachine_run_deferred_flow_schedule_reconciliation();

	datamachine_smoke_assert(
		false !== get_option( 'datamachine_flow_schedule_reconciliation_pending', false ),
		'a genuine failure retains the marker'
	);
	datamachine_smoke_assert( 1 === count( $GLOBALS['datamachine_smoke_logs'] ), 'a genuine failure is logged' );
	datamachine_smoke_assert( 'error' === ( $GLOBALS['datamachine_smoke_logs'][0][0] ?? null ), 'a genuine failure is logged at error level' );
	datamachine_smoke_assert( true === get_transient( DATAMACHINE_FLOW_SCHEDULE_RECONCILE_BACKOFF ), 'a genuine failure arms the backoff transient' );

	// While the backoff is armed, the init handler must not re-enqueue even though the marker is still set.
	$GLOBALS['datamachine_smoke_as_actions'] = array();
	datamachine_reconcile_marked_flow_schedules();
	datamachine_smoke_assert( array() === $GLOBALS['datamachine_smoke_as_actions'], 'while the backoff is armed, the init handler does not re-enqueue on every request' );

	// --- (e) a genuine success clears the marker and logs completion. ---
	datamachine_smoke_reset();
	update_option( 'datamachine_flow_schedule_reconciliation_pending', array( 'marked_at' => time() ) );
	FlowRoutines::$result = array(
		'success' => true,
		'applied' => true,
		'covered' => 12,
		'missing' => 0,
		'removed' => 0,
		'errors'  => array(),
	);

	datamachine_run_deferred_flow_schedule_reconciliation();

	datamachine_smoke_assert( false === get_option( 'datamachine_flow_schedule_reconciliation_pending', false ), 'a genuine success clears the marker' );
	datamachine_smoke_assert( 1 === count( $GLOBALS['datamachine_smoke_logs'] ), 'a genuine success is logged' );
	datamachine_smoke_assert( 'info' === ( $GLOBALS['datamachine_smoke_logs'][0][0] ?? null ), 'a genuine success is logged at info level' );
	datamachine_smoke_assert( false === get_transient( DATAMACHINE_FLOW_SCHEDULE_RECONCILE_BACKOFF ), 'a genuine success does not arm the backoff' );

	echo "\nAll flow-schedule-reconciliation-worker assertions passed.\n";
}
