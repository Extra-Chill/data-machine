<?php
/**
 * Smoke: DirectJobEnqueuer::liveGenerationExecution() sees actions whose args
 * exceed Action Scheduler's 191-char index limit (Extra-Chill/data-machine#3578).
 *
 * Action Scheduler stores md5( json ) in `args` and the real JSON in
 * `extended_args` for long payloads, so `partial_args_matching=like` silently
 * misses them. The stand-in below reproduces the DBStore storage and query
 * semantics (ActionScheduler_DBStore.php insert + query_actions).
 *
 * Run with: php tests/direct-job-live-generation-lookup-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DirectJobEnqueuer;

final class LiveGenerationLookupFakeJobs extends Jobs {
	public function __construct() {}
}

final class LiveGenerationLookupFakeAction {
	public function __construct( private array $args ) {}

	public function get_args(): array {
		return $this->args;
	}
}

/** Mirrors the ActionScheduler_DBStore args/extended_args columns. */
final class LiveGenerationLookupStore {
	public static array $rows = array();
	public static array $queries = array();

	public static function insert( string $hook, array $args, string $status ): void {
		$json = json_encode( $args );
		$row  = array(
			'hook'          => $hook,
			'status'        => $status,
			'args'          => $json,
			'extended_args' => null,
		);
		if ( strlen( $json ) > 191 ) {
			$row['args']          = md5( $json );
			$row['extended_args'] = $json;
		}
		self::$rows[] = $row;
	}

	public static function query( array $query ): array {
		self::$queries[] = $query;
		$statuses        = (array) ( $query['status'] ?? array() );
		$matches         = array();
		foreach ( self::$rows as $id => $row ) {
			if ( ! empty( $query['hook'] ) && $row['hook'] !== $query['hook'] ) {
				continue;
			}
			if ( ! empty( $statuses ) && ! in_array( $row['status'], $statuses, true ) ) {
				continue;
			}
			// partial_args_matching=like: `a.args LIKE %"key":value%` per arg (args column only).
			if ( isset( $query['args'] ) && 'like' === ( $query['partial_args_matching'] ?? 'off' ) ) {
				foreach ( $query['args'] as $key => $value ) {
					$needle = is_int( $value ) ? '"' . $key . '":' . $value : '"' . $key . '":' . json_encode( $value );
					if ( ! str_contains( (string) $row['args'], $needle ) ) {
						continue 2;
					}
				}
			}
			// search: hook OR args (when no extended_args) OR extended_args LIKE %search%.
			if ( ! empty( $query['search'] ) ) {
				$search = (string) $query['search'];
				$hit    = str_contains( $row['hook'], $search )
					|| ( null === $row['extended_args'] && str_contains( (string) $row['args'], $search ) )
					|| ( null !== $row['extended_args'] && str_contains( $row['extended_args'], $search ) );
				if ( ! $hit ) {
					continue;
				}
			}
			$matches[ $id ] = $row;
		}

		$matches = array_slice( $matches, (int) ( $query['offset'] ?? 0 ), (int) ( $query['per_page'] ?? 5 ), true );
		$out     = array();
		foreach ( $matches as $id => $row ) {
			$out[ $id ] = new LiveGenerationLookupFakeAction( json_decode( $row['extended_args'] ?? $row['args'], true ) );
		}
		return $out;
	}
}

if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
	function as_get_scheduled_actions( array $query = array(), string $return_format = 'OBJECT' ): array {
		$actions = LiveGenerationLookupStore::query( $query );
		return 'ids' === $return_format ? array_keys( $actions ) : $actions;
	}
}

function live_generation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

echo "=== direct-job-live-generation-lookup-smoke ===\n";

$enqueuer = new DirectJobEnqueuer( new LiveGenerationLookupFakeJobs(), static fn() => 1, static fn() => 0, static fn() => true );
$token    = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

