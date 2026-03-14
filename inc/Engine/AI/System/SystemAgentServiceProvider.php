<?php
/**
 * System Agent Service Provider.
 *
 * Registers task infrastructure: built-in task handlers, Action Scheduler
 * hooks, and scheduled task management. Bridges the legacy SystemAgent
 * singleton with the new TaskRegistry and TaskScheduler classes.
 *
 * @package DataMachine\Engine\AI\System
 * @since 0.22.4
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\AltTextTask;
use DataMachine\Engine\AI\System\Tasks\DailyMemoryTask;
use DataMachine\Engine\AI\System\Tasks\GitHubIssueTask;
use DataMachine\Engine\AI\System\Tasks\ImageGenerationTask;
use DataMachine\Engine\AI\System\Tasks\InternalLinkingTask;
use DataMachine\Engine\AI\System\Tasks\MetaDescriptionTask;
use DataMachine\Engine\Tasks\TaskRegistry;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachine\Core\PluginSettings;

class SystemAgentServiceProvider {

	/**
	 * Action Scheduler hook name for daily memory generation.
	 */
	const DAILY_MEMORY_HOOK = 'datamachine_system_agent_daily_memory';

	/**
	 * Constructor - registers all task infrastructure.
	 */
	public function __construct() {
		$this->registerTaskHandlers();
		$this->registerBackwardCompatFilter();
		$this->initializeRegistry();
		$this->registerActionSchedulerHooks();
		$this->manageDailyMemorySchedule();
	}

	/**
	 * Register built-in task handlers on the new filter.
	 *
	 * Hooks the datamachine_tasks filter to register the core task types.
	 */
	private function registerTaskHandlers(): void {
		add_filter(
			'datamachine_tasks',
			array( $this, 'getBuiltInTasks' )
		);
	}

	/**
	 * Register backward-compatible filter bridge.
	 *
	 * Any third-party code hooking the old `datamachine_system_agent_tasks`
	 * filter will have its tasks merged into the new `datamachine_tasks` filter.
	 *
	 * @since 0.37.0
	 */
	private function registerBackwardCompatFilter(): void {
		add_filter(
			'datamachine_tasks',
			function ( array $tasks ): array {
				/**
				 * Legacy filter for registering system agent tasks.
				 *
				 * @deprecated 0.37.0 Use the `datamachine_tasks` filter instead.
				 * @param array $tasks Task type => handler class name mapping.
				 */
				return apply_filters( 'datamachine_system_agent_tasks', $tasks );
			},
			// Run after built-in tasks (priority 10) so legacy filter sees them.
			20
		);
	}

	/**
	 * Get built-in task handlers.
	 *
	 * @param array $tasks Existing task handlers.
	 * @return array Task handlers including built-in ones.
	 */
	public function getBuiltInTasks( array $tasks ): array {
		$tasks['image_generation']            = ImageGenerationTask::class;
		$tasks['alt_text_generation']         = AltTextTask::class;
		$tasks['github_create_issue']         = GitHubIssueTask::class;
		$tasks['internal_linking']            = InternalLinkingTask::class;
		$tasks['daily_memory_generation']     = DailyMemoryTask::class;
		$tasks['meta_description_generation'] = MetaDescriptionTask::class;

		return $tasks;
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
	 * Registers hooks for both new and legacy AS action names.
	 * New actions use `datamachine_task_*` prefix.
	 * Legacy actions (`datamachine_system_agent_*`) are kept for
	 * in-flight jobs scheduled before the upgrade.
	 */
	private function registerActionSchedulerHooks(): void {
		// New action names.
		add_action(
			'datamachine_task_handle',
			array( $this, 'handleScheduledTask' )
		);

		add_action(
			'datamachine_task_process_batch',
			array( $this, 'handleBatchChunk' )
		);

		// Legacy action names — handle in-flight jobs from before upgrade.
		add_action(
			'datamachine_system_agent_handle_task',
			array( $this, 'handleScheduledTask' )
		);

		add_action(
			'datamachine_system_agent_process_batch',
			array( $this, 'handleBatchChunk' )
		);

		add_action(
			'datamachine_system_agent_set_featured_image',
			array( $this, 'handleDeferredFeaturedImage' ),
			10,
			3
		);

		add_action(
			self::DAILY_MEMORY_HOOK,
			array( $this, 'handleDailyMemoryGeneration' )
		);
	}

	/**
	 * Manage the daily memory recurring schedule.
	 *
	 * Ensures the recurring Action Scheduler action exists when enabled
	 * and is removed when disabled. Runs on every page load but the
	 * as_next_scheduled_action check is fast.
	 *
	 * @since 0.32.0
	 */
	private function manageDailyMemorySchedule(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		$enabled        = (bool) PluginSettings::get( 'daily_memory_enabled', false );
		$next_scheduled = as_next_scheduled_action( self::DAILY_MEMORY_HOOK, array(), 'data-machine' );

		if ( $enabled && ! $next_scheduled ) {
			// Schedule daily at midnight UTC.
			$midnight = strtotime( 'tomorrow midnight' );
			as_schedule_recurring_action(
				$midnight,
				DAY_IN_SECONDS,
				self::DAILY_MEMORY_HOOK,
				array(),
				'data-machine'
			);
		} elseif ( ! $enabled && $next_scheduled ) {
			as_unschedule_all_actions( self::DAILY_MEMORY_HOOK, array(), 'data-machine' );
		}
	}

	/**
	 * Handle the daily memory generation Action Scheduler callback.
	 *
	 * @since 0.32.0
	 */
	public function handleDailyMemoryGeneration(): void {
		TaskScheduler::schedule( 'daily_memory_generation', array(
			'date' => gmdate( 'Y-m-d' ),
		) );
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
