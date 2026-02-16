<?php
/**
 * Tests for the SystemAgentServiceProvider registration.
 *
 * @package DataMachine\Tests\Unit\AI\System
 */

namespace DataMachine\Tests\Unit\AI\System;

use DataMachine\Engine\AI\System\SystemAgentServiceProvider;
use DataMachine\Engine\AI\System\Tasks\ImageGenerationTask;
use WP_UnitTestCase;

class SystemAgentServiceProviderTest extends WP_UnitTestCase {

	private SystemAgentServiceProvider $provider;

	public function set_up(): void {
		parent::set_up();
		$this->provider = new SystemAgentServiceProvider();
	}

	public function test_get_built_in_tasks_registers_image_generation(): void {
		$tasks = $this->provider->getBuiltInTasks( [] );
		$this->assertArrayHasKey( 'image_generation', $tasks );
		$this->assertSame( ImageGenerationTask::class, $tasks['image_generation'] );
	}

	public function test_get_built_in_tasks_preserves_existing_tasks(): void {
		$existing = [ 'custom_task' => 'CustomTaskClass' ];
		$tasks = $this->provider->getBuiltInTasks( $existing );
		$this->assertArrayHasKey( 'custom_task', $tasks );
		$this->assertArrayHasKey( 'image_generation', $tasks );
	}

	public function test_handle_task_action_is_registered(): void {
		$this->assertIsInt(
			has_action( 'datamachine_system_agent_handle_task', [ $this->provider, 'handleScheduledTask' ] )
		);
	}

	public function test_set_featured_image_action_is_registered(): void {
		$this->assertIsInt(
			has_action( 'datamachine_system_agent_set_featured_image', [ $this->provider, 'handleDeferredFeaturedImage' ] )
		);
	}
}
