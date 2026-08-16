<?php
/** Executable route-level acceptance coverage for packet disposition. */

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public static string $status = 'processing';
		public static array $engine = array();
		public array $transitions = array();
		public function transition_job_status_result( int $job_id, string $status, bool $force = false ): array {
			unset( $force );
			$this->transitions[] = array( $job_id, $status );
			return array( 'success' => true, 'changed' => true, 'status' => $status );
		}
		public function get_job( int $job_id ): array {
			return array( 'job_id' => $job_id, 'status' => self::$status, 'engine_data' => self::$engine );
		}
	}
}

namespace DataMachine\Core\Database\Flows { class Flows {} }
namespace DataMachine\Core\Database\Pipelines { class Pipelines {} }
namespace DataMachine\Core\Database\ProcessedItems {
	class ProcessedItems {
		public const CLAIM_METADATA_KEY = '_datamachine_item_claim';
		public const CLAIMS_METADATA_KEY = '_datamachine_item_claims';
		public static function has_claim_metadata( array $container ): bool {
			return array_key_exists( self::CLAIM_METADATA_KEY, $container ) || array_key_exists( self::CLAIMS_METADATA_KEY, $container );
		}
	}
}

namespace DataMachine\Core {
	class EngineData {
		public function __construct( private array $data ) {}
		public function getFlowStepConfig( string $id ): array { return $this->data['flow_config'][ $id ] ?? array(); }
	}
	class JobStatus {
		public const COMPLETED = 'completed';
		public const COMPLETED_NO_ITEMS = 'completed' . '_no_items';
		public const PENDING = 'pending';
		public static function isStatusWaiting( mixed $status ): bool { return 'waiting' === $status; }
		public static function isStatusSuccess( mixed $status ): bool { return in_array( $status, array( self::COMPLETED, self::COMPLETED_NO_ITEMS ), true ); }
		public static function isStatusFinal( mixed $status ): bool { return self::isStatusSuccess( $status ) || str_starts_with( (string) $status, 'failed' ); }
		public static function fromString( string $status ): object { return new class( $status ) { public function __construct( private string $status ) {} public function getBaseStatus(): string { return $this->status; } }; }
	}
	class StepExecutionResult {}
	class RecoveryExecutionFence {}
	class ChildJobRecoveryPolicy {
		public static function recoveryExecutionMatches( array $engine, int $generation, string $token ): bool {
			return $generation === (int) ( $engine['generation'] ?? 0 ) && hash_equals( $token, (string) ( $engine['token'] ?? '' ) );
		}
	}
	class RunMetrics {}
}

namespace DataMachine\Core\FilesRepository {
	class FileCleanup { public function cleanup_job_data_packets( int $job_id, array $context ): void { unset( $job_id, $context ); } }
	class FileRetrieval {}
}

namespace DataMachine\Core\Steps {
	class Step {}
	class FlowStepConfig {
		public static function usesHandler( array $config ): bool { return ! empty( $config['uses_handler'] ); }
	}
}

namespace DataMachine\Engine {
	class StepNavigator {
		public function get_next_flow_step_id( string $id, array $payload ): ?string {
			unset( $id, $payload );
			return $GLOBALS['route_smoke_next'] ?? null;
		}
	}
}

namespace DataMachine\Engine\Actions\Handlers {
	class StepLifecycleHandler {
		public static int $calls = 0;
		public static bool $fail = false;
		public static bool $stale = false;
		public static array $claims = array();
		public static array $released = array();
		public static array $transferred = array();
		public static bool $takeover_after_transfer = false;
		public static array $last_transfer_claims = array();

