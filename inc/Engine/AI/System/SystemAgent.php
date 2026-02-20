<?php
/**
 * System Agent - Core async task orchestration.
 *
 * Manages async task scheduling and execution for tools that need background
 * processing. Integrates with DM Jobs for tracking and Action Scheduler for
 * execution timing. Routes completed results back to originating contexts.
 *
 * @package DataMachine\Engine\AI\System
 * @since 0.22.4
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

class SystemAgent {

	/**
	 * Singleton instance.
	 *
	 * @var SystemAgent|null
	 */
	private static ?SystemAgent $instance = null;

	/**
	 * Registered task handlers.
	 *
	 * @var array<string, string> Task type => handler class name mapping.
	 */
	private array $taskHandlers = array();

	/**
	 * Private constructor for singleton pattern.
	 */
	private function __construct() {
		$this->loadTaskHandlers();
	}

	/**
	 * Get singleton instance.
	 *
	 * @return SystemAgent
	 */
	public static function getInstance(): SystemAgent {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Schedule an async task.
	 *
	 * Creates a DM Job record and schedules an Action Scheduler action for
	 * task execution. Returns the job ID for tracking purposes.
	 *
	 * @param string $taskType Task type identifier.
	 * @param array  $params   Task parameters to store in engine_data.
	 * @param array  $context  Context for routing results back (origin, IDs, etc.).
	 * @return int|false Job ID on success, false on failure.
	 */
	public function scheduleTask( string $taskType, array $params, array $context = array() ): int|false {
		if ( ! isset( $this->taskHandlers[ $taskType ] ) ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent: Unknown task type '{$taskType}'",
				array(
					'task_type'  => $taskType,
					'agent_type' => 'system',
					'params'     => $params,
					'context'    => $context,
				)
			);
			return false;
		}

		// Create DM Job — matches Jobs::create_job() schema
		$jobs_db = new Jobs();
		$job_id  = $jobs_db->create_job( array(
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'system',
			'label'       => ucfirst( str_replace( '_', ' ', $taskType ) ),
		) );

		if ( ! $job_id ) {
			do_action(
				'datamachine_log',
				'error',
				'System Agent: Failed to create job for task',
				array(
					'task_type'  => $taskType,
					'agent_type' => 'system',
				)
			);
			return false;
		}

		// Store task params in engine_data
		$jobs_db->store_engine_data( (int) $job_id, array_merge( $params, array(
			'task_type'    => $taskType,
			'context'      => $context,
			'scheduled_at' => current_time( 'mysql' ),
		) ) );

		// Mark job as processing
		$jobs_db->start_job( (int) $job_id, JobStatus::PROCESSING );

		// Schedule Action Scheduler action
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$args = array(
				'job_id' => $job_id,
			);

			$action_id = as_schedule_single_action(
				time(),
				'datamachine_system_agent_handle_task',
				$args,
				'data-machine'
			);

			if ( $action_id ) {
				do_action(
					'datamachine_log',
					'info',
					"System Agent task scheduled: {$taskType} (Job #{$job_id})",
					array(
						'job_id'     => $job_id,
						'action_id'  => $action_id,
						'task_type'  => $taskType,
						'agent_type' => 'system',
						'params'     => $params,
						'context'    => $context,
					)
				);

				return $job_id;
			} else {
				// Action Scheduler failed - mark job as failed
				$jobs_db->complete_job( $job_id, JobStatus::failed( 'Failed to schedule Action Scheduler action' )->toString() );
				return false;
			}
		} else {
			// Action Scheduler not available
			$jobs_db->complete_job( $job_id, JobStatus::failed( 'Action Scheduler not available' )->toString() );
			return false;
		}
	}

	/**
	 * Handle a scheduled task (Action Scheduler callback).
	 *
	 * Loads the job, determines the task type, and delegates to the
	 * appropriate handler for execution.
	 *
	 * @param int $jobId Job ID from DM Jobs table.
	 */
	public function handleTask( int $jobId ): void {
		$jobs_db = new Jobs();
		$job     = $jobs_db->get_job( $jobId );

		if ( ! $job ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent: Job {$jobId} not found",
				array(
					'job_id'     => $jobId,
					'agent_type' => 'system',
				)
			);
			return;
		}

		$engine_data = $job['engine_data'] ?? array();
		$task_type   = $engine_data['task_type'] ?? '';

		if ( empty( $task_type ) ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent: No task type found in job {$jobId}",
				array(
					'job_id'      => $jobId,
					'agent_type'  => 'system',
					'engine_data' => $engine_data,
				)
			);

			$jobs_db->complete_job( $jobId, JobStatus::failed( 'No task type found' )->toString() );
			return;
		}

		if ( ! isset( $this->taskHandlers[ $task_type ] ) ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent: Unknown task type '{$task_type}' for job {$jobId}",
				array(
					'job_id'     => $jobId,
					'task_type'  => $task_type,
					'agent_type' => 'system',
				)
			);

			$jobs_db->complete_job( $jobId, JobStatus::failed( "Unknown task type: {$task_type}" )->toString() );
			return;
		}

		// Instantiate and execute the task handler
		$handler_class = $this->taskHandlers[ $task_type ];

		try {
			$handler = new $handler_class();
			$handler->execute( $jobId, $engine_data );
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'error',
				"System Agent task execution failed for job {$jobId}: " . $e->getMessage(),
				array(
					'job_id'         => $jobId,
					'task_type'      => $task_type,
					'agent_type'     => 'system',
					'handler_class'  => $handler_class,
					'exception'      => $e->getMessage(),
					'exception_file' => $e->getFile(),
					'exception_line' => $e->getLine(),
				)
			);

			// Mark job as failed due to exception
			$jobs_db->complete_job( $jobId, JobStatus::failed( 'Task execution exception: ' . $e->getMessage() )->toString() );
		}
	}

	/**
	 * Load task handlers from filter.
	 *
	 * Uses the datamachine_system_agent_tasks filter to allow registration
	 * of task type => handler class mappings.
	 */
	private function loadTaskHandlers(): void {
		/**
		 * Filter to register System Agent task handlers.
		 *
		 * @param array $handlers Task type => handler class name mapping.
		 */
		$this->taskHandlers = apply_filters( 'datamachine_system_agent_tasks', array() );
	}

	/**
	 * Get registered task handlers (for debugging/admin purposes).
	 *
	 * @return array<string, string> Task type => handler class mappings.
	 */
	public function getTaskHandlers(): array {
		return $this->taskHandlers;
	}
}
