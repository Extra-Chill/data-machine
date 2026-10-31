<?php
/**
 * UpdateWordPressAbility tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Update\UpdateWordPressAbility;
use WP_UnitTestCase;

class UpdateWordPressAbilityTest extends WP_UnitTestCase {

	public function test_missing_source_url_returns_bad_request_error(): void {
		$result = ( new UpdateWordPressAbility() )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_update_source_url_required', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'logs', $result->get_error_data() );
	}

	public function test_invalid_source_url_returns_bad_request_error(): void {
		$result = ( new UpdateWordPressAbility() )->execute(
			array( 'source_url' => 'not-a-wordpress-url' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_update_source_url_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'not-a-wordpress-url', $result->get_error_data()['source_url'] );
	}

	public function test_missing_post_returns_not_found_error(): void {
		$result = ( new UpdateWordPressAbility() )->execute(
			array( 'source_url' => home_url( '/?p=999999999' ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_update_post_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( 999999999, $result->get_error_data()['post_id'] );
	}

	public function test_wp_update_post_failure_returns_stable_error_with_core_error_data(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Original title',
				'post_content' => 'Body',
			)
		);
		$fail    = static fn() => true;

		add_filter( 'wp_insert_post_empty_content', $fail );
		try {
			$result = ( new UpdateWordPressAbility() )->execute(
				array(
					'source_url' => get_permalink( $post_id ),
					'title'      => 'Changed title',
				)
			);
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $fail );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_post_update_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( $post_id, $result->get_error_data()['post_id'] );
		$this->assertSame( 'empty_content', $result->get_error_data()['wp_error_code'] );
	}

	public function test_nested_block_error_is_returned_unchanged(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			)
		);

		$result = ( new UpdateWordPressAbility() )->execute(
			array(
				'source_url'    => get_permalink( $post_id ),
				'block_updates' => array(
					array(
						'block_index' => 99,
						'find'        => 'Body',
						'replace'     => 'Changed',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'edit_operations_failed', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertSame( $post_id, $result->get_error_data()['post_id'] );
	}

	public function test_mixed_block_and_surgical_updates_fail_before_writing(): void {
		$original = '<!-- wp:paragraph --><p>Original body</p><!-- /wp:paragraph -->';
		$post_id  = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => $original,
			)
		);

		$result = ( new UpdateWordPressAbility() )->execute(
			array(
				'source_url'    => get_permalink( $post_id ),
				'updates'       => array(
					array(
						'find'    => 'Original',
						'replace' => 'Surgical',
					),
				),
				'block_updates' => array(
					array(
						'block_index' => 0,
						'find'        => 'Original',
						'replace'     => 'Block',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_update_mixed_mutation_modes', $result->get_error_code() );
		$this->assertSame( array( 'updates' ), $result->get_error_data()['conflicts_with'] );
		$this->assertSame( $original, get_post( $post_id )->post_content );
	}

	public function test_single_block_mutation_mode_remains_supported(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Original body</p><!-- /wp:paragraph -->',
			)
		);

		$result = ( new UpdateWordPressAbility() )->execute(
			array(
				'source_url'    => get_permalink( $post_id ),
				'block_updates' => array(
					array(
						'block_index' => 0,
						'find'        => 'Original',
						'replace'     => 'Updated',
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Updated body', get_post( $post_id )->post_content );
	}
}
