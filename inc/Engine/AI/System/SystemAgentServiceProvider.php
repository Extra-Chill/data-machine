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
		$tasks['image_generation'] = ImageGenerationTask::class;

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
}
