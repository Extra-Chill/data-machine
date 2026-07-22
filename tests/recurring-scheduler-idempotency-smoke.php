<?php
/**
 * Pure-PHP smoke for RecurringScheduler idempotency.
 *
 * Run with: php tests/recurring-scheduler-idempotency-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $data;

		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function add_data( array $data ): void {
			$this->data = $data;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'CronExpression' ) ) {
	class CronExpression {
		private string $expression;

		public static function factory( string $expression ): self {
			return new self( $expression );
		}

		public function __construct( string $expression ) {
			$this->expression = $expression;
		}

		public function getNextRunDate(): DateTimeImmutable {
			return new DateTimeImmutable( '+1 hour' );
		}

		public function __toString(): string {
			return $this->expression;
		}
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, int $timestamp ): string {
		return gmdate( $format, $timestamp );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		if ( 'datamachine_scheduler_intervals' === $hook ) {
			return array(
				'hourly' => array( 'seconds' => 3600 ),
				'daily'  => array( 'seconds' => 86400 ),
			);
		}

		return $value;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

$GLOBALS['datamachine_rs_options']            = array();
$GLOBALS['datamachine_rs_add_option_failures'] = 0;
$GLOBALS['datamachine_rs_add_option_calls']    = 0;

function get_option( string $name, $default = false ) {
	return $GLOBALS['datamachine_rs_options'][ $name ] ?? $default;
}

function add_option( string $name, $value, string $deprecated = '', bool $autoload = false ): bool {
	unset( $deprecated, $autoload );
	++$GLOBALS['datamachine_rs_add_option_calls'];
	if ( $GLOBALS['datamachine_rs_add_option_failures'] > 0 ) {
		--$GLOBALS['datamachine_rs_add_option_failures'];
		return false;
	}
	if ( isset( $GLOBALS['datamachine_rs_options'][ $name ] ) ) {
		return false;
	}
	$GLOBALS['datamachine_rs_options'][ $name ] = $value;
	return true;
}

function update_option( string $name, $value, bool $autoload = false ): bool {
	unset( $autoload );
	$GLOBALS['datamachine_rs_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	if ( ! isset( $GLOBALS['datamachine_rs_options'][ $name ] ) ) {
		return false;
	}
	unset( $GLOBALS['datamachine_rs_options'][ $name ] );
	return true;
}

function wp_generate_uuid4(): string {
	return uniqid( 'dm-test-', true );
}

/**
 * Action Scheduler datastore-readiness stub.
 *
 * RecurringScheduler::isActionSchedulerDataStoreReady() checks:
 *   1. did_action( 'action_scheduler_init' ) > 0 (only when did_action exists)
 *   2. \ActionScheduler::is_initialized()        (only when the class exists)
 *
 * We define \ActionScheduler here so the readiness guard is controllable from
 * the tests via ActionScheduler::$initialized. did_action is intentionally NOT
 * defined so the first branch is skipped and the class flag is authoritative.
 */
class ActionScheduler {
	public static bool $initialized = true;

	public static function is_initialized( $function_name = null ): bool {
		unset( $function_name );
		return self::$initialized;
	}
}

if ( ! class_exists( 'ActionScheduler_Schedule' ) ) {
	abstract class ActionScheduler_Schedule {
		abstract public function is_recurring(): bool;
		abstract public function get_date(): DateTimeImmutable;
	}
}

if ( ! class_exists( 'ActionScheduler_Action' ) ) {
	class ActionScheduler_Action {
		private ActionScheduler_Schedule $schedule;
		private int $priority = 10;

		public function __construct( string $hook, array $args, ActionScheduler_Schedule $schedule, string $group ) {
			unset( $hook, $args, $group );
			$this->schedule = $schedule;
		}

		public function get_schedule(): ActionScheduler_Schedule {
			return $this->schedule;
		}

		public function set_priority( int $priority ): void {
			$this->priority = $priority;
		}
	}
}

if ( ! class_exists( 'ActionScheduler_CanceledSchedule' ) ) {
	class ActionScheduler_CanceledSchedule extends ActionScheduler_Schedule {
		private DateTimeImmutable $date;

		public function __construct( DateTimeImmutable $date ) {
			$this->date = $date;
		}

		public function is_recurring(): bool {
			return false;
		}

		public function get_date(): DateTimeImmutable {
			return $this->date;
		}
	}
}

class DmRecurringSchedulerFakeSchedule extends ActionScheduler_Schedule {
	private bool $recurring;
	/**
	 * @var int|string|null
	 */
	private $recurrence;
	private DateTimeImmutable $date;

	public function __construct( bool $recurring, $recurrence, int $timestamp ) {
		$this->recurring  = $recurring;
		$this->recurrence = $recurrence;
		$this->date       = new DateTimeImmutable( '@' . $timestamp );
	}

	public function is_recurring(): bool {
		return $this->recurring;
	}

	public function get_recurrence() {
		return $this->recurrence;
	}

	public function get_date(): DateTimeImmutable {
		return $this->date;
	}
}

class DmRecurringSchedulerFakeAction {
	private DmRecurringSchedulerFakeSchedule $schedule;

	public function __construct( DmRecurringSchedulerFakeSchedule $schedule ) {
		$this->schedule = $schedule;
	}

	public function get_schedule(): DmRecurringSchedulerFakeSchedule {
		return $this->schedule;
	}
}

