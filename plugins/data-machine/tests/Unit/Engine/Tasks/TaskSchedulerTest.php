<?php
/**
 * Tests for the TaskScheduler.
 *
 * @package DataMachine\Tests\Unit\Engine\Tasks
 * @since 0.72.0 Updated: handleTask() removed, schedule() delegates to execute-workflow.
 */

namespace DataMachine\Tests\Unit\Engine\Tasks;

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

	public function test_batch_hook_constant(): void {
		$this->assertSame( 'datamachine_task_process_batch', TaskScheduler::BATCH_HOOK );
	}

	public function test_batch_context_constant(): void {
		$this->assertSame( 'task', TaskScheduler::BATCH_CONTEXT );
	}
}
