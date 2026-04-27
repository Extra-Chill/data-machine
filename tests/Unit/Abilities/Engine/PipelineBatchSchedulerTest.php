<?php
/**
 * Tests for PipelineBatchScheduler.
 *
 * @package DataMachine\Tests\Unit\Abilities\Engine
 */

namespace DataMachine\Tests\Unit\Abilities\Engine;

use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use WP_UnitTestCase;

class PipelineBatchSchedulerTest extends WP_UnitTestCase {

	private Jobs $jobs_db;
	private int $test_pipeline_id;
	private int $test_flow_id;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Ensure Data Machine tables exist (activation hook doesn't fire in tests).
		if ( function_exists( 'datamachine_activate_for_site' ) ) {
			datamachine_activate_for_site();
		}
	}

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->jobs_db = new Jobs();

		// Create real pipeline + flow so engine lookups don't fail.
		$pipeline_ability = wp_get_ability( 'datamachine/create-pipeline' );
		$pipeline         = $pipeline_ability->execute( array( 'pipeline_name' => 'Batch Test Pipeline' ) );
		$this->test_pipeline_id = $pipeline['pipeline_id'];

		$flow_ability = wp_get_ability( 'datamachine/create-flow' );
		$flow         = $flow_ability->execute( array(
			'pipeline_id' => $this->test_pipeline_id,
			'flow_name'   => 'Batch Test Flow',
		) );
		$this->test_flow_id = $flow['flow_id'];
	}

	/**
	 * Build a minimal engine snapshot for testing.
	 */
	private function make_engine_snapshot( int $job_id, ?int $flow_id = null, ?int $pipeline_id = null ): array {
		$flow_id     = $flow_id ?? $this->test_flow_id;
		$pipeline_id = $pipeline_id ?? $this->test_pipeline_id;
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
			'pipeline_id' => $this->test_pipeline_id,
			'flow_id'     => $this->test_flow_id,
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
		$this->assertEquals( \DataMachine\Core\ActionScheduler\BatchScheduler::DEFAULT_CHUNK_SIZE, $result['chunk_size'] );

		// Check batch metadata was stored on parent.
		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertTrue( $parent_engine['batch'] );
		$this->assertEquals( 3, $parent_engine['batch_total'] );
		$this->assertEquals( 0, $parent_engine['batch_scheduled'] );
		$this->assertEquals( 'step_abc_123', $parent_engine['next_flow_step_id'] );
	}

	public function test_fanout_stores_batch_state_in_engine_data(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$packets   = array(
			$this->make_data_packet( 'Event A' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', $packets, $engine );

		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertArrayHasKey( 'batch_state', $parent_engine );

		$batch_state = $parent_engine['batch_state'];
		$this->assertEquals( 1, $batch_state['total'] );
		$this->assertEquals( 0, $batch_state['offset'] );
		$this->assertCount( 1, $batch_state['items'] );
		$this->assertArrayHasKey( 'extra', $batch_state );
		$this->assertEquals( 'step_abc_123', $batch_state['extra']['next_flow_step_id'] );
		$this->assertArrayHasKey( 'engine_snapshot', $batch_state['extra'] );
		$this->assertEquals( PipelineBatchScheduler::BATCH_HOOK, $batch_state['hook'] );

		// next_flow_step_id is also surfaced top-level for legacy consumers.
		$this->assertEquals( 'step_abc_123', $parent_engine['next_flow_step_id'] );

		// No transient should exist.
		$this->assertFalse( get_transient( 'dm_pipeline_batch_' . $parent_id ) );
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
			$this->assertEquals( (string) $this->test_pipeline_id, $child['pipeline_id'] );
			$this->assertEquals( (string) $this->test_flow_id, $child['flow_id'] );
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

		// Parent should be failed with cancellation reason.
		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertStringContainsString( 'failed', $parent_job['status'] );
		$this->assertStringContainsString( 'batch cancelled', $parent_job['status'] );
	}

	public function test_process_chunk_marks_parent_failed_when_batch_state_is_missing(): void {
		$parent_id = $this->create_parent_job();

		$scheduler = new PipelineBatchScheduler();
		$scheduler->processChunk( $parent_id );

		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertStringContainsString( 'failed', $parent_job['status'] );
		$this->assertStringContainsString( 'batch_state_missing', $parent_job['status'] );
	}

	public function test_process_chunk_marks_parent_failed_when_zero_children_scheduled(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );

		datamachine_merge_engine_data( $parent_id, array(
			'batch'             => true,
			'batch_total'       => 0,
			'batch_scheduled'   => 0,
			'batch_chunk_size'  => \DataMachine\Core\ActionScheduler\BatchScheduler::DEFAULT_CHUNK_SIZE,
			'batch_context'     => PipelineBatchScheduler::BATCH_CONTEXT,
			'next_flow_step_id' => 'step_empty',
			'started_at'        => current_time( 'mysql' ),
			'batch_state'       => array(
				'offset' => 0,
				'total'  => 0,
				'items'  => array(),
				'extra'  => array(
					'next_flow_step_id' => 'step_empty',
					'engine_snapshot'   => $engine,
				),
				'hook'   => PipelineBatchScheduler::BATCH_HOOK,
			),
		) );

		$scheduler = new PipelineBatchScheduler();
		$scheduler->processChunk( $parent_id );

		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertStringContainsString( 'failed', $parent_job['status'] );
		$this->assertStringContainsString( 'batch_no_children_scheduled', $parent_job['status'] );
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

	public function test_child_jobs_receive_per_item_engine_data(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );

		// Two packets with different _engine_data (per-item venue context).
		$packet_a             = $this->make_data_packet( 'Show at Venue A' );
		$packet_a['metadata']['_engine_data'] = array(
			'venue'        => 'The Continental Club',
			'venueCity'    => 'Austin',
			'venueState'   => 'TX',
			'venue_context' => array( 'name' => 'The Continental Club', 'city' => 'Austin' ),
		);

		$packet_b             = $this->make_data_packet( 'Show at Venue B' );
		$packet_b['metadata']['_engine_data'] = array(
			'venue'        => 'Hotel Vegas',
			'venueCity'    => 'Austin',
			'venueState'   => 'TX',
			'venue_context' => array( 'name' => 'Hotel Vegas', 'city' => 'Austin' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', array( $packet_a, $packet_b ), $engine );
		$scheduler->processChunk( $parent_id );

		// Get child jobs.
		global $wpdb;
		$table    = $wpdb->prefix . 'datamachine_jobs';
		$children = $wpdb->get_results(
			$wpdb->prepare( "SELECT job_id, label FROM {$table} WHERE parent_job_id = %d ORDER BY job_id", $parent_id ),
			ARRAY_A
		);

		$this->assertCount( 2, $children );

		// Child A should have Venue A's engine data.
		$child_a_engine = datamachine_get_engine_data( (int) $children[0]['job_id'] );
		$this->assertEquals( 'The Continental Club', $child_a_engine['venue'] );
		$this->assertEquals( 'Austin', $child_a_engine['venueCity'] );
		$this->assertEquals( 'The Continental Club', $child_a_engine['venue_context']['name'] );

		// Child B should have Venue B's engine data.
		$child_b_engine = datamachine_get_engine_data( (int) $children[1]['job_id'] );
		$this->assertEquals( 'Hotel Vegas', $child_b_engine['venue'] );
		$this->assertEquals( 'Austin', $child_b_engine['venueCity'] );
		$this->assertEquals( 'Hotel Vegas', $child_b_engine['venue_context']['name'] );
	}

	public function test_packet_engine_data_cannot_clobber_reserved_child_context(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );

		$engine['job']['agent_id'] = 123;
		$engine['job']['user_id']  = 456;
		$engine['flow_config']     = array( 'original' => 'flow-config' );
		$engine['pipeline_config'] = array( 'original' => 'pipeline-config' );

		$packet = $this->make_data_packet( 'Reserved Context Attempt' );
		$packet['metadata']['_engine_data'] = array(
			'job'             => array( 'agent_id' => 999 ),
			'flow_config'     => array(),
			'pipeline_config' => array(),
			'batch_total'     => 999,
			'item_identifier' => 'safe-id',
			'venue'           => 'Safe Venue',
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', array( $packet ), $engine );
		$scheduler->processChunk( $parent_id );

		global $wpdb;
		$table    = $wpdb->prefix . 'datamachine_jobs';
		$children = $wpdb->get_results(
			$wpdb->prepare( "SELECT job_id FROM {$table} WHERE parent_job_id = %d", $parent_id ),
			ARRAY_A
		);

		$this->assertCount( 1, $children );

		$child_engine = datamachine_get_engine_data( (int) $children[0]['job_id'] );

		$this->assertEquals( 123, $child_engine['job']['agent_id'] );
		$this->assertEquals( 456, $child_engine['job']['user_id'] );
		$this->assertEquals( array( 'original' => 'flow-config' ), $child_engine['flow_config'] );
		$this->assertEquals( array( 'original' => 'pipeline-config' ), $child_engine['pipeline_config'] );
		$this->assertArrayNotHasKey( 'batch_total', $child_engine );
		$this->assertEquals( 'safe-id', $child_engine['item_identifier'] );
		$this->assertEquals( 'Safe Venue', $child_engine['venue'] );
	}

	public function test_child_jobs_work_without_engine_data_key(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );

		// Packet without _engine_data — should still work fine.
		$packet = $this->make_data_packet( 'Basic Event' );

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', array( $packet ), $engine );
		$scheduler->processChunk( $parent_id );

		global $wpdb;
		$table    = $wpdb->prefix . 'datamachine_jobs';
		$children = $wpdb->get_results(
			$wpdb->prepare( "SELECT job_id FROM {$table} WHERE parent_job_id = %d", $parent_id ),
			ARRAY_A
		);

		$this->assertCount( 1, $children );

		// Child should have the parent snapshot's keys but no extra seeded data.
		$child_engine = datamachine_get_engine_data( (int) $children[0]['job_id'] );
		$this->assertArrayHasKey( 'job', $child_engine );
		$this->assertArrayHasKey( 'flow', $child_engine );
		$this->assertArrayNotHasKey( 'venue', $child_engine );
	}

	public function test_batch_state_cleaned_up_after_all_chunks_processed(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$packets   = array(
			$this->make_data_packet( 'Event A' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', $packets, $engine );

		// Verify batch_state exists before processing.
		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertArrayHasKey( 'batch_state', $parent_engine );

		$scheduler->processChunk( $parent_id );

		// After all chunks processed, batch_state should be removed.
		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertArrayNotHasKey( 'batch_state', $parent_engine );

		// But batch metadata should still exist for onChildComplete.
		$this->assertTrue( $parent_engine['batch'] );
		$this->assertEquals( 1, $parent_engine['batch_total'] );
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