$GLOBALS['datamachine_rs_actions']            = array();
$GLOBALS['datamachine_rs_scheduled_single']   = 0;
$GLOBALS['datamachine_rs_scheduled_recurring'] = 0;
$GLOBALS['datamachine_rs_scheduled_cron']      = 0;
$GLOBALS['datamachine_rs_unscheduled']         = 0;
$GLOBALS['datamachine_rs_recurring_unique']    = array();
$GLOBALS['datamachine_rs_cron_unique']         = array();
$GLOBALS['datamachine_rs_schedule_race']       = false;
$GLOBALS['datamachine_rs_reentrant_race']      = false;
$GLOBALS['datamachine_rs_reentrant_result']    = null;
$GLOBALS['datamachine_rs_single_reentrant']    = null;
$GLOBALS['datamachine_rs_steal_lock_on_query'] = false;
$GLOBALS['datamachine_rs_expire_lock_on_query'] = false;

function datamachine_rs_key( string $hook, array $args, string $group ): string {
	return $group . '|' . $hook . '|' . serialize( $args );
}

/**
 * Seed an additional pending action for a signature.
 *
 * The store keeps a LIST per signature so the harness can model the live
 * duplicate-chain bug (two parallel pending actions for one hook/args/group).
 */
function datamachine_rs_seed_action( string $hook, array $args, string $group, DmRecurringSchedulerFakeSchedule $schedule, string $status = 'pending' ): void {
	$key = datamachine_rs_key( $hook, $args, $group );
	if ( ! isset( $GLOBALS['datamachine_rs_actions'][ $key ][ $status ] ) ) {
		$GLOBALS['datamachine_rs_actions'][ $key ][ $status ] = array();
	}
	$GLOBALS['datamachine_rs_actions'][ $key ][ $status ][] = new DmRecurringSchedulerFakeAction( $schedule );
}

function datamachine_rs_pending_count( string $hook, array $args, string $group ): int {
	$key = datamachine_rs_key( $hook, $args, $group );
	return isset( $GLOBALS['datamachine_rs_actions'][ $key ]['pending'] ) ? count( $GLOBALS['datamachine_rs_actions'][ $key ]['pending'] ) : 0;
}

function datamachine_rs_reset(): void {
	$GLOBALS['datamachine_rs_actions']             = array();
	$GLOBALS['datamachine_rs_scheduled_single']    = 0;
	$GLOBALS['datamachine_rs_scheduled_recurring'] = 0;
	$GLOBALS['datamachine_rs_scheduled_cron']      = 0;
	$GLOBALS['datamachine_rs_unscheduled']         = 0;
	$GLOBALS['datamachine_rs_recurring_unique']    = array();
	$GLOBALS['datamachine_rs_cron_unique']         = array();
	$GLOBALS['datamachine_rs_schedule_race']       = false;
	$GLOBALS['datamachine_rs_reentrant_race']      = false;
	$GLOBALS['datamachine_rs_reentrant_result']    = null;
	$GLOBALS['datamachine_rs_single_reentrant']    = null;
	$GLOBALS['datamachine_rs_steal_lock_on_query'] = false;
	$GLOBALS['datamachine_rs_expire_lock_on_query'] = false;
	$GLOBALS['datamachine_rs_options']             = array();
	$GLOBALS['datamachine_rs_add_option_failures'] = 0;
	$GLOBALS['datamachine_rs_add_option_calls']    = 0;
	ActionScheduler::$initialized         = true;
}

function datamachine_rs_counter( string $key ): int {
	return (int) ( $GLOBALS[ $key ] ?? 0 );
}

function as_get_scheduled_actions( array $args = array(), $return_format = OBJECT ): array {
	if ( $GLOBALS['datamachine_rs_expire_lock_on_query'] ) {
		$GLOBALS['datamachine_rs_expire_lock_on_query'] = false;
		foreach ( $GLOBALS['datamachine_rs_options'] as $name => $payload ) {
			if ( str_starts_with( $name, 'datamachine_schedule_lock_' ) && is_array( $payload ) ) {
				$GLOBALS['datamachine_rs_options'][ $name ]['expires_at'] = time() - 1;
				break;
			}
		}
	}
	if ( $GLOBALS['datamachine_rs_steal_lock_on_query'] ) {
		$GLOBALS['datamachine_rs_steal_lock_on_query'] = false;
		foreach ( $GLOBALS['datamachine_rs_options'] as $name => $payload ) {
			if ( str_starts_with( $name, 'datamachine_schedule_lock_' ) && is_array( $payload ) ) {
				$GLOBALS['datamachine_rs_options'][ $name ]['token'] = 'stale-owner-taken-over';
				break;
			}
		}
	}
	$key      = datamachine_rs_key( $args['hook'], $args['args'] ?? array(), $args['group'] ?? '' );
	$status   = $args['status'] ?? 'pending';
	$matches  = $GLOBALS['datamachine_rs_actions'][ $key ][ $status ] ?? array();
	$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : count( $matches );
	if ( $per_page > 0 ) {
		$matches = array_slice( $matches, 0, $per_page );
	}

	if ( 'ids' === $return_format ) {
		return array_keys( $matches );
	}

	return $matches;
}

