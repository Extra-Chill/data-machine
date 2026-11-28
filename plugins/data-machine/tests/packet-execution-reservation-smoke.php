<?php
/** Executable durable packet-handler reservation state-machine coverage. */

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public static array $engines = array();
		public static bool $fail_next_cas = false;

		public function retrieve_engine_data( int $job_id ): array {
			return self::$engines[ $job_id ] ?? array();
		}

		public function compare_and_swap_engine_data( int $job_id, array $expected, array $next ): array {
			if ( self::$fail_next_cas ) {
				self::$fail_next_cas = false;
				return array( 'updated' => false, 'conflict' => false, 'retryable' => false, 'error' => 'forced_persist_failure' );
			}
			if ( ( self::$engines[ $job_id ] ?? array() ) !== $expected ) {
				return array( 'updated' => false, 'conflict' => true, 'error' => null );
			}
			self::$engines[ $job_id ] = $next;
			return array( 'updated' => true, 'conflict' => false, 'error' => null );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
	function wp_cache_get( mixed $key, string $group = '' ): false { unset( $key, $group ); return false; }
	function wp_cache_set( mixed $key, mixed $value, string $group = '' ): bool { unset( $key, $value, $group ); return true; }
	function wp_rand( int $min, int $max ): int { unset( $max ); return $min; }
	function do_action( string $hook, mixed ...$args ): void { unset( $hook, $args ); }

	require_once __DIR__ . '/../inc/Core/EngineData.php';
	require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolExecutor.php';

	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Engine\AI\Tools\ToolExecutor;

	$failures = 0;
	$passes   = 0;
	$assert = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
		if ( $condition ) { ++$passes; echo "  [PASS] {$label}\n"; return; }
		++$failures; echo "  [FAIL] {$label}\n";
	};
	$payload = static fn( int $job_id ): array => array( 'job_id' => $job_id, 'engine_data' => Jobs::$engines[ $job_id ] ?? array() );
	$id      = hash( 'sha256', 'packet-identity' );

	$first = ToolExecutor::beginPacketExecution( 'handler', $id, $payload( 1 ) );
	$second = ToolExecutor::beginPacketExecution( 'handler', $id, $payload( 1 ) );
	$assert( ! empty( $first['acquired'] ), 'first caller atomically acquires execution reservation' );
	$assert( empty( $second['acquired'] ) && 'packet_execution_outcome_ambiguous' === ( $second['result']['code'] ?? '' ), 'concurrent caller is blocked before side effects' );
	$assert( ToolExecutor::finishPacketExecution( 'handler', $id, $payload( 1 ), (string) $first['token'], 'succeeded' ), 'successful outcome is durably finalized' );
	$duplicate = ToolExecutor::beginPacketExecution( 'handler', $id, $payload( 1 ) );
	$assert( empty( $duplicate['acquired'] ) && ! empty( $duplicate['result']['already_dispositioned'] ), 'successful execution remains idempotently blocked' );

	$ambiguous = ToolExecutor::beginPacketExecution( 'handler', $id, $payload( 2 ) );
	Jobs::$fail_next_cas = true;
	$assert( ! ToolExecutor::finishPacketExecution( 'handler', $id, $payload( 2 ), (string) $ambiguous['token'], 'succeeded' ), 'final persistence failure is reported' );
	$after_failure = ToolExecutor::beginPacketExecution( 'handler', $id, $payload( 2 ) );
	$assert( empty( $after_failure['acquired'] ) && ! empty( $after_failure['result']['automatic_replay_blocked'] ), 'ambiguous outcome survives process/persistence failure and blocks replay' );

	$known_failure = ToolExecutor::beginPacketExecution( 'handler', $id, $payload( 3 ) );
	$assert( ToolExecutor::finishPacketExecution( 'handler', $id, $payload( 3 ), (string) $known_failure['token'], 'failed' ), 'known failure is durably recorded' );
	$retry = ToolExecutor::beginPacketExecution( 'handler', $id, $payload( 3 ) );
	$assert( ! empty( $retry['acquired'] ), 'known pre-success failure permits a new reserved attempt' );
	$request_id = 'runtime_tool_3';
	$assert( ToolExecutor::finishPacketExecution( 'handler', $id, $payload( 3 ), (string) $retry['token'], 'pending', $request_id ), 'external pending outcome is durably parked' );
	$pending = ToolExecutor::beginPacketExecution( 'handler', $id, $payload( 3 ) );
	$assert( empty( $pending['acquired'] ) && ! empty( $pending['result']['automatic_replay_blocked'] ), 'pending external execution blocks duplicate initiation' );
	$assert( ! ToolExecutor::finishPendingPacketExecution( 'handler', $id, 3, 'succeeded', 'wrong-token', $request_id ), 'replayed or forged reservation token is rejected' );
	$assert( ! ToolExecutor::finishPendingPacketExecution( 'handler', $id, 3, 'succeeded', (string) $retry['token'], 'runtime_tool_old' ), 'replayed external request identity is rejected' );
	$assert( ToolExecutor::finishPendingPacketExecution( 'handler', $id, 3, 'succeeded', (string) $retry['token'], $request_id ), 'submitted external result finalizes the same durable reservation' );
	$assert( ToolExecutor::finishPendingPacketExecution( 'handler', $id, 3, 'succeeded', (string) $retry['token'], $request_id ), 'same callback identity is idempotent after a crash' );
	$external_complete = ToolExecutor::beginPacketExecution( 'handler', $id, $payload( 3 ) );
	$assert( empty( $external_complete['acquired'] ) && ! empty( $external_complete['result']['already_dispositioned'] ), 'fulfilled external execution is idempotently complete' );

	echo "packet-execution-reservation-smoke: {$passes} passed, {$failures} failed\n";
	exit( $failures > 0 ? 1 : 0 );
}