// Short payload (<= 191 chars): stored plain in `args`.
LiveGenerationLookupStore::insert( 'datamachine_execute_step', array( 'job_id' => 42, 'flow_step_id' => 'ephemeral_step_0', 'operation_generation' => 3, 'operation_claim_token' => $token ), 'pending' );
live_generation_assert( 'step' === $enqueuer->liveGenerationExecution( 42, 3, $token ), 'short (plain args) step action is found' );

// Long payload (> 191 chars): args column holds md5, JSON lives in extended_args.
$long_step = str_repeat( 'long_flow_step_id_', 14 ) . '_9';
$long_args = array( 'job_id' => 77, 'flow_step_id' => $long_step, 'operation_generation' => 5, 'operation_claim_token' => $token );
live_generation_assert( strlen( json_encode( $long_args ) ) > 191, 'fixture payload exceeds the 191-char index limit' );
LiveGenerationLookupStore::insert( 'datamachine_execute_step', $long_args, 'in-progress' );
$stored = end( LiveGenerationLookupStore::$rows );
live_generation_assert( 32 === strlen( $stored['args'] ) && null !== $stored['extended_args'], 'fixture is stored as md5 + extended_args like Action Scheduler' );

// Regression proof: the old query shape returns nothing for the long payload.
$old_shape = LiveGenerationLookupStore::query(
	array(
		'hook'                  => 'datamachine_execute_step',
		'args'                  => array( 'job_id' => 77, 'operation_generation' => 5, 'operation_claim_token' => $token ),
		'partial_args_matching' => 'like',
		'status'                => array( 'pending', 'in-progress' ),
		'per_page'              => 1,
	)
);
live_generation_assert( array() === $old_shape, 'partial_args_matching=like misses the >191-char action (the bug)' );
live_generation_assert( 'step' === $enqueuer->liveGenerationExecution( 77, 5, $token ), 'long (extended_args) step action is found' );

// AI continuation with long args.
LiveGenerationLookupStore::insert( 'datamachine_resume_ai_step', array_merge( $long_args, array( 'job_id' => 88, 'ai_resume_generation' => 2 ) ), 'pending' );
live_generation_assert( 'ai_continuation' === $enqueuer->liveGenerationExecution( 88, 5, $token ), 'long AI continuation action is found' );

// Ownership is verified from hydrated args, not substring evidence.
live_generation_assert( 'none' === $enqueuer->liveGenerationExecution( 77, 4, $token ), 'different generation does not match' );
live_generation_assert( 'none' === $enqueuer->liveGenerationExecution( 7, 5, $token ), 'different job (substring of 77) does not match' );
live_generation_assert( 'none' === $enqueuer->liveGenerationExecution( 77, 5, 'ffffffffffffffffffffffffffffffff' ), 'different token does not match' );

// Finished actions are not live.
LiveGenerationLookupStore::insert( 'datamachine_execute_step', array( 'job_id' => 99, 'flow_step_id' => $long_step, 'operation_generation' => 1, 'operation_claim_token' => 'deadbeef' . $token ), 'complete' );
live_generation_assert( 'none' === $enqueuer->liveGenerationExecution( 99, 1, 'deadbeef' . $token ), 'complete action is not live' );

// A shared token across many jobs is paged rather than truncated.
for ( $i = 1000; $i < 1040; ++$i ) {
	LiveGenerationLookupStore::insert( 'datamachine_execute_step', array( 'job_id' => $i, 'flow_step_id' => $long_step, 'operation_generation' => 9, 'operation_claim_token' => 'shared' . $token ), 'pending' );
}
live_generation_assert( 'step' === $enqueuer->liveGenerationExecution( 1039, 9, 'shared' . $token ), 'match beyond the first page is found' );

live_generation_assert( 'none' === $enqueuer->liveGenerationExecution( 0, 3, $token ) && 'none' === $enqueuer->liveGenerationExecution( 42, 0, $token ) && 'none' === $enqueuer->liveGenerationExecution( 42, 3, '' ), 'invalid identity short-circuits to none' );

echo "=== direct-job-live-generation-lookup-smoke: ALL PASS ===\n";
