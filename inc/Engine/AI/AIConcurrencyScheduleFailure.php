<?php
/**
 * Recover AI continuation scheduling failures without violating generation ownership.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\EngineData;

defined( 'ABSPATH' ) || exit;

final class AIConcurrencyScheduleFailure {

	public static function handle( int $job_id, string $flow_step_id, string $provider, int $generation, string $token, array $contention, array $scheduler ): void {
		$released = AIConcurrencyBackpressure::releaseUnscheduledGeneration( $job_id, $flow_step_id, $generation, $token );
		if ( ! $released ) {
			self::handleAmbiguousRelease( $job_id, $flow_step_id, $generation, $token, $scheduler );
			return;
		}

		$contention['state']           = 'deferred';
		$contention['terminal_reason'] = 'ai_concurrency_defer_schedule_failed';
		$contention['next_retry_at']   = null;
		$contention['action_id']       = null;
		$contention['scheduler']       = array(
			'attempts'       => (int) ( $scheduler['attempts'] ?? 0 ),
			'error_message'  => (string) ( $scheduler['error_message'] ?? '' ),
			'claim_released' => true,
		);
		EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $contention ): array {
				$engine['ai_concurrency_throttle'] = $contention;
				return $engine;
			},
			'ai_concurrency_defer_schedule_failed'
		);
		do_action(
			'datamachine_fail_job',
			$job_id,
			'ai_concurrency_defer_schedule_failed',
			array(
				'flow_step_id'       => $flow_step_id,
				'ai_provider'        => $provider,
				'retryable'          => true,
				'resume_generation'  => $generation,
				'scheduler_attempts' => (int) ( $scheduler['attempts'] ?? 0 ),
				'error_message'      => (string) ( $scheduler['error_message'] ?? '' ),
				'claim_released'     => true,
			)
		);
	}

	private static function handleAmbiguousRelease( int $job_id, string $flow_step_id, int $generation, string $token, array $scheduler ): void {
		$execution_state = AIConcurrencyBackpressure::generationExecutionState( $job_id, $flow_step_id, $generation, $token );
		if ( 'scheduled' === $execution_state ) {
			( new Jobs() )->update_job_status( $job_id, 'pending' );
		}

		do_action(
			'datamachine_log',
			'' === $execution_state ? 'error' : 'info',
			'' === $execution_state
				? 'AI continuation scheduling failed and generation ownership could not be released'
				: 'AI continuation scheduling raced existing generation execution',
			array(
				'job_id'            => $job_id,
				'flow_step_id'      => $flow_step_id,
				'resume_generation' => $generation,
				'execution_state'   => $execution_state,
				'scheduler'         => $scheduler,
			)
		);
	}
}
