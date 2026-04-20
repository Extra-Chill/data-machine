<?php
/**
 * System Agent Service Provider.
 *
 * Registers task infrastructure: built-in task handlers, built-in recurring
 * schedules, Action Scheduler hooks, and the generic recurring-schedule
 * runtime that dispatches scheduled ticks into ephemeral DM jobs.
 *
 * Scheduling plumbing lives on RecurringScheduler (shared with FlowScheduling).
 * Schedule definitions live in the datamachine_recurring_schedules filter.
 * This provider only wires everything up — it no longer hardcodes per-task
 * scheduling logic.
 *
 * @package DataMachine\Engine\AI\System
 * @since 0.22.4
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\AgentPingTask;
use DataMachine\Engine\AI\System\Tasks\AltTextTask;
use DataMachine\Engine\AI\System\Tasks\DailyMemoryTask;
use DataMachine\Engine\AI\System\Tasks\ImageGenerationTask;
use DataMachine\Engine\AI\System\Tasks\ImageOptimizationTask;
use DataMachine\Engine\AI\System\Tasks\InternalLinkingTask;
use DataMachine\Engine\AI\System\Tasks\MetaDescriptionTask;
use DataMachine\Engine\Tasks\RecurringScheduleRegistry;
use DataMachine\Engine\Tasks\RecurringScheduler;
use DataMachine\Engine\Tasks\TaskRegistry;
use DataMachine\Engine\Tasks\TaskScheduler;

class SystemAgentServiceProvider {

	/**
	 * Legacy Action Scheduler hook name for daily memory generation.
	 *
	 * Retained only to unschedule lingering AS actions after the refactor;
	 * new scheduling uses `datamachine_recurring_daily_memory_generation`.
	 *
	 * @deprecated 0.71.0 use RecurringScheduleRegistry::hookFor() instead.
	 */
	const LEGACY_DAILY_MEMORY_HOOK = 'datamachine_system_agent_daily_memory';

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
	 *
	 * Schedules are registered separately from task handlers so a task
	 * class stays a pure handler with no knowledge of how or how often
	 * it runs. The same task handler can be referenced by zero or many
	 * schedules (or none — it can be invoked on demand only).
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
			'task_type'          => 'daily_memory_generation',
			'interval'           => 'daily',
			'enabled_setting'    => 'daily_memory_enabled',
			'default_enabled'    => false,
			'label'              => 'Daily at midnight UTC',
			'first_run_callback' => 'strtotime',
			'first_run_arg'      => 'tomorrow midnight',
			'task_params_callback' => static function () {
				return array( 'date' => gmdate( 'Y-m-d' ) );
			},
		);

		return $schedules;
	}

	/**
	 * Initialize the TaskRegistry.
	 *
	 * Ensures the registry is loaded early in the WordPress lifecycle
	 * so task handlers are available for scheduling.
	 *
	 * @since 0.37.0
	 */
	private function initializeRegistry(): void {
		TaskRegistry::load();
	}

	/**
	 * Register Action Scheduler hooks.
	 *
	 * Wires three classes of AS callback:
	 *   - datamachine_task_handle         → run ephemeral jobs
	 *   - datamachine_task_process_batch  → process batch chunks
	 *   - datamachine_recurring_<task>    → one hook per registered schedule,
	 *     turns each AS tick into an ephemeral job via TaskScheduler.
	 *
	 * The legacy daily-memory hook is kept on the handler map for one
	 * release so AS actions enqueued before this refactor still fire.
	 */
	private function registerActionSchedulerHooks(): void {
		add_action( 'datamachine_task_handle', array( $this, 'handleScheduledTask' ) );
		add_action( 'datamachine_task_process_batch', array( $this, 'handleBatchChunk' ) );
		add_action(
			'datamachine_system_agent_set_featured_image',
			array( $this, 'handleDeferredFeaturedImage' ),
			10,
			3
		);

		// Generic per-schedule handler: one action hook per registered
		// recurring schedule. Action Scheduler fires the hook; the closure
		// enqueues an ephemeral DM job with the task's params.
		foreach ( RecurringScheduleRegistry::all() as $schedule ) {
			$hook      = RecurringScheduleRegistry::hookFor( $schedule );
			$task_type = $schedule['task_type'];
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

					TaskScheduler::schedule( $task_type, $params );
				}
			);
		}

		// Legacy daily-memory hook: still route to the new task handler so
		// any AS action enqueued before the refactor still fires correctly.
		// Will be removed after one release.
		add_action(
			self::LEGACY_DAILY_MEMORY_HOOK,
			static function (): void {
				TaskScheduler::schedule(
					'daily_memory_generation',
					array( 'date' => gmdate( 'Y-m-d' ) )
				);
			}
		);
	}

	/**
	 * Reconcile all registered recurring schedules with Action Scheduler.
	 *
	 * For every registered schedule:
	 *   - If enabled → ensure the matching AS action exists via
	 *     RecurringScheduler::ensureSchedule().
	 *   - If disabled → unschedule.
	 *
	 * Also unschedules the legacy daily-memory hook so ghost AS actions
	 * from before this refactor don't linger in the queue.
	 *
	 * Deferred to action_scheduler_init to avoid calling AS functions
	 * before the data store is initialized.
	 *
	 * @since 0.71.0
	 */
	public function manageRecurringTaskSchedules(): void {
		// One-time cleanup: legacy daily-memory hook.
		RecurringScheduler::unschedule( self::LEGACY_DAILY_MEMORY_HOOK, array() );

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
	 * Handle Action Scheduler task callback.
	 *
	 * @param int $jobId Job ID from DM Jobs table.
	 */
	public function handleScheduledTask( int $jobId ): void {
		TaskScheduler::handleTask( $jobId );
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
	 * Handle deferred featured image assignment.
	 *
	 * Called when the System Agent finished image generation before the
	 * pipeline published the post. Retries up to 12 times (3 minutes total
	 * at 15-second intervals).
	 *
	 * @param int $attachmentId   WordPress attachment ID.
	 * @param int $pipelineJobId  Pipeline job ID to check for post_id.
	 * @param int $attempt        Current attempt number.
	 */
	public function handleDeferredFeaturedImage( int $attachmentId, int $pipelineJobId, int $attempt = 1 ): void {
		$max_attempts = 12; // 12 × 15s = 3 minutes

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

			// Reschedule.
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

		// Don't overwrite existing featured image.
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