function as_next_scheduled_action( string $hook, ?array $args = null, string $group = '' ) {
	$key = datamachine_rs_key( $hook, $args ?? array(), $group );
	if ( empty( $GLOBALS['datamachine_rs_actions'][ $key ]['pending'] ) ) {
		return false;
	}

	$first = reset( $GLOBALS['datamachine_rs_actions'][ $key ]['pending'] );
	return $first->get_schedule()->get_date()->getTimestamp();
}

function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
	++$GLOBALS['datamachine_rs_unscheduled'];
	unset( $GLOBALS['datamachine_rs_actions'][ datamachine_rs_key( $hook, $args, $group ) ]['pending'] );
}

function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
	++$GLOBALS['datamachine_rs_scheduled_single'];
	if ( is_array( $GLOBALS['datamachine_rs_single_reentrant'] ) ) {
		$requested                                  = $GLOBALS['datamachine_rs_single_reentrant'];
		$GLOBALS['datamachine_rs_single_reentrant'] = null;
		$GLOBALS['datamachine_rs_reentrant_result'] = \DataMachine\Engine\Tasks\RecurringScheduler::ensureSchedule(
			$hook,
			$args,
			$requested['interval'],
			$requested['options'] + array( 'group' => $group )
		);
	}
	datamachine_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( false, null, $timestamp ) );
	return 101;
}

function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '', bool $unique = false ): int {
	++$GLOBALS['datamachine_rs_scheduled_recurring'];
	$GLOBALS['datamachine_rs_recurring_unique'][] = $unique;
	if ( $GLOBALS['datamachine_rs_reentrant_race'] ) {
		$GLOBALS['datamachine_rs_reentrant_race']   = false;
		$GLOBALS['datamachine_rs_reentrant_result'] = \DataMachine\Engine\Tasks\RecurringScheduler::ensureSchedule( $hook, $args, 'hourly', array( 'group' => $group ) );
	}
	if ( $GLOBALS['datamachine_rs_schedule_race'] ) {
		$GLOBALS['datamachine_rs_schedule_race'] = false;
		datamachine_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( true, $interval, $timestamp ) );
		return 0;
	}
	if ( $unique && datamachine_rs_pending_count( $hook, $args, $group ) > 0 ) {
		return 0;
	}
	datamachine_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( true, $interval, $timestamp ) );
	return 202;
}

function as_schedule_cron_action( int $timestamp, string $expression, string $hook, array $args = array(), string $group = '', bool $unique = false ): int {
	++$GLOBALS['datamachine_rs_scheduled_cron'];
	$GLOBALS['datamachine_rs_cron_unique'][] = $unique;
	datamachine_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( true, $expression, $timestamp ) );
	return 303;
}

require_once __DIR__ . '/../inc/Core/OptionLeaseStore.php';
require_once __DIR__ . '/../inc/Engine/Tasks/GenerationFencedAction.php';
require_once __DIR__ . '/../inc/Engine/Tasks/RecurringScheduler.php';

use DataMachine\Engine\Tasks\RecurringScheduler;

function datamachine_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}

	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

function datamachine_assert_schedule_result( $result ): array {
	datamachine_assert( is_array( $result ), 'scheduler returned result array' );
	return $result;
}

echo "=== recurring-scheduler-idempotency-smoke ===\n";

echo "\n[1] matching recurring schedule is preserved\n";
datamachine_rs_reset();
datamachine_rs_seed_action( 'datamachine_hook', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 86400, time() + 600 ) );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_hook', array(), 'daily' ) );
datamachine_assert( true === ( $result['preserved'] ?? false ), 'result marks preserved recurring action' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'matching recurring action was not unscheduled' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_scheduled_recurring' ), 'matching recurring action was not recreated' );

echo "\n[2] changed recurring interval is replaced\n";
datamachine_rs_reset();
datamachine_rs_seed_action( 'datamachine_hook', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 86400, time() + 600 ) );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_hook', array(), 'hourly' ) );
datamachine_assert( empty( $result['preserved'] ), 'changed recurring action is not marked preserved' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'changed recurring action was unscheduled' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_scheduled_recurring' ), 'changed recurring action was recreated' );
datamachine_assert( 3600 === $result['interval_seconds'], 'new recurring interval is applied' );

echo "\n[3] force_reschedule replaces an otherwise matching recurring schedule\n";
datamachine_rs_reset();
datamachine_rs_seed_action( 'datamachine_hook', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 86400, time() + 600 ) );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_hook', array(), 'daily', array( 'force_reschedule' => true ) ) );
datamachine_assert( empty( $result['preserved'] ), 'forced recurring action is not marked preserved' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'forced recurring action was unscheduled' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_scheduled_recurring' ), 'forced recurring action was recreated' );

echo "\n[4] matching cron schedule is preserved\n";
datamachine_rs_reset();
datamachine_rs_seed_action( 'datamachine_cron', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, '0 0 * * *', time() + 600 ) );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_cron', array(), 'cron', array( 'cron_expression' => '0 0 * * *' ) ) );
datamachine_assert( true === ( $result['preserved'] ?? false ), 'result marks preserved cron action' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'matching cron action was not unscheduled' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_scheduled_cron' ), 'matching cron action was not recreated' );

echo "\n[5] changed cron expression is replaced\n";
datamachine_rs_reset();
datamachine_rs_seed_action( 'datamachine_cron', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, '0 0 * * *', time() + 600 ) );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_cron', array(), 'cron', array( 'cron_expression' => '15 0 * * *' ) ) );
datamachine_assert( empty( $result['preserved'] ), 'changed cron action is not marked preserved' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'changed cron action was unscheduled' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_scheduled_cron' ), 'changed cron action was recreated' );

echo "\n[6] matching one-time timestamp is preserved\n";
datamachine_rs_reset();
$timestamp = time() + 3600;
datamachine_rs_seed_action( 'datamachine_once', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( false, null, $timestamp ) );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_once', array(), 'one_time', array( 'timestamp' => $timestamp ) ) );
datamachine_assert( true === ( $result['preserved'] ?? false ), 'result marks preserved one-time action' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'matching one-time action was not unscheduled' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_scheduled_single' ), 'matching one-time action was not recreated' );

echo "\n[7] changed one-time timestamp is replaced\n";
datamachine_rs_reset();
datamachine_rs_seed_action( 'datamachine_once', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( false, null, $timestamp ) );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_once', array(), 'one_time', array( 'timestamp' => $timestamp + 60 ) ) );
datamachine_assert( empty( $result['preserved'] ), 'changed one-time action is not marked preserved' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'changed one-time action was unscheduled' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_scheduled_single' ), 'changed one-time action was recreated' );

echo "\n[8] calling ensureSchedule twice yields exactly ONE recurring chain (#2512)\n";
datamachine_rs_reset();
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_dup', array(), 'hourly' ) );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_scheduled_recurring' ), 'first call creates one recurring chain' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_dup', array(), RecurringScheduler::GROUP ), 'one pending action after first call' );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_dup', array(), 'hourly' ) );
datamachine_assert( true === ( $result['preserved'] ?? false ), 'second call preserves the single matching chain' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_scheduled_recurring' ), 'second call does NOT create a second chain' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_dup', array(), RecurringScheduler::GROUP ), 'still exactly one pending action after second call' );

