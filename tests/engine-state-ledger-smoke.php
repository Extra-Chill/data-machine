<?php
/**
 * Smoke test for versioned engine state ledger behavior.
 *
 * Run with: php tests/engine-state-ledger-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public static array $engine_data = array();

		public function retrieve_engine_data( int $job_id ): array {
			return self::$engine_data[ $job_id ] ?? array();
		}

		public function store_engine_data( int $job_id, array $data ): bool {
			self::$engine_data[ $job_id ] = $data;
			return true;
		}
	}
}

namespace {
	$root     = dirname( __DIR__ );
	$failures = array();
	$passes   = 0;

	defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );

	function datamachine_engine_state_ledger_assert( bool $condition, string $label, array &$failures, int &$passes ): void {
		if ( $condition ) {
			++$passes;
			echo "  PASS {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  FAIL {$label}\n";
	}

	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, $group = '' ) {
			return $GLOBALS['datamachine_engine_state_ledger_cache'][ $group ][ $key ] ?? false;
		}
	}

	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $data, $group = '' ): bool {
			$GLOBALS['datamachine_engine_state_ledger_cache'][ $group ][ $key ] = $data;
			return true;
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( string $key ): string {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value ) {
			return json_encode( $value );
		}
	}

	require_once $root . '/inc/Core/EngineData.php';
	require_once $root . '/inc/Engine/Filters/EngineData.php';

	\datamachine_set_engine_data(
		101,
		array(
			'existing' => 'snapshot-value',
			'nested'   => array( 'before' => true ),
		)
	);

	$first = \datamachine_append_engine_state_event(
		101,
		'tool_result_recorded',
		array(
			'tool_outputs' => array( 'first' => 'alpha' ),
			'nested'       => array( 'after' => true ),
		)
	);
	$second = \datamachine_append_engine_state_event(
		101,
		'artifact_recorded',
		array( 'artifact_files' => array( 'trace' => 'trace.json' ) )
	);

	$snapshot = \datamachine_get_engine_data( 101 );
	$ledger   = $snapshot['_engine_state_ledger'] ?? array();

	datamachine_engine_state_ledger_assert( 1 === ( $first['version'] ?? null ), 'first append starts at version 1', $failures, $passes );
	datamachine_engine_state_ledger_assert( 2 === ( $second['version'] ?? null ), 'second append increments version monotonically', $failures, $passes );
	datamachine_engine_state_ledger_assert( 2 === count( $ledger ), 'append path preserves both ledger entries', $failures, $passes );
	datamachine_engine_state_ledger_assert( 'snapshot-value' === ( $snapshot['existing'] ?? null ), 'snapshot compatibility preserves existing keys', $failures, $passes );
	datamachine_engine_state_ledger_assert( 'alpha' === ( $snapshot['tool_outputs']['first'] ?? null ), 'snapshot projection includes appended patch data', $failures, $passes );
	datamachine_engine_state_ledger_assert( true === ( $snapshot['nested']['before'] ?? null ) && true === ( $snapshot['nested']['after'] ?? null ), 'snapshot projection recursively merges patches', $failures, $passes );
	datamachine_engine_state_ledger_assert( in_array( 'artifact_files', $ledger[1]['patch_keys'] ?? array(), true ), 'ledger records compact patch keys', $failures, $passes );
	datamachine_engine_state_ledger_assert( is_string( $ledger[1]['patch_hash'] ?? null ) && 0 === strpos( $ledger[1]['patch_hash'], 'sha256:' ), 'ledger records deterministic patch hash', $failures, $passes );
	datamachine_engine_state_ledger_assert( ! array_key_exists( 'patch', $ledger[1] ?? array() ), 'ledger omits full patch payload by default', $failures, $passes );

	if ( ! empty( $failures ) ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nEngine state ledger smoke passed ({$passes} assertions).\n";
}
