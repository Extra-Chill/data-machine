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

$GLOBALS['dm_rs_actions']            = array();
$GLOBALS['dm_rs_scheduled_single']   = 0;
$GLOBALS['dm_rs_scheduled_recurring'] = 0;
$GLOBALS['dm_rs_scheduled_cron']      = 0;
$GLOBALS['dm_rs_unscheduled']         = 0;

function dm_rs_key( string $hook, array $args, string $group ): string {
	return $group . '|' . $hook . '|' . serialize( $args );
}

function dm_rs_seed_action( string $hook, array $args, string $group, DmRecurringSchedulerFakeSchedule $schedule ): void {
	$GLOBALS['dm_rs_actions'][ dm_rs_key( $hook, $args, $group ) ] = new DmRecurringSchedulerFakeAction( $schedule );
}

function dm_rs_reset(): void {
	$GLOBALS['dm_rs_actions']             = array();
	$GLOBALS['dm_rs_scheduled_single']    = 0;
	$GLOBALS['dm_rs_scheduled_recurring'] = 0;
	$GLOBALS['dm_rs_scheduled_cron']      = 0;
	$GLOBALS['dm_rs_unscheduled']         = 0;
}

function dm_rs_counter( string $key ): int {
	return (int) ( $GLOBALS[ $key ] ?? 0 );
}

function as_get_scheduled_actions( array $args = array(), $return_format = OBJECT ): array {
	$key = dm_rs_key( $args['hook'], $args['args'] ?? array(), $args['group'] ?? '' );
	return isset( $GLOBALS['dm_rs_actions'][ $key ] ) ? array( 1 => $GLOBALS['dm_rs_actions'][ $key ] ) : array();
}

function as_next_scheduled_action( string $hook, ?array $args = null, string $group = '' ) {
	$key = dm_rs_key( $hook, $args ?? array(), $group );
	if ( ! isset( $GLOBALS['dm_rs_actions'][ $key ] ) ) {
		return false;
	}

	return $GLOBALS['dm_rs_actions'][ $key ]->get_schedule()->get_date()->getTimestamp();
}

function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
	++$GLOBALS['dm_rs_unscheduled'];
	unset( $GLOBALS['dm_rs_actions'][ dm_rs_key( $hook, $args, $group ) ] );
}

function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
	++$GLOBALS['dm_rs_scheduled_single'];
	dm_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( false, null, $timestamp ) );
	return 101;
}

function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int {
	++$GLOBALS['dm_rs_scheduled_recurring'];
	dm_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( true, $interval, $timestamp ) );
	return 202;
}

function as_schedule_cron_action( int $timestamp, string $expression, string $hook, array $args = array(), string $group = '' ): int {
	++$GLOBALS['dm_rs_scheduled_cron'];
	dm_rs_seed_action( $hook, $args, $group, new DmRecurringSchedulerFakeSchedule( true, $expression, $timestamp ) );
	return 303;
}

require_once __DIR__ . '/../inc/Engine/Tasks/RecurringScheduler.php';

use DataMachine\Engine\Tasks\RecurringScheduler;

function dm_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}

	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

function dm_assert_schedule_result( $result ): array {
	dm_assert( is_array( $result ), 'scheduler returned result array' );
	return $result;
}

echo "=== recurring-scheduler-idempotency-smoke ===\n";

echo "\n[1] matching recurring schedule is preserved\n";
dm_rs_reset();
dm_rs_seed_action( 'dm_hook', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 86400, time() + 600 ) );
$result = dm_assert_schedule_result( RecurringScheduler::ensureSchedule( 'dm_hook', array(), 'daily' ) );
dm_assert( true === ( $result['preserved'] ?? false ), 'result marks preserved recurring action' );
dm_assert( 0 === dm_rs_counter( 'dm_rs_unscheduled' ), 'matching recurring action was not unscheduled' );
dm_assert( 0 === dm_rs_counter( 'dm_rs_scheduled_recurring' ), 'matching recurring action was not recreated' );