echo "\n[9] datastore-not-ready bails instead of creating a duplicate recurring chain (#2512)\n";
datamachine_rs_reset();
// Existing live chain (created earlier when AS was ready).
datamachine_rs_seed_action( 'datamachine_dup', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 3600, time() + 600 ) );
ActionScheduler::$initialized = false; // AS datastore reports NOT ready.
$result                       = RecurringScheduler::ensureSchedule( 'datamachine_dup', array(), 'hourly' );
datamachine_assert( $result instanceof WP_Error, 'not-ready datastore returns WP_Error' );
datamachine_assert( 'datastore_not_ready' === $result->get_error_code(), 'error code is datastore_not_ready' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_scheduled_recurring' ), 'no new recurring chain created while AS not ready' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'no unschedule attempted while AS not ready' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_dup', array(), RecurringScheduler::GROUP ), 'existing chain left intact — still exactly one' );

echo "\n[10] an already-duplicated recurring signature self-heals to one chain (#2512)\n";
datamachine_rs_reset();
// Simulate the live bug: two parallel pending chains for the same signature.
datamachine_rs_seed_action( 'datamachine_dup', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 3600, time() + 600 ) );
datamachine_rs_seed_action( 'datamachine_dup', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 3600, time() + 700 ) );
datamachine_assert( 2 === datamachine_rs_pending_count( 'datamachine_dup', array(), RecurringScheduler::GROUP ), 'two duplicate chains seeded' );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_dup', array(), 'hourly' ) );
datamachine_assert( true === ( $result['deduplicated'] ?? false ), 'result marks deduplicated' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'duplicates were unscheduled' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_scheduled_recurring' ), 'exactly one chain recreated' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_dup', array(), RecurringScheduler::GROUP ), 'collapsed to exactly one pending action' );

echo "\n[11] cron branch also bails when datastore not ready (#2512)\n";
datamachine_rs_reset();
ActionScheduler::$initialized = false;
$result                       = RecurringScheduler::ensureSchedule( 'datamachine_cron', array(), 'cron', array( 'cron_expression' => '0 0 * * *' ) );
datamachine_assert( $result instanceof WP_Error, 'cron not-ready datastore returns WP_Error' );
datamachine_assert( 'datastore_not_ready' === $result->get_error_code(), 'cron error code is datastore_not_ready' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_scheduled_cron' ), 'no cron chain created while AS not ready' );

echo "\n[12] one_time branch also bails when datastore not ready (#2512)\n";
datamachine_rs_reset();
ActionScheduler::$initialized = false;
$result                       = RecurringScheduler::ensureSchedule( 'datamachine_once', array(), 'one_time', array( 'timestamp' => time() + 3600 ) );
datamachine_assert( $result instanceof WP_Error, 'one_time not-ready datastore returns WP_Error' );
datamachine_assert( 'datastore_not_ready' === $result->get_error_code(), 'one_time error code is datastore_not_ready' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_scheduled_single' ), 'no one_time action created while AS not ready' );

echo "\n[13] an already-duplicated cron signature self-heals to one chain (#2512)\n";
datamachine_rs_reset();
datamachine_rs_seed_action( 'datamachine_cron', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, '0 0 * * *', time() + 600 ) );
datamachine_rs_seed_action( 'datamachine_cron', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, '0 0 * * *', time() + 700 ) );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_cron', array(), 'cron', array( 'cron_expression' => '0 0 * * *' ) ) );
datamachine_assert( true === ( $result['deduplicated'] ?? false ), 'cron result marks deduplicated' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'cron duplicates were unscheduled' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_scheduled_cron' ), 'exactly one cron chain recreated' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_cron', array(), RecurringScheduler::GROUP ), 'cron collapsed to exactly one pending action' );

