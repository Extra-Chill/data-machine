<?php
/**
 * Tests for the TaskScheduler.
 *
 * @package DataMachine\Tests\Unit\Engine
 */

namespace DataMachine\Tests\Unit\Engine;

use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachine\Engine\Tasks\TaskRegistry;
use WP_UnitTestCase;

class TaskSchedulerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		TaskRegistry::reset();
		TaskRegistry::load();
	}

	public function tear_down(): void {
		TaskRegistry::reset();
		parent::tear_down();
	}

	public function test_schedule_returns_false_for_unknown_task(): void {
		$result = TaskScheduler::schedule( 'nonexistent_task_type', array() );
		$this->assertFalse( $result );
	}

	public function test_handle_task_handles_missing_job_gracefully(): void {
		// Job ID 999999 should not exist — should not throw.
		TaskScheduler::handleTask( 999999 );
		$this->assertTrue( true );
	}

	public function test_handle_task_fails_job_with_missing_task_type(): void {
		$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
		$job_id  = $jobs_db->create_job( array(
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'system',
			'label'       => 'Test Job',
		) );

		if ( ! $job_id ) {
			$this->markTestSkipped( 'Could not create test job.' );
		}

		// Store engine_data without task_type.
		$jobs_db->store_engine_data( (int) $job_id, array( 'foo' => 'bar' ) );

		TaskScheduler::handleTask( (int) $job_id );

		$job = $jobs_db->get_job( (int) $job_id );
		$this->assertStringContainsString( 'failed', strtolower( $job['status'] ?? '' ) );
	}

	public function test_schedule_batch_returns_false_for_unknown_task(): void {
		$result = TaskScheduler::scheduleBatch( 'nonexistent_task_type', array( array( 'foo' => 'bar' ) ) );
		$this->assertFalse( $result );
	}

	public function test_schedule_batch_returns_false_for_empty_items(): void {
		$result = TaskScheduler::scheduleBatch( 'image_generation', array() );
		$this->assertFalse( $result );
	}

	public function test_get_batch_status_returns_null_for_nonexistent_job(): void {
		$result = TaskScheduler::getBatchStatus( 999999 );
		$this->assertNull( $result );
	}

	public function test_cancel_batch_returns_false_for_nonexistent_job(): void {
		$result = TaskScheduler::cancelBatch( 999999 );
		$this->assertFalse( $result );
	}

	public function test_list_batches_returns_array(): void {
		$result = TaskScheduler::listBatches();
		$this->assertIsArray( $result );
	}

	public function test_batch_chunk_size_constant(): void {
		$this->assertSame( 10, TaskScheduler::BATCH_CHUNK_SIZE );
	}

	public function test_batch_chunk_delay_constant(): void {
		$this->assertSame( 30, TaskScheduler::BATCH_CHUNK_DELAY );
	}
}
