<?php
/**
 * Tests for the TaskRegistry.
 *
 * @package DataMachine\Tests\Unit\Engine
 */

namespace DataMachine\Tests\Unit\Engine;

use DataMachine\Engine\Tasks\TaskRegistry;
use WP_UnitTestCase;

class TaskRegistryTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		TaskRegistry::reset();
	}

	public function tear_down(): void {
		TaskRegistry::reset();
		parent::tear_down();
	}

	public function test_load_populates_handlers_from_filter(): void {
		TaskRegistry::load();
		$handlers = TaskRegistry::getHandlers();
		$this->assertIsArray( $handlers );
	}

	public function test_get_handlers_returns_built_in_tasks(): void {
		$handlers = TaskRegistry::getHandlers();

		// Built-in tasks should be registered via the ServiceProvider.
		$this->assertArrayHasKey( 'image_generation', $handlers );
		$this->assertArrayHasKey( 'alt_text_generation', $handlers );
		$this->assertArrayHasKey( 'internal_linking', $handlers );
		$this->assertArrayHasKey( 'daily_memory_generation', $handlers );
		$this->assertArrayHasKey( 'meta_description_generation', $handlers );
		$this->assertArrayHasKey( 'github_create_issue', $handlers );
	}

	public function test_is_registered_returns_true_for_known_task(): void {
		$this->assertTrue( TaskRegistry::isRegistered( 'image_generation' ) );
	}

	public function test_is_registered_returns_false_for_unknown_task(): void {
		$this->assertFalse( TaskRegistry::isRegistered( 'nonexistent_task' ) );
	}

	public function test_get_handler_returns_class_for_known_task(): void {
		$handler = TaskRegistry::getHandler( 'image_generation' );
		$this->assertNotNull( $handler );
		$this->assertIsString( $handler );
	}

	public function test_get_handler_returns_null_for_unknown_task(): void {
		$this->assertNull( TaskRegistry::getHandler( 'nonexistent_task' ) );
	}

	public function test_get_registry_returns_metadata(): void {
		$registry = TaskRegistry::getRegistry();
		$this->assertIsArray( $registry );
		$this->assertArrayHasKey( 'image_generation', $registry );

		$entry = $registry['image_generation'];
		$this->assertArrayHasKey( 'task_type', $entry );
		$this->assertArrayHasKey( 'label', $entry );
		$this->assertArrayHasKey( 'description', $entry );
		$this->assertArrayHasKey( 'enabled', $entry );
	}

	public function test_custom_task_via_filter(): void {
		add_filter( 'datamachine_tasks', function ( $tasks ) {
			$tasks['test_custom_task'] = 'TestCustomTaskClass';
			return $tasks;
		}, 30 );

		TaskRegistry::reset();

		$this->assertTrue( TaskRegistry::isRegistered( 'test_custom_task' ) );
		$this->assertSame( 'TestCustomTaskClass', TaskRegistry::getHandler( 'test_custom_task' ) );
	}

	public function test_reset_clears_cache(): void {
		TaskRegistry::getHandlers(); // Populate cache.
		TaskRegistry::reset();

		// After reset, the next getHandlers call re-loads from filter.
		$handlers = TaskRegistry::getHandlers();
		$this->assertIsArray( $handlers );
	}
}
