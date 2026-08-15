<?php
/** Executable transactional rollback coverage for packet reconciliation. */

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public const TABLE_NAME = 'datamachine_' . 'jobs';
		public function get_table_name(): string { return 'wp_' . self::TABLE_NAME; }
		public function store_engine_data_in_transaction( int $job_id, array $engine ): bool {
			unset( $job_id );
			$GLOBALS['transaction_smoke_events'][] = 'store';
			$GLOBALS['wpdb']->engine = $engine;
			return true;
		}
		public function publish_committed_engine_data( int $job_id, array $engine ): void { unset( $job_id, $engine ); $GLOBALS['transaction_smoke_events'][] = 'publish'; }
		public function has_retryable_transaction_error(): bool { return str_contains( strtolower( $GLOBALS['wpdb']->last_error ), 'deadlock' ); }
	}
}

namespace DataMachine\Core {
	class ChildJobRecoveryPolicy { public static bool $matches = true; public static function recoveryExecutionMatches( array $engine, int $generation, string $token ): bool { unset( $engine, $generation, $token ); return self::$matches; } }
	class EngineData {}
	class JobStatus {
		public static function isStatusFinal( string $status ): bool { return in_array( $status, array( 'completed', 'failed' ), true ); }
		public static function isStatusSuccess( string $status ): bool { return 'completed' === $status; }
		public static function failed( string $reason ): object { return new class( $reason ) { public function __construct( private string $reason ) {} public function toString(): string { return 'failed - ' . $this->reason; } }; }
	}
	class PacketEngineData { public static function sanitize( array $data, int $job_id ): array { unset( $job_id ); return $data; } }
	class RunMetrics { public static function recordStepResult( int $job_id, string $step, array $data ): void { unset( $job_id, $step, $data ); } }
	class DataPacketStore {}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	class WP_Error { public function __construct( public string $code, public string $message, public mixed $data = null ) {} }

	class PacketReconciliationFakeWpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public array $engine = array();
		public array $rows = array();
		public bool $deadlock_once = false;
		public bool $deadlock_commit_once = false;
		public int $transaction_starts = 0;
		private array $prepared_args = array();
		private ?array $snapshot = null;
		public function prepare( string $query, mixed ...$args ): string { $this->prepared_args = $args; return $query; }
		public function get_row( string $query, string $output ): array {
			unset( $output );
			if ( str_contains( $query, 'engine_data' ) || str_contains( $query, \DataMachine\Core\Database\Jobs\Jobs::TABLE_NAME ) ) {
				return array( 'job_id' => 9, 'status' => 'processing', 'engine_data' => json_encode( $this->engine ) );
			}
			$args = $this->prepared_args;
			foreach ( $this->rows as $row ) {
				if ( ( $row['flow_step_id'] ?? '' ) === ( $args[1] ?? null ) && ( $row['source_type'] ?? '' ) === ( $args[2] ?? null ) && ( $row['item_identifier'] ?? '' ) === ( $args[3] ?? null ) ) {
					return $row;
				}
			}
			return array();
		}
		public function get_var( string $query ): string|false {
			unset( $query );
			if ( $this->deadlock_once ) {
				$this->deadlock_once = false;
				$this->last_error = 'Deadlock found when trying to get lock; try restarting transaction';
				return false;
			}
			$args = $this->prepared_args;
			foreach ( $this->rows as $row ) {
				if ( ( $row['flow_step_id'] ?? '' ) === ( $args[1] ?? null ) && ( $row['source_type'] ?? '' ) === ( $args[2] ?? null ) && ( $row['item_identifier'] ?? '' ) === ( $args[3] ?? null ) && ( $row['claim_token'] ?? '' ) === ( $args[4] ?? null ) && ( $row['status'] ?? '' ) === ( $args[5] ?? null ) ) {
					return (string) $row['claim_token'];
				}
			}
			return false;
		}
		public function query( string $query ): int|false {
			$GLOBALS['transaction_smoke_events'][] = $query;
			if ( 'START TRANSACTION' === $query ) { ++$this->transaction_starts; $this->last_error = ''; $this->snapshot = array( $this->engine, $this->rows ); return 1; }
			if ( 'ROLLBACK' === $query ) { if ( null !== $this->snapshot ) { $this->engine = $this->snapshot[0]; $this->rows = $this->snapshot[1]; } $this->snapshot = null; return 1; }
			if ( 'COMMIT' === $query && $this->deadlock_commit_once ) { $this->deadlock_commit_once = false; $this->last_error = 'Deadlock found when trying to commit; try restarting transaction'; return false; }
			if ( 'COMMIT' === $query ) { $this->snapshot = null; return 1; }
			if ( str_starts_with( $query, 'INSERT INTO' ) ) {
				$args = $this->prepared_args;
				foreach ( $this->rows as $row ) {
					if ( ( $row['flow_step_id'] ?? '' ) === ( $args[1] ?? null ) && ( $row['source_type'] ?? '' ) === ( $args[2] ?? null ) && ( $row['item_identifier'] ?? '' ) === ( $args[3] ?? null ) ) {
						return 0;
					}
				}
				$this->rows[] = array( 'id' => count( $this->rows ) + 1, 'flow_step_id' => $args[1], 'source_type' => $args[2], 'item_identifier' => $args[3], 'job_id' => $args[4], 'status' => $args[5], 'claim_expires_at' => $args[6], 'claim_token' => $args[7] );
				return 1;
			}
			if ( str_starts_with( $query, 'UPDATE ' ) ) {
				$args = $this->prepared_args;
				foreach ( $this->rows as &$row ) {
					if ( ( $row['flow_step_id'] ?? '' ) === ( $args[4] ?? null ) && ( $row['source_type'] ?? '' ) === ( $args[5] ?? null ) && ( $row['item_identifier'] ?? '' ) === ( $args[6] ?? null ) && ( $row['claim_token'] ?? '' ) === ( $args[7] ?? null ) && ( $row['status'] ?? '' ) === ( $args[8] ?? null ) ) {
						$row['status'] = $args[1];
						$row['job_id'] = $args[2];
						return 1;
					}
				}
				unset( $row );
				return 0;
			}
			return 1;
		}
		public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
			unset( $table, $formats, $where_formats );
			foreach ( $this->rows as &$row ) {
				if ( empty( array_diff_assoc( $where, $row ) ) ) { $row = array_merge( $row, $data ); return 1; }
			}
			unset( $row );
			return 0;
		}
		public function delete( string $table, array $where, array $formats ): int|false {
			unset( $table, $formats );
			foreach ( $this->rows as $index => $row ) {
				if ( empty( array_diff_assoc( $where, $row ) ) ) { unset( $this->rows[ $index ] ); return 1; }
			}
			return 0;
		}
	}

	$GLOBALS['wpdb'] = new PacketReconciliationFakeWpdb();
	$GLOBALS['transaction_smoke_claim_filter'] = null;
	$GLOBALS['transaction_smoke_persist'] = true;
	$GLOBALS['transaction_smoke_events'] = array();
	function apply_filters( string $hook, mixed $value, ...$args ): mixed {
		if ( 'datamachine_packet_reconciliation_claim_mutation' === $hook && is_callable( $GLOBALS['transaction_smoke_claim_filter'] ) ) {
			return ( $GLOBALS['transaction_smoke_claim_filter'] )( $value, ...$args );
		}
		if ( 'datamachine_packet_reconciliation_engine_persist' === $hook ) { return $GLOBALS['transaction_smoke_persist']; }
		return $value;
	}
	function do_action( string $hook, mixed ...$args ): void { unset( $hook, $args ); }
	function wp_cache_delete( mixed ...$args ): void { unset( $args ); }
	function wp_cache_set( mixed ...$args ): void { unset( $args ); }
	function wp_rand( int $min, int $max ): int { unset( $max ); return $min; }
	function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return gmdate( 'Y-m-d H:i:s' ); }
	function datamachine_get_engine_data( int $job_id ): array { unset( $job_id ); return $GLOBALS['wpdb']->engine; }

	require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
	require_once __DIR__ . '/../inc/Core/Database/ProcessedItems/ProcessedItems.php';
	require_once __DIR__ . '/../inc/Engine/Actions/Handlers/StepLifecycleHandler.php';

	use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
	use DataMachine\Engine\Actions\Handlers\StepLifecycleHandler;

	$claim = static function ( string $item ): array {
		return array(
			'identity_scope'  => 'scope',
			'source_type'     => 'source',
			'item_identifier' => $item,
			'ownership_token' => 'owner-' . $item,
			'disposition_id'  => ProcessedItems::disposition_identity( 'scope', 'source', $item ),
		);
	};
	$packet = static fn( array $claim ): array => array( 'metadata' => array( ProcessedItems::CLAIM_METADATA_KEY => $claim, ProcessedItems::DISPOSITION_ID_METADATA_KEY => $claim['disposition_id'], 'disposition_id' => $claim['disposition_id'], 'packet_disposition' => 'succeeded' ) );
	$reset = static function () use ( $claim ): array {
		$claims = array( $claim( 'first' ), $claim( 'second' ), $claim( 'third' ) );
		$GLOBALS['wpdb']->engine = array( ProcessedItems::CLAIMS_METADATA_KEY => $claims );
		$GLOBALS['wpdb']->rows = array_map(
			static fn( array $item ): array => array( 'flow_step_id' => $item['identity_scope'], 'source_type' => $item['source_type'], 'item_identifier' => $item['item_identifier'], 'claim_token' => $item['ownership_token'], 'status' => 'claimed' ),
			$claims
		);
		return $claims;
	};
	$failures = 0;
	$passes = 0;
	$assert = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void { if ( $condition ) { ++$passes; echo "  [PASS] {$label}\n"; } else { ++$failures; echo "  [FAIL] {$label}\n"; } };

	$claims = $reset();
	$GLOBALS['transaction_smoke_claim_filter'] = static fn( bool $allowed, int $index ): bool => $allowed && 1 !== $index;
	$result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'ai' ), array(), false );
	$assert( ! $result['success'] && 3 === count( $GLOBALS['wpdb']->rows ) && 3 === count( $GLOBALS['wpdb']->engine[ ProcessedItems::CLAIMS_METADATA_KEY ] ), 'mid-batch mutation failure rolls back rows and engine' );

	$claims = $reset();
	$GLOBALS['transaction_smoke_claim_filter'] = null;
	$GLOBALS['transaction_smoke_persist'] = false;
	$result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'ai' ), array( $packet( $claims[1] ) ), true );
	$assert( ! $result['success'] && 3 === count( $GLOBALS['wpdb']->rows ) && 3 === count( $GLOBALS['wpdb']->engine[ ProcessedItems::CLAIMS_METADATA_KEY ] ), 'final engine persistence failure rolls back prior claim releases' );

	$GLOBALS['transaction_smoke_persist'] = true;
	$result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'ai' ), array( $packet( $claims[1] ) ), true );
	$retained = $GLOBALS['wpdb']->engine[ ProcessedItems::CLAIMS_METADATA_KEY ] ?? array();
	$assert( $result['success'] && 1 === count( $retained ) && $claims[1]['disposition_id'] === $retained[0]['disposition_id'] && 1 === count( $GLOBALS['wpdb']->rows ), 'retry completes exact replacement after rollback' );

	$claims = $reset();
	$GLOBALS['transaction_smoke_events'] = array();
	$result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'ai' ), array( $packet( $claims[1] ) ), true );
	$commit_position  = array_search( 'COMMIT', $GLOBALS['transaction_smoke_events'], true );
	$publish_position = array_search( 'publish', $GLOBALS['transaction_smoke_events'], true );
	$assert( $result['success'] && false !== $commit_position && false !== $publish_position && $publish_position > $commit_position, 'cache publication occurs only after transaction commit' );

	$claims = $reset();
	$GLOBALS['wpdb']->deadlock_once = true;
	$starts = $GLOBALS['wpdb']->transaction_starts;
	$result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'ai' ), array( $packet( $claims[1] ) ), true );
	$assert( $result['success'] && 2 === $GLOBALS['wpdb']->transaction_starts - $starts, 'deadlock restarts the complete reconciliation transaction once' );

	$claims = $reset();
	$GLOBALS['wpdb']->deadlock_commit_once = true;
	$starts = $GLOBALS['wpdb']->transaction_starts;
	$result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'ai' ), array( $packet( $claims[1] ) ), true );
	$assert( $result['success'] && 2 === $GLOBALS['wpdb']->transaction_starts - $starts && 1 === count( $GLOBALS['wpdb']->rows ), 'commit-time deadlock retries the whole reconciliation transaction without duplicate claim effects' );

	$malformed = $claim( 'malformed' );
	$malformed['disposition_id'] = str_repeat( 'f', 64 );
	$GLOBALS['wpdb']->engine = array( ProcessedItems::CLAIM_METADATA_KEY => $malformed );
	$result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'ai' ), array(), false );
	$assert( ! $result['success'], 'malformed persisted claim metadata fails closed' );
	$GLOBALS['wpdb']->engine = array( 'ordinary_non_fetch_state' => true );
	$result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'transform' ), array(), true );
	$assert( $result['success'] && ! $result['handled'], 'claimless non-fetch pipeline remains a compatible no-op' );

	$all_claims = array();
	foreach ( range( 1, 52 ) as $index ) { $all_claims[] = $claim( 'full-' . $index ); }
	$GLOBALS['wpdb']->engine = array( ProcessedItems::CLAIMS_METADATA_KEY => $all_claims );
	$GLOBALS['wpdb']->rows = array_map(
		static fn( array $item, int $index ): array => array( 'id' => $index + 1, 'flow_step_id' => $item['identity_scope'], 'source_type' => $item['source_type'], 'item_identifier' => $item['item_identifier'], 'job_id' => 9, 'claim_token' => $item['ownership_token'], 'status' => 'claimed' ),
		$all_claims,
		array_keys( $all_claims )
	);
	$selected_indexes = array_values( array_filter( range( 0, 51 ), static fn( int $index ): bool => 0 === $index % 2 || 51 === $index ) );
	$selected_packets = array_map( static fn( int $index ): array => $packet( $all_claims[ $index ] ), $selected_indexes );
	$ai_result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'ai', 'flow_step_id' => 'ai-52-27' ), $selected_packets, true );
	$upsert_result = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'upsert', 'flow_step_id' => 'upsert-52-27' ), $selected_packets, true );
	$GLOBALS['wpdb']->query( 'START TRANSACTION' );
	$terminal = StepLifecycleHandler::handleCompleted( 9, $GLOBALS['wpdb']->engine, true );
	$GLOBALS['wpdb']->query( $terminal ? 'COMMIT' : 'ROLLBACK' );
	$processed_rows = array_values( array_filter( $GLOBALS['wpdb']->rows, static fn( array $row ): bool => 'processed' === ( $row['status'] ?? '' ) ) );
	$omitted_indexes = array_values( array_diff( range( 0, 51 ), $selected_indexes ) );
	$reacquired = 0;
	$processed_repository = new ProcessedItems();
	foreach ( $omitted_indexes as $offset => $index ) {
		if ( false !== $processed_repository->claim_item_owned( 'scope', 'source', 'full-' . ( $index + 1 ), 1000 + $offset ) ) { ++$reacquired; }
	}
	$assert( $ai_result['success'] && 27 === $ai_result['retained'] && 25 === $ai_result['omitted'], 'full AI boundary retains explicit non-contiguous 27 and releases 25' );
	$assert( $upsert_result['success'] && 27 === $upsert_result['retained'], 'upsert-like continuation preserves the exact 27 claims' );
	$assert( $terminal && 27 === count( $processed_rows ) && 25 === $reacquired, 'terminal result processes exactly 27 and all 25 omissions are immediately reacquirable' );

	$cleanup_claim = $claim( 'cleanup-state' );
	$cleanup_id    = $cleanup_claim['disposition_id'];
	$GLOBALS['wpdb']->engine = array(
		ProcessedItems::CLAIM_METADATA_KEY => $cleanup_claim,
		'packet_dispositions' => array( $cleanup_id => array( 'disposition' => 'defer_item' ) ),
		'packet_tool_executions' => array( 'handler' => array( $cleanup_id => array( 'state' => 'failed' ) ) ),
		'successful_packet_tool_executions' => array( 'handler' => array( $cleanup_id => 'now' ) ),
	);
	$GLOBALS['wpdb']->rows = array( array( 'flow_step_id' => 'scope', 'source_type' => 'source', 'item_identifier' => 'cleanup-state', 'claim_token' => $cleanup_claim['ownership_token'], 'status' => 'claimed' ) );
	$cleaned = StepLifecycleHandler::reconcileStepOutput( 9, array( 'step_type' => 'ai' ), array(), false );
	$assert( $cleaned['success'] && ! isset( $GLOBALS['wpdb']->engine['packet_dispositions'], $GLOBALS['wpdb']->engine['packet_tool_executions'], $GLOBALS['wpdb']->engine['successful_packet_tool_executions'] ), 'resolved claims compact disposition and reservation state' );

	$claims = $reset();
	$transfer_id = 'prepared-transfer';
	$GLOBALS['wpdb']->engine = array(
		'packet_fanout_transfer' => array( 'transfer_id' => $transfer_id, 'state' => 'prepared', 'claims' => array_column( $claims, null, 'disposition_id' ) ),
	);
	$recovered = StepLifecycleHandler::recoverPreparedFanoutTransfer( 9 );
	$assert( $recovered['success'] && $recovered['restored'] && 3 === count( $GLOBALS['wpdb']->engine[ ProcessedItems::CLAIMS_METADATA_KEY ] ?? array() ), 'stale pre-adoption marker restores currently owned claims' );

	$GLOBALS['wpdb']->engine = array(
		'packet_fanout_transfer' => array( 'transfer_id' => $transfer_id, 'state' => 'prepared', 'claims' => array_column( $claims, null, 'disposition_id' ) ),
		'batch_state' => array( 'checksum' => 'durable-worklist' ),
	);
	$recovered = StepLifecycleHandler::recoverPreparedFanoutTransfer( 9 );
	$assert( $recovered['success'] && $recovered['adopted'] && ! isset( $GLOBALS['wpdb']->engine['packet_fanout_transfer'] ) && ! isset( $GLOBALS['wpdb']->engine[ ProcessedItems::CLAIMS_METADATA_KEY ] ), 'post-adoption recovery clears marker without restoring parent claims' );

	$GLOBALS['wpdb']->engine = array(
		'packet_fanout_transfer' => array( 'transfer_id' => $transfer_id, 'state' => 'prepared', 'claims' => array_column( $claims, null, 'disposition_id' ) ),
	);
	\DataMachine\Core\ChildJobRecoveryPolicy::$matches = false;
	$stale = StepLifecycleHandler::recoverPreparedFanoutTransfer( 9, 2, 'stale-token' );
	$assert( ! $stale['success'] && $stale['stale'] && isset( $GLOBALS['wpdb']->engine['packet_fanout_transfer'] ), 'stale recovery worker cannot restore after takeover' );
	\DataMachine\Core\ChildJobRecoveryPolicy::$matches = true;

	$GLOBALS['wpdb']->engine = array( ProcessedItems::CLAIMS_METADATA_KEY => $claims );
	$renewed = StepLifecycleHandler::renewParkedClaims( 9 );
	$assert( $renewed['success'] && 3 === $renewed['renewed'], 'parked execution renews every token-owned claim atomically' );
	$GLOBALS['wpdb']->rows[0]['claim_token'] = 'replacement-owner';
	$lost = StepLifecycleHandler::renewParkedClaims( 9 );
	$assert( ! $lost['success'], 'parked execution fails closed after ownership loss' );

	$malformed = array( ProcessedItems::CLAIMS_METADATA_KEY => array( $claims[0], 'scalar-corruption' ) );
	$terminal_validation = StepLifecycleHandler::filterTerminalStatus( 'completed', 9, array( 'engine_data' => $malformed ) );
	$assert( $terminal_validation instanceof WP_Error, 'scalar malformed metadata blocks terminal approval without TypeError' );

	echo "packet-reconciliation-transaction-smoke: {$passes} passed, {$failures} failed\n";
	exit( $failures > 0 ? 1 : 0 );
}
