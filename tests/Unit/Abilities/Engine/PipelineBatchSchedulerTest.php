<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * Tests for PipelineBatchScheduler.
 *
 * @package DataMachine\Tests\Unit\Abilities\Engine
 */

namespace DataMachine\Tests\Unit\Abilities\Engine;

use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Core\ActionScheduler\BatchScheduler;
use DataMachine\Core\ActionScheduler\PathlessBatchRecovery;
use DataMachine\Core\Database\BatchItems\BatchItems;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
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
		datamachine_test_prepare_site();

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

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
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

	public function test_fanout_stores_only_lightweight_batch_state_in_engine_data(): void {
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
		$this->assertSame( 2, (int) $parent_engine['batch_storage_version'] );
		$this->assertEquals( 1, $batch_state['total'] );
		$this->assertEquals( 0, $batch_state['offset'] );
		$this->assertArrayNotHasKey( 'items', $batch_state );
		$this->assertArrayNotHasKey( 'cleanup_contexts', $batch_state );
		$this->assertArrayHasKey( 'extra', $batch_state );
		$this->assertEquals( 'step_abc_123', $batch_state['extra']['next_flow_step_id'] );
		$this->assertArrayNotHasKey( 'engine_snapshot', $batch_state['extra'] );
		$this->assertEquals( PipelineBatchScheduler::BATCH_HOOK, $batch_state['hook'] );

		// next_flow_step_id is also surfaced top-level for legacy consumers.
		$this->assertEquals( 'step_abc_123', $parent_engine['next_flow_step_id'] );

		// No transient should exist.
		$this->assertFalse( get_transient( 'datamachine_pipeline_batch_' . $parent_id ) );
	}

	public function test_realistic_batch_keeps_parent_engine_data_bounded(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$packets   = array();
		for ( $index = 0; $index < 175; $index++ ) {
			$packet                 = $this->make_data_packet( 'Event ' . $index );
			$packet['data']['body'] = str_repeat( 'x', 32768 );
			$packets[]              = $packet;
		}

		$result = ( new PipelineBatchScheduler() )->fanOut( $parent_id, 'step_abc_123', $packets, $engine );

		$this->assertSame( 175, $result['total'] );
		$this->assertLessThan( 32768, strlen( (string) wp_json_encode( datamachine_get_engine_data( $parent_id ) ) ) );
		global $wpdb;
		$this->assertSame( 175, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d', $wpdb->prefix . 'datamachine_batch_items', $parent_id ) ) );
	}

	public function test_finalize_removes_batch_state_from_historical_large_parent_without_retry(): void {
		global $wpdb;
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$engine['historical_payload']    = str_repeat( 'x', 1024 * 1024 );
		$engine['unrelated_key']         = array( 'preserved' => true );
		$engine['batch_storage_version'] = 2;
		$engine['batch_context']         = PipelineBatchScheduler::BATCH_CONTEXT;
		$engine['batch_hook']            = PipelineBatchScheduler::BATCH_HOOK;
		$engine['batch_state']           = array(
			'hook'              => PipelineBatchScheduler::BATCH_HOOK,
			'offset'            => 175,
			'worklist_complete' => true,
		);
		$wpdb->update(
			$this->jobs_db->get_table_name(),
			array( 'engine_data' => wp_json_encode( $engine ) ),
			array( 'job_id' => $parent_id ),
			array( '%s' ),
			array( '%d' )
		);
		wp_cache_delete( $parent_id, 'datamachine_engine_data' );
		$this->assertTrue( $this->jobs_db->complete_job( $parent_id, JobStatus::COMPLETED ) );

		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND args = %s AND status = 'pending'", PipelineBatchScheduler::BATCH_HOOK, wp_json_encode( array( 'parent_job_id' => $parent_id, 'offset' => 175 ) ) ) );
		$this->assertTrue( BatchScheduler::finalize( $parent_id ) );
		$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND args = %s AND status = 'pending'", PipelineBatchScheduler::BATCH_HOOK, wp_json_encode( array( 'parent_job_id' => $parent_id, 'offset' => 175 ) ) ) );

		$final = datamachine_get_engine_data( $parent_id );
		$this->assertArrayNotHasKey( 'batch_state', $final );
		$this->assertSame( array( 'preserved' => true ), $final['unrelated_key'] );
		$this->assertSame( 1024 * 1024, strlen( $final['historical_payload'] ) );
		$this->assertSame( $before, $after );
		$this->assertSame( JobStatus::COMPLETED, $this->jobs_db->get_job( $parent_id )['status'] );
	}

	public function test_exact_retry_rejects_changed_batch_consumer_contract(): void {
		$parent_id = $this->create_parent_job();
		$items     = array( $this->make_data_packet( 'Event A' ) );
		$first     = BatchScheduler::start( $parent_id, 'first_hook', $items, array( 'route' => 'first' ), 'pipeline', BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE );
		$retry     = BatchScheduler::start( $parent_id, 'second_hook', $items, array( 'route' => 'second' ), 'pipeline', BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE );

		$this->assertTrue( $first['scheduled'] );
		$this->assertFalse( $retry['scheduled'] );
		$state = datamachine_get_engine_data( $parent_id )['batch_state'];
		$this->assertSame( 'first_hook', $state['hook'] );
		$this->assertSame( 'first', $state['extra']['route'] );
	}

	public function test_exact_retry_adopts_one_existing_chunk_path(): void {
		global $wpdb;
		$parent_id = $this->create_parent_job();
		$items     = array( $this->make_data_packet( 'Event A' ) );

		$first = BatchScheduler::start( $parent_id, PipelineBatchScheduler::BATCH_HOOK, $items, array( 'next_flow_step_id' => 'step_a' ), 'pipeline', BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE );
		$retry = BatchScheduler::start( $parent_id, PipelineBatchScheduler::BATCH_HOOK, $items, array( 'next_flow_step_id' => 'step_a' ), 'pipeline', BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE );

		$this->assertTrue( $first['scheduled'] );
		$this->assertTrue( $retry['scheduled'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND args = %s AND status = 'pending'", PipelineBatchScheduler::BATCH_HOOK, wp_json_encode( array( 'parent_job_id' => $parent_id, 'offset' => 0 ) ) ) ) );
	}

	public function test_duplicate_completed_chunk_does_not_perpetuate_recovery_actions(): void {
		global $wpdb;
		$parent_id = $this->create_parent_job();
		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_a', array( $this->make_data_packet( 'Event A' ) ), $this->make_engine_snapshot( $parent_id ) );
		$scheduler->processChunk( $parent_id, 0 );
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND args = %s AND status = 'pending'", PipelineBatchScheduler::BATCH_HOOK, wp_json_encode( array( 'parent_job_id' => $parent_id, 'offset' => 0 ) ) ) );

		$scheduler->processChunk( $parent_id, 0 );

		$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND args = %s AND status = 'pending'", PipelineBatchScheduler::BATCH_HOOK, wp_json_encode( array( 'parent_job_id' => $parent_id, 'offset' => 0 ) ) ) );
		$this->assertSame( $before, $after );
	}

	public function test_pathless_batch_recovery_is_idempotent_without_scheduler_scan(): void {
		global $wpdb;
		$parent_id = $this->create_parent_job();
		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_a', array( $this->make_data_packet( 'Event A' ) ), $this->make_engine_snapshot( $parent_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND args = %s", PipelineBatchScheduler::BATCH_HOOK, wp_json_encode( array( 'parent_job_id' => $parent_id, 'offset' => 0 ) ) ) );

		$this->assertTrue( PathlessBatchRecovery::recover( $parent_id ) );
		$this->assertFalse( PathlessBatchRecovery::recover( $parent_id ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND args = %s AND status = 'pending'", PipelineBatchScheduler::BATCH_HOOK, wp_json_encode( array( 'parent_job_id' => $parent_id, 'offset' => 0 ) ) ) ) );
	}

	public function test_pathless_batch_recovery_preserves_nested_and_serialized_action_args(): void {
		$extract = new \ReflectionMethod( PathlessBatchRecovery::class, 'extractParentJobId' );

		$this->assertSame( 100, $extract->invoke( null, wp_json_encode( array( 'parent_job_id' => 100, 'offset' => 0 ) ) ) );
		$this->assertSame( 123, $extract->invoke( null, wp_json_encode( array( array( 'parent_job_id' => 123 ) ) ) ) );
		$this->assertSame( 321, $extract->invoke( null, serialize( array( 'parent_job_id' => 321, 'offset' => 0 ) ) ) );
		$this->assertSame( 456, $extract->invoke( null, serialize( array( array( 'parent_job_id' => 456 ) ) ) ) );
	}

	/**
	 * Regression for #2762: a concurrent RunMetrics write must not clobber
	 * batch_state written by fan-out.
	 *
	 * Reproduces the lost-update race where the Ticketmaster fan-out flow
	 * fails children with batch_state_missing: fan-out writes batch_state, but
	 * a RunMetrics persist that started from a pre-fan-out baseline overwrites
	 * the whole engine_data column with a snapshot that has no batch key. The
	 * stale object-cache entry below stands in for that baseline. With the
	 * compare-and-swap path, RunMetrics re-reads the live snapshot and the
	 * batch key survives.
	 */
	public function test_runmetrics_write_does_not_clobber_batch_state(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );

		// Snapshot the parent BEFORE fan-out — this is the stale baseline a
		// concurrent RunMetrics writer would have read.
		$stale_baseline = datamachine_get_engine_data( $parent_id );
		$this->assertArrayNotHasKey( 'batch', $stale_baseline );

		$packets = array(
			$this->make_data_packet( 'Event A' ),
			$this->make_data_packet( 'Event B' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', $packets, $engine );

		// Parent now carries the batch state on disk.
		$after_fanout = datamachine_get_engine_data( $parent_id );
		$this->assertTrue( $after_fanout['batch'] );
		$this->assertArrayHasKey( 'batch_state', $after_fanout );

		// Re-prime the object cache with the stale pre-fan-out baseline to
		// simulate a concurrent runner that read engine_data before fan-out
		// committed. A blind read-modify-write persist would push this stale
		// snapshot back and drop batch / batch_state.
		wp_cache_set( $parent_id, $stale_baseline, 'datamachine_engine_data' );

		\DataMachine\Core\RunMetrics::increment( $parent_id, 'processed' );

		$preserved = datamachine_get_engine_data( $parent_id );
		$this->assertTrue( $preserved['batch'] ?? false, 'batch flag survives concurrent RunMetrics write' );
		$this->assertArrayHasKey( 'batch_state', $preserved, 'batch_state survives concurrent RunMetrics write' );
		$this->assertSame( 2, (int) $preserved['batch_state']['total'] );
		$this->assertSame( 1, (int) $preserved['run_metrics']['counts']['processed'] );

		// And the chunk that runs next still finds its state — no
		// batch_state_missing failure.
		$scheduler->processChunk( $parent_id );
		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertStringNotContainsString( 'batch_state_missing', (string) $parent_job['status'] );
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

	public function test_process_chunk_propagates_claim_ownership_to_children(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$claim     = array(
			'identity_scope'  => 'shared:source',
			'source_type'     => 'source',
			'item_identifier' => 'item-1',
			'ownership_token' => 'opaque-token',
			'completion'      => array(),
		);
		$claim['disposition_id'] = ProcessedItems::disposition_identity( $claim['identity_scope'], $claim['source_type'], $claim['item_identifier'] );
		$sibling = array(
			'identity_scope'  => 'shared:source',
			'source_type'     => 'source',
			'item_identifier' => 'sibling-item',
			'ownership_token' => 'sibling-token',
		);
		$packet = $this->make_data_packet( 'Claimed Event' );
		$packet['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ] = $claim;
		$packet['metadata']['_engine_data'][ ProcessedItems::CLAIMS_METADATA_KEY ] = array( $sibling );

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', array( $packet ), $engine );
		$scheduler->processChunk( $parent_id );

		global $wpdb;
		$child_id    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT job_id FROM %i WHERE parent_job_id = %d', $wpdb->prefix . 'datamachine_jobs', $parent_id ) );
		$child_engine = datamachine_get_engine_data( $child_id );
		$this->assertSame( $claim, $child_engine[ ProcessedItems::CLAIM_METADATA_KEY ] );
		$this->assertArrayNotHasKey( ProcessedItems::CLAIMS_METADATA_KEY, $child_engine );
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

		// Parent should terminalize through the generic cancellation status.
		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertSame( JobStatus::CANCELLED, $parent_job['status'] );
	}

	public function test_duplicate_chunk_delivery_for_same_offset_does_not_create_duplicate_children(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );
		$packets   = array(
			$this->make_data_packet( 'Event A' ),
			$this->make_data_packet( 'Event B' ),
		);

		$scheduler = new PipelineBatchScheduler();
		$scheduler->fanOut( $parent_id, 'step_abc_123', $packets, $engine );

		$scheduler->processChunk( $parent_id, 0 );
		$scheduler->processChunk( $parent_id, 0 );

		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE parent_job_id = %d", $parent_id )
		);

		$this->assertSame( 2, $count );

		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertSame( 2, (int) $parent_engine['batch_scheduled'] );
		$this->assertTrue( $parent_engine['batch_state']['worklist_complete'] );
	}

	public function test_process_chunk_marks_parent_failed_when_batch_state_is_missing(): void {
		$parent_id = $this->create_parent_job();

		$scheduler = new PipelineBatchScheduler();
		$scheduler->processChunk( $parent_id );

		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertStringContainsString( 'failed', $parent_job['status'] );
		$this->assertStringContainsString( 'batch_state_missing', $parent_job['status'] );
	}

	public function test_v2_missing_state_fails_parent_and_cleans_orphan_worklist(): void {
		$parent_id = $this->create_parent_job();
		$engine    = datamachine_get_engine_data( $parent_id );
		$engine['batch']                 = true;
		$engine['batch_storage_version'] = 2;
		$engine['batch_total']           = 1;
		$engine['batch_context']         = PipelineBatchScheduler::BATCH_CONTEXT;
		$engine['batch_hook']            = PipelineBatchScheduler::BATCH_HOOK;
		$this->assertTrue( datamachine_set_engine_data( $parent_id, $engine ) );
		$worklist = new BatchItems();
		$this->assertTrue( $worklist->insert_batch( $parent_id, array( $this->make_data_packet( 'Orphan' ) ), array( array() ) )['success'] );

		( new PipelineBatchScheduler() )->processChunk( $parent_id, 0 );

		$this->assertStringContainsString( 'batch_state_missing', $this->jobs_db->get_job( $parent_id )['status'] );
		$this->assertNull( $worklist->first_outstanding_index( $parent_id ) );
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

	public function test_parent_engine_write_failure_keeps_child_core_stage_retryable(): void {
		[ $parent_id, $child_id ] = $this->create_terminal_ready_batch();
		$failure                  = static fn() => static fn(): bool => false;

		add_filter( 'datamachine_pipeline_batch_parent_engine_persister', $failure );
		try {
			$this->assertTrue( $this->jobs_db->complete_job( $child_id, JobStatus::COMPLETED ) );
		} finally {
			remove_filter( 'datamachine_pipeline_batch_parent_engine_persister', $failure );
		}

		$this->assertSame( JobStatus::PROCESSING, $this->jobs_db->get_job( $parent_id )['status'] );
		$this->assertSame( 1, (int) $this->jobs_db->get_job( $child_id )['terminal_accounting_state'] );

		$replayed = ( new Jobs() )->reconcile_terminal_accounting( $child_id );
		$this->assertTrue( $replayed['complete'] );
		$this->assertSame( JobStatus::COMPLETED, $this->jobs_db->get_job( $parent_id )['status'] );
	}

	public function test_parent_completion_failure_keeps_child_core_stage_retryable(): void {
		[ $parent_id, $child_id ] = $this->create_terminal_ready_batch();
		$failure                  = static fn() => static fn(): bool => false;

		add_filter( 'datamachine_pipeline_batch_parent_completer', $failure );
		try {
			$this->assertTrue( $this->jobs_db->complete_job( $child_id, JobStatus::COMPLETED ) );
		} finally {
			remove_filter( 'datamachine_pipeline_batch_parent_completer', $failure );
		}

		$this->assertSame( JobStatus::PROCESSING, $this->jobs_db->get_job( $parent_id )['status'] );
		$this->assertSame( 1, (int) $this->jobs_db->get_job( $child_id )['terminal_accounting_state'] );

		$replayed = ( new Jobs() )->reconcile_terminal_accounting( $child_id );
		$this->assertTrue( $replayed['complete'] );
		$this->assertSame( JobStatus::COMPLETED, $this->jobs_db->get_job( $parent_id )['status'] );
	}

	public function test_replay_after_durable_parent_completion_is_idempotent(): void {
		[ $parent_id, $child_id ] = $this->create_terminal_ready_batch();
		$interrupted              = false;
		$interrupt                = static function ( bool $should_interrupt, string $boundary, int $job_id ) use ( $child_id, &$interrupted ): bool {
			if ( ! $interrupted && $child_id === $job_id && 'after_operation:core_callbacks' === $boundary ) {
				$interrupted = true;
				return true;
			}
			return $should_interrupt;
		};

		add_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10, 3 );
		try {
			$this->jobs_db->complete_job( $child_id, JobStatus::COMPLETED );
			$this->fail( 'Expected interruption after durable parent completion.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Terminal accounting interrupted at after_operation:core_callbacks', $exception->getMessage() );
		} finally {
			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10 );
		}

		$parent = $this->jobs_db->get_job( $parent_id );
		$this->assertSame( JobStatus::COMPLETED, $parent['status'] );
		$completed_at = $parent['completed_at'];
		$this->expire_terminal_accounting_lease( $child_id );

		$replayed = ( new Jobs() )->reconcile_terminal_accounting( $child_id );
		$this->assertTrue( $replayed['complete'] );
		$this->assertSame( $completed_at, $this->jobs_db->get_job( $parent_id )['completed_at'] );
	}

	public function test_final_chunk_completes_parent_when_children_already_terminal(): void {
		$parent_id = $this->create_parent_job();
		$engine    = $this->make_engine_snapshot( $parent_id );

		datamachine_merge_engine_data( $parent_id, array(
			'batch'             => true,
			'batch_total'       => 1,
			'batch_scheduled'   => 1,
			'batch_chunk_size'  => \DataMachine\Core\ActionScheduler\BatchScheduler::DEFAULT_CHUNK_SIZE,
			'batch_context'     => PipelineBatchScheduler::BATCH_CONTEXT,
			'next_flow_step_id' => 'step_done',
			'started_at'        => current_time( 'mysql' ),
			'batch_state'       => array(
				'offset' => 1,
				'total'  => 1,
				'items'  => array(),
				'extra'  => array(
					'next_flow_step_id' => 'step_done',
					'engine_snapshot'   => $engine,
				),
				'hook'   => PipelineBatchScheduler::BATCH_HOOK,
			),
		) );

		$child = $this->jobs_db->create_job( array(
			'pipeline_id'   => $this->test_pipeline_id,
			'flow_id'       => $this->test_flow_id,
			'source'        => 'pipeline',
			'label'         => 'Already terminal child',
			'parent_job_id' => $parent_id,
		) );
		$this->jobs_db->start_job( (int) $child );
		$this->jobs_db->complete_job( (int) $child, JobStatus::COMPLETED_NO_ITEMS );

		$scheduler = new PipelineBatchScheduler();
		$scheduler->processChunk( $parent_id );

		$parent_job = $this->jobs_db->get_job( $parent_id );
		$this->assertEquals( JobStatus::COMPLETED_NO_ITEMS, $parent_job['status'] );

		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertEquals( 1, $parent_engine['batch_results']['skipped'] );
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

	public function test_batch_state_remains_replayable_until_parent_terminalizes(): void {
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

		// Worklist completion remains replayable until the child terminalizes.
		$parent_engine = datamachine_get_engine_data( $parent_id );
		$this->assertTrue( $parent_engine['batch_state']['worklist_complete'] );

		// Batch metadata remains available to onChildComplete.
		$this->assertTrue( $parent_engine['batch'] );
		$this->assertEquals( 1, $parent_engine['batch_total'] );

		global $wpdb;
		$child_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT job_id FROM %i WHERE parent_job_id = %d LIMIT 1', $this->jobs_db->get_table_name(), $parent_id )
		);
		$this->jobs_db->complete_job( $child_id, JobStatus::COMPLETED );
		PipelineBatchScheduler::onChildComplete( $child_id, JobStatus::COMPLETED );
		$this->assertArrayNotHasKey( 'batch_state', datamachine_get_engine_data( $parent_id ) );
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

	/** @return array{int,int} */
	private function create_terminal_ready_batch(): array {
		$parent_id = $this->create_parent_job();
		$engine    = datamachine_get_engine_data( $parent_id );
		$engine['batch']           = true;
		$engine['batch_total']     = 1;
		$engine['batch_scheduled'] = 1;
		$engine['batch_context']   = PipelineBatchScheduler::BATCH_CONTEXT;
		$this->assertTrue( datamachine_set_engine_data( $parent_id, $engine ) );

		$child_id = $this->jobs_db->create_job(
			array(
				'pipeline_id'   => $this->test_pipeline_id,
				'flow_id'       => $this->test_flow_id,
				'source'        => 'pipeline',
				'label'         => 'Terminal accounting child',
				'parent_job_id' => $parent_id,
			)
		);
		$this->assertIsInt( $child_id );
		$this->assertTrue( $this->jobs_db->start_job( $child_id ) );

		return array( $parent_id, $child_id );
	}

	private function expire_terminal_accounting_lease( int $job_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->jobs_db->get_table_name(),
			array( 'terminal_accounting_claimed_at' => '2000-01-01 00:00:00' ),
			array( 'job_id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
