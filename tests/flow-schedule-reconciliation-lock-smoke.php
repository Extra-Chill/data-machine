<?php
/**
 * Pure-PHP behavioral smoke for the reconciliation option lock.
 *
 * Run with: php tests/flow-schedule-reconciliation-lock-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['datamachine_lock_options'] = array();

class WP_Error {
	public function __construct( private string $code, private string $message ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

class DatamachineLockWpdb {
	public string $options = 'wp_options';

	public function prepare( string $query, ...$args ): array {
		return array( $query, $args );
	}

	public function query( array $prepared ): int {
		list( $query, $args ) = $prepared;
		if ( str_starts_with( $query, 'UPDATE' ) ) {
			list( , $replacement, $option_name, $expected ) = $args;
			$current = $GLOBALS['datamachine_lock_options'][ $option_name ] ?? null;
			if ( maybe_serialize( $current ) !== $expected ) {
				return 0;
			}
			$GLOBALS['datamachine_lock_options'][ $option_name ] = unserialize( $replacement );
			return 1;
		}

		if ( str_starts_with( $query, 'DELETE' ) ) {
			list( , $option_name, $expected ) = $args;
			$current = $GLOBALS['datamachine_lock_options'][ $option_name ] ?? null;
			if ( maybe_serialize( $current ) !== $expected ) {
				return 0;
			}
			unset( $GLOBALS['datamachine_lock_options'][ $option_name ] );
			return 1;
		}

		return 0;
	}
}

$wpdb = new DatamachineLockWpdb();

function wp_generate_uuid4(): string {
	static $counter = 0;
	return 'test-token-' . ++$counter;
}

function add_option( string $name, $value, string $deprecated = '', bool $autoload = true ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $name, $GLOBALS['datamachine_lock_options'] ) ) {
		return false;
	}
	$GLOBALS['datamachine_lock_options'][ $name ] = $value;
	return true;
}

function get_option( string $name, $default = false ) {
	return $GLOBALS['datamachine_lock_options'][ $name ] ?? $default;
}

function maybe_serialize( $value ): string {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
}

function wp_cache_delete( string $key, string $group ): bool {
	unset( $key, $group );
	return true;
}

require_once __DIR__ . '/../inc/Api/Flows/FlowScheduleReconciliationLock.php';

use DataMachine\Api\Flows\FlowScheduleReconciliationLock;

function datamachine_lock_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}

	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

echo "=== flow-schedule-reconciliation-lock-smoke ===\n";

$first = FlowScheduleReconciliationLock::acquire();
datamachine_lock_assert( is_string( $first ), 'first owner atomically acquires the lock' );

$blocked = FlowScheduleReconciliationLock::acquire();
datamachine_lock_assert( $blocked instanceof WP_Error, 'concurrent owner is rejected' );
datamachine_lock_assert( 'flow_schedule_reconciliation_locked' === $blocked->get_error_code(), 'lock rejection is machine-readable' );
datamachine_lock_assert( FlowScheduleReconciliationLock::refresh( $first ), 'same-second heartbeat advances the lease monotonically' );
datamachine_lock_assert( ! FlowScheduleReconciliationLock::refresh( 'wrong-token' ), 'non-owner cannot refresh the lease' );
$GLOBALS['datamachine_lock_options']['datamachine_flow_schedule_reconciliation_lock']['acquired_at'] = time() - 1200;
datamachine_lock_assert( FlowScheduleReconciliationLock::refresh( $first ), 'owner atomically refreshes the lease' );
datamachine_lock_assert(
	(int) $GLOBALS['datamachine_lock_options']['datamachine_flow_schedule_reconciliation_lock']['acquired_at'] > time() - 5,
	'refreshed lease receives current timing'
);

$option_name = 'datamachine_flow_schedule_reconciliation_lock';
$GLOBALS['datamachine_lock_options'][ $option_name ]['acquired_at'] = time() - 3600;
$replacement = FlowScheduleReconciliationLock::acquire();
datamachine_lock_assert( is_string( $replacement ) && $replacement !== $first, 'stale owner is replaced with a new token' );
datamachine_lock_assert( ! FlowScheduleReconciliationLock::release( $first ), 'stale owner cannot release replacement lock' );
datamachine_lock_assert( FlowScheduleReconciliationLock::release( $replacement ), 'replacement owner releases its lock' );
datamachine_lock_assert( ! array_key_exists( $option_name, $GLOBALS['datamachine_lock_options'] ), 'successful release deletes the lock option' );

$GLOBALS['datamachine_lock_options'][ $option_name ] = 'malformed-stale-lock';
$recovered = FlowScheduleReconciliationLock::acquire();
datamachine_lock_assert( is_string( $recovered ), 'malformed stale lock is recovered atomically' );
datamachine_lock_assert( FlowScheduleReconciliationLock::release( $recovered ), 'recovered lock remains token-safe' );

echo "\nAll reconciliation lock assertions passed.\n";
