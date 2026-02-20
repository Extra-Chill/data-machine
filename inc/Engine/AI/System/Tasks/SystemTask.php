<?php
/**
 * Abstract base class for all System Agent tasks.
 *
 * Provides standardized task execution interface with shared helpers for
 * job completion, failure handling, and rescheduling. All async system
 * tasks must extend this class.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.22.4
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

abstract class SystemTask {

	/**
	 * Execute the task for a specific job.
	 *
	 * @param int   $jobId  The job ID from DM Jobs table.
	 * @param array $params Task parameters from the job's engine_data.
	 */
	abstract public function execute( int $jobId, array $params ): void;

	/**
	 * Get the task type identifier.
	 *
	 * @return string Task type identifier.
	 */
	abstract public function getTaskType(): string;

	/**
	 * Complete a job with successful results.
	 *
	 * Updates the job's engine_data with the result and marks it as completed.
	 *
	 * @param int   $jobId Job ID.
	 * @param array $result Result data to store in engine_data.
	 */
	protected function completeJob( int $jobId, array $result ): void {
		$jobs_db = new Jobs();

		// Store result in engine_data
		$jobs_db->store_engine_data( $jobId, $result );

		// Mark job as completed
		$jobs_db->complete_job( $jobId, JobStatus::COMPLETED );

		do_action(
			'datamachine_log',
			'info',
			"System Agent task completed successfully for job {$jobId}",
			array(
				'job_id'     => $jobId,
				'task_type'  => $this->getTaskType(),
				'agent_type' => 'system',
				'result'     => $result,
			)
		);
	}

	/**
	 * Fail a job with error reason.
	 *
	 * Updates the job's engine_data with error details and marks it as failed.
	 *
	 * @param int    $jobId  Job ID.
	 * @param string $reason Failure reason.
	 */
	protected function failJob( int $jobId, string $reason ): void {
		$jobs_db = new Jobs();

		// Store error in engine_data
		$error_data = array(
			'error'     => $reason,
			'failed_at' => current_time( 'mysql' ),
			'task_type' => $this->getTaskType(),
		);
		$jobs_db->store_engine_data( $jobId, $error_data );

		// Mark job as failed
		$jobs_db->complete_job( $jobId, JobStatus::failed( $reason )->toString() );

		do_action(
			'datamachine_log',
			'error',
			"System Agent task failed for job {$jobId}: {$reason}",
			array(
				'job_id'     => $jobId,
				'task_type'  => $this->getTaskType(),
				'agent_type' => 'system',
				'error'      => $reason,
			)
		);
	}

	/**
	 * Reschedule a job for later execution.
	 *
	 * Useful for polling scenarios where the task needs to check status again.
	 * Includes attempt tracking to prevent infinite rescheduling.
	 *
	 * @param int $jobId        Job ID.
	 * @param int $delaySeconds Delay in seconds before next execution.
	 */
	protected function reschedule( int $jobId, int $delaySeconds = 10 ): void {
		$jobs_db = new Jobs();

		// Get current engine_data to track attempts
		$job = $jobs_db->get_job( $jobId );
		if ( ! $job ) {
			$this->failJob( $jobId, 'Job not found for rescheduling' );
			return;
		}

		$engine_data  = $job['engine_data'] ?? array();
		$attempts     = ( $engine_data['attempts'] ?? 0 ) + 1;
		$max_attempts = $engine_data['max_attempts'] ?? 24; // Default 24 attempts

		// Check if we've exceeded max attempts
		if ( $attempts > $max_attempts ) {
			$this->failJob( $jobId, "Task exceeded maximum attempts ({$max_attempts})" );
			return;
		}

		// Update attempt count
		$engine_data['attempts']     = $attempts;
		$engine_data['last_attempt'] = current_time( 'mysql' );
		$jobs_db->store_engine_data( $jobId, $engine_data );

		// Schedule next execution
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$args = array(
				'job_id' => $jobId,
			);

			as_schedule_single_action(
				time() + $delaySeconds,
				'datamachine_system_agent_handle_task',
				$args,
				'data-machine'
			);

			do_action(
				'datamachine_log',
				'debug',
				"System Agent task rescheduled for job {$jobId} (attempt {$attempts}/{$max_attempts})",
				array(
					'job_id'        => $jobId,
					'task_type'     => $this->getTaskType(),
					'agent_type'    => 'system',
					'attempts'      => $attempts,
					'max_attempts'  => $max_attempts,
					'delay_seconds' => $delaySeconds,
				)
			);
		} else {
			$this->failJob( $jobId, 'Action Scheduler not available for rescheduling' );
		}
	}
}
