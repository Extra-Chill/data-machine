<?php
/**
 * Pure-PHP behavioral smoke: system schedules honor their declared
 * `first_run_callback` / `first_run_arg` anchor (Extra-Chill/data-machine#3618).
 *
 * Runs the real FlowRoutines::boot() against the real vendored Agents API
 * routine registry and Action Scheduler bridge, backed by an in-memory fake
 * Action Scheduler. Proves:
 *
 *  - an anchored interval schedule's only pending recurring action fires
 *    first at the anchor (not a staggered offset from "now");
 *  - the anchored action passes the bridge's generation fence;
 *  - re-booting (same request or a new request) with an anchor that has
 *    drifted does not reschedule the routine (no churn);
 *  - an interval change re-anchors; disable then re-enable re-anchors;
 *  - unanchored interval schedules keep the staggered first run;
 *  - cron-expression schedules ignore the anchor.
 *
 * Run with: php tests/flow-routines-system-first-run-anchor-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public function get_flow_schedules(): array {
			return array();
		}
	}
}

namespace DataMachine\Engine\Tasks {
	class RecurringScheduleRegistry {
		/** @var array<string, array<string, mixed>> */
		public static array $schedules = array();
		/** @var array<string, bool> */
		public static array $disabled = array();

		public static function all(): array {
			return self::$schedules;
		}

		public static function isOwnedByCurrentSite( array $schedule ): bool {
			unset( $schedule );
			return true;
		}

		public static function isEnabled( array $schedule ): bool {
			return empty( self::$disabled[ $schedule['schedule_id'] ?? '' ] );
		}
	}
}

namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	// ---- WordPress shims --------------------------------------------------.

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

	function sanitize_title( $str ) {
		$str = strtolower( (string) $str );
		$str = preg_replace( '/[^a-z0-9_-]+/', '-', $str ) ?? '';
		return trim( $str, '-' );
	}

	function esc_html( $s ) {
		return $s;
	}

	function wp_generate_uuid4(): string {
		static $seq = 0;
		return sprintf( 'gen-%08d', ++$seq );
	}

	$GLOBALS['smoke_hooks'] = array();
	$GLOBALS['smoke_logs']  = array();

	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $accepted_args );
		$GLOBALS['smoke_hooks'][ $hook ][ $priority ][] = $callback;
	}

	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action( $hook, $callback, $priority, $accepted_args );
	}

	function do_action( string $hook, ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['smoke_logs'][] = $args;
		}
		$callbacks = $GLOBALS['smoke_hooks'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				call_user_func_array( $callback, $args );
			}
		}
	}

	function apply_filters( string $hook, $value, ...$args ) {
		if ( 'datamachine_scheduler_intervals' === $hook ) {
			return array(
				'daily'  => array( 'seconds' => 86400 ),
				'weekly' => array( 'seconds' => 604800 ),
			);
		}
		$callbacks = $GLOBALS['smoke_hooks'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
			}
		}
		return $value;
	}

	$GLOBALS['smoke_options'] = array();

	function add_option( string $name, $value = '', string $deprecated = '', $autoload = null ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $name, $GLOBALS['smoke_options'] ) ) {
			return false;
		}
		$GLOBALS['smoke_options'][ $name ] = $value;
		return true;
	}

	function get_option( string $name, $default = false ) {
		return $GLOBALS['smoke_options'][ $name ] ?? $default;
	}

	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['smoke_options'][ $name ] = $value;
		return true;
	}

	function delete_option( string $name ): bool {
		unset( $GLOBALS['smoke_options'][ $name ] );
		return true;
	}

	// ---- Fake Action Scheduler -------------------------------------------.

	class CronExpression {
		public static function factory( string $expression ): self {
			if ( 5 !== count( preg_split( '/\s+/', trim( $expression ) ) ) ) {
				throw new InvalidArgumentException( 'bad cron' );
			}
			return new self();
		}

		public function getNextRunDate(): DateTime {
			return new DateTime();
		}
	}

	$GLOBALS['smoke_as']         = array();
	$GLOBALS['smoke_as_next_id'] = 0;

	class ActionScheduler_Schedule {
		public function __construct( private int $timestamp = 0, private int $interval = 0, private string $cron = '' ) {}

		public function is_recurring(): bool {
			return $this->interval > 0 || '' !== $this->cron;
		}

		/** @return int|string */
		public function get_recurrence() {
			return '' !== $this->cron ? $this->cron : $this->interval;
		}
	}

	class ActionScheduler_Action {
		public function __construct( private string $hook, private array $args, private ActionScheduler_Schedule $schedule, private string $group ) {}

		public function get_hook(): string {
			return $this->hook;
		}

		public function get_args(): array {
			return $this->args;
		}

		public function get_schedule(): ActionScheduler_Schedule {
			return $this->schedule;
		}

		public function get_group(): string {
			return $this->group;
		}
	}

	class ActionScheduler_Store {
		public const STATUS_PENDING  = 'pending';
		public const STATUS_CANCELED = 'canceled';

		public static function instance(): self {
			static $instance   = null;
			return $instance ??= new self();
		}

		/** @param int|string $action_id Action id. */
		public function fetch_action( $action_id ): ActionScheduler_Action {
			$row = $GLOBALS['smoke_as'][ (int) $action_id ] ?? null;
			if ( null === $row ) {
				throw new RuntimeException( 'unknown action' );
			}
			return new ActionScheduler_Action( $row['hook'], $row['args'], new ActionScheduler_Schedule( $row['timestamp'], $row['interval'], $row['cron'] ), $row['group'] );
		}

		/** @param int|string $action_id Action id. */
		public function get_status( $action_id ): string {
			return $GLOBALS['smoke_as'][ (int) $action_id ]['status'] ?? '';
		}

		/** @param int|string $action_id Action id. */
		public function cancel_action( $action_id ): void {
			if ( isset( $GLOBALS['smoke_as'][ (int) $action_id ] ) ) {
				$GLOBALS['smoke_as'][ (int) $action_id ]['status'] = self::STATUS_CANCELED;
			}
		}
	}

	function smoke_as_save( string $hook, array $args, string $group, int $timestamp, int $interval = 0, string $cron = '' ): int {
		$id                         = ++$GLOBALS['smoke_as_next_id'];
		$GLOBALS['smoke_as'][ $id ] = array(
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
			'timestamp' => $timestamp,
			'interval'  => $interval,
			'cron'      => $cron,
			'status'    => ActionScheduler_Store::STATUS_PENDING,
		);
		return $id;
	}

	function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int {
		return smoke_as_save( $hook, $args, $group, $timestamp, $interval );
	}

	function as_schedule_cron_action( int $timestamp, string $schedule, string $hook, array $args = array(), string $group = '' ): int {
		return smoke_as_save( $hook, $args, $group, $timestamp, 0, $schedule );
	}

	function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
		foreach ( $GLOBALS['smoke_as'] as $id => $row ) {
			if ( $row['hook'] === $hook && $row['group'] === $group && $row['args'] === $args && ActionScheduler_Store::STATUS_PENDING === $row['status'] ) {
				$GLOBALS['smoke_as'][ $id ]['status'] = ActionScheduler_Store::STATUS_CANCELED;
			}
		}
	}

	function as_get_scheduled_actions( array $query = array(), string $return_format = 'OBJECT' ): array {
		unset( $return_format );
		$ids = array();
		foreach ( $GLOBALS['smoke_as'] as $id => $row ) {
			if ( ( $query['hook'] ?? $row['hook'] ) !== $row['hook'] || ( $query['group'] ?? $row['group'] ) !== $row['group'] || ( $query['status'] ?? $row['status'] ) !== $row['status'] ) {
				continue;
			}
			$ids[] = $id;
		}
		return $ids;
	}

	// ---- Modules under test ----------------------------------------------.

	$agents_api_routines = __DIR__ . '/../vendor/wordpress/agents-api/src/Routines/';
	require_once $agents_api_routines . 'class-wp-agent-routine.php';
	require_once $agents_api_routines . 'interface-wp-agent-routine-backend.php';
	require_once $agents_api_routines . 'class-wp-agent-routine-registry.php';
	require_once $agents_api_routines . 'class-wp-agent-routine-action-scheduler-bridge.php';
	require_once $agents_api_routines . 'register-routine-bridge-sync.php';
	require_once __DIR__ . '/../inc/Core/ActionScheduler/GroupRegistrar.php';
	require_once __DIR__ . '/../inc/Engine/Scheduling/FlowRoutines.php';

	use AgentsAPI\AI\Routines\WP_Agent_Routine;
	use AgentsAPI\AI\Routines\WP_Agent_Routine_Action_Scheduler_Bridge;
	use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry;
	use DataMachine\Engine\Scheduling\FlowRoutines;
	use DataMachine\Engine\Tasks\RecurringScheduleRegistry;

	function smoke_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	/** Simulate a fresh request: empty in-memory registry and bridge cache. */
	function smoke_new_request(): void {
		WP_Agent_Routine_Registry::reset();
		WP_Agent_Routine_Action_Scheduler_Bridge::instance()->reset_pending_cache_for_tests();
	}

	/** @return array<int, array<string, mixed>> Pending actions for one routine. */
	function smoke_pending( string $routine_id ): array {
		$rows = array();
		foreach ( $GLOBALS['smoke_as'] as $id => $row ) {
			if ( ActionScheduler_Store::STATUS_PENDING === $row['status'] && ( $row['args']['routine_id'] ?? '' ) === $routine_id ) {
				$rows[ $id ] = $row;
			}
		}
		return $rows;
	}

	function smoke_watermark( string $routine_id ): int {
		$state = get_option( WP_Agent_Routine_Action_Scheduler_Bridge::generation_option_name( $routine_id ), array() );
		return (int) ( $state['watermark'] ?? 0 );
	}

	$GLOBALS['smoke_anchor'] = time() + 3 * 86400 + 4321;

	function smoke_anchor_callback( $arg ) {
		unset( $arg );
		return $GLOBALS['smoke_anchor'];
	}

	echo "flow-routines-system-first-run-anchor-smoke\n";

	// --- Anchor resolution contract. ---
	$now = 1_700_000_000;
	smoke_assert( null === FlowRoutines::system_first_run_anchor( array(), $now ), 'no first_run_* declared resolves to no anchor' );
	smoke_assert(
		strtotime( '+1 day', $now ) === FlowRoutines::system_first_run_anchor(
			array(
				'first_run_callback' => static fn( $arg ) => strtotime( $arg, $now ),
				'first_run_arg'      => '+1 day',
			),
			$now
		),
		'callback is invoked with first_run_arg'
	);
	smoke_assert(
		null !== FlowRoutines::system_first_run_anchor( array( 'first_run_arg' => 'next thursday 15:00 UTC' ) ),
		'a string first_run_arg without a callback is parsed with strtotime'
	);
	smoke_assert(
		null === FlowRoutines::system_first_run_anchor(
			array(
				'first_run_callback' => static fn() => $now - 60,
				'first_run_arg'      => 'x',
			),
			$now
		),
		'a past anchor is ignored'
	);
	smoke_assert(
		null === FlowRoutines::system_first_run_anchor(
			array(
				'first_run_callback' => 'datamachine_not_a_function',
				'first_run_arg'      => 'x',
			),
			$now
		),
		'a non-callable callback is ignored'
	);

	// --- Anchored weekly schedule: first registration. ---
	RecurringScheduleRegistry::$schedules = array(
		'local_digest' => array(
			'schedule_id'        => 'local_digest',
			'interval'           => 'weekly',
			'first_run_callback' => 'smoke_anchor_callback',
			'first_run_arg'      => 'next thursday 15:00 UTC',
			'label'              => 'Thursdays 15:00 UTC',
		),
		'plain_daily'  => array(
			'schedule_id' => 'plain_daily',
			'interval'    => 'daily',
		),
		'cron_digest'  => array(
			'schedule_id'        => 'cron_digest',
			'interval'           => '0 15 * * 4',
			'first_run_callback' => 'smoke_anchor_callback',
			'first_run_arg'      => 'ignored',
		),
	);

	$anchored_id = FlowRoutines::system_routine_id( 'local_digest' );
	$plain_id    = FlowRoutines::system_routine_id( 'plain_daily' );
	$cron_id     = FlowRoutines::system_routine_id( 'cron_digest' );

	smoke_new_request();
	$boot_started = time();
	FlowRoutines::boot();

	$pending = smoke_pending( $anchored_id );
	smoke_assert( 1 === count( $pending ), 'anchored schedule has exactly one pending action after first boot' );
	$first_action_id = (int) array_key_first( $pending );
	$first_row       = $pending[ $first_action_id ];
	smoke_assert( $GLOBALS['smoke_anchor'] === $first_row['timestamp'], 'anchored schedule first run is the declared anchor' );
	smoke_assert( 604800 === $first_row['interval'], 'anchored schedule recurs at the weekly interval from the anchor' );
	smoke_assert( $first_action_id >= smoke_watermark( $anchored_id ), 'anchored action passes the bridge generation fence' );

	$canceled_provisional = 0;
	foreach ( $GLOBALS['smoke_as'] as $row ) {
		if ( ( $row['args']['routine_id'] ?? '' ) === $anchored_id && ActionScheduler_Store::STATUS_CANCELED === $row['status'] ) {
			++$canceled_provisional;
		}
	}
	smoke_assert( 1 === $canceled_provisional, 'the bridge provisional (staggered) action was cancelled' );

	$plain_pending = smoke_pending( $plain_id );
	smoke_assert( 1 === count( $plain_pending ), 'unanchored schedule has one pending action' );
	$plain_row      = reset( $plain_pending );
	$expected_plain = ( new WP_Agent_Routine(
		$plain_id,
		array(
			'ability'  => 'x/y',
			'interval' => 86400,
			'stagger'  => true,
		)
	) )->stagger_offset();
	smoke_assert(
		$plain_row['timestamp'] >= $boot_started + $expected_plain && $plain_row['timestamp'] <= time() + $expected_plain,
		'unanchored schedule keeps the staggered first run'
	);

	$cron_pending = smoke_pending( $cron_id );
	$cron_row     = reset( $cron_pending );
	smoke_assert( 1 === count( $cron_pending ) && '0 15 * * 4' === $cron_row['cron'], 'cron schedule registers its expression' );
	smoke_assert( $GLOBALS['smoke_anchor'] !== $cron_row['timestamp'], 'cron schedule ignores first_run_* anchors' );

	// --- No churn: same-request re-boot, then new-request re-boot with a drifted anchor. ---
	$actions_before           = $GLOBALS['smoke_as_next_id'];
	$GLOBALS['smoke_anchor'] += 3600;
	FlowRoutines::boot();
	smoke_assert( $actions_before === $GLOBALS['smoke_as_next_id'], 'same-request re-boot stores no new actions' );

	smoke_new_request();
	$GLOBALS['smoke_anchor'] += 3600;
	FlowRoutines::boot();
	smoke_assert( $actions_before === $GLOBALS['smoke_as_next_id'], 'new-request re-boot with a drifted anchor stores no new actions' );
	smoke_assert( array( $first_action_id ) === array_keys( smoke_pending( $anchored_id ) ), 'anchored action is left untouched across re-boots' );

	// --- Interval change mints a new chain, which is re-anchored. ---
	RecurringScheduleRegistry::$schedules['local_digest']['interval'] = 'daily';
	smoke_new_request();
	FlowRoutines::boot();
	$pending = smoke_pending( $anchored_id );
	$row     = reset( $pending );
	smoke_assert( 1 === count( $pending ) && 86400 === $row['interval'], 'interval change leaves one pending action at the new interval' );
	smoke_assert( $GLOBALS['smoke_anchor'] === $row['timestamp'], 'interval change re-anchors to the declared first run' );
	smoke_assert( ! isset( $pending[ $first_action_id ] ), 'old weekly chain was cancelled' );

	// --- Disable, then re-enable: the re-enabled chain is anchored. ---
	RecurringScheduleRegistry::$disabled['local_digest'] = true;
	FlowRoutines::boot(); // Same request: routine is in memory, so unregister cancels.
	smoke_assert( array() === smoke_pending( $anchored_id ), 'disabling cancels the anchored chain' );

	unset( RecurringScheduleRegistry::$disabled['local_digest'] );
	$GLOBALS['smoke_anchor'] += 86400;
	smoke_new_request();
	FlowRoutines::boot();
	$pending = smoke_pending( $anchored_id );
	$row     = reset( $pending );
	smoke_assert( 1 === count( $pending ) && $GLOBALS['smoke_anchor'] === $row['timestamp'], 're-enabling anchors the first run to the declared anchor' );

	echo "\nAll flow-routines-system-first-run-anchor assertions passed.\n";
}
