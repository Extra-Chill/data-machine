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

		public function compare_and_swap_engine_data( int $job_id, array $expected_data, array $new_data ): array {
			$current = self::$engine_data[ $job_id ] ?? array();
			if ( $current !== $expected_data ) {
				return array(
					'updated'  => false,
					'conflict' => true,
					'error'    => null,
				);
			}

			self::$engine_data[ $job_id ] = $new_data;

			return array(
				'updated'  => true,
				'conflict' => false,
				'error'    => null,
			);
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

	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		function wp_generate_uuid4(): string {
			return '00000000-0000-4000-8000-000000000000';
		}
	}

	require_once $root . '/inc/Core/EngineStateLedger.php';
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
		),
		array(
			'op_id'  => 'op-001',
			'actor'  => 'test-runner',
			'source' => 'engine-state-ledger-smoke',
		)
	);
	$second = \datamachine_append_engine_state_event(
		101,
		'artifact_recorded',
		array( 'artifact_files' => array( 'trace' => 'trace.json' ) ),
		array(
			'op_id'  => 'op-002',
			'actor'  => 'test-runner',
			'source' => 'artifact-writer',
		)
	);
	$once_first = \datamachine_append_engine_state_event_once(
		101,
		'op-once-001',
		'runtime_state_patch',
		array( 'append_once' => array( 'first' => 'kept' ) ),
		array(
			'actor'  => 'test-runner',
			'source' => 'append-once-path',
		)
	);
	$once_duplicate = \DataMachine\Core\EngineStateLedger::appendOnce(
		101,
		'op-once-001',
		'runtime_state_patch',
		array( 'append_once' => array( 'first' => 'duplicate-applied' ) ),
		array(
			'actor'  => 'test-runner',
			'source' => 'append-once-path',
		)
	);
	$once_missing_op = \datamachine_append_engine_state_event_once(
		101,
		'',
		'runtime_state_patch',
		array( 'append_once' => array( 'invalid' => true ) )
	);

	$snapshot        = \datamachine_get_engine_data( 101 );
	$ledger          = \DataMachine\Core\EngineStateLedger::fromSnapshot( $snapshot );
	$once_event      = \DataMachine\Core\EngineStateLedger::findByOpId( $snapshot, 'op-once-001' );
	$replayed        = \DataMachine\Core\EngineStateLedger::replaySnapshotLedger( $snapshot, array( 'existing' => 'snapshot-value', 'nested' => array( 'before' => true ) ) );
	$replay_expected = \DataMachine\Core\EngineStateLedger::snapshotForHashing( $snapshot );

	datamachine_engine_state_ledger_assert( 1 === ( $first['version'] ?? null ), 'first append starts at version 1', $failures, $passes );
	datamachine_engine_state_ledger_assert( 2 === ( $second['version'] ?? null ), 'second append increments version monotonically', $failures, $passes );
	datamachine_engine_state_ledger_assert( 3 === ( $once_first['version'] ?? null ), 'append-once append increments version once', $failures, $passes );
	datamachine_engine_state_ledger_assert( $once_first === $once_duplicate, 'append-once duplicate returns existing ledger event', $failures, $passes );
	datamachine_engine_state_ledger_assert( null === $once_missing_op, 'append-once rejects empty operation id', $failures, $passes );
	datamachine_engine_state_ledger_assert( 3 === count( $ledger ), 'append-once suppresses duplicate ledger entries', $failures, $passes );
	datamachine_engine_state_ledger_assert( 'snapshot-value' === ( $snapshot['existing'] ?? null ), 'snapshot compatibility preserves existing keys', $failures, $passes );
	datamachine_engine_state_ledger_assert( 'alpha' === ( $snapshot['tool_outputs']['first'] ?? null ), 'snapshot projection includes appended patch data', $failures, $passes );
	datamachine_engine_state_ledger_assert( 'kept' === ( $snapshot['append_once']['first'] ?? null ), 'append-once duplicate does not reapply patch data', $failures, $passes );
	datamachine_engine_state_ledger_assert( true === ( $snapshot['nested']['before'] ?? null ) && true === ( $snapshot['nested']['after'] ?? null ), 'snapshot projection recursively merges patches', $failures, $passes );
	datamachine_engine_state_ledger_assert( 1 === ( $ledger[0]['schema_version'] ?? null ), 'ledger records schema version', $failures, $passes );
	datamachine_engine_state_ledger_assert( 'tool_result_recorded' === ( $ledger[0]['event_type'] ?? null ), 'ledger records event type alias', $failures, $passes );
	datamachine_engine_state_ledger_assert( 'op-001' === ( $ledger[0]['op_id'] ?? null ), 'ledger records operation id', $failures, $passes );
	datamachine_engine_state_ledger_assert( 'test-runner' === ( $ledger[0]['actor'] ?? null ), 'ledger records actor', $failures, $passes );
	datamachine_engine_state_ledger_assert( 'engine-state-ledger-smoke' === ( $ledger[0]['source'] ?? null ), 'ledger records source', $failures, $passes );
	datamachine_engine_state_ledger_assert( array( 'tool_outputs' => array( 'first' => 'alpha' ), 'nested' => array( 'after' => true ) ) === ( $ledger[0]['patch'] ?? null ), 'ledger stores replayable patch body', $failures, $passes );
	datamachine_engine_state_ledger_assert( in_array( 'artifact_files', $ledger[1]['patch_keys'] ?? array(), true ), 'ledger records compact patch keys', $failures, $passes );
	datamachine_engine_state_ledger_assert( is_string( $ledger[1]['patch_hash'] ?? null ) && 0 === strpos( $ledger[1]['patch_hash'], 'sha256:' ), 'ledger records deterministic patch hash', $failures, $passes );
	datamachine_engine_state_ledger_assert( is_string( $ledger[1]['pre_snapshot_hash'] ?? null ) && 0 === strpos( $ledger[1]['pre_snapshot_hash'], 'sha256:' ), 'ledger records pre snapshot hash', $failures, $passes );
	datamachine_engine_state_ledger_assert( is_string( $ledger[1]['post_snapshot_hash'] ?? null ) && 0 === strpos( $ledger[1]['post_snapshot_hash'], 'sha256:' ), 'ledger records post snapshot hash', $failures, $passes );
	datamachine_engine_state_ledger_assert( $once_first === $once_event, 'ledger can query by operation id', $failures, $passes );
	datamachine_engine_state_ledger_assert( $replay_expected === $replayed, 'ledger events replay to snapshot projection', $failures, $passes );

	if ( ! empty( $failures ) ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nEngine state ledger smoke passed ({$passes} assertions).\n";
}
