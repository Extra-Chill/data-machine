<?php
/**
 * Pure-PHP behavioral smoke for FlowRoutines::reconcile()'s lock-before-boot
 * ordering and boot()'s persist-survives-a-throw guarantee.
 *
 * Regression coverage for Extra-Chill/data-machine#3492: a deploy-time
 * reconcile marker caused `FlowRoutines::reconcile(true)` to run on every
 * web request. Because `boot()` (a full ~700-routine registration pass) ran
 * BEFORE the registry's own reconcile lock was taken, every concurrent
 * request paid the full boot() cost before discovering someone else already
 * held the lock. This smoke proves:
 *
 *  (a) with the DM reconcile guard held, reconcile() returns a `skipped`
 *      result WITHOUT ever calling boot() (zero registry registration calls,
 *      zero persist() calls) — and that normal operation resumes once the
 *      guard is released.
 *  (c) boot() calls HashGatedRoutineBackend::persist() even when a routine
 *      registration throws, and even when something outside the per-routine
 *      try/catch throws — a lost persist() is what made the real incident
 *      self-sustaining (lost fingerprints reschedule everything again).
 *
 * Run with: php tests/flow-routines-reconcile-lock-before-boot-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace AgentsAPI\AI\Routines {
	interface WP_Agent_Routine_Backend {}

	class WP_Agent_Routine_Registry {
		/** @var string[] */
		public static array $register_calls = array();
		/** @var string[] */
		public static array $throw_for = array();

		public static function register( string $id, array $args ) {
			unset( $args );
			self::$register_calls[] = $id;
			if ( in_array( $id, self::$throw_for, true ) ) {
				throw new \RuntimeException( "synthetic register throw for {$id}" );
			}
			return true;
		}

		public static function unregister( string $id ) {
			unset( $id );
			return true;
		}

		public static function find( string $id ) {
			unset( $id );
			return null;
		}

		public static function reconcile( array $opts = array() ): array {
			unset( $opts );
			return array(
				'enqueued'  => array(),
				'removed'   => array(),
				'unchanged' => array(),
				'errors'    => array(),
			);
		}

		public static function reset(): void {
			self::$register_calls = array();
			self::$throw_for      = array();
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	class Flows {
		/** @var array<int, array<string, mixed>> */
		public static array $schedules = array();

		public function get_flow_schedules(): array {
			return self::$schedules;
		}
	}
}

namespace DataMachine\Engine\Tasks {
	class RecurringScheduleRegistry {
		public static bool $throw_on_all = false;

		public static function all(): array {
			if ( self::$throw_on_all ) {
				throw new \RuntimeException( 'synthetic RecurringScheduleRegistry::all() throw' );
			}
			return array();
		}

		public static function isEnabled( array $schedule ): bool {
			unset( $schedule );
			return false;
		}
	}
}

// GroupRegistrar is required from its real file below (not stubbed): it is
// a plain constant holder plus DB-touching methods this smoke never calls,
// and requiring it avoids hand-duplicating its GROUP slug literal here.

// Spy replacing the real HashGatedRoutineBackend: same namespace as
// FlowRoutines, so its unqualified `HashGatedRoutineBackend::persist()`
// calls resolve here instead of the real (unrequired) file.
namespace DataMachine\Engine\Scheduling {
	final class HashGatedRoutineBackend {
		public static int $persist_calls = 0;

		public static function persist(): void {
			++self::$persist_calls;
		}

		public static function set_verification_mode( bool $mode ): void {
			unset( $mode );
		}

		public static function reset_state(): void {
			self::$persist_calls = 0;
		}
	}
}

namespace {

	define( 'ABSPATH', __DIR__ );
	define( 'ARRAY_A', 'ARRAY_A' );

	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	// The object cache and `wp_options` are modeled as two INDEPENDENT
	// stores, exactly like a real Redis-backed object cache sitting in
	// front of MySQL. FlowScheduleReconciliationLock must never trust the
	// cache store for a locking decision — see the "ghost cache entry"
	// scenario below, which reproduces the Extra-Chill/data-machine#3492
	// failure mode where a killed request left a cache entry with no
	// backing DB row.
	$GLOBALS['datamachine_smoke_options'] = array();
	$GLOBALS['datamachine_smoke_db']      = array();

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