echo "\n[14] concurrent recurring reconciliation creates exactly one unique chain (#2892)\n";
datamachine_rs_reset();
$GLOBALS['datamachine_rs_schedule_race'] = true;
$result                                  = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_recurring_retention_as_actions', array(), 'hourly' ) );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_recurring_retention_as_actions', array(), RecurringScheduler::GROUP ), 'concurrent reconciliation preserves exactly one pending chain' );
datamachine_assert( array( true ) === $GLOBALS['datamachine_rs_recurring_unique'], 'recurring reconciliation requests Action Scheduler uniqueness' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'fenced reconciliation clears the pre-existing slot before atomic unique creation' );

echo "\n[15] all recurring and cron replacement paths request Action Scheduler uniqueness (#2892)\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_recurring_retention_as_actions', array(), 'hourly' ) );
datamachine_assert( array( true ) === $GLOBALS['datamachine_rs_recurring_unique'], 'missing enabled recurrence is restored as a unique action' );
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_cron', array(), 'cron', array( 'cron_expression' => '0 0 * * *' ) ) );
datamachine_assert( array( true ) === $GLOBALS['datamachine_rs_cron_unique'], 'cron replacement is also unique' );

echo "\n[16] Action Scheduler native recurring-ensure hook repairs interrupted schedules (#2892)\n";
$provider_source = file_get_contents( __DIR__ . '/../inc/Engine/AI/System/SystemAgentServiceProvider.php' ) ?: '';
datamachine_assert( str_contains( $provider_source, "add_action( 'action_scheduler_ensure_recurring_actions', array( \$this, 'manageRecurringTaskSchedules' ) );" ), 'provider registers reconciliation on Action Scheduler native recurring-ensure hook' );

echo "\n[17] distinct argument tuples sharing a hook remain independently schedulable\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_per_flow', array( 'flow_id' => 1 ), 'hourly' ) );
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_per_flow', array( 'flow_id' => 2 ), 'hourly' ) );
datamachine_assert( array( false, false ) === $GLOBALS['datamachine_rs_recurring_unique'], 'argument-bearing schedules do not use hook/group-only Action Scheduler uniqueness' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_per_flow', array( 'flow_id' => 1 ), RecurringScheduler::GROUP ), 'first argument tuple has its own pending chain' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_per_flow', array( 'flow_id' => 2 ), RecurringScheduler::GROUP ), 'second argument tuple has its own pending chain' );

