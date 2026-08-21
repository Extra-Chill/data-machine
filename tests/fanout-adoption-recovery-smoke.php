<?php
/** Executable fanout adoption rollback and crash-recovery coverage. */

namespace DataMachine\Core\Database\BatchItems {
	class BatchItems {
		public const DEFAULT_LEASE_SECONDS = 60;
		public static array $worklists = array();
		public function insert_batch( int $job_id, array $items, array $cleanup ): array {
			unset( $cleanup );
			if ( isset( self::$worklists[ $job_id ] ) ) {
				return array( 'success' => true, 'created' => false, 'existing' => true, 'ownership_token' => '' );
			}
			self::$worklists[ $job_id ] = array( 'items' => $items, 'token' => 'owner-' . $job_id, 'completed' => array() );
			return array( 'success' => true, 'created' => true, 'existing' => false, 'ownership_token' => 'owner-' . $job_id );
		}
		public function discard_owned( int $job_id, string $token ): array {
			unset( $job_id, $token );
			return array( 'success' => true, 'rows' => array(), 'remaining' => false );
		}
		public function delete_owned_batch( int $job_id, string $token ): bool {
			if ( ! isset( self::$worklists[ $job_id ] ) || $token !== self::$worklists[ $job_id ]['token'] ) {
				return false;
			}
			unset( self::$worklists[ $job_id ] );
			return true;
		}
		public function claim_chunk( int $job_id, int $offset, int $limit, int $lease, ?callable $owner = null ): array {
			unset( $lease );
			if ( ! isset( self::$worklists[ $job_id ] ) || ! empty( self::$worklists[ $job_id ]['completed'][ $offset ] ) ) {
				return array();
			}
			if ( null !== $owner && ! $owner() ) {
				return array();
			}
			$rows = array();
			foreach ( array_slice( self::$worklists[ $job_id ]['items'], $offset, $limit, true ) as $index => $payload ) {
				$rows[] = array( 'item_index' => $index, 'payload' => $payload, 'payload_valid' => true, 'payload_checksum' => hash( 'sha256', (string) json_encode( $payload ) ), 'cleanup_context' => array(), 'lease_token' => 'lease-' . $index );
			}
			return $rows;
		}
		public function complete( int $job_id, int $index, string $lease, mixed $result ): bool {
			unset( $lease, $result );
			if ( ! isset( self::$worklists[ $job_id ]['items'][ $index ] ) || ! empty( self::$worklists[ $job_id ]['completed'][ $index ] ) ) {
				return false;
			}
			self::$worklists[ $job_id ]['completed'][ $index ] = true;
			return true;
		}
		public function first_outstanding_index( int $job_id ): ?int {
			foreach ( self::$worklists[ $job_id ]['items'] ?? array() as $index => $item ) {
				unset( $item );
				if ( empty( self::$worklists[ $job_id ]['completed'][ $index ] ) ) {
					return $index;
				}
			}
			return null;
		}
		public function count_completed( int $job_id ): int { return count( self::$worklists[ $job_id ]['completed'] ?? array() ); }
	}
}

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public static array $engines = array();
		public function get_job( int $job_id ): ?array {
			$engine = self::$engines[ $job_id ] ?? \DataMachine\Core\EngineData::$engines[ $job_id ] ?? array();
			return array( 'engine_data' => $engine );
		}
	}
}

namespace DataMachine\Core {
	class PluginSettings { public static function get( string $key, array $default ): array { unset( $key ); return $default; } }
	class DataPacketStore {
		public static function reference_packet_collections_in_value( mixed $value ): mixed { return $value; }
		public static function hydrate_packet_collections_with_status( mixed $value ): array { return array( 'success' => true, 'value' => $value ); }
	}
	class JobStatus { public static function isStatusFinal( string $status ): bool { return false; } }
	class PacketEngineData {}
	class RunMetrics { public static function start( int $job_id, array $context ): void { unset( $job_id, $context ); } }
	class EngineData {
		public static array $engines = array();
		public static function mutate( int $job_id, callable $callback, string $event ): array {
			unset( $event );
			$current = self::$engines[ $job_id ] ?? array();
			$next = $callback( $current );
			if ( ! is_array( $next ) ) {
				return array( 'success' => false, 'snapshot' => $current );
			}
			self::$engines[ $job_id ] = $next;
			return array( 'success' => true, 'snapshot' => $next );
		}
		public static function retrieve( int $job_id ): array { return self::$engines[ $job_id ] ?? array(); }
	}
}

