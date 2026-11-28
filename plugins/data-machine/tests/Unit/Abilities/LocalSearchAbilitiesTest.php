<?php
/**
 * LocalSearchAbilities Tests
 *
 * Tests for local search ability.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\LocalSearchAbilities;
use WP_UnitTestCase;

class LocalSearchAbilitiesTest extends WP_UnitTestCase {

	private LocalSearchAbilities $local_search_abilities;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->local_search_abilities = new LocalSearchAbilities();
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/local-search' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/local-search', $ability->get_name() );
	}

	public function test_search_with_valid_query(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test Search Post',
			)
		);

		$result = $this->local_search_abilities->executeLocalSearch(
			array(
				'query'      => 'Test Search Post',
				'post_types' => array( 'post' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'results_count', $result );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertGreaterThan( 0, $result['results_count'] );
	}

	public function test_search_fails_with_empty_query(): void {
		$result = $this->local_search_abilities->executeLocalSearch(
			array(
				'query'      => '',
				'post_types' => array( 'post' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 0, $result['results_count'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'query', $result['error'] );
	}

	public function test_search_returns_empty_for_no_matches(): void {
		$result = $this->local_search_abilities->executeLocalSearch(
			array(
				'query'      => 'nonexistent_content_xyz_12345',
				'post_types' => array( 'post' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 0, $result['results_count'] );
		$this->assertEmpty( $result['results'] );
		$this->assertArrayNotHasKey( 'error', $result );
	}

	public function test_search_by_title_only(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Unique Title For Testing',
				'post_content' => 'Different content here',
			)
		);

		$result = $this->local_search_abilities->executeLocalSearch(
			array(
				'query'      => 'Unique Title For Testing',
				'post_types' => array( 'post' ),
				'title_only' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'title_only', $result['search_method'] );
		$this->assertGreaterThan( 0, $result['results_count'] );
	}

	public function test_search_result_format(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Format Test Post',
				'post_content' => 'This is the content for format testing.',
			)
		);

		$result = $this->local_search_abilities->executeLocalSearch(
			array(
				'query'      => 'Format Test Post',
				'post_types' => array( 'post' ),
			)
		);

		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'query', $result );
		$this->assertArrayHasKey( 'results_count', $result );
		$this->assertArrayHasKey( 'post_types_searched', $result );
		$this->assertArrayHasKey( 'search_method', $result );
		$this->assertArrayHasKey( 'results', $result );

		if ( $result['results_count'] > 0 ) {
			$first_result = $result['results'][0];
			$this->assertArrayHasKey( 'post_id', $first_result );
			$this->assertArrayHasKey( 'title', $first_result );
			$this->assertArrayHasKey( 'link', $first_result );
			$this->assertArrayHasKey( 'excerpt', $first_result );
			$this->assertArrayHasKey( 'post_type', $first_result );
			$this->assertArrayHasKey( 'publish_date', $first_result );
			$this->assertArrayHasKey( 'author', $first_result );
		}
	}

	public function test_search_multiple_post_types(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Multi Type Test Post',
			)
		);

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Multi Type Test Page',
			)
		);

		$result = $this->local_search_abilities->executeLocalSearch(
			array(
				'query'      => 'Multi Type Test',
				'post_types' => array( 'post', 'page' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 2, $result['results_count'] );
		$this->assertContains( 'post', $result['post_types_searched'] );
		$this->assertContains( 'page', $result['post_types_searched'] );
	}

	public function test_permission_callback_with_admin(): void {
		$ability = wp_get_ability( 'datamachine/local-search' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array( 'query' => 'test' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_permission_callback_without_permissions(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/local-search' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array( 'query' => 'test' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}
}
