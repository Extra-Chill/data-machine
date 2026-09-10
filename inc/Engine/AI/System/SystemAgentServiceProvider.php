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
 * @since 0.72.0
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Engine\AI\System\Tasks\AgentCallTask;
use DataMachine\Engine\AI\System\Tasks\AltTextTask;
use DataMachine\Engine\AI\System\Tasks\Corpus\CorpusEmbedChunksTask;
use DataMachine\Engine\AI\System\Tasks\Corpus\CorpusIndexTask;
use DataMachine\Engine\AI\System\Tasks\Corpus\CorpusRefreshTask;
use DataMachine\Engine\AI\System\Tasks\Corpus\CorpusRetrieveEvalTask;
use DataMachine\Engine\AI\System\Tasks\DailyMemoryTask;
use DataMachine\Engine\AI\System\Tasks\DispatchMessageTask;
use DataMachine\Engine\AI\System\Tasks\EmitDataPacketsTask;
use DataMachine\Engine\AI\System\Tasks\ImageGenerationTask;
use DataMachine\Engine\AI\System\Tasks\InternalLinkingTask;
use DataMachine\Engine\AI\System\Tasks\MetaDescriptionTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionActionSchedulerTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionChatSessionsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCompletedJobsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionEngineDataTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionFailedJobsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionFilesTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionJobArtifactsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionLogsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionProcessedItemsTask;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionStaleClaimsTask;
use DataMachine\Engine\AI\System\Tasks\SourceInventoryTask;
use DataMachine\Engine\AI\System\Tasks\StaleClaimReconciliationTask;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\AI\System\Tasks\WakeBriefingTask;
use DataMachine\Engine\Tasks\RecurringRejectionTracker;
use DataMachine\Engine\Tasks\RecurringScheduleRegistry;
use DataMachine\Engine\Tasks\TaskRegistry;
use DataMachine\Engine\Tasks\TaskScheduler;

class SystemAgentServiceProvider {