namespace {
	use DataMachine\Core\ActionScheduler\BatchScheduler;
	use DataMachine\Core\Database\BatchItems\BatchItems;
	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
	use DataMachine\Core\EngineData;

	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['fanout_actions'] = array();
	$GLOBALS['fanout_schedule_fails'] = false;
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return gmdate( 'Y-m-d H:i:s' ); }
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
	function datamachine_get_engine_data( int $job_id ): array { return EngineData::$engines[ $job_id ] ?? array(); }
	function do_action( string $hook, mixed ...$args ): void { unset( $hook, $args ); }
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		if ( 'datamachine_batch_engine_adoption_state' === $hook ) {
			return \DataMachine\Engine\Actions\Handlers\StepLifecycleHandler::filterBatchAdoptionState( $value, (string) $args[0], (string) $args[1] );
		}
		if ( 'datamachine_batch_engine_adoption_rollback_state' === $hook ) {
			return \DataMachine\Engine\Actions\Handlers\StepLifecycleHandler::filterBatchAdoptionRollbackState( $value, (string) $args[0], (string) $args[1] );
		}
		return $value;
	}
	function as_has_scheduled_action( string $hook, array $args, string $group ): int {
		foreach ( $GLOBALS['fanout_actions'] as $id => $action ) {
			if ( $hook === $action['hook'] && $args === $action['args'] && $group === $action['group'] ) {
				return $id + 1;
			}
		}
		return 0;
	}
	function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ): int {
		unset( $timestamp );
		if ( $GLOBALS['fanout_schedule_fails'] ) {
			return 0;
		}
		$existing = as_has_scheduled_action( $hook, $args, $group );
		if ( $existing > 0 ) {
			return $existing;
		}
		$GLOBALS['fanout_actions'][] = compact( 'hook', 'args', 'group' );
		return count( $GLOBALS['fanout_actions'] );
	}

	require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
	require_once __DIR__ . '/../inc/Core/Database/ProcessedItems/ProcessedItemDeferrals.php';
	require_once __DIR__ . '/../inc/Core/Database/ProcessedItems/ProcessedItems.php';
	require_once __DIR__ . '/../inc/Engine/Actions/Handlers/StepLifecycleHandler.php';
	require_once __DIR__ . '/../inc/Core/ActionScheduler/GroupRegistrar.php';
	require_once __DIR__ . '/../inc/Core/ActionScheduler/BatchScheduler.php';

	$passes = 0;
	$failures = 0;
	$assert = static function ( bool $condition, string $label ) use ( &$passes, &$failures ): void {
		if ( $condition ) { ++$passes; echo "  [PASS] {$label}\n"; return; }
		++$failures; echo "  [FAIL] {$label}\n";
	};
	$claim = array( 'identity_scope' => 'scope', 'source_type' => 'source', 'item_identifier' => 'one', 'ownership_token' => 'claim-owner' );
	$claim['disposition_id'] = ProcessedItems::disposition_identity( 'scope', 'source', 'one' );
	$item = array( 'metadata' => array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) );

	EngineData::$engines[10] = array(
		'packet_fanout_transfer' => array( 'transfer_id' => 'transfer-10', 'state' => 'prepared', 'claims' => array( $claim['disposition_id'] => $claim ) ),
	);
	$GLOBALS['fanout_schedule_fails'] = true;
	$failed = BatchScheduler::start( 10, 'fanout_hook', array( $item ), array(), 'pipeline', BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE );
	$failed_engine = EngineData::$engines[10];
	$assert( ! $failed['scheduled'], 'initial scheduling failure is reported' );
	$assert( ! isset( $failed_engine['packet_fanout_transfer'], $failed_engine['batch_state'] ), 'failed initial schedule atomically clears adopted transfer and batch state' );
	$assert( isset( ProcessedItems::disposition_claims( $failed_engine )[ $claim['disposition_id'] ] ), 'failed initial schedule restores parent claim ownership' );
	$assert( ! isset( BatchItems::$worklists[10] ), 'restored ownership permits worklist deletion without discarding the claim' );

	$GLOBALS['fanout_schedule_fails'] = false;
	EngineData::$engines[20] = array(
		'packet_fanout_transfer' => array( 'transfer_id' => 'transfer-20', 'state' => 'prepared', 'claims' => array( $claim['disposition_id'] => $claim ) ),
	);
	BatchItems::$worklists[20] = array( 'items' => array( $item ), 'token' => 'crashed-owner', 'completed' => array() );
	$recovered = BatchScheduler::start( 20, 'fanout_hook', array( $item ), array(), 'pipeline', BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE );
	$retried   = BatchScheduler::start( 20, 'fanout_hook', array( $item ), array(), 'pipeline', BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE );
	$assert( $recovered['scheduled'] && $recovered['adopted'], 'existing crash worklist is adopted only after an initial action exists' );
	$assert( $retried['scheduled'] && $retried['adopted'], 'exact retry adopts the same scheduled worklist' );
	$assert( 1 === count( $GLOBALS['fanout_actions'] ), 'existing-worklist recovery creates no duplicate initial actions' );
	$assert( 1 === count( BatchItems::$worklists[20]['items'] ), 'existing-worklist recovery creates no duplicate work items' );
	$children = 0;
	$create_child = static function () use ( &$children ): int { return ++$children; };
	BatchScheduler::processChunk( 20, $create_child, 0 );
	BatchScheduler::processChunk( 20, $create_child, 0 );
	$assert( 1 === $children, 'duplicate recovered chunk delivery creates exactly one child' );

	EngineData::$engines[30] = array();
	BatchScheduler::start( 30, 'fanout_hook', array( $item ), array(), 'pipeline', BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE );
	Jobs::$engines[30]       = EngineData::$engines[30];
	EngineData::$engines[30] = array();
	$scheduled_children      = 0;
	BatchScheduler::processChunk( 30, static function () use ( &$scheduled_children ): int { return ++$scheduled_children; }, 0 );
	$assert( 1 === $scheduled_children, 'scheduled chunk reads durable batch state when the persistent cache is stale' );

	echo "fanout-adoption-recovery-smoke: {$passes} passed, {$failures} failed\n";
	exit( $failures > 0 ? 1 : 0 );
}