		public static function reconcileStepOutput( int $job_id, array $config, array $packets, bool $success, int $generation = 0, string $token = '' ): array {
			unset( $job_id, $config, $generation, $token );
			++self::$calls;
			if ( self::$fail || self::$stale ) {
				return array( 'success' => false, 'stale' => self::$stale, 'handled' => true, 'retained' => count( self::$claims ), 'explicit' => 0 );
			}
			$resolved = array();
			if ( $success ) {
				foreach ( $packets as $packet ) {
					$id = (string) ( $packet['metadata']['disposition_id'] ?? '' );
					if ( '' !== $id && isset( self::$claims[ $id ] ) ) {
						$resolved[ $id ] = true;
					}
				}
			}
			foreach ( array_keys( self::$claims ) as $id ) {
				if ( ! isset( $resolved[ $id ] ) ) {
					self::$released[] = $id;
					unset( self::$claims[ $id ] );
				}
			}
			return array( 'success' => true, 'stale' => false, 'handled' => true, 'retained' => count( self::$claims ), 'explicit' => count( $resolved ) );
		}

		public static function transferClaimsToFanout( int $job_id, array $packets, int $generation = 0, string $token = '' ): array {
			unset( $job_id, $token );
			self::$last_transfer_claims = array();
			foreach ( $packets as $packet ) {
				$id = (string) ( $packet['metadata']['disposition_id'] ?? '' );
				if ( isset( self::$claims[ $id ] ) ) {
					self::$last_transfer_claims[ $id ] = true;
					self::$transferred[] = $id;
					unset( self::$claims[ $id ] );
				}
			}
			if ( self::$takeover_after_transfer ) {
				\DataMachine\Core\Database\Jobs\Jobs::$engine = array( 'generation' => $generation + 1, 'token' => 'new-owner' );
			}
			return array( 'success' => true, 'stale' => false, 'transfer_id' => 'fixture-transfer' );
		}
		public static function renewParkedClaims( int $job_id, int $generation = 0, string $token = '' ): array { unset( $job_id, $generation, $token ); return array( 'success' => true, 'stale' => false, 'renewed' => count( self::$claims ) ); }
		public static function recoverPreparedFanoutTransfer( int $job_id, int $generation = 0, string $token = '' ): array { unset( $job_id, $generation, $token ); return array( 'success' => true, 'stale' => false ); }
		public static function restorePreparedFanoutTransfer( int $job_id, string $transfer_id, int $generation = 0, string $token = '' ): bool {
			unset( $job_id, $generation, $token );
			if ( 'fixture-transfer' !== $transfer_id ) { return false; }
			self::$claims = array_replace( self::$claims, self::$last_transfer_claims );
			return true;
		}
		public static function adoptPreparedFanoutTransfer( int $job_id, string $transfer_id, int $generation = 0, string $token = '' ): bool { unset( $job_id, $transfer_id, $generation, $token ); return true; }
		public static function finalizePreparedFanoutTransfer( int $job_id, string $transfer_id, int $generation = 0, string $token = '' ): bool { unset( $job_id, $transfer_id, $generation, $token ); return true; }
	}
}

