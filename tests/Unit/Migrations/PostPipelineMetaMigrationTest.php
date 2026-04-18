<?php
/**
 * Post pipeline meta migration tests (#1091).
 *
 * @package DataMachine\Tests\Unit\Migrations
 */

namespace DataMachine\Tests\Unit\Migrations;

use WP_UnitTestCase;

class PostPipelineMetaMigrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'datamachine_post_pipeline_meta_dropped' );
	}

	public function test_migration_deletes_legacy_pipeline_id_rows(): void {
		$post_a = self::factory()->post->create();
		$post_b = self::factory()->post->create();
		$post_c = self::factory()->post->create();

		update_post_meta( $post_a, '_datamachine_post_pipeline_id', 100 );
		update_post_meta( $post_b, '_datamachine_post_pipeline_id', 200 );
		update_post_meta( $post_c, '_datamachine_post_handler', 'rss' );
		update_post_meta( $post_c, '_datamachine_post_flow_id', 5 );

		datamachine_drop_redundant_post_pipeline_meta();

		$this->assertSame( '', get_post_meta( $post_a, '_datamachine_post_pipeline_id', true ) );
		$this->assertSame( '', get_post_meta( $post_b, '_datamachine_post_pipeline_id', true ) );
		// Non-pipeline meta untouched on post C.
		$this->assertSame( 'rss', get_post_meta( $post_c, '_datamachine_post_handler', true ) );
		$this->assertSame( '5', get_post_meta( $post_c, '_datamachine_post_flow_id', true ) );
	}

	public function test_migration_sets_completion_flag(): void {
		$this->assertFalse( get_option( 'datamachine_post_pipeline_meta_dropped', false ) );

		datamachine_drop_redundant_post_pipeline_meta();

		$this->assertTrue( (bool) get_option( 'datamachine_post_pipeline_meta_dropped' ) );
	}

	public function test_migration_is_idempotent_after_completion(): void {
		datamachine_drop_redundant_post_pipeline_meta();

		// Subsequent rows should NOT be touched because the flag short-circuits
		// the migration — protecting any intentional writes after completion.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_datamachine_post_pipeline_id', 42 );

		datamachine_drop_redundant_post_pipeline_meta();

		$this->assertSame( '42', get_post_meta( $post_id, '_datamachine_post_pipeline_id', true ) );
	}

	public function test_migration_no_op_when_no_legacy_rows_exist(): void {
		// Clean site with no DM history — migration should still set the flag
		// and do nothing else.
		datamachine_drop_redundant_post_pipeline_meta();

		$this->assertTrue( (bool) get_option( 'datamachine_post_pipeline_meta_dropped' ) );
	}
}
