<?php
/**
 * Retry Job Ability
 *
 * Retries a failed or stuck job by marking it failed and optionally requeuing its prompt.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.18.0
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Core\DirectJobEnqueuer;
use DataMachine\Core\DirectOperationRecoveryPolicy;
use DataMachine\Core\JobRetryPolicy;

defined( 'ABSPATH' ) || exit;

class RetryJobAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/retry-job ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/retry-job',
				array(
					'label'               => __( 'Retry Job', 'data-machine' ),
					'description'         => __( 'Retry a failed or stuck job by marking it failed and optionally requeuing its prompt.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'job_id' => array(
								'type'        => 'integer',
								'description' => __( 'The job ID to retry.', 'data-machine' ),
							),
							'force'  => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Allow retrying any status, not just failed/processing.', 'data-machine' ),
							),
						),
						'required'   => array( 'job_id' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'job_id'          => array( 'type' => 'integer' ),
							'previous_status' => array( 'type' => 'string' ),
							'prompt_requeued' => array( 'type' => 'boolean' ),
							'direct_requeued' => array( 'type' => 'boolean' ),
							'retryable'       => array( 'type' => 'boolean' ),
							'error_code'      => array( 'type' => 'string' ),
							'message'         => array( 'type' => 'string' ),
							'error'           => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute retry-job ability.
	 *
	 * Marks the job as failed and requeues its prompt if a backup exists in engine_data.
	 *
	 * @param array $input Input parameters with job_id and optional force.
	 * @return array Result with job_id, previous_status, and prompt_requeued flag.
	 */
	public function execute( array $input ): array|\WP_Error {
		if ( empty( $input['job_id'] ) || ! is_numeric( $input['job_id'] ) ) {
			return new \WP_Error( 'invalid_job_id', 'job_id is required and must be a positive integer.', array( 'status' => 400 ) );
		}

		$job_id = (int) $input['job_id'];
		$force  = ! empty( $input['force'] );

		$job = $this->db_jobs->get_job( $job_id );

		if ( ! $job ) {
			return new \WP_Error(
				'job_not_found',
				sprintf( 'Job %d not found.', $job_id ),
				array(
					'status' => 404,
					'job_id' => $job_id,
				)
			);
		}

		if ( ! $this->canAccessJob( $job ) ) {
			return $this->jobAccessDeniedError();
		}

		$previous_status = $job['status'] ?? '';

		// Unless forced, only allow retrying failed or processing jobs.
		if ( ! $force && ! str_starts_with( $previous_status, 'failed' ) && 'processing' !== $previous_status ) {
			return new \WP_Error(
				'invalid_job_status',
				sprintf( 'Job %d has status "%s" — use --force to retry non-failed jobs.', $job_id, $previous_status ),
				array(
					'status'    => 409,
					'job_id'    => $job_id,
					'retryable' => true,
				)
			);
		}

		// Retrieve engine data for prompt backup before marking failed.
		$engine_data = $this->db_jobs->retrieve_engine_data( $job_id );

		if ( 'direct' === (string) ( $job['flow_id'] ?? '' ) ) {
			if ( empty( $engine_data['flow_config'] ) ) {
				return new \WP_Error(
					'retry_data_unavailable',
					'Direct workflow execution data is no longer available for retry.',
					array(
						'status'    => 409,
						'retryable' => false,
						'job_id'    => $job_id,
					)
				);
			}

			$flow_step_id = JobRetryPolicy::resolveDirectResumeStepId( $engine_data );
			if ( '' === $flow_step_id ) {
				return new \WP_Error(
					'retry_resume_unavailable',
					'Direct workflow has no safe resume step.',
					array(
						'status'    => 409,
						'retryable' => false,
						'job_id'    => $job_id,
					)
				);
			}

			if ( 'processing' === $previous_status ) {
				$generation     = (int) ( $job['operation_generation'] ?? 0 );
				$token          = (string) ( $job['operation_claim_token'] ?? '' );
				$enqueuer       = new DirectJobEnqueuer( $this->db_jobs );
				$live_execution = $generation > 0 && '' !== $token ? $enqueuer->liveGenerationExecution( $job_id, $generation, $token ) : 'none';
				if ( 'none' !== $live_execution ) {
					return new \WP_Error(
						'job_execution_in_progress',
						sprintf( 'Job %d still has live execution for generation %d; retry after it exits or is recovered.', $job_id, $generation ),
						array(
							'status'     => 409,
							'retryable'  => true,
							'job_id'     => $job_id,
							'generation' => $generation,
							'ownership'  => 'operation',
						)
					);
				}

				$missing_action = DirectOperationRecoveryPolicy::diagnose(
					$job,
					$live_execution,
					DirectOperationRecoveryPolicy::recordedActionExists( (int) ( $job['operation_action_id'] ?? 0 ) )
				);
				if ( is_array( $missing_action ) ) {
					if ( ! empty( $job['operation_effects_begun_at'] ) ) {
						return new \WP_Error(
							'job_effects_begun',
							sprintf( 'Job %d may have begun operation effects and cannot be safely retried.', $job_id ),
							array(
								'status'     => 409,
								'retryable'  => false,
								'job_id'     => $job_id,
								'generation' => $generation,
								'ownership'  => 'operation',
							)
						);
					}

					$requeue = $this->db_jobs->commit_missing_direct_operation_requeue(
						$job_id,
						(int) $missing_action['action_id'],
						(int) $missing_action['generation'],
						$token,
						'manual_retry',
						static fn( int $new_generation, string $new_token ): int => (int) as_schedule_single_action(
							time(),
							DirectJobEnqueuer::HOOK,
							array(
								'job_id'                => $job_id,
								'flow_step_id'          => $flow_step_id,
								'operation_generation'  => $new_generation,
								'operation_claim_token' => $new_token,
							),
							DirectJobEnqueuer::GROUP,
							true
						)
					);
					if ( empty( $requeue['success'] ) ) {
						return new \WP_Error(
							(string) ( $requeue['reason'] ?? 'retry_enqueue_failed' ),
							sprintf( 'Job %d retry could not establish durable scheduler ownership.', $job_id ),
							array(
								'status'     => 503,
								'retryable'  => true,
								'job_id'     => $job_id,
								'generation' => $missing_action['generation'] ?? 0,
								'ownership'  => 'scheduler',
							)
						);
					}

					\DataMachine\Core\RunMetrics::increment( $job_id, 'retried' );
					return array(
						'success'         => true,
						'job_id'          => $job_id,
						'previous_status' => $previous_status,
						'prompt_requeued' => false,
						'direct_requeued' => true,
						'message'         => sprintf( 'Job %d direct workflow retry enqueued.', $job_id ),
					);
				}
				$this->db_jobs->complete_job( $job_id, 'failed - manual_retry' );
			}
			if ( ! $this->db_jobs->reopen_failed_job( $job_id ) ) {
				return new \WP_Error(
					'job_reopen_failed',
					sprintf( 'Job %d could not be reopened for retry.', $job_id ),
					array(
						'status'    => 500,
						'retryable' => true,
						'job_id'    => $job_id,
					)
				);
			}

			$enqueue = ( new DirectJobEnqueuer( $this->db_jobs ) )->enqueue( $job_id, $flow_step_id );
			if ( empty( $enqueue['success'] ) ) {
				$this->db_jobs->complete_job( $job_id, 'failed - retry_enqueue_failed' );
				return new \WP_Error(
					'retry_enqueue_failed',
					sprintf( 'Job %d retry could not be enqueued.', $job_id ),
					array(
						'status'    => 503,
						'retryable' => true,
						'job_id'    => $job_id,
					)
				);
			}

			\DataMachine\Core\RunMetrics::increment( $job_id, 'retried' );
			return array(
				'success'         => true,
				'job_id'          => $job_id,
				'previous_status' => $previous_status,
				'prompt_requeued' => false,
				'direct_requeued' => true,
				'message'         => sprintf( 'Job %d direct workflow retry enqueued.', $job_id ),
			);
		}

		// Mark as failed for retry.
		\DataMachine\Core\RunMetrics::increment( $job_id, 'retried' );
		$this->db_jobs->complete_job( $job_id, 'failed - manual_retry' );

		// Restore drain-mode queued_prompt_backup if the prior run removed an entry.
		$prompt_requeued = false;
		$job_flow_id     = (int) ( $job['flow_id'] ?? 0 );
		$backup          = $engine_data['queued_prompt_backup'] ?? array();

		if ( ! empty( $backup ) && $job_flow_id > 0 ) {
			$prompt_requeued = $this->restoreQueuedPromptBackup( $job_flow_id, $backup );
		}

		do_action(
			'datamachine_log',
			'info',
			'Job retried via ability',
			array(
				'job_id'          => $job_id,
				'previous_status' => $previous_status,
				'prompt_requeued' => $prompt_requeued,
			)
		);

		$message = $prompt_requeued
			? sprintf( 'Job %d marked as failed and prompt requeued.', $job_id )
			: sprintf( 'Job %d marked as failed (no prompt backup to requeue).', $job_id );

		return array(
			'success'         => true,
			'job_id'          => $job_id,
			'previous_status' => $previous_status,
			'prompt_requeued' => $prompt_requeued,
			'message'         => $message,
		);
	}
}