namespace DataMachine\Abilities\Engine {
	trait EngineHelpers {
		protected \DataMachine\Core\Database\Jobs\Jobs $db_jobs;
	}
	class ParallelMapFanoutAdapter {
		public const STEP_TYPE = 'parallel';
		public const SHAPE_INLINE = 'inline';
		public static bool $fanout = true;
		public static array $children = array();
		public static int $dispatches = 0;
		public static function shouldFanOut( array $step, array $context = array() ): bool { unset( $step, $context ); return self::$fanout; }
		public function dispatch( array $step, int $job_id, string $next, array $engine, ?bool $fanout = null ): array {
			unset( $job_id, $next, $engine );
			++self::$dispatches;
			self::$children = array_map( static fn( array $packet ): array => array( $packet['metadata']['disposition_id'] ?? '' ), $step['items'] );
			return false === $fanout ? array( 'shape' => self::SHAPE_INLINE ) : array( 'shape' => 'map', 'batch' => array( 'scheduled' => true, 'adopted' => true ) );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['route_smoke_actions'] = array();
	function do_action( string $hook, ...$args ): void { $GLOBALS['route_smoke_actions'][] = array( $hook, $args ); }
	function datamachine_get_engine_data( int $job_id ): array { unset( $job_id ); return $GLOBALS['route_smoke_engine'] ?? array(); }
	function datamachine_get_file_context( mixed $flow_id ): array { unset( $flow_id ); return array(); }
	function sanitize_key( string $key ): string { return $key; }

	require_once __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php';

	use DataMachine\Abilities\Engine\ExecuteStepAbility;
	use DataMachine\Abilities\Engine\ParallelMapFanoutAdapter;
	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Core\EngineData;
	use DataMachine\Engine\Actions\Handlers\StepLifecycleHandler;

	$failures = 0;
	$passes   = 0;
	$assert = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
		if ( $condition ) { ++$passes; echo "  [PASS] {$label}\n"; return; }
		++$failures; echo "  [FAIL] {$label}\n";
	};
	$reflection = new \ReflectionClass( ExecuteStepAbility::class );
	$ability    = $reflection->newInstanceWithoutConstructor();
	$jobs       = new Jobs();
	$reflection->getProperty( 'db_jobs' )->setValue( $ability, $jobs );
	$route = $reflection->getMethod( 'routeAfterExecution' );
	$invoke = static function ( array $packets, bool $success, mixed $override = null, string $type = 'ai', array $next_config = array(), int $generation = 0, string $token = '', array $execution_result = array() ) use ( $route, $ability ): array {
		$GLOBALS['route_smoke_next'] = empty( $next_config ) ? null : 'next';
		$engine = new EngineData( array( 'flow_config' => array( 'next' => $next_config ) ) );
		return $route->invoke( $ability, 77, 'current', 3, array( 'step_type' => $type ), $type, 'FixtureStep', $packets, array( 'engine' => $engine ), $success, $override, $execution_result, $generation, $token );
	};
	$packet = static fn( string $id, string $type = 'ai_handler_complete' ): array => array( 'type' => $type, 'metadata' => array( 'tool_name' => 'handler', 'handler_tool' => 'handler', 'disposition_id' => $id ) );

	foreach ( range( 1, 52 ) as $id ) { StepLifecycleHandler::$claims[ (string) $id ] = true; }
	$selected = array_values( array_filter( range( 1, 52 ), static fn( int $id ): bool => 0 !== $id % 2 || 52 === $id ) );
	$output   = array_map( $packet, array_map( 'strval', $selected ) );
	$result = $invoke( $output, true, null, 'ai', array( 'uses_handler' => true, 'step_type' => 'upsert' ) );
	$assert( 'inline_continuation' === $result['outcome'], 'non-contiguous outputs continue inline' );
	$assert( 27 === count( StepLifecycleHandler::$claims ) && 25 === count( StepLifecycleHandler::$released ), 'partial output retains 27 and releases exactly 25 identities' );

	$GLOBALS['route_smoke_next'] = null;
	$result = $invoke( $output, true );
	$assert( 'completed' === $result['outcome'] && array( 77, 'completed' ) === end( $jobs->transitions ), 'successful terminal completes retained claims' );

	StepLifecycleHandler::$claims = array( 'zero' => true );
	$result = $invoke( array(), false );
	$assert( 'failed' === $result['outcome'] && in_array( 'zero', StepLifecycleHandler::$released, true ), 'zero output releases its claim and fails' );

	StepLifecycleHandler::$claims = array( 'waiting' => true );
	$calls = StepLifecycleHandler::$calls;
	$result = $invoke( array(), true, 'waiting' );
	$assert( 'waiting' === $result['outcome'] && $calls === StepLifecycleHandler::$calls && isset( StepLifecycleHandler::$claims['waiting'] ), 'waiting skips reconciliation' );

	StepLifecycleHandler::$claims = array( 'override' => true );
	$result = $invoke( array( $packet( 'override' ) ), true, 'completed' );
	$assert( 'completed_override' === $result['outcome'], 'successful status override completes through terminal route' );
	StepLifecycleHandler::$claims = array( 'failed-override' => true );
	$result = $invoke( array(), false, 'failed - fixture' );
	$assert( 'completed_override' === $result['outcome'] && ! isset( StepLifecycleHandler::$claims['failed-override'] ), 'failure status override releases claims before terminal route' );

