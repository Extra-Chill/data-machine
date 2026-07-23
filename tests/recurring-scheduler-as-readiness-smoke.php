<?php
/**
 * Pure-PHP smoke for RecurringScheduler Action Scheduler datastore readiness.
 *
 * Run with: php tests/recurring-scheduler-as-readiness-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			unset( $code, $message, $data );
		}
	}
}

class ActionScheduler {
	public static bool $initialized = false;

	public static function is_initialized( $function_name = null ): bool {
		unset( $function_name );
		return self::$initialized;
	}
}

$GLOBALS['datamachine_as_next_scheduled_calls'] = 0;

function as_next_scheduled_action( string $hook, ?array $args = null, string $group = '' ) {
	unset( $hook, $args, $group );
	++$GLOBALS['datamachine_as_next_scheduled_calls'];
	return time() + 3600;
}

require_once __DIR__ . '/../inc/Engine/Tasks/ScheduleActionIdentity.php';
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

echo "=== recurring-scheduler-as-readiness-smoke ===\n";

ActionScheduler::$initialized = false;
$result                       = RecurringScheduler::isScheduled( 'datamachine_hook', array(), RecurringScheduler::GROUP );
datamachine_assert( false === $result, 'uninitialized Action Scheduler datastore reports unscheduled' );
datamachine_assert( 0 === $GLOBALS['datamachine_as_next_scheduled_calls'], 'as_next_scheduled_action is not called before datastore initialization' );

ActionScheduler::$initialized = true;
$result                       = RecurringScheduler::isScheduled( 'datamachine_hook', array(), RecurringScheduler::GROUP );
datamachine_assert( true === $result, 'initialized Action Scheduler datastore delegates to as_next_scheduled_action' );
datamachine_assert( 1 === $GLOBALS['datamachine_as_next_scheduled_calls'], 'as_next_scheduled_action is called after datastore initialization' );

echo "\nAll recurring scheduler AS readiness assertions passed.\n";
