<?php
/**
 * Tests for the SystemAgent singleton and task scheduling.
 *
 * @package DataMachine\Tests\Unit\AI\System
 */

namespace DataMachine\Tests\Unit\AI\System;

use DataMachine\Engine\AI\System\SystemAgent;
use WP_UnitTestCase;

class SystemAgentTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Reset singleton for clean tests via reflection.
		$ref = new \ReflectionClass( SystemAgent::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	public function tear_down(): void {
		// Reset singleton after tests.
		$ref = new \ReflectionClass( SystemAgent::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		parent::tear_down();
	}

	public function test_get_instance_returns_singleton(): void {
		$a = SystemAgent::getInstance();
		$b = SystemAgent::getInstance();
		$this->assertSame( $a, $b );
	}

	public function test_get_task_handlers_returns_registered_handlers(): void {
		$agent = SystemAgent::getInstance();
		$handlers = $agent->getTaskHandlers();
		$this->assertIsArray( $handlers );
	}

	public function test_schedule_task_returns_false_for_unknown_task_type(): void {
		$agent = SystemAgent::getInstance();
		$result = $agent->scheduleTask( 'nonexistent_task_type', [] );
		$this->assertFalse( $result );
	}

	public function test_handle_task_handles_missing_job_gracefully(): void {
		$agent = SystemAgent::getInstance();
		// Job ID 999999 should not exist — should not throw.
		$agent->handleTask( 999999 );
		// If we get here without exception, the test passes.
		$this->assertTrue( true );
	}

	public function test_handle_task_handles_missing_task_type(): void {
		// Create a job without task_type in engine_data.
		$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
		$job_id = $jobs_db->create_job( [
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'system',
			'label'       => 'Test Job',
		] );

		if ( ! $job_id ) {
			$this->markTestSkipped( 'Could not create test job.' );
		}

		// Store engine_data without task_type.
		$jobs_db->store_engine_data( (int) $job_id, [ 'foo' => 'bar' ] );

		$agent = SystemAgent::getInstance();
		$agent->handleTask( (int) $job_id );

		// Job should be marked as failed.
		$job = $jobs_db->get_job( (int) $job_id );
		$this->assertStringContainsString( 'failed', strtolower( $job['status'] ?? '' ) );
	}
}
