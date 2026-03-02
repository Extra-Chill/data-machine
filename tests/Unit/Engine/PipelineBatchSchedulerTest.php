<?php
/**
 * Tests for PipelineBatchScheduler.
 *
 * @package DataMachine\Tests\Unit\Engine
 */

namespace DataMachine\Tests\Unit\Engine;

use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use WP_UnitTestCase;

class PipelineBatchSchedulerTest extends WP_UnitTestCase {

	private Jobs $jobs_db;

	public function set_up(): void {
		parent::set_up();
		$this->jobs_db = new Jobs();
	}

	/**
	 * Build a minimal engine snapshot for testing.
	 */
	private function make_engine_snapshot( int $job_id, int $flow_id = 1, int $pipeline_id = 1 ): array {
		return array(
			'job'             => array(
				'job_id'      => $job_id,
				'flow_id'     => $flow_id,
				'pipeline_id' => $pipeline_id,
				'created_at'  => current_time( 'mysql', true ),
			),
			'flow'            => array(
				'name'        => 'Test Flow',
				'description' => '',
				'scheduling'  => array(),
			),
			'pipeline'        => array(
				'name'        => 'Test Pipeline',
				'description' => '',
			),
			'flow_config'     => array(),
			'pipeline_config' => array(),
		);
	}

	/**
	 * Build a DataPacket array (mimics what handlers return after addTo()).
	 */
	private function make_data_packet( string $title = 'Test Event' ): array {
		return array(
			'type'      => 'event_import',
			'timestamp' => time(),
			'data'      => array(
				'title' => $title,
				'body'  => wp_json_encode( array( 'event' => array( 'title' => $title ) ) ),
			),
			'metadata'  => array(
				'source_type'      => 'ticketmaster',
				'event_identifier' => md5( $title ),
				'success'          => true,
			),
		);
	}

	/**
	 * Create a parent job in the DB and store engine data.
	 */
	private function create_parent_job(): int {
		$job_id = $this->jobs_db->create_job( array(
			'pipeline_id' => 1,
			'flow_id'     => 1,
			'source'      => 'pipeline',
			'label'       => 'Test Flow',
		) );

		$this->assertNotFalse( $job_id );
		$this->jobs_db->start_job( (int) $job_id );

		$engine = $this->make_engine_snapshot( (int) $job_id );
		datamachine_set_engine_data( (int) $job_id, $engine );

		return (int) $job_id;
	}

