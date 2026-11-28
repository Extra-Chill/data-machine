<?php
/**
 * A skipped system-task child must not fail its parent pipeline job (#3638).
 *
 * Reproduces the alt-text shape: the attachment was deleted between queue
 * time and execution. The child job completes as skipped, and the parent
 * SystemTaskStep must classify as succeeded — not packet_failure.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\SystemTask
 */

namespace DataMachine\Tests\Unit\Core\Steps\SystemTask;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\EngineData;
use DataMachine\Core\JobStatus;
use DataMachine\Core\Steps\SystemTask\SystemTaskStep;
use DataMachine\Core\Steps\WorkflowConfigFactory;
use DataMachine\Engine\AI\System\Tasks\AltTextTask;
use WP_UnitTestCase;

final class SystemTaskSkippedChildParentStatusTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Jobs::create_table();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_skipped_alt_text_child_does_not_fail_parent(): void {
		$deleted_id = $this->createImageAttachment();
		wp_delete_attachment( $deleted_id, true );

		$parent_failures = array();
		$result          = $this->runAltTextStep(
			array( 'attachment_id' => $deleted_id ),
			$parent_id,
			$parent_failures
		);

		// Parent step classifies as succeeded — not packet_failure.
		$this->assertSame( 'succeeded', $result['status'] ?? '' );
		$this->assertTrue( (bool) ( $result['success'] ?? false ) );
		$this->assertNotSame( 'packet_failure', $result['reason'] ?? '' );

		// No failure routed for the parent job.
		$this->assertSame( array(), $parent_failures );

		// Child job completed as skipped with the deletion reason.
		$jobs_db  = new Jobs();
		$children = $jobs_db->get_children( $parent_id );
		$this->assertCount( 1, $children );
		$child = $children[0];
		$this->assertStringContainsString( 'completed', strtolower( $child['status'] ?? '' ) );
		$this->assertFalse( JobStatus::isStatusFailure( (string) ( $child['status'] ?? '' ) ) );

		$child_data = $child['engine_data'] ?? array();
		$this->assertTrue( (bool) ( $child_data['skipped'] ?? false ) );
		$this->assertSame( 'attachment_deleted', $child_data['reason'] ?? '' );

		// Result packet reports the skip as a success to downstream steps.
		$packet = $this->lastPacket( $result );
		$this->assertTrue( (bool) ( $packet['metadata']['success'] ?? false ) );
		$this->assertTrue( (bool) ( $packet['metadata']['skipped'] ?? false ) );
	}

	public function test_failed_non_image_child_still_fails_parent_step(): void {
		$upload_dir = wp_upload_dir();
		$text_path  = $upload_dir['path'] . '/parent-status-doc.txt';
		file_put_contents( $text_path, 'not an image' );
		$text_attachment = self::factory()->attachment->create_object( array(
			'file'           => $text_path,
			'post_mime_type' => 'text/plain',
		) );
		update_attached_file( $text_attachment, $text_path );

		$parent_failures = array();
		$result          = $this->runAltTextStep(
			array(
				'attachment_id' => $text_attachment,
				'force'         => true,
			),
			$parent_id,
			$parent_failures
		);

		$this->assertSame( 'failed', $result['status'] ?? '' );

		$jobs_db  = new Jobs();
		$children = $jobs_db->get_children( $parent_id );
		$this->assertCount( 1, $children );
		$this->assertTrue( JobStatus::isStatusFailure( (string) ( $children[0]['status'] ?? '' ) ) );

		wp_delete_file( $text_path );
	}

	/**
	 * Execute the alt_text_generation task as a SystemTaskStep for a
	 * delegated (agentless, frozen-owner) parent job.
	 *
	 * @param array      $params          Task params.
	 * @param int|null   $parent_id       Receives the created parent job ID.
	 * @param array|null $parent_failures Receives datamachine_fail_job codes fired for the parent.
	 * @return array Step execution result.
	 */
	private function runAltTextStep( array $params, ?int &$parent_id, ?array &$parent_failures ): array {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$workflow = ( new AltTextTask() )->getWorkflow( $params );
		$configs  = WorkflowConfigFactory::buildEphemeralConfigs( $workflow );
		$step_id  = (string) array_key_first( $configs['flow_config'] );

		$operation = array(
			'action'          => 'fixture/alt-text',
			'initiator'       => array( 'user_id' => $owner ),
			'execution_owner' => array(
				'user_id'  => $owner,
				'agent_id' => 0,
			),
		);

		$jobs      = new Jobs();
		$parent_id = (int) $jobs->create_job( array(
			'pipeline_id'        => 'direct',
			'flow_id'            => 'direct',
			'source'             => 'delegated',
			'label'              => 'Alt Text Generation',
			'user_id'            => $owner,
			'operation_envelope' => array( 'delegated_operation' => $operation ),
		) );
		$this->assertGreaterThan( 0, $parent_id, 'Could not create parent job.' );

		$engine = new EngineData(
			array(
				'flow_config'         => $configs['flow_config'],
				'pipeline_config'     => $configs['pipeline_config'],
				'job'                 => array(
					'job_id'      => $parent_id,
					'user_id'     => $owner,
					'flow_id'     => 'direct',
					'pipeline_id' => 'direct',
				),
				'delegated_operation' => $operation,
			),
			$parent_id
		);

		$parent_failures = array();
		$job_for_capture = $parent_id;
		$capture         = static function ( $job_id, $code ) use ( &$parent_failures, $job_for_capture ): void {
			if ( (int) $job_id === $job_for_capture ) {
				$parent_failures[] = $code;
			}
		};
		add_action( 'datamachine_fail_job', $capture, 1, 2 );

		try {
			return ( new SystemTaskStep() )->execute(
				array(
					'job_id'       => $parent_id,
					'flow_step_id' => $step_id,
					'data'         => array(),
					'engine'       => $engine,
				)
			);
		} finally {
			remove_action( 'datamachine_fail_job', $capture, 1 );
		}
	}

	private function createImageAttachment(): int {
		$upload_dir = wp_upload_dir();
		$image_path = $upload_dir['path'] . '/parent-status-image.jpg';
		$jpeg_data  = base64_decode( '/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/3/AD' );
		file_put_contents( $image_path, $jpeg_data );

		$attachment_id = self::factory()->attachment->create_object( array(
			'file'           => $image_path,
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Parent Status Image',
		) );
		update_attached_file( $attachment_id, $image_path );

		return (int) $attachment_id;
	}

	/**
	 * Get the last packet from a step execution result.
	 *
	 * @param array $result Step execution result.
	 * @return array Packet.
	 */
	private function lastPacket( array $result ): array {
		$packets = is_array( $result['packets'] ?? null ) ? $result['packets'] : array();
		$this->assertNotEmpty( $packets );
		$packet = end( $packets );

		return is_array( $packet ) ? $packet : array();
	}
}