	StepLifecycleHandler::$claims = array( 'transition' => true );
	$result = $invoke( array( $packet( 'transition', 'tool_result' ) ), true, null, 'ai', array( 'uses_handler' => true, 'step_type' => 'publish' ) );
	$assert( 'failed' === $result['outcome'], 'transition-filter failure does not schedule next work' );

	StepLifecycleHandler::$claims = array( 'fan-a' => true, 'fan-b' => true );
	$result = $invoke( array( $packet( 'fan-a' ), $packet( 'fan-b' ) ), true, null, 'transform', array( 'step_type' => 'transform' ) );
	$assert( 'batch_scheduled' === $result['outcome'] && empty( StepLifecycleHandler::$claims ), 'fanout transfers parent ownership' );
	$assert( array( array( 'fan-a' ), array( 'fan-b' ) ) === ParallelMapFanoutAdapter::$children, 'fanout children receive one matching identity each' );

	StepLifecycleHandler::$claims = array( 'stale' => true );
	StepLifecycleHandler::$stale = true;
	$result = $invoke( array( $packet( 'stale' ) ), true, null, 'ai', array(), 2, 'old-owner' );
	$assert( 'stale_recovery_noop' === $result['outcome'] && isset( StepLifecycleHandler::$claims['stale'] ), 'recovery takeover fences claim mutation' );
	StepLifecycleHandler::$stale = false;

	StepLifecycleHandler::$claims = array( 'fan-recovery-a' => true, 'fan-recovery-b' => true );
	StepLifecycleHandler::$takeover_after_transfer = true;
	Jobs::$engine = array( 'generation' => 4, 'token' => 'current-owner' );
	$dispatches = ParallelMapFanoutAdapter::$dispatches;
	$result = $invoke( array( $packet( 'fan-recovery-a' ), $packet( 'fan-recovery-b' ) ), true, null, 'transform', array( 'step_type' => 'transform' ), 4, 'current-owner' );
	$assert( 'stale_recovery_noop' === $result['outcome'] && $dispatches === ParallelMapFanoutAdapter::$dispatches && 2 === count( StepLifecycleHandler::$claims ), 'post-transfer recovery takeover blocks dispatch and restores claims' );
	StepLifecycleHandler::$takeover_after_transfer = false;
	Jobs::$engine = array();

	StepLifecycleHandler::$claims = array( 'blocked' => true );
	$calls = StepLifecycleHandler::$calls;
	$result = $invoke( array(), false, null, 'ai', array(), 0, '', array( 'status' => 'blocked' ) );
	$assert( 'blocked' === $result['outcome'] && $calls === StepLifecycleHandler::$calls && isset( StepLifecycleHandler::$claims['blocked'] ), 'blocked AI concurrency retains claims for scheduled resume' );

	StepLifecycleHandler::$claims = array( 'pending-retry' => true );
	Jobs::$status = 'pending';
	$GLOBALS['route_smoke_engine'] = array( 'retry' => array( 'next_retry_at' => '2026-08-15T20:00:00Z' ) );
	$calls = StepLifecycleHandler::$calls;
	$result = $invoke( array(), false );
	$assert( 'failed' === $result['outcome'] && $calls === StepLifecycleHandler::$calls && isset( StepLifecycleHandler::$claims['pending-retry'] ), 'already-scheduled pending retry retains claims' );
	Jobs::$status = 'processing';
	$GLOBALS['route_smoke_engine'] = array();

	StepLifecycleHandler::$fail = true;
	$GLOBALS['route_smoke_actions'] = array();
	$result = $invoke( array( $packet( 'stale' ) ), true, null, 'ai', array( 'step_type' => 'next' ) );
	$schedules = array_filter( $GLOBALS['route_smoke_actions'], static fn( array $action ): bool => 'datamachine_schedule_next_step' === $action[0] );
	$assert( 'claim_reconciliation_failed' === $result['outcome'] && empty( $schedules ), 'persistence failure prevents next-step scheduling' );

	echo "packet-disposition-route-smoke: {$passes} passed, {$failures} failed\n";
	exit( $failures > 0 ? 1 : 0 );
}