	public function test_fanout_creates_batch_metadata_on_parent(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$packets   = array(
			$this->make_data_packet( 'Event A' ),
			$this->make_data_packet( 'Event B' ),
			$this->make_data_packet( 'Event C' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$result    = $scheduler->fanOut( $parent_id, 'step_abc_123', $packets, $engine );

		$this->assertEquals( $parent_id, $result['parent_job_id'] );
		$this->assertEquals( 3, $result['total'] );
		$this->assertEquals( PipelineBatchScheduler::CHUNK_SIZE, $result['chunk_size'] );

		// Check batch metadata was stored on parent.
		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertTrue( $parent_engine['batch'] );
		$this->assertEquals( 3, $parent_engine['batch_total'] );
		$this->assertEquals( 0, $parent_engine['batch_scheduled'] );
		$this->assertEquals( 'step_abc_123', $parent_engine['next_flow_step_id'] );
	}

	public function test_fanout_stores_transient(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$packets   = array(
			$this->make_data_packet( 'Event A' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', $packets, $engine );

		$transient = get_transient( 'dm_pipeline_batch_' . $parent_id );
		$this->assertNotFalse( $transient );
		$this->assertEquals( $parent_id, $transient['parent_job_id'] );
		$this->assertEquals( 1, $transient['total'] );
		$this->assertCount( 1, $transient['data_packets'] );
	}

	public function test_process_chunk_creates_child_jobs(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$packets   = array(
			$this->make_data_packet( 'Event A' ),
			$this->make_data_packet( 'Event B' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', $packets, $engine );

		// Process the chunk manually (normally AS would call this).
		$scheduler->processChunk( $parent_id );

		// Check child jobs were created.
		global $wpdb;
		$table    = $wpdb->prefix . 'datamachine_jobs';
		$children = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE parent_job_id = %d", $parent_id ),
			ARRAY_A
		);

		$this->assertCount( 2, $children );

		foreach ( $children as $child ) {
			$this->assertEquals( $parent_id, $child['parent_job_id'] );
			$this->assertEquals( 'pipeline', $child['source'] );
			$this->assertEquals( '1', $child['pipeline_id'] );
			$this->assertEquals( '1', $child['flow_id'] );
		}

		// Check parent progress was updated.
		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertEquals( 2, $parent_engine['batch_scheduled'] );
	}

	public function test_process_chunk_respects_cancellation(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$packets   = array(
			$this->make_data_packet( 'Event A' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', $packets, $engine );

		// Set cancellation flag.
		$parent_engine              = datamachine_get_engine_data( $parent_id );
		$parent_engine['cancelled'] = true;
		datamachine_set_engine_data( $parent_id, $parent_engine );

		$scheduler->processChunk( $parent_id );

		// No child jobs should have been created.
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE parent_job_id = %d", $parent_id )
		);

		$this->assertEquals( 0, $count );

		// Parent should be cancelled.
		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertStringContainsString( 'cancelled', $parent_job['status'] );
	}

	public function test_child_labels_use_packet_titles(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$packets   = array(
			$this->make_data_packet( 'Phish at MSG' ),
			$this->make_data_packet( 'Dead & Co Final Tour' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', $packets, $engine );
		$scheduler->processChunk( $parent_id );

		global $wpdb;
		$table  = $wpdb->prefix . 'datamachine_jobs';
		$labels = $wpdb->get_col(
			$wpdb->prepare( "SELECT label FROM {$table} WHERE parent_job_id = %d ORDER BY job_id", $parent_id )
		);

		$this->assertContains( 'Phish at MSG', $labels );
		$this->assertContains( 'Dead & Co Final Tour', $labels );
	}

	public function test_on_child_complete_marks_parent_done_when_all_children_finish(): void {
		$parent_id = $this->create_parent_job();

		// Store batch metadata on parent.
		$parent_engine          = datamachine_get_engine_data( $parent_id );
		$parent_engine['batch'] = true;
		$parent_engine['batch_total'] = 2;
		datamachine_set_engine_data( $parent_id, $parent_engine );

		// Create two child jobs.
		$child_1 = $this->jobs_db->create_job( array(
			'pipeline_id'   => 1,
			'flow_id'       => 1,
			'source'        => 'pipeline',
			'label'         => 'Child 1',
			'parent_job_id' => $parent_id,
		) );
		$this->jobs_db->start_job( (int) $child_1 );

		$child_2 = $this->jobs_db->create_job( array(
			'pipeline_id'   => 1,
			'flow_id'       => 1,
			'source'        => 'pipeline',
			'label'         => 'Child 2',
			'parent_job_id' => $parent_id,
		) );
		$this->jobs_db->start_job( (int) $child_2 );

		// Complete first child.
		$this->jobs_db->complete_job( (int) $child_1, JobStatus::COMPLETED );
		PipelineBatchScheduler::onChildComplete( (int) $child_1, JobStatus::COMPLETED );

		// Parent should still be processing (child 2 not done).
		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertEquals( 'processing', $parent_job['status'] );

		// Complete second child.
		$this->jobs_db->complete_job( (int) $child_2, JobStatus::COMPLETED );
		PipelineBatchScheduler::onChildComplete( (int) $child_2, JobStatus::COMPLETED );

		// Parent should now be completed.
		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertEquals( JobStatus::COMPLETED, $parent_job['status'] );

		// Check batch results in engine data.
		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertEquals( 2, $parent_engine['batch_results']['completed'] );
		$this->assertEquals( 0, $parent_engine['batch_results']['failed'] );
	}

	public function test_on_child_complete_marks_parent_failed_when_all_children_fail(): void {
		$parent_id = $this->create_parent_job();

		$parent_engine                = datamachine_get_engine_data( $parent_id );
		$parent_engine['batch']       = true;
		$parent_engine['batch_total'] = 2;
		datamachine_set_engine_data( $parent_id, $parent_engine );

		$child_1 = $this->jobs_db->create_job( array(
			'pipeline_id'   => 1,
			'flow_id'       => 1,
			'source'        => 'pipeline',
			'label'         => 'Child 1',
			'parent_job_id' => $parent_id,
		) );
		$this->jobs_db->start_job( (int) $child_1 );

		$child_2 = $this->jobs_db->create_job( array(
			'pipeline_id'   => 1,
			'flow_id'       => 1,
			'source'        => 'pipeline',
			'label'         => 'Child 2',
			'parent_job_id' => $parent_id,
		) );
		$this->jobs_db->start_job( (int) $child_2 );

		$fail_status = JobStatus::failed( 'test error' )->toString();

		$this->jobs_db->complete_job( (int) $child_1, $fail_status );
		PipelineBatchScheduler::onChildComplete( (int) $child_1, $fail_status );

		$this->jobs_db->complete_job( (int) $child_2, $fail_status );
		PipelineBatchScheduler::onChildComplete( (int) $child_2, $fail_status );

		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertStringContainsString( 'failed', $parent_job['status'] );
	}

	public function test_on_child_complete_ignores_non_batch_parents(): void {
		// Create a parent job WITHOUT batch metadata.
		$parent_id = $this->create_parent_job();

		$child = $this->jobs_db->create_job( array(
			'pipeline_id'   => 1,
			'flow_id'       => 1,
			'source'        => 'pipeline',
			'label'         => 'Child',
			'parent_job_id' => $parent_id,
		) );
		$this->jobs_db->start_job( (int) $child );
		$this->jobs_db->complete_job( (int) $child, JobStatus::COMPLETED );

		// Should not throw or modify parent.
		PipelineBatchScheduler::onChildComplete( (int) $child, JobStatus::COMPLETED );

		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertEquals( 'processing', $parent_job['status'] );
	}
}
