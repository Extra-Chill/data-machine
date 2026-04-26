<?php
/**
 * System Agent Service Provider.
 *
 * Registers task infrastructure: built-in task handlers, built-in recurring
 * schedules, Action Scheduler hooks, and the generic recurring-schedule
 * runtime that dispatches scheduled ticks into ephemeral DM jobs.
 *
 * @package DataMachine\Engine\AI\System
 * @since 0.22.4
 * @since 0.72.0 Removed datamachine_task_handle; all scheduling routes through execute-workflow.
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Engine\AI\System\Tasks\AgentPingTask;
use DataMachine\Engine\AI\System\Tasks\AltTextTask;
use DataMachine\Engine\AI\System\Tasks\DailyMemoryTask;
use DataMachine\Engine\AI\System\Tasks\ImageGenerationTask;
use DataMachine\Engine\AI\System\Tasks\ImageOptimizationTask;
use DataMachine\Engine\AI\System\Tasks\InternalLinkingTask;
use DataMachine\Engine\AI\System\Tasks\MetaDescriptionTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionActionSchedulerTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionChatSessionsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCompletedJobsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionFailedJobsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionFilesTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionLogsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionProcessedItemsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionStaleClaimsTask;
use DataMachine\Engine\Tasks\RecurringScheduleRegistry;
use DataMachine\Engine\Tasks\RecurringScheduler;
use DataMachine\Engine\Tasks\TaskRegistry;
use DataMachine\Engine\Tasks\TaskScheduler;

class SystemAgentServiceProvider {

	/**
	 * Legacy Action Scheduler hook for daily memory generation.
	 *
	 * Used solely to unschedule stale AS actions queued under the old name.
	 */
	private const LEGACY_DAILY_MEMORY_HOOK = 'datamachine_system_agent_daily_memory';

	/**
	 * Legacy task handle hook — unschedule on upgrade.
	 *
	 * @since 0.72.0
	 */
	private const LEGACY_TASK_HANDLE_HOOK = 'datamachine_task_handle';

	/**
	 * Legacy retention hooks replaced by retention SystemTasks.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	private const LEGACY_RETENTION_HOOKS = array(
		array( 'datamachine_cleanup_stale_claims', 'datamachine-maintenance' ),
		array( 'datamachine_cleanup_failed_jobs', 'datamachine-maintenance' ),
		array( 'datamachine_cleanup_completed_jobs', 'datamachine-maintenance' ),
		array( 'datamachine_cleanup_logs', 'datamachine-maintenance' ),
		array( 'datamachine_cleanup_processed_items', 'datamachine-maintenance' ),
		array( 'datamachine_cleanup_as_actions', 'datamachine-maintenance' ),
		array( 'datamachine_cleanup_old_files', 'datamachine-files' ),
		array( 'datamachine_cleanup_chat_sessions', 'datamachine-chat' ),
	);

	/**
	 * Constructor - registers all task infrastructure.
	 */
	public function __construct() {
		$this->registerTaskHandlers();
		$this->registerBuiltInSchedules();
		$this->initializeRegistry();
		$this->registerActionSchedulerHooks();
		add_action( 'action_scheduler_init', array( $this, 'manageRecurringTaskSchedules' ) );
	}

	/**
	 * Register built-in task handlers on the datamachine_tasks filter.
	 */
	private function registerTaskHandlers(): void {
		add_filter( 'datamachine_tasks', array( $this, 'getBuiltInTasks' ) );
	}

	/**
	 * Register built-in recurring schedules.
	 */
	private function registerBuiltInSchedules(): void {
		add_filter( 'datamachine_recurring_schedules', array( $this, 'getBuiltInSchedules' ) );
	}

	/**
	 * Get built-in task handlers.
	 *
	 * @param array $tasks Existing task handlers.
	 * @return array Task handlers including built-in ones.
	 */
	public function getBuiltInTasks( array $tasks ): array {
		$tasks['agent_ping']                  = AgentPingTask::class;
		$tasks['image_generation']            = ImageGenerationTask::class;
		$tasks['image_optimization']          = ImageOptimizationTask::class;
		$tasks['alt_text_generation']         = AltTextTask::class;
		$tasks['internal_linking']            = InternalLinkingTask::class;
		$tasks['daily_memory_generation']     = DailyMemoryTask::class;
		$tasks['meta_description_generation'] = MetaDescriptionTask::class;
		$tasks[ RetentionCleanup::TASK_COMPLETED_JOBS ]  = RetentionCompletedJobsTask::class;
		$tasks[ RetentionCleanup::TASK_FAILED_JOBS ]     = RetentionFailedJobsTask::class;
		$tasks[ RetentionCleanup::TASK_LOGS ]            = RetentionLogsTask::class;
		$tasks[ RetentionCleanup::TASK_PROCESSED_ITEMS ] = RetentionProcessedItemsTask::class;
		$tasks[ RetentionCleanup::TASK_AS_ACTIONS ]      = RetentionActionSchedulerTask::class;
		$tasks[ RetentionCleanup::TASK_STALE_CLAIMS ]    = RetentionStaleClaimsTask::class;
		$tasks[ RetentionCleanup::TASK_FILES ]           = RetentionFilesTask::class;
		$tasks[ RetentionCleanup::TASK_CHAT_SESSIONS ]   = RetentionChatSessionsTask::class;

		return $tasks;
	}

	/**
	 * Get built-in recurring schedules.
	 *
	 * @param array $schedules Existing schedule definitions.
	 * @return array Schedules including built-in ones.
	 */
	public function getBuiltInSchedules( array $schedules ): array {
		$schedules['daily_memory_generation'] = array(
			'task_type'            => 'daily_memory_generation',
			'interval'             => 'daily',
			'enabled_setting'      => 'daily_memory_enabled',
			'default_enabled'      => false,
			'label'                => 'Daily at midnight UTC',
			'first_run_callback'   => 'strtotime',
			'first_run_arg'        => 'tomorrow midnight',
			// Each agent owns its own MEMORY.md and daily archive — fan
			// out so every active agent gets compacted on the daily tick.
			// Without this, only the install's primary agent (oldest by
			// agent_id) ever has its memory compacted; agents 2+ grow
			// forever.
			'per_agent'            => true,
			'task_params_callback' => static function () {
				return array( 'date' => gmdate( 'Y-m-d' ) );
			},
		);

		foreach ( self::getRetentionScheduleDefinitions() as $schedule_id => $schedule ) {
			$schedules[ $schedule_id ] = $schedule;
		}

		return $schedules;
	}

	private static function getRetentionScheduleDefinitions(): array {
		$daily_first_run = array(
			'first_run_callback' => 'strtotime',
			'first_run_arg'      => '+1 day',
		);

		return array(
			RetentionCleanup::TASK_COMPLETED_JOBS  => array_merge(
				$daily_first_run,
				array(
					'task_type'       => RetentionCleanup::TASK_COMPLETED_JOBS,
					'interval'        => 'daily',
					'enabled_setting' => 'retention_completed_jobs_enabled',
					'default_enabled' => true,
					'label'           => 'Daily completed-jobs cleanup',
				)
			),
			RetentionCleanup::TASK_FAILED_JOBS     => array_merge(
				$daily_first_run,
				array(
					'task_type'       => RetentionCleanup::TASK_FAILED_JOBS,
					'interval'        => 'daily',
					'enabled_setting' => 'retention_failed_jobs_enabled',
					'default_enabled' => true,
					'label'           => 'Daily failed-jobs cleanup',
				)
			),
			RetentionCleanup::TASK_LOGS            => array_merge(
				$daily_first_run,
				array(
					'task_type'       => RetentionCleanup::TASK_LOGS,
					'interval'        => 'daily',
					'enabled_setting' => 'retention_logs_enabled',
					'default_enabled' => true,
					'label'           => 'Daily log cleanup',
				)
			),
			RetentionCleanup::TASK_PROCESSED_ITEMS => array_merge(
				$daily_first_run,
				array(
					'task_type'       => RetentionCleanup::TASK_PROCESSED_ITEMS,
					'interval'        => 'daily',
					'enabled_setting' => 'retention_processed_items_enabled',
					'default_enabled' => true,
					'label'           => 'Daily processed-items cleanup',
				)
			),
			RetentionCleanup::TASK_AS_ACTIONS      => array_merge(
				$daily_first_run,
				array(
					'task_type'       => RetentionCleanup::TASK_AS_ACTIONS,
					'interval'        => 'daily',
					'enabled_setting' => 'retention_as_actions_enabled',
					'default_enabled' => true,
					'label'           => 'Daily Action Scheduler action cleanup',
				)
			),
			RetentionCleanup::TASK_STALE_CLAIMS    => array_merge(
				$daily_first_run,
				array(
					'task_type'       => RetentionCleanup::TASK_STALE_CLAIMS,
					'interval'        => 'daily',
					'enabled_setting' => 'retention_stale_claims_enabled',
					'default_enabled' => true,
					'label'           => 'Daily stale-claims cleanup',
				)
			),
			RetentionCleanup::TASK_FILES           => array(
				'task_type'          => RetentionCleanup::TASK_FILES,
				'interval'           => 'weekly',
				'enabled_setting'    => 'retention_files_enabled',
				'default_enabled'    => true,
				'label'              => 'Weekly repository-file cleanup',
				'first_run_callback' => 'strtotime',
				'first_run_arg'      => '+1 week',
			),
			RetentionCleanup::TASK_CHAT_SESSIONS   => array_merge(
				$daily_first_run,
				array(
					'task_type'       => RetentionCleanup::TASK_CHAT_SESSIONS,
					'interval'        => 'daily',
					'enabled_setting' => 'retention_chat_sessions_enabled',
					'default_enabled' => true,
					'label'           => 'Daily chat-session cleanup',
				)
			),
		);
	}

	/**
	 * Initialize the TaskRegistry.
	 *
	 * @since 0.37.0
	 */
	private function initializeRegistry(): void {
		TaskRegistry::load();
	}

	/**
	 * Register Action Scheduler hooks.
	 *
	 * Post-migration hooks:
	 *   - datamachine_task_process_batch  → process batch chunks
	 *   - datamachine_task_retry          → retry polling tasks (e.g. image generation)
	 *   - datamachine_system_agent_set_featured_image → deferred featured image
	 *   - datamachine_recurring_<task>    → per-schedule ticks via TaskScheduler
	 *
	 * The legacy datamachine_task_handle hook is no longer registered.
	 * All task scheduling routes through datamachine/execute-workflow.
	 *
	 * @since 0.72.0 Removed datamachine_task_handle; added datamachine_task_retry.
	 */
	private function registerActionSchedulerHooks(): void {
		add_action( 'datamachine_task_process_batch', array( $this, 'handleBatchChunk' ) );
		add_action( 'datamachine_task_retry', array( $this, 'handleTaskRetry' ) );
		add_action(
			'datamachine_system_agent_set_featured_image',
			array( $this, 'handleDeferredFeaturedImage' ),
			10,
			3
		);

		// Generic per-schedule handler: one action hook per registered
		// recurring schedule. Action Scheduler fires the hook; the closure
		// enqueues an ephemeral DM job with the task's params via TaskScheduler.
		//
		// When the schedule declares per_agent => true, the closure
		// iterates every active agent and fires one job per agent with
		// that agent's identity in $context. Without this, recurring
		// per-agent tasks (daily memory) only run against the install's
		// primary agent.
		foreach ( RecurringScheduleRegistry::all() as $schedule ) {
			$hook        = RecurringScheduleRegistry::hookFor( $schedule );
			$task_type   = $schedule['task_type'];
			$schedule_id = $schedule['schedule_id'];

			add_action(
				$hook,
				static function () use ( $schedule_id, $task_type ): void {
					$def = RecurringScheduleRegistry::get( $schedule_id );
					if ( null === $def ) {
						return;
					}

					$params = $def['task_params'] ?? array();
					if ( ! empty( $def['task_params_callback'] ) && is_callable( $def['task_params_callback'] ) ) {
						$params = (array) call_user_func( $def['task_params_callback'] );
					}

					if ( ! empty( $def['per_agent'] ) ) {
						$agents_repo = new Agents();
						$agents      = $agents_repo->get_all();

						if ( empty( $agents ) ) {
							// No agents on this install — fall back to a
							// single site-scoped run so the task still
							// fires (mirrors pre-multi-agent behaviour).
							TaskScheduler::schedule( $task_type, $params );
							return;
						}

						foreach ( $agents as $agent ) {
							$agent_id = (int) ( $agent['agent_id'] ?? 0 );
							$owner_id = (int) ( $agent['owner_id'] ?? 0 );

							if ( $agent_id <= 0 ) {
								continue;
							}

							$agent_params             = $params;
							$agent_params['agent_id'] = $agent_id;
							$agent_params['user_id']  = $owner_id;

							TaskScheduler::schedule(
								$task_type,
								$agent_params,
								array(
									'agent_id' => $agent_id,
									'user_id'  => $owner_id,
								)
							);
						}
						return;
					}

					TaskScheduler::schedule( $task_type, $params );
				}
			);
		}
	}

	/**
	 * Reconcile all registered recurring schedules with Action Scheduler.
	 *
	 * Also cleans up legacy hooks from pre-migration versions.
	 *
	 * @since 0.71.0
	 * @since 0.72.0 Also unschedules legacy datamachine_task_handle.
	 */
	public function manageRecurringTaskSchedules(): void {
		// Upgrade cleanup: strip legacy hooks.
		RecurringScheduler::unschedule( self::LEGACY_DAILY_MEMORY_HOOK, array() );

		// Unschedule any orphaned datamachine_task_handle actions from pre-0.72.0.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::LEGACY_TASK_HANDLE_HOOK );

			foreach ( self::LEGACY_RETENTION_HOOKS as $legacy_hook ) {
				as_unschedule_all_actions( $legacy_hook[0], array(), $legacy_hook[1] );
			}
		}

		foreach ( RecurringScheduleRegistry::all() as $schedule ) {
			$hook    = RecurringScheduleRegistry::hookFor( $schedule );
			$enabled = RecurringScheduleRegistry::isEnabled( $schedule );

			$options = array();
			if ( ! empty( $schedule['cron_expression'] ) ) {
				$options['cron_expression'] = $schedule['cron_expression'];
			}
			if ( ! empty( $schedule['first_run_callback'] ) && is_callable( $schedule['first_run_callback'] ) ) {
				$first_run = call_user_func( $schedule['first_run_callback'], $schedule['first_run_arg'] ?? null );
				if ( is_int( $first_run ) && $first_run > 0 ) {
					$options['first_run_timestamp'] = $first_run;
				}
			}

			$result = RecurringScheduler::ensureSchedule(
				$hook,
				array(),
				$schedule['interval'],
				$options,
				$enabled
			);

			if ( is_wp_error( $result ) ) {
				do_action(
					'datamachine_log',
					'warning',
					'Recurring schedule reconciliation failed: ' . $result->get_error_message(),
					array(
						'schedule_id' => $schedule['schedule_id'],
						'task_type'   => $schedule['task_type'],
						'hook'        => $hook,
						'interval'    => $schedule['interval'],
					)
				);
			}
		}
	}

	/**
	 * Handle a batch chunk (Action Scheduler callback).
	 *
	 * @since 0.32.0
	 *
	 * @param string $batchId Batch identifier.
	 */
	public function handleBatchChunk( string $batchId ): void {
		TaskScheduler::processBatchChunk( $batchId );
	}

	/**
	 * Handle task retry (Action Scheduler callback for polling tasks).
	 *
	 * Used by tasks with polling patterns (e.g. ImageGenerationTask) that
	 * call reschedule() to retry after a delay. Resolves the task type
	 * from the job's engine_data and calls executeTask() directly.
	 *
	 * @since 0.72.0
	 *
	 * @param int $jobId Job ID from DM Jobs table.
	 */
	public function handleTaskRetry( int $jobId ): void {
		$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
		$job     = $jobs_db->get_job( $jobId );

		if ( ! $job ) {
			do_action(
				'datamachine_log',
				'warning',
				"Task retry: Job #{$jobId} not found",
				array( 'job_id' => $jobId, 'context' => 'system' )
			);
			return;
		}

		$engine_data = $job['engine_data'] ?? array();
		$task_type   = $engine_data['task_type'] ?? '';

		if ( empty( $task_type ) ) {
			do_action(
				'datamachine_log',
				'warning',
				"Task retry: No task_type in engine_data for job #{$jobId}",
				array( 'job_id' => $jobId, 'context' => 'system' )
			);
			return;
		}

		$handler_class = TaskRegistry::getHandler( $task_type );

		if ( ! $handler_class || ! class_exists( $handler_class ) ) {
			do_action(
				'datamachine_log',
				'error',
				"Task retry: Handler not found for '{$task_type}' (job #{$jobId})",
				array( 'job_id' => $jobId, 'task_type' => $task_type, 'context' => 'system' )
			);
			return;
		}

		try {
			$handler = new $handler_class();
			$handler->executeTask( $jobId, $engine_data );
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'error',
				"Task retry: Exception in '{$task_type}' (job #{$jobId}): " . $e->getMessage(),
				array(
					'job_id'    => $jobId,
					'task_type' => $task_type,
					'context'   => 'system',
					'exception' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Handle deferred featured image assignment.
	 *
	 * @param int $attachmentId   WordPress attachment ID.
	 * @param int $pipelineJobId  Pipeline job ID to check for post_id.
	 * @param int $attempt        Current attempt number.
	 */
	public function handleDeferredFeaturedImage( int $attachmentId, int $pipelineJobId, int $attempt = 1 ): void {
		$max_attempts = 12;

		$pipeline_engine_data = datamachine_get_engine_data( $pipelineJobId );
		$post_id              = $pipeline_engine_data['post_id'] ?? 0;

		if ( empty( $post_id ) ) {
			if ( $attempt >= $max_attempts ) {
				do_action(
					'datamachine_log',
					'warning',
					"Deferred featured image: Gave up waiting for post_id after {$max_attempts} attempts (pipeline job #{$pipelineJobId})",
					array(
						'attachment_id'   => $attachmentId,
						'pipeline_job_id' => $pipelineJobId,
						'context'         => 'system',
					)
				);
				return;
			}

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + 15,
					'datamachine_system_agent_set_featured_image',
					array(
						'attachment_id'   => $attachmentId,
						'pipeline_job_id' => $pipelineJobId,
						'attempt'         => $attempt + 1,
					),
					'data-machine'
				);
			}
			return;
		}

		if ( has_post_thumbnail( $post_id ) ) {
			return;
		}

		$result = set_post_thumbnail( $post_id, $attachmentId );

		do_action(
			'datamachine_log',
			$result ? 'info' : 'warning',
			$result
				? "Deferred featured image set on post #{$post_id} (attempt #{$attempt})"
				: "Failed to set deferred featured image on post #{$post_id}",
			array(
				'post_id'         => $post_id,
				'attachment_id'   => $attachmentId,
				'pipeline_job_id' => $pipelineJobId,
				'attempt'         => $attempt,
				'context'         => 'system',
			)
		);
	}
}
