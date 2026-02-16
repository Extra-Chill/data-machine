<?php
/**
 * Tests for the ImageGenerationTask system task.
 *
 * @package DataMachine\Tests\Unit\AI\System\Tasks
 */

namespace DataMachine\Tests\Unit\AI\System\Tasks;

use DataMachine\Engine\AI\System\Tasks\ImageGenerationTask;
use WP_UnitTestCase;

class ImageGenerationTaskTest extends WP_UnitTestCase {

	private ImageGenerationTask $task;

	public function set_up(): void {
		parent::set_up();
		$this->task = new ImageGenerationTask();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_image_generation_config' );
		parent::tear_down();
	}

	public function test_get_task_type_returns_image_generation(): void {
		$this->assertSame( 'image_generation', $this->task->getTaskType() );
	}

	public function test_execute_fails_when_prediction_id_missing(): void {
		$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
		$job_id = $jobs_db->create_job( [
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'system',
			'label'       => 'Test Image Gen',
		] );

		if ( ! $job_id ) {
			$this->markTestSkipped( 'Could not create test job.' );
		}

		$this->task->execute( (int) $job_id, [] );

		$job = $jobs_db->get_job( (int) $job_id );
		$this->assertStringContainsString( 'failed', strtolower( $job['status'] ?? '' ) );
	}

	public function test_execute_fails_when_api_key_not_configured(): void {
		delete_site_option( 'datamachine_image_generation_config' );

		$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
		$job_id = $jobs_db->create_job( [
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'system',
			'label'       => 'Test Image Gen',
		] );

		if ( ! $job_id ) {
			$this->markTestSkipped( 'Could not create test job.' );
		}

		$this->task->execute( (int) $job_id, [ 'prediction_id' => 'test-pred-123' ] );

		$job = $jobs_db->get_job( (int) $job_id );
		$this->assertStringContainsString( 'failed', strtolower( $job['status'] ?? '' ) );
	}
}
