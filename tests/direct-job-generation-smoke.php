<?php
/**
 * Pure-PHP smoke coverage for direct enqueue generations.
 *
 * Run with: php tests/direct-job-generation-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DirectJobEnqueuer;

final class DirectJobGenerationFakeJobs extends Jobs {
	public array $job = array(
		'job_id'              => 42,
		'status'              => 'pending',
		'operation_state'     => 'preparing',
		'operation_generation' => 0,
	);
	public bool $claim_blocked = false;

	public function __construct() {}

	public function get_job( int $job_id ): ?array {
		return 42 === $job_id ? $this->job : null;
	}

	public function claim_operation_enqueue( int $job_id, int $lease_seconds = 30 ): array|false {
		$lease_seconds;
		if ( 42 !== $job_id || $this->claim_blocked ) {
			return false;
		}

		++$this->job['operation_generation'];
		$this->job['operation_state']       = 'enqueuing';
		$this->job['operation_claim_token'] = 'token-' . $this->job['operation_generation'];
		return array(
			'token'      => $this->job['operation_claim_token'],
			'generation' => $this->job['operation_generation'],
		);
	}

	public function owns_operation_enqueue_claim( int $job_id, string $token, int $generation ): bool {
		return 42 === $job_id
			&& 'enqueuing' === $this->job['operation_state']
			&& $generation === $this->job['operation_generation']
			&& $token === $this->job['operation_claim_token'];
	}

	public function finish_operation_enqueue( int $job_id, string $state, int $action_id, string $token, int $generation ): bool {
		if ( ! $this->owns_operation_enqueue_claim( $job_id, $token, $generation ) ) {
			return false;
		}

		$this->job['operation_state']      = $state;
		$this->job['operation_action_id']  = $action_id;
		$this->job['operation_claim_token'] = null;
		return true;
	}

	public function reclaim_missing_operation_action( int $job_id ): bool {
		if ( 42 !== $job_id || 'enqueued' !== $this->job['operation_state'] ) {
			return false;
		}
		$this->job['operation_state'] = 'enqueue_failed';
		return true;
	}

	public function forceTakeover(): array {
		$this->claim_blocked = false;
		return $this->claim_operation_enqueue( 42 );
	}
}

function direct_generation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

echo "=== direct-job-generation-smoke ===\n";

$blocked               = new DirectJobGenerationFakeJobs();
$blocked->job['operation_state'] = 'enqueuing';
$blocked->job['operation_generation'] = 1;
$blocked->job['operation_claim_token'] = 'owner-token';
$blocked->claim_blocked = true;
$blocked_result         = ( new DirectJobEnqueuer( $blocked, static fn() => 100, static fn() => 0 ) )->enqueue( 42, 'ephemeral_step_0' );
direct_generation_assert( false === $blocked_result['success'], 'non-owner does not acknowledge success without durable action' );
direct_generation_assert( true === $blocked_result['retryable'] && 'enqueue_in_progress' === $blocked_result['error'], 'non-owner receives explicit retryable in-progress result' );

$interleaved = new DirectJobGenerationFakeJobs();
$takeover    = null;
$slow_result = ( new DirectJobEnqueuer(
	$interleaved,
	static function () use ( $interleaved, &$takeover ) {
		$takeover = $interleaved->forceTakeover();
		return 101;
	},
	static fn() => 0
) )->enqueue( 42, 'ephemeral_step_0' );
direct_generation_assert( false === $slow_result['success'] && 'enqueue_claim_fenced' === $slow_result['error'], 'expired slow generation cannot finish after takeover' );
direct_generation_assert( 2 === $takeover['generation'], 'takeover advances enqueue generation' );
direct_generation_assert( $interleaved->finish_operation_enqueue( 42, 'enqueued', 202, $takeover['token'], $takeover['generation'] ), 'active generation can finish' );
direct_generation_assert( 202 === $interleaved->job['operation_action_id'], 'only active generation action is accepted' );

$retry = new DirectJobGenerationFakeJobs();
$retry->job['operation_generation'] = 1;
$retry->job['operation_state']      = 'preparing';
$seen_args = array();
$retry_result = ( new DirectJobEnqueuer(
	$retry,
	static function ( int $run_at, string $hook, array $args ) use ( &$seen_args ) {
		$run_at;
		$hook;
		$seen_args = $args;
		return 303;
	},
	static function ( array $args ): int {
		return 1 === (int) ( $args['operation_generation'] ?? 0 ) ? 111 : 0;
	}
) )->enqueue( 42, 'ephemeral_step_0' );
direct_generation_assert( true === $retry_result['success'], 'retry generation schedules successfully while prior action exists' );
direct_generation_assert( 2 === $seen_args['operation_generation'], 'retry action is keyed to the next generation' );

$jobs_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Database/Jobs/Jobs.php' ) ?: '';
$step_source = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Engine/ExecuteStepAbility.php' ) ?: '';
direct_generation_assert( str_contains( $jobs_source, 'operation_claim_token = %s' ) && str_contains( $jobs_source, 'operation_generation = %d' ), 'enqueue finish is fenced by token and generation' );
direct_generation_assert( str_contains( $step_source, 'stale_generation' ) && str_contains( $step_source, 'operation_generation' ), 'worker rejects stale execution generations' );

echo "=== direct-job-generation-smoke: ALL PASS ===\n";
