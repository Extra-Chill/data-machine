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
use DataMachine\Engine\AI\System\Tasks\ImageGenerationTask;

class SystemAgentServiceProvider {

	/**
	 * Constructor - registers all System Agent components.
	 */
	public function __construct() {
		$this->registerTaskHandlers();
		$this->instantiateSystemAgent();
		$this->registerActionSchedulerHooks();
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
			[ $this, 'getBuiltInTasks' ]
		);
	}

	/**
	 * Get built-in task handlers.
	 *
	 * @param array $tasks Existing task handlers.
	 * @return array Task handlers including built-in ones.
	 */
	public function getBuiltInTasks( array $tasks ): array {
		$tasks['image_generation']    = ImageGenerationTask::class;
		$tasks['alt_text_generation'] = AltTextTask::class;

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
			[ $this, 'handleScheduledTask' ]
		);

		add_action(
			'datamachine_system_agent_set_featured_image',
			[ $this, 'handleDeferredFeaturedImage' ],
			10,
			3
		);
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
					[
						'attachment_id'   => $attachmentId,
						'pipeline_job_id' => $pipelineJobId,
						'agent_type'      => 'system',
					]
				);
				return;
			}

			// Reschedule
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + 15,
					'datamachine_system_agent_set_featured_image',
					[
						'attachment_id'   => $attachmentId,
						'pipeline_job_id' => $pipelineJobId,
						'attempt'         => $attempt + 1,
					],
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
			[
				'post_id'         => $post_id,
				'attachment_id'   => $attachmentId,
				'pipeline_job_id' => $pipelineJobId,
				'attempt'         => $attempt,
				'agent_type'      => 'system',
			]
		);
	}
}
