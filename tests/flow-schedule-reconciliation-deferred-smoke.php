<?php
/**
 * Pure-PHP deferred reconciliation marker smoke.
 *
 * Run with: php tests/flow-schedule-reconciliation-deferred-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Engine\Tasks {
	class RecurringScheduler {
		public static function isReady(): bool {
			return true;
		}
	}
}

namespace DataMachine\Api\Flows {
	class FlowScheduleReconciler {
		public static array $result = array();

		public function reconcile( bool $apply = false ): array {
			unset( $apply );
			return self::$result;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	$GLOBALS['datamachine_deferred_options'] = array();

	function get_option( string $name, $default = false ) {
		return $GLOBALS['datamachine_deferred_options'][ $name ] ?? $default;
	}

	function update_option( string $name, $value, bool $autoload = true ): bool {
		unset( $autoload );
		$GLOBALS['datamachine_deferred_options'][ $name ] = $value;
		return true;
	}

	function delete_option( string $name ): bool {
		unset( $GLOBALS['datamachine_deferred_options'][ $name ] );
		return true;
	}

	function add_action( string $hook, $callback, int $priority = 10 ): void {
		unset( $hook, $callback, $priority );
	}

	function do_action( string $hook, ...$args ): void {
		unset( $hook, $args );
	}

	require_once __DIR__ . '/../inc/migrations/flows.php';

	use DataMachine\Api\Flows\FlowScheduleReconciler;

	function datamachine_deferred_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	echo "=== flow-schedule-reconciliation-deferred-smoke ===\n";

	$marker = 'datamachine_flow_schedule_reconciliation_pending';
	update_option( $marker, array( 'marked_at' => time() ), false );
	FlowScheduleReconciler::$result = array(
		'success'   => false,
		'transient' => true,
		'blocked'   => 1,
	);
	datamachine_reconcile_marked_flow_schedules();
	datamachine_deferred_assert( false !== get_option( $marker, false ), 'blocked transient result retains deferred marker' );

	FlowScheduleReconciler::$result = array(
		'success'  => true,
		'transient' => false,
		'invalid'  => 1,
	);
	datamachine_reconcile_marked_flow_schedules();
	datamachine_deferred_assert( false === get_option( $marker, false ), 'permanent invalid definitions do not retain deferred marker' );

	echo "\nAll deferred reconciliation marker assertions passed.\n";
}
