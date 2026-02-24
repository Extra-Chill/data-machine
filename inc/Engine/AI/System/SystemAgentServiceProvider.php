<?php
/**
 * System Agent Service Provider.
 *
 * Registers the System Agent infrastructure including built-in tasks,
 * singleton instantiation, and Action Scheduler hooks.
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
use DataMachine\Core\PluginSettings;

class SystemAgentServiceProvider {

	/**
	 * Action Scheduler hook name for daily memory generation.
	 */
	const DAILY_MEMORY_HOOK = 'datamachine_system_agent_daily_memory';

	/**
	 * Constructor - registers all System Agent components.
	 */
	public function __construct() {
		$this->registerTaskHandlers();
		$this->instantiateSystemAgent();
		$this->registerActionSchedulerHooks();
		$this->manageDailyMemorySchedule();
	}

	/**
	 * Register built-in task handlers.
	 *
	 * Hooks the datamachine_system_agent_tasks filter to register
	 * the core task types provided by Data Machine.
	 */
	private function registerTaskHandlers(): void {
		add_filter(
			'datamachine_system_agent_tasks',
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
		$tasks['image_generation']        = ImageGenerationTask::class;
		$tasks['alt_text_generation']     = AltTextTask::class;
		$tasks['github_create_issue']     = GitHubIssueTask::class;
		$tasks['internal_linking']        = InternalLinkingTask::class;
		$tasks['daily_memory_generation'] = DailyMemoryTask::class;

		return $tasks;
	}

	/**
	 * Instantiate the SystemAgent singleton.
	 *
	 * This ensures the SystemAgent is initialized and task handlers
	 * are loaded early in the WordPress lifecycle.
	 */
	private function instantiateSystemAgent(): void {
		SystemAgent::getInstance();
	}

	/**
	 * Register Action Scheduler hooks.
	 *
	 * Registers the hook that Action Scheduler will call to execute
	 * system agent tasks.
	 */
	private function registerActionSchedulerHooks(): void {
		add_action(
			'datamachine_system_agent_handle_task',
			array( $this, 'handleScheduledTask' )
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

		$enabled       = (bool) PluginSettings::get( 'daily_memory_enabled', false );
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
	 * Delegates to SystemAgent::scheduleTask() which creates a job
	 * and executes the DailyMemoryTask.
	 *
	 * @since 0.32.0
	 */
	public function handleDailyMemoryGeneration(): void {
		$system_agent = SystemAgent::getInstance();
		$system_agent->scheduleTask( 'daily_memory_generation', array(
			'date' => gmdate( 'Y-m-d' ),
		) );
	}

	/**
	 * Handle Action Scheduler task callback.
	 *
	 * This is the callback function that Action Scheduler calls when
	 * a system agent task is ready for execution.
	 *
	 * @param int $jobId Job ID from DM Jobs table.
	 */
	public function handleScheduledTask( int $jobId ): void {
		$systemAgent = SystemAgent::getInstance();
		$systemAgent->handleTask( $jobId );
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
					"System Agent: Gave up waiting for post_id after {$max_attempts} attempts (pipeline job #{$pipelineJobId})",
					array(
						'attachment_id'   => $attachmentId,
						'pipeline_job_id' => $pipelineJobId,
						'agent_type'      => 'system',
					)
				);
				return;
			}

			// Reschedule
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

		// Don't overwrite existing featured image
		if ( has_post_thumbnail( $post_id ) ) {
			return;
		}

		$result = set_post_thumbnail( $post_id, $attachmentId );

		do_action(
			'datamachine_log',
			$result ? 'info' : 'warning',
			$result
				? "System Agent: Deferred featured image set on post #{$post_id} (attempt #{$attempt})"
				: "System Agent: Failed to set deferred featured image on post #{$post_id}",
			array(
				'post_id'         => $post_id,
				'attachment_id'   => $attachmentId,
				'pipeline_job_id' => $pipelineJobId,
				'attempt'         => $attempt,
				'agent_type'      => 'system',
			)
		);
	}
}
