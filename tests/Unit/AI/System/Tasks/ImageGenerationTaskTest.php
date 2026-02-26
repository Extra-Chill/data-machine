<?php
/**
 * Tests for the ImageGenerationTask system task.
 *
 * @package DataMachine\Tests\Unit\AI\System\Tasks
 */

namespace DataMachine\Tests\Unit\AI\System\Tasks;

use DataMachine\Engine\AI\System\Tasks\ImageGenerationTask;
use WP_UnitTestCase;

class TestableImageGenerationTask extends ImageGenerationTask {
	public array $set_featured_image_calls = [];

	public function callHandleSuccess( int $job_id, array $status_data, string $model, string $prompt, string $aspect_ratio, array $params ): void {
		$this->handleSuccess( $job_id, $status_data, $model, $prompt, $aspect_ratio, $params );
	}

	protected function sideloadImage( string $image_url, string $prompt, string $model ): array|\WP_Error {
		return [
			'attachment_id'  => 123,
			'attachment_url' => 'https://example.com/image.jpg',
		];
	}

	protected function trySetFeaturedImage( int $jobId, int $attachmentId, array $params ): array {
		$this->set_featured_image_calls[] = [
			'job_id'         => $jobId,
			'attachment_id'  => $attachmentId,
			'params'         => $params,
		];
		return [];
	}
}

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

	public function test_handle_success_passes_params_to_try_set_featured_image(): void {
		$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
		$job_id  = $jobs_db->create_job( [
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'system',
			'label'       => 'Test Image Gen Success',
		] );

		if ( ! $job_id ) {
			$this->markTestSkipped( 'Could not create test job.' );
		}

		$task = new TestableImageGenerationTask();

		$params = [
			'context' => [
				'pipeline_job_id' => 999,
			],
		];

		$task->callHandleSuccess(
			(int) $job_id,
			[ 'output' => 'https://example.com/remote-image.jpg' ],
			'test-model',
			'test prompt',
			'3:4',
			$params
		);

		$this->assertCount( 1, $task->set_featured_image_calls );
		$this->assertSame( (int) $job_id, $task->set_featured_image_calls[0]['job_id'] );
		$this->assertSame( 123, $task->set_featured_image_calls[0]['attachment_id'] );
		$this->assertSame( $params, $task->set_featured_image_calls[0]['params'] );
	}
}