	function add_option( string $name, $value, string $deprecated = '', bool $autoload = true ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $name, $GLOBALS['datamachine_smoke_options'] ) ) {
			return false;
		}
		$GLOBALS['datamachine_smoke_options'][ $name ] = $value;
		return true;
	}

	function maybe_serialize( $value ): string {
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
	}

	function maybe_unserialize( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$unserialized = @unserialize( $value );
		return ( false !== $unserialized || 'b:0;' === $value ) ? $unserialized : $value;
	}

	$GLOBALS['datamachine_smoke_cache_delete_calls'] = array();

	function wp_cache_delete( string $key, string $group ): bool {
		$GLOBALS['datamachine_smoke_cache_delete_calls'][] = array( $key, $group );
		// A real persistent object cache actually removes the entry —
		// model that so tests can prove a ghost cache entry doesn't
		// survive the class's own defensive wp_cache_delete() calls.
		unset( $GLOBALS['datamachine_smoke_options'][ $key ] );
		return true;
	}

	function wp_generate_uuid4(): string {
		static $counter = 0;
		return 'test-token-' . ++$counter;
	}

	function add_filter( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $tag, $callback, $priority, $accepted_args );
		return true;
	}

	function apply_filters( string $tag, $value ) {
		if ( 'datamachine_scheduler_intervals' === $tag ) {
			return array( 'hourly' => array( 'seconds' => 3600 ) );
		}
		return $value;
	}

	$GLOBALS['datamachine_smoke_logs'] = array();

	function do_action( string $tag, ...$args ): void {
		if ( 'datamachine_log' === $tag ) {
			$GLOBALS['datamachine_smoke_logs'][] = $args;
		}
	}

	class DatamachineSmokeWpdb {
		public string $options = 'wp_options';

		public function prepare( string $query, ...$args ): array {
			return array( $query, $args );
		}

		/**
		 * Every locking decision reads straight from the `datamachine_smoke_db`
		 * store — never `datamachine_smoke_options` (the cache).
		 */
		public function get_row( array $prepared, $output = null ) {
			unset( $output );
			list( $query, $args ) = $prepared;
			if ( ! str_starts_with( $query, 'SELECT' ) ) {
				return null;
			}

			list( , $option_name ) = $args;
			if ( ! array_key_exists( $option_name, $GLOBALS['datamachine_smoke_db'] ) ) {
				return null;
			}

			return array( 'option_value' => $GLOBALS['datamachine_smoke_db'][ $option_name ] );
		}

		public function query( array $prepared ): int {
			list( $query, $args ) = $prepared;

			if ( str_starts_with( $query, 'INSERT IGNORE' ) ) {
				list( , $option_name, $value ) = $args;
				if ( array_key_exists( $option_name, $GLOBALS['datamachine_smoke_db'] ) ) {
					return 0;
				}
				$GLOBALS['datamachine_smoke_db'][ $option_name ] = $value;
				return 1;
			}

			if ( str_starts_with( $query, 'UPDATE' ) ) {
				list( , $replacement, $option_name, $expected_raw ) = $args;
				$current_raw                                        = $GLOBALS['datamachine_smoke_db'][ $option_name ] ?? null;
				if ( $current_raw !== $expected_raw ) {
					return 0;
				}
				$GLOBALS['datamachine_smoke_db'][ $option_name ] = $replacement;
				return 1;
			}

			if ( str_starts_with( $query, 'DELETE' ) ) {
				list( , $option_name, $expected_raw ) = $args;
				$current_raw                          = $GLOBALS['datamachine_smoke_db'][ $option_name ] ?? null;
				if ( $current_raw !== $expected_raw ) {
					return 0;
				}
				unset( $GLOBALS['datamachine_smoke_db'][ $option_name ] );
				return 1;
			}

			return 0;
		}
	}

	$wpdb = new DatamachineSmokeWpdb();

	require_once __DIR__ . '/../inc/Core/ActionScheduler/GroupRegistrar.php';
	require_once __DIR__ . '/../inc/Api/Flows/FlowScheduleReconciliationLock.php';
	require_once __DIR__ . '/../inc/Engine/Scheduling/FlowRoutines.php';

	use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry;
	use DataMachine\Api\Flows\FlowScheduleReconciliationLock;
	use DataMachine\Core\Database\Flows\Flows;
	use DataMachine\Engine\Scheduling\FlowRoutines;
	use DataMachine\Engine\Scheduling\HashGatedRoutineBackend;
	use DataMachine\Engine\Tasks\RecurringScheduleRegistry;

	function datamachine_smoke_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	function datamachine_smoke_reset(): void {
		WP_Agent_Routine_Registry::reset();
		HashGatedRoutineBackend::reset_state();
		RecurringScheduleRegistry::$throw_on_all         = false;
		$GLOBALS['datamachine_smoke_logs']               = array();
		$GLOBALS['datamachine_smoke_db']                 = array();
		$GLOBALS['datamachine_smoke_options']            = array( FlowRoutines::MIGRATED_OPTION => true );
		$GLOBALS['datamachine_smoke_cache_delete_calls'] = array();
	}

	echo "=== flow-routines-reconcile-lock-before-boot-smoke ===\n";

	// datamachine_smoke_reset() primes FlowRoutines::MIGRATED_OPTION in the
	// cache store so boot() doesn't reach for as_get_scheduled_actions()
	// (Action Scheduler is not stubbed here) — the one-shot legacy-action
	// migration is out of scope for this smoke.

	// --- (a) lock held elsewhere: reconcile() must skip without booting. ---
	Flows::$schedules = array(
		array(
			'flow_id'           => 1,
			'flow_name'         => 'Flow One',
			'scheduling_config' => array(
				'enabled'  => true,
				'interval' => 'hourly',
			),
		),
	);
	datamachine_smoke_reset();

	$other_owner = FlowScheduleReconciliationLock::acquire();
	datamachine_smoke_assert( is_string( $other_owner ), 'setup: a concurrent caller holds the reconcile lock' );

	$result = FlowRoutines::reconcile( true );

	datamachine_smoke_assert( true === ( $result['success'] ?? null ), 'locked reconcile() reports success (contention is not a failure)' );
	datamachine_smoke_assert( true === ( $result['skipped'] ?? null ), 'locked reconcile() reports skipped => true' );
	datamachine_smoke_assert( 'locked' === ( $result['reason'] ?? null ), 'locked reconcile() reports a machine-readable reason' );
	datamachine_smoke_assert( array() === WP_Agent_Routine_Registry::$register_calls, 'locked reconcile() never calls boot() — zero registry register() calls' );
	datamachine_smoke_assert( 0 === HashGatedRoutineBackend::$persist_calls, 'locked reconcile() never calls persist()' );

	datamachine_smoke_assert( FlowScheduleReconciliationLock::release( $other_owner ), 'teardown: release the concurrent lock' );

	// --- Lock now free: reconcile() must proceed normally. ---
	datamachine_smoke_reset();
	$result = FlowRoutines::reconcile( true );

	datamachine_smoke_assert( true === ( $result['success'] ?? null ), 'unlocked reconcile() succeeds' );
	datamachine_smoke_assert( empty( $result['skipped'] ), 'unlocked reconcile() is not marked skipped' );
	datamachine_smoke_assert( array( 'flow-1' ) === WP_Agent_Routine_Registry::$register_calls, 'unlocked reconcile() actually boots and registers the flow routine' );
	datamachine_smoke_assert( 2 === HashGatedRoutineBackend::$persist_calls, 'unlocked reconcile() persists from both boot() and the registry-reconcile finally' );

	// --- (c) a throwing registration must not skip persist(). ---
	Flows::$schedules = array(
		array(
			'flow_id'           => 1,
			'flow_name'         => 'Flow One',
			'scheduling_config' => array(
				'enabled'  => true,
				'interval' => 'hourly',
			),
		),
		array(
			'flow_id'           => 2,
			'flow_name'         => 'Flow Two',
			'scheduling_config' => array(
				'enabled'  => true,
				'interval' => 'hourly',
			),
		),
	);
	datamachine_smoke_reset();
	WP_Agent_Routine_Registry::$throw_for = array( 'flow-1' );

	FlowRoutines::boot();

	datamachine_smoke_assert(
		array( 'flow-1', 'flow-2' ) === WP_Agent_Routine_Registry::$register_calls,
		'boot() continues to the next routine after one registration throws'
	);
	datamachine_smoke_assert( 1 === HashGatedRoutineBackend::$persist_calls, 'boot() still persists once even though a registration threw' );
	datamachine_smoke_assert( 1 === count( $GLOBALS['datamachine_smoke_logs'] ), 'the throw is logged once via datamachine_log' );
	$logged_error = $GLOBALS['datamachine_smoke_logs'][0];
	datamachine_smoke_assert( 'error' === ( $logged_error[0] ?? null ), 'the throw is logged at error level' );
	datamachine_smoke_assert( 'flow-1' === ( $logged_error[2]['routine_id'] ?? null ), 'the log identifies the routine that threw' );

	// --- boot()'s top-level finally must persist even on a throw outside the per-routine catch. ---
	datamachine_smoke_reset();
	RecurringScheduleRegistry::$throw_on_all = true;

	$threw = false;
	try {
		FlowRoutines::boot();
	} catch ( \Throwable $error ) {
		$threw = true;
		unset( $error );
	}

	datamachine_smoke_assert( $threw, 'an unexpected throw outside register_routine_logged() propagates out of boot()' );
	datamachine_smoke_assert(
		array( 'flow-1', 'flow-2' ) === WP_Agent_Routine_Registry::$register_calls,
		'both flow routines from the first loop still registered before the second loop threw'
	);
	datamachine_smoke_assert( 1 === HashGatedRoutineBackend::$persist_calls, 'boot() persists via its top-level finally even when it ultimately rethrows' );

	// --- Cache/DB split: a ghost cache entry with no backing DB row must never block acquisition. ---
	//
	// Reproduces the Extra-Chill/data-machine#3492 recovery-time failure
	// mode observed on the underlying agents-api registry lock: a killed
	// request leaves the lock cached (Redis) with no row in `wp_options`.
	// A lock built on add_option()/get_option() would see the cached
	// payload, treat it as live for up to STALE_AFTER seconds, and then
	// fail its compare-and-set forever (there is no DB row to match against).
	datamachine_smoke_reset();
	$lock_option = 'datamachine_flow_schedule_reconciliation_lock';

	datamachine_smoke_assert( ! array_key_exists( $lock_option, $GLOBALS['datamachine_smoke_db'] ), 'setup: no DB row backs the lock' );

	// A "fresh" (not stale) cached payload from a request that died right
	// after writing the cache but before (or without) ever writing the DB row.
	$GLOBALS['datamachine_smoke_options'][ $lock_option ] = array(
		'token'       => 'ghost-owner-token',
		'acquired_at' => time(),
	);

	$acquired = FlowScheduleReconciliationLock::acquire();

	datamachine_smoke_assert( is_string( $acquired ), 'a ghost cache entry with no DB row never blocks acquire() — the DB, not the cache, is authoritative' );
	datamachine_smoke_assert( $acquired !== 'ghost-owner-token', 'the new owner gets its own token, independent of the ghost cache payload' );
	datamachine_smoke_assert( array_key_exists( $lock_option, $GLOBALS['datamachine_smoke_db'] ), 'acquire() actually wrote a backing DB row' );
	datamachine_smoke_assert(
		! array_key_exists( $lock_option, $GLOBALS['datamachine_smoke_options'] ),
		'the ghost cache entry was cleared, not left to mislead the next reader'
	);
	datamachine_smoke_assert(
		! empty( $GLOBALS['datamachine_smoke_cache_delete_calls'] ),
		'acquiring against a cache-only ghost entry defensively calls wp_cache_delete()'
	);
	datamachine_smoke_assert( FlowScheduleReconciliationLock::release( $acquired ), 'teardown: release the lock acquired over the ghost cache entry' );

	// --- Two acquires race against an empty DB: exactly one wins. ---
	datamachine_smoke_reset();
	datamachine_smoke_assert( array() === $GLOBALS['datamachine_smoke_db'], 'setup: the DB starts with no lock row at all' );

	$racer_a = FlowScheduleReconciliationLock::acquire();
	$racer_b = FlowScheduleReconciliationLock::acquire();

	datamachine_smoke_assert( is_string( $racer_a ), 'the first racer against an empty DB wins the lock' );
	datamachine_smoke_assert( is_wp_error( $racer_b ), 'the second racer against the now-occupied DB loses' );
	datamachine_smoke_assert(
		'flow_schedule_reconciliation_locked' === $racer_b->get_error_code(),
		'the losing racer gets the machine-readable lock error, not a crash or a silently-granted duplicate token'
	);
	datamachine_smoke_assert( 1 === count( $GLOBALS['datamachine_smoke_db'] ), 'exactly one lock row exists after the race — no duplicate rows, no torn state' );
	datamachine_smoke_assert( FlowScheduleReconciliationLock::release( $racer_a ), 'teardown: release the winning racer\'s lock' );

	echo "\nAll flow-routines-reconcile-lock-before-boot assertions passed.\n";
}