	/**
	 * Constructor - registers all task infrastructure.
	 *
	 * Recurring schedule registration runs through Agents API Routines —
	 * FlowRoutines::boot() re-declares `system-<schedule_id>` routines each
	 * request and the `datamachine/dispatch-system-task` wake target below
	 * fans ticks out into DM jobs.
	 */
	public function __construct() {
		$this->registerTaskHandlers();
		$this->registerBuiltInSchedules();
		$this->registerDispatchAbility();
		$this->registerActionSchedulerHooks();
		RetentionActionSchedulerTask::registerNativeRetention();
		WakeBriefingTask::registerStalenessGuard();
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
		$tasks['agent_call']                             = AgentCallTask::class;
		$tasks['corpus_index']                           = CorpusIndexTask::class;
		$tasks['corpus_refresh']                         = CorpusRefreshTask::class;
		$tasks['corpus_embed_chunks']                    = CorpusEmbedChunksTask::class;
		$tasks['corpus_retrieve_eval']                   = CorpusRetrieveEvalTask::class;
		$tasks['dispatch_message']                       = DispatchMessageTask::class;
		$tasks['emit_data_packets']                      = EmitDataPacketsTask::class;
		$tasks['source_inventory']                       = SourceInventoryTask::class;
		$tasks['image_generation']                       = ImageGenerationTask::class;
		$tasks['alt_text_generation']                    = AltTextTask::class;
		$tasks['internal_linking']                       = InternalLinkingTask::class;
		$tasks['daily_memory_generation']                = DailyMemoryTask::class;
		$tasks['wake_briefing']                          = WakeBriefingTask::class;
		$tasks['meta_description_generation']            = MetaDescriptionTask::class;
		$tasks[ RetentionCleanup::TASK_COMPLETED_JOBS ]  = RetentionCompletedJobsTask::class;
		$tasks[ RetentionCleanup::TASK_FAILED_JOBS ]     = RetentionFailedJobsTask::class;
		$tasks[ RetentionCleanup::TASK_ENGINE_DATA ]     = RetentionEngineDataTask::class;
		$tasks[ RetentionCleanup::TASK_LOGS ]            = RetentionLogsTask::class;
		$tasks[ RetentionCleanup::TASK_PROCESSED_ITEMS ] = RetentionProcessedItemsTask::class;
		$tasks[ RetentionCleanup::TASK_AS_ACTIONS ]      = RetentionActionSchedulerTask::class;
		$tasks[ RetentionCleanup::TASK_STALE_CLAIMS ]    = RetentionStaleClaimsTask::class;
		$tasks[ RetentionCleanup::TASK_FILES ]           = RetentionFilesTask::class;
		$tasks[ RetentionCleanup::TASK_CHAT_SESSIONS ]   = RetentionChatSessionsTask::class;
		$tasks[ RetentionCleanup::TASK_JOB_ARTIFACTS ]   = RetentionJobArtifactsTask::class;
		$tasks['stale_claim_reconciliation']             = StaleClaimReconciliationTask::class;

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

		$schedules['wake_briefing'] = array(
			'task_type'            => 'wake_briefing',
			'interval'             => 'hourly',
			'enabled_setting'      => 'wake_briefing_enabled',
			'default_enabled'      => false,
			'label'                => 'Hourly — composes WAKE.md continuity digest',
			// Each agent owns its own WAKE.md (the "recent sessions" pulse is
			// agent-scoped), so fan out one composition per active agent. The
			// task itself opts out of the agent-context gate, but per_agent
			// still supplies identity so each agent's file is written.
			'per_agent'            => true,
			'task_params_callback' => static function () {
				return array();
			},
		);

		// Crashed-run reconciliation must keep pace with job creation the
		// same way high-churn AS cleanup does: a worker killed mid-step
		// orphans its job until this detects it, so the detection cadence —
		// not the operator — is the safety net. Five minutes keeps
		// crash-to-recovery latency bounded while the per-pass candidate
		// batch and the shared retry-attempt bound keep each tick cheap.
		$schedules['stale_claim_reconciliation'] = array(
			'task_type'          => 'stale_claim_reconciliation',
			'interval'           => 'every_5_minutes',
			'enabled_setting'    => 'stale_claim_reconciliation_enabled',
			'default_enabled'    => true,
			'label'              => 'Stale job-claim reconciliation (high-frequency)',
			'first_run_callback' => 'strtotime',
			'first_run_arg'      => '+5 minutes',
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
			RetentionCleanup::TASK_ENGINE_DATA     => array_merge(
				$daily_first_run,
				array(
					'task_type'       => RetentionCleanup::TASK_ENGINE_DATA,
					'interval'        => 'daily',
					'enabled_setting' => 'retention_engine_data_enabled',
					'default_enabled' => true,
					'label'           => 'Daily terminal-job engine_data shedding',
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
			// Action Scheduler actions are the highest-churn table on busy
			// installs: a single step-execution fan-out can add millions of
			// completed rows per day (~tens per second). A once-daily,
			// time-boxed pass deletes far fewer rows than accrue between runs,
			// so the table grows without bound. Run this one on a short cadence
			// so cleanup chips continuously and keeps pace with generation.
			RetentionCleanup::TASK_AS_ACTIONS      => array(
				'task_type'          => RetentionCleanup::TASK_AS_ACTIONS,
				'interval'           => 'every_5_minutes',
				'enabled_setting'    => 'retention_as_actions_enabled',
				'default_enabled'    => true,
				'label'              => 'Action Scheduler action cleanup (high-frequency)',
				'first_run_callback' => 'strtotime',
				'first_run_arg'      => '+5 minutes',
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
					'network_only'    => true,
				)
			),
			RetentionCleanup::TASK_JOB_ARTIFACTS   => array_merge(
				$daily_first_run,
				array(
					'task_type'       => RetentionCleanup::TASK_JOB_ARTIFACTS,
					'interval'        => 'daily',
					'enabled_setting' => 'retention_job_artifacts_enabled',
					'default_enabled' => true,
					'label'           => 'Daily scoped job-artifact cleanup',
				)
			),
		);
	}

	/**
	 * Register Action Scheduler hooks.
	 *
	 * Hooks:
	 *   - datamachine_task_process_batch  → process batch chunks
	 *   - datamachine_task_retry          → retry polling tasks (e.g. image generation)
	 *   - datamachine_system_agent_set_featured_image → deferred featured image
	 *
	 * Per-schedule recurring ticks moved to Agents API Routines: each
	 * schedule is a `system-<schedule_id>` routine whose wake target is the
	 * `datamachine/dispatch-system-task` ability registered below.
	 *
	 * @since 0.72.0
	 */
	private function registerActionSchedulerHooks(): void {
		add_action( 'datamachine_task_process_batch', array( $this, 'handleBatchChunk' ), 10, 2 );
		add_action( 'datamachine_task_retry', array( $this, 'handleTaskRetry' ) );
		add_action(
			'datamachine_system_agent_set_featured_image',
			array( $this, 'handleDeferredFeaturedImage' ),
			10,
			3
		);
	}

	/**
	 * Register the recurring system-task wake target.
	 *
	 * FlowRoutines::boot() registers one `system-<schedule_id>` routine per
	 * active schedule definition; each wake executes this ability with the
	 * schedule id. The ability resolves the definition, fans per-agent
	 * schedules out into one DM job per active agent, and records rejection
	 * telemetry so a persistently-rejected binding escalates.
	 */
	private function registerDispatchAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/dispatch-system-task',
				array(
					'label'               => __( 'Dispatch Recurring System Task', 'data-machine' ),
					'description'         => __( 'Routine wake target: fan a recurring schedule tick out into DM jobs via TaskScheduler.', 'data-machine' ),
					'category'            => 'datamachine-system',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'schedule_id' ),
						'properties' => array(
							'schedule_id' => array(
								'type'        => 'string',
								'description' => 'Recurring schedule identifier from RecurringScheduleRegistry.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'schedule_id' => array( 'type' => 'string' ),
							'job_ids'     => array( 'type' => 'array' ),
							'message'     => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'dispatchSchedule' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute one recurring schedule tick.
	 *
	 * @param array<string, mixed> $input { schedule_id: string }.
	 * @return array Result with the fanned-out job ids.
	 */
	public static function dispatchSchedule( array $input ): array {
		$schedule_id = is_string( $input['schedule_id'] ?? null ) ? $input['schedule_id'] : '';
		if ( '' === $schedule_id ) {
			return array(
				'success'     => false,
				'schedule_id' => '',
				'job_ids'     => array(),
				'message'     => 'schedule_id is required.',
			);
		}

		$def = RecurringScheduleRegistry::get( $schedule_id );
		if ( null === $def || ! self::isScheduleOwnedByCurrentSite( $def ) ) {
			return array(
				'success'     => false,
				'schedule_id' => $schedule_id,
				'job_ids'     => array(),
				'message'     => 'Unknown schedule or not owned by the current site.',
			);
		}

		$task_type = (string) ( $def['task_type'] ?? '' );
		$params    = $def['task_params'] ?? array();
		if ( ! empty( $def['task_params_callback'] ) && is_callable( $def['task_params_callback'] ) ) {
			$params = (array) call_user_func( $def['task_params_callback'] );
		}

		$job_ids = array();

		if ( ! empty( $def['per_agent'] ) ) {
			$agents_repo = new Agents();
			$agents      = $agents_repo->get_all();

			if ( empty( $agents ) ) {
				do_action(
					'datamachine_log',
					'warning',
					'SystemAgentServiceProvider: per-agent recurring task skipped because no active agents exist',
					array(
						'schedule_id' => $schedule_id,
						'task_type'   => $task_type,
						'error_code'  => 'recurring_task_agent_context_required',
					)
				);
				// A persistent "no agents" condition is the same class of
				// silent recurring-binding failure as a gate rejection:
				// nothing ever runs. Track it so it escalates.
				RecurringRejectionTracker::record_rejection(
					$schedule_id,
					$task_type,
					'recurring_task_agent_context_required'
				);
				return array(
					'success'     => false,
					'schedule_id' => $schedule_id,
					'job_ids'     => array(),
					'message'     => 'No active agents for per-agent fan-out.',
				);
			}

			// For per-agent schedules, a tick "ran" if at least one
			// agent fan-out was accepted. Only when every fan-out is
			// rejected is the recurring binding effectively dead.
			foreach ( $agents as $agent ) {
				$agent_id = (int) ( $agent['agent_id'] ?? 0 );
				$owner_id = (int) ( $agent['owner_id'] ?? 0 );

				if ( $agent_id <= 0 ) {
					continue;
				}

				$agent_params             = $params;
				$agent_params['agent_id'] = $agent_id;
				$agent_params['user_id']  = $owner_id;

				$scheduled = self::schedule_tick( $task_type, $agent_params, $agent_id, $owner_id );
				if ( false !== $scheduled ) {
					$job_ids[] = (int) $scheduled;
				}
			}

			$accepted = array() !== $job_ids;
			self::record_tick_outcome( $schedule_id, $task_type, $accepted );

			return self::tick_result(
				$accepted,
				$schedule_id,
				$job_ids,
				$accepted ? 'Per-agent fan-out accepted.' : 'Every agent fan-out was rejected.'
			);
		}

		$scheduled = self::schedule_tick( $task_type, $params );
		$accepted  = false !== $scheduled;
		if ( $accepted ) {
			$job_ids[] = (int) $scheduled;
		}
		self::record_tick_outcome( $schedule_id, $task_type, $accepted );

		return self::tick_result(
			$accepted,
			$schedule_id,
			$job_ids,
			$accepted ? 'Task scheduled.' : 'TaskScheduler rejected the tick.'
		);
	}

	/**
	 * Build the recurring-tick ability result.
	 *
	 * @param array<int, int> $job_ids Accepted job ids.
	 */
	private static function tick_result( bool $accepted, string $schedule_id, array $job_ids, string $message ): array {
		return array(
			'success'     => $accepted,
			'schedule_id' => $schedule_id,
			'job_ids'     => $job_ids,
			'message'     => $message,
		);
	}

	/**
	 * Schedule one recurring-tick job via TaskScheduler.
	 *
	 * @param string               $task_type Task type.
	 * @param array<string, mixed> $params    Task params.
	 * @param int|null             $agent_id  Optional acting agent for the tick.
	 * @param int|null             $user_id   Optional acting user for the tick.
	 * @return int|false Scheduled job id, or false when rejected.
	 */
	private static function schedule_tick( string $task_type, array $params, ?int $agent_id = null, ?int $user_id = null ): int|false {
		$context = array();
		if ( null !== $agent_id && null !== $user_id ) {
			$context = array(
				'agent_id' => $agent_id,
				'user_id'  => $user_id,
			);
		}

		return TaskScheduler::schedule( $task_type, $params, $context );
	}

	/**
	 * Record the rejection telemetry outcome for one schedule tick.
	 */
	private static function record_tick_outcome( string $schedule_id, string $task_type, bool $accepted ): void {
		if ( $accepted ) {
			RecurringRejectionTracker::record_success( $schedule_id );
			return;
		}

		RecurringRejectionTracker::record_rejection( $schedule_id, $task_type, 'task_scheduler_rejected' );
	}

	/**
	 * Determine whether the current site owns a recurring schedule.
	 *
	 * Network-table maintenance is scheduled only from the network's main site.
	 * Reconciliation on subsites receives disabled state so legacy duplicate
	 * chains are unscheduled, while the dispatch guard makes fetched stale
	 * actions harmless before that reconciliation occurs.
	 */
	private static function isScheduleOwnedByCurrentSite( array $schedule ): bool {
		if ( empty( $schedule['network_only'] ) || ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return true;
		}

		return function_exists( 'is_main_site' ) && is_main_site();
	}

	/**
	 * Handle a batch chunk (Action Scheduler callback).
	 *
	 * @since 0.32.0
	 *
	 * @param string   $batchId Batch identifier.
	 * @param int|null $offset  Offset key carried by the scheduler action.
	 */
	public function handleBatchChunk( string $batchId, ?int $offset = null ): void {
		TaskScheduler::processBatchChunk( $batchId, $offset );
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
				array(
					'job_id'  => $jobId,
					'context' => 'system',
				)
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
				array(
					'job_id'  => $jobId,
					'context' => 'system',
				)
			);
			return;
		}

		$handler_class = TaskRegistry::getHandler( $task_type );

		if ( ! $handler_class || ! class_exists( $handler_class ) ) {
			do_action(
				'datamachine_log',
				'error',
				"Task retry: Handler not found for '{$task_type}' (job #{$jobId})",
				array(
					'job_id'    => $jobId,
					'task_type' => $task_type,
					'context'   => 'system',
				)
			);
			return;
		}

		try {
			$handler = new $handler_class();
			if ( ! $handler instanceof SystemTask ) {
				return;
			}

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
