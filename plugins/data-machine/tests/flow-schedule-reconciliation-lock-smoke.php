<?php
/**
 * Pure-PHP behavioral smoke for the reconciliation option lock.
 *
 * The fake `$wpdb` models `wp_options` as the sole source of truth (a
 * dedicated `$GLOBALS['datamachine_lock_db']` store, keyed by option name,
 * holding the exact raw serialized string a real `option_value` column
 * would hold). The class under test must never consult `add_option()` /
 * `get_option()` — see tests/flow-routines-reconcile-lock-before-boot-smoke.php
 * for coverage of the cache/DB split this is designed to defend against.
 *
 * Run with: php tests/flow-schedule-reconciliation-lock-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['datamachine_lock_db'] = array();

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

	public function get_row( array $prepared, $output = ARRAY_A ) {
		unset( $output );
		list( $query, $args ) = $prepared;
		if ( ! str_starts_with( $query, 'SELECT' ) ) {
			return null;
		}

		list( , $option_name ) = $args;
		if ( ! array_key_exists( $option_name, $GLOBALS['datamachine_lock_db'] ) ) {
			return null;
		}

		return array( 'option_value' => $GLOBALS['datamachine_lock_db'][ $option_name ] );
	}

	public function query( array $prepared ): int {
		list( $query, $args ) = $prepared;

		if ( str_starts_with( $query, 'INSERT IGNORE' ) ) {
			list( , $option_name, $value ) = $args;
			if ( array_key_exists( $option_name, $GLOBALS['datamachine_lock_db'] ) ) {
				return 0;
			}
			$GLOBALS['datamachine_lock_db'][ $option_name ] = $value;
			return 1;
		}

		if ( str_starts_with( $query, 'UPDATE' ) ) {
			list( , $replacement, $option_name, $expected_raw ) = $args;
			$current_raw                                        = $GLOBALS['datamachine_lock_db'][ $option_name ] ?? null;
			if ( $current_raw !== $expected_raw ) {
				return 0;
			}
			$GLOBALS['datamachine_lock_db'][ $option_name ] = $replacement;
			return 1;
		}

		if ( str_starts_with( $query, 'DELETE' ) ) {
			list( , $option_name, $expected_raw ) = $args;
			$current_raw                          = $GLOBALS['datamachine_lock_db'][ $option_name ] ?? null;
			if ( $current_raw !== $expected_raw ) {
				return 0;
			}
			unset( $GLOBALS['datamachine_lock_db'][ $option_name ] );
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

function wp_cache_delete( string $key, string $group ): bool {
	unset( $key, $group );
	return true;
}

/** Directly poke the fake DB row's decoded value, mirroring a real UPDATE outside this class. */
function datamachine_lock_smoke_db_get( string $option_name ) {
	if ( ! array_key_exists( $option_name, $GLOBALS['datamachine_lock_db'] ) ) {
		return null;
	}
	return maybe_unserialize( $GLOBALS['datamachine_lock_db'][ $option_name ] );
}

function datamachine_lock_smoke_db_set( string $option_name, $value ): void {
	$GLOBALS['datamachine_lock_db'][ $option_name ] = maybe_serialize( $value );
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

$option_name            = 'datamachine_flow_schedule_reconciliation_lock';
$current                = datamachine_lock_smoke_db_get( $option_name );
$current['acquired_at'] = time() - 1200;
datamachine_lock_smoke_db_set( $option_name, $current );
datamachine_lock_assert( FlowScheduleReconciliationLock::refresh( $first ), 'owner atomically refreshes the lease' );
datamachine_lock_assert(
	(int) datamachine_lock_smoke_db_get( $option_name )['acquired_at'] > time() - 5,
	'refreshed lease receives current timing'
);

$current                = datamachine_lock_smoke_db_get( $option_name );
$current['acquired_at'] = time() - 3600;
datamachine_lock_smoke_db_set( $option_name, $current );
$replacement = FlowScheduleReconciliationLock::acquire();
datamachine_lock_assert( is_string( $replacement ) && $replacement !== $first, 'stale owner is replaced with a new token' );
datamachine_lock_assert( ! FlowScheduleReconciliationLock::release( $first ), 'stale owner cannot release replacement lock' );
datamachine_lock_assert( FlowScheduleReconciliationLock::release( $replacement ), 'replacement owner releases its lock' );
datamachine_lock_assert( ! array_key_exists( $option_name, $GLOBALS['datamachine_lock_db'] ), 'successful release deletes the lock row' );

datamachine_lock_smoke_db_set( $option_name, 'malformed-stale-lock' );
$recovered = FlowScheduleReconciliationLock::acquire();
datamachine_lock_assert( is_string( $recovered ), 'malformed stale lock is recovered atomically' );
datamachine_lock_assert( FlowScheduleReconciliationLock::release( $recovered ), 'recovered lock remains token-safe' );

echo "\nAll reconciliation lock assertions passed.\n";
