<?php
/**
 * PostTracking Tests
 *
 * Covers the post-tracking write path (PostTracking::store) and the
 * pipeline_id resolver / fallback introduced by #1091.
 *
 * @package DataMachine\Tests\Unit\WordPress
 */

namespace DataMachine\Tests\Unit\WordPress;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Jobs\JobsOperations;
use DataMachine\Core\WordPress\PostTracking;
use WP_UnitTestCase;

class PostTrackingTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function test_store_writes_handler_and_flow_id_only(): void {
		$pipeline_id = 123;
		$flow_id     = $this->create_flow( $pipeline_id );
		$job_id      = $this->create_job( $flow_id, $pipeline_id );

		$post_id = self::factory()->post->create();

		PostTracking::store(
			$post_id,
			array( 'handler' => 'rss' ),
			$job_id
		);

		$this->assertSame( 'rss', get_post_meta( $post_id, PostTracking::HANDLER_META_KEY, true ) );
		$this->assertSame( (string) $flow_id, get_post_meta( $post_id, PostTracking::FLOW_ID_META_KEY, true ) );
		// pipeline_id is no longer stored on posts (#1091) — derivable from flow_id.
		$this->assertSame( '', get_post_meta( $post_id, '_datamachine_post_pipeline_id', true ) );
	}

	public function test_store_skips_invalid_post_id(): void {
		PostTracking::store( 0, array( 'handler' => 'rss' ), 0 );
		PostTracking::store( -1, array( 'handler' => 'rss' ), 0 );
		// No exception, no fatal — nothing to assert beyond the absence of error.
		$this->assertTrue( true );
	}

	public function test_get_pipeline_id_for_post_resolves_via_flow_row(): void {
		$pipeline_id = 555;
		$flow_id     = $this->create_flow( $pipeline_id );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, PostTracking::FLOW_ID_META_KEY, $flow_id );

		$this->assertSame( $pipeline_id, PostTracking::getPipelineIdForPost( $post_id ) );
	}

	public function test_get_pipeline_id_for_post_returns_zero_when_flow_row_missing(): void {
		// Post has a flow_id meta pointing at a flow that does not exist
		// (either never created or since deleted). The resolver returns 0
		// rather than consulting redundant cached meta.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, PostTracking::FLOW_ID_META_KEY, 987654321 );

		$this->assertSame( 0, PostTracking::getPipelineIdForPost( $post_id ) );
	}

	public function test_get_pipeline_id_for_post_returns_zero_for_untracked_post(): void {
		$post_id = self::factory()->post->create();
		$this->assertSame( 0, PostTracking::getPipelineIdForPost( $post_id ) );
	}

	public function test_get_pipeline_id_for_post_returns_zero_for_invalid_post_id(): void {
		$this->assertSame( 0, PostTracking::getPipelineIdForPost( 0 ) );
		$this->assertSame( 0, PostTracking::getPipelineIdForPost( -5 ) );
	}

	public function test_get_flow_ids_for_pipeline_returns_flow_ids(): void {
		$pipeline_id = 222;
		$a           = $this->create_flow( $pipeline_id, 'Flow A' );
		$b           = $this->create_flow( $pipeline_id, 'Flow B' );
		// A flow bound to a different pipeline should not appear.
		$this->create_flow( 333, 'Other Pipeline Flow' );

		$result = PostTracking::getFlowIdsForPipeline( $pipeline_id );

		$this->assertContains( $a, $result );
		$this->assertContains( $b, $result );
		$this->assertCount( 2, $result );
	}

	public function test_get_flow_ids_for_pipeline_empty_when_no_flows(): void {
		$this->assertSame( array(), PostTracking::getFlowIdsForPipeline( 999999 ) );
		$this->assertSame( array(), PostTracking::getFlowIdsForPipeline( 0 ) );
		$this->assertSame( array(), PostTracking::getFlowIdsForPipeline( -1 ) );
	}

	public function test_extract_post_id_from_various_result_shapes(): void {
		$this->assertSame( 42, PostTracking::extractPostId( array( 'data' => array( 'post_id' => 42 ) ) ) );
		$this->assertSame( 99, PostTracking::extractPostId( array( 'post_id' => 99 ) ) );
		$this->assertSame( 0, PostTracking::extractPostId( array() ) );
		$this->assertSame( 0, PostTracking::extractPostId( array( 'data' => array( 'post_id' => 0 ) ) ) );
	}

	private function create_flow( int $pipeline_id, string $flow_name = 'Test Flow' ): int {
		$flows_db = new Flows();
		$flow_id  = $flows_db->create_flow(
			array(
				'pipeline_id'       => $pipeline_id,
				'user_id'           => get_current_user_id(),
				'flow_name'         => $flow_name,
				'flow_config'       => array(),
				'scheduling_config' => array(),
			)
		);
		$this->assertIsInt( $flow_id );
		$this->assertGreaterThan( 0, $flow_id );
		return (int) $flow_id;
	}

	private function create_job( int $flow_id, int $pipeline_id ): int {
		$jobs_db = new Jobs();
		$ops     = new JobsOperations( $jobs_db );
		$job_id  = $ops->create_job(
			array(
				'flow_id'     => $flow_id,
				'pipeline_id' => $pipeline_id,
				'user_id'     => get_current_user_id(),
				'status'      => 'pending',
			)
		);
		$this->assertIsInt( $job_id );
		$this->assertGreaterThan( 0, $job_id );
		return (int) $job_id;
	}
}
