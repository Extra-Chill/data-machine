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

		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			unset( $data );
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function add_data( array $data ): void {
			unset( $data );
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

class DmRecurringSchedulerFakeSchedule {
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

function datamachine_rs_key( string $hook, array $args, string $group ): string {
	return $group . '|' . $hook . '|' . serialize( $args );
}

/**
 * Seed an additional pending action for a signature.
 *
 * The store keeps a LIST per signature so the harness can model the live
 * duplicate-chain bug (two parallel pending actions for one hook/args/group).
 */
function datamachine_rs_seed_action( string $hook, array $args, string $group, DmRecurringSchedulerFakeSchedule $schedule ): void {
	$key = datamachine_rs_key( $hook, $args, $group );
	if ( ! isset( $GLOBALS['datamachine_rs_actions'][ $key ] ) ) {
		$GLOBALS['datamachine_rs_actions'][ $key ] = array();
	}
	$GLOBALS['datamachine_rs_actions'][ $key ][] = new DmRecurringSchedulerFakeAction( $schedule );
}

function datamachine_rs_pending_count( string $hook, array $args, string $group ): int {
	$key = datamachine_rs_key( $hook, $args, $group );
	return isset( $GLOBALS['datamachine_rs_actions'][ $key ] ) ? count( $GLOBALS['datamachine_rs_actions'][ $key ] ) : 0;
}

function datamachine_rs_reset(): void {
	$GLOBALS['datamachine_rs_actions']             = array();
	$GLOBALS['datamachine_rs_scheduled_single']    = 0;
	$GLOBALS['datamachine_rs_scheduled_recurring'] = 0;
	$GLOBALS['datamachine_rs_scheduled_cron']      = 0;
	$GLOBALS['datamachine_rs_unscheduled']         = 0;
	ActionScheduler::$initialized         = true;
}

function datamachine_rs_counter( string $key ): int {
	return (int) ( $GLOBALS[ $key ] ?? 0 );
}

function as_get_scheduled_actions( array $args = array(), $return_format = OBJECT ): array {
	$key      = datamachine_rs_key( $args['hook'], $args['args'] ?? array(), $args['group'] ?? '' );
	$matches  = $GLOBALS['datamachine_rs_actions'][ $key ] ?? array();
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
	if ( empty( $GLOBALS['datamachine_rs_actions'][ $key ] ) ) {
		return false;
	}

	$first = reset( $GLOBALS['datamachine_rs_actions'][ $key ] );
	return $first->get_schedule()->get_date()->getTimestamp();
}

function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
	++$GLOBALS['datamachine_rs_unscheduled'];
	unset( $GLOBALS['datamachine_rs_actions'][ datamachine_rs_key( $hook, $args, $group ) ] );
}

function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
	++$GLOBALS['datamachine_rs_scheduled_single'];
	datamachine_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( false, null, $timestamp ) );
	return 101;
}

function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int {
	++$GLOBALS['datamachine_rs_scheduled_recurring'];
	datamachine_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( true, $interval, $timestamp ) );
	return 202;
}

function as_schedule_cron_action( int $timestamp, string $expression, string $hook, array $args = array(), string $group = '' ): int {
	++$GLOBALS['datamachine_rs_scheduled_cron'];
	datamachine_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( true, $expression, $timestamp ) );
	return 303;
}

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

echo "\nAll recurring scheduler idempotency assertions passed.\n";