echo "\n[2] changed recurring interval is replaced\n";
dm_rs_reset();
dm_rs_seed_action( 'dm_hook', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 86400, time() + 600 ) );
$result = dm_assert_schedule_result( RecurringScheduler::ensureSchedule( 'dm_hook', array(), 'hourly' ) );
dm_assert( empty( $result['preserved'] ), 'changed recurring action is not marked preserved' );
dm_assert( 1 === dm_rs_counter( 'dm_rs_unscheduled' ), 'changed recurring action was unscheduled' );
dm_assert( 1 === dm_rs_counter( 'dm_rs_scheduled_recurring' ), 'changed recurring action was recreated' );
dm_assert( 3600 === $result['interval_seconds'], 'new recurring interval is applied' );

echo "\n[3] force_reschedule replaces an otherwise matching recurring schedule\n";
dm_rs_reset();
dm_rs_seed_action( 'dm_hook', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, 86400, time() + 600 ) );
$result = dm_assert_schedule_result( RecurringScheduler::ensureSchedule( 'dm_hook', array(), 'daily', array( 'force_reschedule' => true ) ) );
dm_assert( empty( $result['preserved'] ), 'forced recurring action is not marked preserved' );
dm_assert( 1 === dm_rs_counter( 'dm_rs_unscheduled' ), 'forced recurring action was unscheduled' );
dm_assert( 1 === dm_rs_counter( 'dm_rs_scheduled_recurring' ), 'forced recurring action was recreated' );

echo "\n[4] matching cron schedule is preserved\n";
dm_rs_reset();
dm_rs_seed_action( 'dm_cron', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, '0 0 * * *', time() + 600 ) );
$result = dm_assert_schedule_result( RecurringScheduler::ensureSchedule( 'dm_cron', array(), 'cron', array( 'cron_expression' => '0 0 * * *' ) ) );
dm_assert( true === ( $result['preserved'] ?? false ), 'result marks preserved cron action' );
dm_assert( 0 === dm_rs_counter( 'dm_rs_unscheduled' ), 'matching cron action was not unscheduled' );
dm_assert( 0 === dm_rs_counter( 'dm_rs_scheduled_cron' ), 'matching cron action was not recreated' );

echo "\n[5] changed cron expression is replaced\n";
dm_rs_reset();
dm_rs_seed_action( 'dm_cron', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( true, '0 0 * * *', time() + 600 ) );
$result = dm_assert_schedule_result( RecurringScheduler::ensureSchedule( 'dm_cron', array(), 'cron', array( 'cron_expression' => '15 0 * * *' ) ) );
dm_assert( empty( $result['preserved'] ), 'changed cron action is not marked preserved' );
dm_assert( 1 === dm_rs_counter( 'dm_rs_unscheduled' ), 'changed cron action was unscheduled' );
dm_assert( 1 === dm_rs_counter( 'dm_rs_scheduled_cron' ), 'changed cron action was recreated' );

echo "\n[6] matching one-time timestamp is preserved\n";
dm_rs_reset();
$timestamp = time() + 3600;
dm_rs_seed_action( 'dm_once', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( false, null, $timestamp ) );
$result = dm_assert_schedule_result( RecurringScheduler::ensureSchedule( 'dm_once', array(), 'one_time', array( 'timestamp' => $timestamp ) ) );
dm_assert( true === ( $result['preserved'] ?? false ), 'result marks preserved one-time action' );
dm_assert( 0 === dm_rs_counter( 'dm_rs_unscheduled' ), 'matching one-time action was not unscheduled' );
dm_assert( 0 === dm_rs_counter( 'dm_rs_scheduled_single' ), 'matching one-time action was not recreated' );

echo "\n[7] changed one-time timestamp is replaced\n";
dm_rs_reset();
dm_rs_seed_action( 'dm_once', array(), RecurringScheduler::GROUP, new DmRecurringSchedulerFakeSchedule( false, null, $timestamp ) );
$result = dm_assert_schedule_result( RecurringScheduler::ensureSchedule( 'dm_once', array(), 'one_time', array( 'timestamp' => $timestamp + 60 ) ) );
dm_assert( empty( $result['preserved'] ), 'changed one-time action is not marked preserved' );
dm_assert( 1 === dm_rs_counter( 'dm_rs_unscheduled' ), 'changed one-time action was unscheduled' );
dm_assert( 1 === dm_rs_counter( 'dm_rs_scheduled_single' ), 'changed one-time action was recreated' );

echo "\nAll recurring scheduler idempotency assertions passed.\n";