echo "\n[18] reentrant argument-bearing reconciliation cannot create a parallel chain\n";
datamachine_rs_reset();
$GLOBALS['datamachine_rs_reentrant_race'] = true;
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_per_flow', array( 42 ), 'hourly' ) );
datamachine_assert( $GLOBALS['datamachine_rs_reentrant_result'] instanceof WP_Error, 'contending reconciliation receives a retryable lock error' );
datamachine_assert( 'schedule_lock_timeout' === $GLOBALS['datamachine_rs_reentrant_result']->get_error_code(), 'contention reports schedule_lock_timeout' );
$lock_data = $GLOBALS['datamachine_rs_reentrant_result']->get_error_data();
datamachine_assert( 409 === ( $lock_data['status'] ?? 0 ), 'lock timeout preserves conflict status metadata' );
datamachine_assert( true === ( $lock_data['retryable'] ?? false ), 'lock timeout is explicitly retryable' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_per_flow', array( 42 ), RecurringScheduler::GROUP ), 'outer owner creates exactly one pending chain' );

echo "\n[19] transient lease contention retries before scheduling\n";
datamachine_rs_reset();
$GLOBALS['datamachine_rs_add_option_failures'] = 1;
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_retry', array( 7 ), 'hourly' ) );
datamachine_assert( $GLOBALS['datamachine_rs_add_option_calls'] >= 2, 'scheduler retries after losing the first atomic lease attempt' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_retry', array( 7 ), RecurringScheduler::GROUP ), 'retry creates one pending chain without action loss' );

echo "\n[20] matching running recurrence owns coverage\n";
datamachine_rs_reset();
datamachine_rs_seed_action( 'datamachine_running', array( 9 ), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 3600, time() ), 'in-progress' );
$result = datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_running', array( 9 ), 'hourly' ) );
datamachine_assert( true === ( $result['preserved'] ?? false ), 'running recurrence is preserved as the current owner' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_scheduled_recurring' ), 'running recurrence does not spawn a parallel pending chain' );

echo "\n[21] concurrent one-time callers create exactly one action\n";
datamachine_rs_reset();
$one_time = time() + 3600;
$GLOBALS['datamachine_rs_single_reentrant'] = array(
	'interval' => 'one_time',
	'options'  => array( 'timestamp' => $one_time ),
);
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_once_race', array( 11 ), 'one_time', array( 'timestamp' => $one_time ) ) );
datamachine_assert( $GLOBALS['datamachine_rs_reentrant_result'] instanceof WP_Error, 'second one-time caller is fenced while the owner schedules' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_once_race', array( 11 ), RecurringScheduler::GROUP ), 'one-time race leaves exactly one pending action' );

echo "\n[22] cross-caller mutation cannot cancel a locked one-time owner\n";
datamachine_rs_reset();
$GLOBALS['datamachine_rs_single_reentrant'] = array(
	'interval' => 'hourly',
	'options'  => array(),
);
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_cross_caller', array( 12 ), 'one_time', array( 'timestamp' => $one_time ) ) );
datamachine_assert( 'schedule_lock_timeout' === $GLOBALS['datamachine_rs_reentrant_result']->get_error_code(), 'cross-caller cadence update cannot enter the owner mutation' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_cross_caller', array( 12 ), RecurringScheduler::GROUP ), 'locked one-time action survives the contending cadence update' );

echo "\n[23] manual transition fences a running recurrence before AS repeat\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_running_disable', array( 13 ), 'hourly' ) );
$old_schedule = new DmRecurringSchedulerFakeSchedule( true, 3600, time() );
$old_action   = RecurringScheduler::fenceStoredAction( new ActionScheduler_Action( 'datamachine_running_disable', array( 13 ), $old_schedule, RecurringScheduler::GROUP ), 'datamachine_running_disable', array( 13 ), $old_schedule, RecurringScheduler::GROUP, 10 );
datamachine_assert( $old_action->get_schedule()->is_recurring(), 'fetched running action starts on the current generation' );
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_running_disable', array( 13 ), 'manual' ) );
datamachine_assert( ! $old_action->get_schedule()->is_recurring(), 'manual transition makes the fetched old action non-recurring before repeat' );
datamachine_assert( 0 === datamachine_rs_pending_count( 'datamachine_running_disable', array( 13 ), RecurringScheduler::GROUP ), 'manual transition leaves no successor' );

echo "\n[24] cadence transition fences the old running schedule\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_running_change', array( 14 ), 'hourly' ) );
$old_schedule = new DmRecurringSchedulerFakeSchedule( true, 3600, time() );
$old_action   = RecurringScheduler::fenceStoredAction( new ActionScheduler_Action( 'datamachine_running_change', array( 14 ), $old_schedule, RecurringScheduler::GROUP ), 'datamachine_running_change', array( 14 ), $old_schedule, RecurringScheduler::GROUP, 10 );
$key          = datamachine_rs_key( 'datamachine_running_change', array( 14 ), RecurringScheduler::GROUP );
$GLOBALS['datamachine_rs_actions'][ $key ]['pending'] = array();
datamachine_rs_seed_action( 'datamachine_running_change', array( 14 ), RecurringScheduler::GROUP, $old_schedule, 'in-progress' );
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_running_change', array( 14 ), 'daily' ) );
datamachine_assert( ! $old_action->get_schedule()->is_recurring(), 'old fetched hourly action cannot repeat after daily transition' );
$new_action = reset( $GLOBALS['datamachine_rs_actions'][ $key ]['pending'] );
datamachine_assert( 86400 === $new_action->get_schedule()->get_recurrence(), 'exactly one daily successor owns the new generation' );

echo "\n[25] stale owner cannot mutate after lease takeover during a slow query\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_stale_owner', array( 15 ), 'hourly' ) );
$GLOBALS['datamachine_rs_steal_lock_on_query'] = true;
$result = RecurringScheduler::ensureSchedule( 'datamachine_stale_owner', array( 15 ), 'daily' );
datamachine_assert( $result instanceof WP_Error, 'owner that lost its lease returns an error' );
datamachine_assert( 'schedule_lock_lost' === $result->get_error_code(), 'stale owner reports schedule_lock_lost' );
datamachine_assert( true === ( $result->get_error_data()['retryable'] ?? false ), 'lost ownership propagates retry metadata' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_stale_owner', array( 15 ), RecurringScheduler::GROUP ), 'stale owner leaves the existing cadence untouched' );

echo "\n[26] ScheduleFlowAbility has no direct Action Scheduler mutation path\n";
$schedule_flow_source    = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/ScheduleFlowAbility.php' ) ?: '';
$plugin_source           = file_get_contents( __DIR__ . '/../data-machine.php' ) ?: '';
$flow_scheduling_source = file_get_contents( __DIR__ . '/../inc/Api/Flows/FlowScheduling.php' ) ?: '';
datamachine_assert( ! str_contains( $schedule_flow_source, 'as_unschedule_all_actions' ), 'schedule-flow ability does not unschedule outside the primitive' );
datamachine_assert( ! str_contains( $schedule_flow_source, 'as_schedule_single_action' ), 'schedule-flow ability does not create one-time actions outside the primitive' );
datamachine_assert( str_contains( $schedule_flow_source, "array( 'interval' => 'manual' )" ), 'manual transition delegates to FlowScheduling' );
datamachine_assert( str_contains( $schedule_flow_source, "'interval'  => 'one_time'" ), 'one-time transition delegates to FlowScheduling' );
datamachine_assert( str_contains( $schedule_flow_source, "'retry_after_ms'" ), 'ability response preserves retry/status metadata' );
datamachine_assert( str_contains( $plugin_source, 'RecurringScheduler::registerGenerationFence()' ), 'generation fence is registered for every Action Scheduler runner request' );
datamachine_assert( str_contains( $flow_scheduling_source, "'force_reschedule' => \$force" ), 'flow force transitions reach the fenced primitive' );

echo "\n[27] forced replacement fences the fetched running generation\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_running_force', array( 16 ), 'hourly' ) );
$old_schedule = new DmRecurringSchedulerFakeSchedule( true, 3600, time() );
$old_action   = RecurringScheduler::fenceStoredAction( new ActionScheduler_Action( 'datamachine_running_force', array( 16 ), $old_schedule, RecurringScheduler::GROUP ), 'datamachine_running_force', array( 16 ), $old_schedule, RecurringScheduler::GROUP, 10 );
$key          = datamachine_rs_key( 'datamachine_running_force', array( 16 ), RecurringScheduler::GROUP );
$GLOBALS['datamachine_rs_actions'][ $key ]['pending'] = array();
datamachine_rs_seed_action( 'datamachine_running_force', array( 16 ), RecurringScheduler::GROUP, $old_schedule, 'in-progress' );
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_running_force', array( 16 ), 'hourly', array( 'force_reschedule' => true ) ) );
datamachine_assert( ! $old_action->get_schedule()->is_recurring(), 'forced replacement invalidates the old fetched recurrence' );
datamachine_assert( 1 === datamachine_rs_pending_count( 'datamachine_running_force', array( 16 ), RecurringScheduler::GROUP ), 'forced replacement installs exactly one successor' );

echo "\n[28] cron transition fences the fetched interval generation\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_running_cron', array( 17 ), 'hourly' ) );
$old_schedule = new DmRecurringSchedulerFakeSchedule( true, 3600, time() );
$old_action   = RecurringScheduler::fenceStoredAction( new ActionScheduler_Action( 'datamachine_running_cron', array( 17 ), $old_schedule, RecurringScheduler::GROUP ), 'datamachine_running_cron', array( 17 ), $old_schedule, RecurringScheduler::GROUP, 10 );
$key          = datamachine_rs_key( 'datamachine_running_cron', array( 17 ), RecurringScheduler::GROUP );
$GLOBALS['datamachine_rs_actions'][ $key ]['pending'] = array();
datamachine_rs_seed_action( 'datamachine_running_cron', array( 17 ), RecurringScheduler::GROUP, $old_schedule, 'in-progress' );
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_running_cron', array( 17 ), 'cron', array( 'cron_expression' => '0 0 * * *' ) ) );
datamachine_assert( ! $old_action->get_schedule()->is_recurring(), 'cron transition invalidates the old fetched interval recurrence' );
$new_action = reset( $GLOBALS['datamachine_rs_actions'][ $key ]['pending'] );
datamachine_assert( '0 0 * * *' === $new_action->get_schedule()->get_recurrence(), 'cron transition installs only the desired cron successor' );

echo "\n[29] expired owner cannot revive its lease or overwrite generation\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_expired_owner', array( 18 ), 'hourly' ) );
$generation_before = '';
foreach ( $GLOBALS['datamachine_rs_options'] as $name => $value ) {
	if ( str_starts_with( $name, 'datamachine_schedule_generation_' ) ) {
		$generation_before = $value;
		break;
	}
}
$GLOBALS['datamachine_rs_expire_lock_on_query'] = true;
$result = RecurringScheduler::ensureSchedule( 'datamachine_expired_owner', array( 18 ), 'daily' );
datamachine_assert( $result instanceof WP_Error, 'expired owner returns an ownership error' );
datamachine_assert( 'schedule_lock_lost' === $result->get_error_code(), 'expired owner cannot refresh after expiry' );
$generation_after = '';
foreach ( $GLOBALS['datamachine_rs_options'] as $name => $value ) {
	if ( str_starts_with( $name, 'datamachine_schedule_generation_' ) ) {
		$generation_after = $value;
		break;
	}
}
datamachine_assert( $generation_before === $generation_after, 'expired owner cannot overwrite the persisted generation' );

echo "\n[30] public unschedule compatibility uses the fenced primitive\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_public_unschedule', array( 19 ), 'hourly' ) );
$GLOBALS['datamachine_rs_unscheduled'] = 0;
$unschedule_result = RecurringScheduler::unschedule( 'datamachine_public_unschedule', array( 19 ) );
datamachine_assert_schedule_result( $unschedule_result );
datamachine_assert( 0 === datamachine_rs_pending_count( 'datamachine_public_unschedule', array( 19 ), RecurringScheduler::GROUP ), 'legacy public unschedule removes the pending schedule' );
datamachine_assert( 1 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'legacy public unschedule performs one fenced mutation' );

echo "\n[31] destructive callers reject or record scheduler failures\n";
$delete_flow_source     = file_get_contents( __DIR__ . '/../inc/Abilities/Flow/DeleteFlowAbility.php' ) ?: '';
$delete_pipeline_source = file_get_contents( __DIR__ . '/../inc/Abilities/Pipeline/DeletePipelineAbility.php' ) ?: '';
$pause_flow_source      = file_get_contents( __DIR__ . '/../inc/Abilities/Flow/PauseFlowAbility.php' ) ?: '';
$create_flow_source     = file_get_contents( __DIR__ . '/../inc/Abilities/Flow/CreateFlowAbility.php' ) ?: '';
$engine_source          = file_get_contents( __DIR__ . '/../inc/Engine/Actions/Engine.php' ) ?: '';
datamachine_assert( str_contains( $delete_flow_source, 'commitDesiredSchedule' ), 'flow deletion commits desired absence under the schedule lease' );
datamachine_assert( str_contains( $delete_pipeline_source, "'schedule_failures'" ), 'pipeline deletion aggregates schedule reconciliation failures' );
datamachine_assert( str_contains( $pause_flow_source, "'status'  => 'pause_error'" ), 'pause reports scheduler failure instead of success' );
datamachine_assert( str_contains( $create_flow_source, "'schedule_cleanup'" ), 'creation rollback records failed schedule compensation' );
datamachine_assert( str_contains( $engine_source, 'Orphaned schedule cleanup deferred after ownership failure' ), 'orphan cleanup records retryable scheduler failure' );
datamachine_assert( str_contains( $schedule_flow_source, 'SCHEDULE_GENERATION_PREFIX' ) || str_contains( file_get_contents( __DIR__ . '/../inc/Engine/Tasks/RecurringScheduler.php' ) ?: '', 'Do not bulk-delete' ), 'generation tombstones document bounded retention and prohibit blind deletion' );

echo "\n[32] generation fence matches bundled Action Scheduler repeat order\n";
$queue_runner_source = file_get_contents( __DIR__ . '/../vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler_Abstract_QueueRunner.php' ) ?: '';
$factory_source      = file_get_contents( __DIR__ . '/../vendor/woocommerce/action-scheduler/classes/ActionScheduler_ActionFactory.php' ) ?: '';
$scheduler_source    = file_get_contents( __DIR__ . '/../inc/Engine/Tasks/RecurringScheduler.php' ) ?: '';
$execute_position    = strpos( $queue_runner_source, '$action->execute()' );
$repeat_position     = strpos( $queue_runner_source, '$action->get_schedule()->is_recurring()' );
datamachine_assert( false !== $execute_position && false !== $repeat_position && $execute_position < $repeat_position, 'bundled AS re-reads the fetched action schedule after callback execution' );
datamachine_assert( str_contains( $factory_source, '$schedule = $action->get_schedule();' ) && str_contains( $factory_source, '$this->store( $new_action )' ), 'bundled AS repeats from the fetched action schedule object' );
datamachine_assert( str_contains( $scheduler_source, "'action_scheduler_stored_action'" ) && str_contains( $scheduler_source, 'cancel_action( $action_id )' ), 'post-store reconciliation cancels a stale native successor' );

echo "\n[33] desired-state persistence failure prevents schedule mutation\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_commit_failure', array( 20 ), 'hourly' ) );
$GLOBALS['datamachine_rs_unscheduled'] = 0;
$result = RecurringScheduler::commitDesiredSchedule(
	'datamachine_commit_failure',
	array( 20 ),
	'daily',
	array(),
	true,
	static fn(): bool => false,
	static fn(): bool => true
);
datamachine_assert( $result instanceof WP_Error, 'failed desired-state commit returns an error' );
datamachine_assert( 'schedule_state_commit_failed' === $result->get_error_code(), 'persistence failure is observable' );
datamachine_assert( true === ( $result->get_error_data()['retryable'] ?? false ), 'persistence failure is explicitly retryable' );
datamachine_assert( 0 === datamachine_rs_counter( 'datamachine_rs_unscheduled' ), 'persistence failure performs no destructive AS mutation' );
$key = datamachine_rs_key( 'datamachine_commit_failure', array( 20 ), RecurringScheduler::GROUP );
$preserved_action = reset( $GLOBALS['datamachine_rs_actions'][ $key ]['pending'] );
datamachine_assert( 3600 === $preserved_action->get_schedule()->get_recurrence(), 'old schedule remains intact when desired state cannot commit' );

echo "\n[34] desired commit and drift recording remain inside the signature lease\n";
datamachine_rs_reset();
datamachine_assert_schedule_result( RecurringScheduler::ensureSchedule( 'datamachine_commit_race', array( 21 ), 'hourly' ) );
$commit_contender = null;
$record_contender = null;
$result = RecurringScheduler::commitDesiredSchedule(
	'datamachine_commit_race',
	array( 21 ),
	'daily',
	array(),
	true,
	static function () use ( &$commit_contender ): bool {
		$commit_contender = RecurringScheduler::ensureSchedule( 'datamachine_commit_race', array( 21 ), 'manual' );
		return true;
	},
	static function () use ( &$record_contender ): bool {
		$record_contender = RecurringScheduler::ensureSchedule( 'datamachine_commit_race', array( 21 ), 'manual' );
		return true;
	}
);
datamachine_assert_schedule_result( $result );
datamachine_assert( $commit_contender instanceof WP_Error && 'schedule_lock_timeout' === $commit_contender->get_error_code(), 'contender cannot mutate between desired commit and reconciliation' );
datamachine_assert( $record_contender instanceof WP_Error && 'schedule_lock_timeout' === $record_contender->get_error_code(), 'contender cannot mutate before reconciliation status persistence' );
$key = datamachine_rs_key( 'datamachine_commit_race', array( 21 ), RecurringScheduler::GROUP );
$committed_action = reset( $GLOBALS['datamachine_rs_actions'][ $key ]['pending'] );
datamachine_assert( 86400 === $committed_action->get_schedule()->get_recurrence(), 'committed desired cadence wins both races' );

echo "\nAll recurring scheduler idempotency assertions passed.\n";
