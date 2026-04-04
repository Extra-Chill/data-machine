<?php
/**
 * System Agent Service Provider.
 *
 * Registers task infrastructure: built-in task handlers, Action Scheduler
 * hooks, and scheduled task management.
 *
 * @package DataMachine\Engine\AI\System
 * @since 0.22.4
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\AgentPingTask;
use DataMachine\Engine\AI\System\Tasks\AltTextTask;
use DataMachine\Engine\AI\System\Tasks\DailyMemoryTask;
// GitHubIssueTask moved to data-machine-code extension.
use DataMachine\Engine\AI\System\Tasks\ImageGenerationTask;
use DataMachine\Engine\AI\System\Tasks\ImageOptimizationTask;
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
	 * Get built-in task handlers.
	 *
	 * @param array $tasks Existing task handlers.
	 * @return array Task handlers including built-in ones.
	 */
	public function getBuiltInTasks( array $tasks ): array {
		$tasks['agent_ping']                 = AgentPingTask::class;
		$tasks['image_generation']            = ImageGenerationTask::class;
		$tasks['image_optimization']          = ImageOptimizationTask::class;
		$tasks['alt_text_generation']         = AltTextTask::class;
		// github_create_issue moved to data-machine-code extension.
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
	 */
	private function registerActionSchedulerHooks(): void {
		add_action(
			'datamachine_task_handle',
			array( $this, 'handleScheduledTask' )
		);

		add_action(
			'datamachine_task_process_batch',
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
	 * Defers to the action_scheduler_init hook to ensure the AS data store
	 * is initialized before calling scheduling functions. This matches the
	 * pattern used by all other DM cleanup/scheduling classes.
	 *
	 * @since 0.32.0
	 */
	private function manageDailyMemorySchedule(): void {
		add_action( 'action_scheduler_init', function () {
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
		} );
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
