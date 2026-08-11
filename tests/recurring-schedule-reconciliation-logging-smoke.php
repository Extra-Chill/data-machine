<?php
/**
 * Pure-PHP smoke for recurring schedule reconciliation logging.
 *
 * Run with: php tests/recurring-schedule-reconciliation-logging-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Engine\Tasks {
	$real_wordpress = function_exists( 'add_action' );
	if ( ! defined( 'DATAMACHINE_RECONCILIATION_REAL_WORDPRESS' ) ) {
		define( 'DATAMACHINE_RECONCILIATION_REAL_WORDPRESS', $real_wordpress );
	}

	if ( ! $real_wordpress ) {
		class RecurringScheduleRegistry {
			public static array $schedules = array();

			public static function all(): array {
				return self::$schedules;
			}

			public static function hookFor( array $schedule ): string {
				return 'datamachine_recurring_' . $schedule['schedule_id'];
			}

			public static function isEnabled( array $schedule ): bool {
				unset( $schedule );
				return true;
			}
		}

		class RecurringScheduler {
			public static array $results = array();

			public static function ensureSchedule( string $hook, array $args, string $interval, array $options, bool $enabled ) {
				unset( $hook, $args, $interval, $options, $enabled );
				return array_shift( self::$results );
			}
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;

			public function __construct( string $code, string $message ) {
				$this->code    = $code;
				$this->message = $message;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}
		}
	}
}

namespace DataMachine\Engine\AI\System {
	use DataMachine\Engine\Tasks\RecurringScheduleRegistry;
	use DataMachine\Engine\Tasks\RecurringScheduler;

	$GLOBALS['datamachine_reconciliation_logs'] = array();

	function wp_installing(): bool {
		return false;
	}

	function do_action( string $hook, ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['datamachine_reconciliation_logs'][] = $args;
		}
	}

	function datamachine_reconciliation_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	require_once __DIR__ . '/../inc/Engine/AI/System/SystemAgentServiceProvider.php';

	echo "=== recurring-schedule-reconciliation-logging-smoke ===\n";

	echo "\n[1] request bootstrap does not reconcile the registry\n";
	$provider_source = file_get_contents( __DIR__ . '/../inc/Engine/AI/System/SystemAgentServiceProvider.php' ) ?: '';
	datamachine_reconciliation_assert( ! str_contains( $provider_source, "add_action( 'action_scheduler_init', array( \$this, 'manageRecurringTaskSchedules' ) );" ), 'per-request Action Scheduler initialization does not trigger reconciliation' );
	datamachine_reconciliation_assert( str_contains( $provider_source, "add_action( 'action_scheduler_ensure_recurring_actions', array( \$this, 'manageRecurringTaskSchedules' ) );" ), 'native daily recurring-action repair remains registered' );
	if ( DATAMACHINE_RECONCILIATION_REAL_WORDPRESS ) {
		echo "\nReal WordPress runtime: standalone stub behavior skipped after source contract passed.\n";
		return;
	}

	$provider = ( new \ReflectionClass( SystemAgentServiceProvider::class ) )->newInstanceWithoutConstructor();
	$schedule = array(
		'schedule_id' => 'retention_logs',
		'task_type'   => 'retention_logs',
		'interval'    => 'daily',
	);

	echo "\n[2] expected ownership contention is diagnostic, not a warning\n";
	$lost_schedule                              = $schedule;
	$lost_schedule['schedule_id']               = 'retention_jobs';
	RecurringScheduleRegistry::$schedules       = array( $schedule, $lost_schedule );
	RecurringScheduler::$results                = array(
		new \WP_Error( 'schedule_lock_timeout', 'Another request is updating this schedule; retry shortly.' ),
		new \WP_Error( 'schedule_lock_lost', 'Schedule ownership changed during reconciliation; retry without mutating the existing schedule.' ),
	);
	$GLOBALS['datamachine_reconciliation_logs'] = array();
	$provider->manageRecurringTaskSchedules();
	$timeout_log = $GLOBALS['datamachine_reconciliation_logs'][0] ?? array();
	$lost_log    = $GLOBALS['datamachine_reconciliation_logs'][1] ?? array();
	datamachine_reconciliation_assert( 'debug' === ( $timeout_log[0] ?? '' ) && 'debug' === ( $lost_log[0] ?? '' ), 'expected lease contention is classified below persistent warning level' );
	datamachine_reconciliation_assert( 'schedule_lock_timeout' === ( $timeout_log[2]['error_code'] ?? '' ) && 'schedule_lock_lost' === ( $lost_log[2]['error_code'] ?? '' ), 'both contention diagnostics preserve their scheduler error codes' );

	echo "\n[3] terminal reconciliation failures remain warning-level\n";
	RecurringScheduleRegistry::$schedules       = array( $schedule );
	RecurringScheduler::$results                = array( new \WP_Error( 'invalid_interval', 'Invalid interval: missing' ) );
	$GLOBALS['datamachine_reconciliation_logs'] = array();
	$provider->manageRecurringTaskSchedules();
	$terminal_log = $GLOBALS['datamachine_reconciliation_logs'][0] ?? array();
	datamachine_reconciliation_assert( 'warning' === ( $terminal_log[0] ?? '' ), 'terminal scheduler failures remain observable warnings' );
	datamachine_reconciliation_assert( 'invalid_interval' === ( $terminal_log[2]['error_code'] ?? '' ), 'terminal warning preserves the scheduler error code' );

	echo "\nAll recurring schedule reconciliation logging assertions passed.\n";
}
