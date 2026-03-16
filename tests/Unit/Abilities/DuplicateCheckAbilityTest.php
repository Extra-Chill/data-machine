<?php
/**
 * DuplicateCheckAbility Tests
 *
 * Tests for the unified duplicate check ability — core strategies,
 * extension strategy registry, and titles-match ability.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\DuplicateCheck\DuplicateCheckAbility;
use DataMachine\Core\WordPress\PostTracking;
use WP_UnitTestCase;

class DuplicateCheckAbilityTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		new DuplicateCheckAbility();
	}

	// -----------------------------------------------------------------------
	// Ability registration
	// -----------------------------------------------------------------------

	public function test_check_duplicate_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/check-duplicate' );
		$this->assertNotNull( $ability, 'datamachine/check-duplicate ability should be registered' );
	}

	public function test_titles_match_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/titles-match' );
		$this->assertNotNull( $ability, 'datamachine/titles-match ability should be registered' );
	}

	// -----------------------------------------------------------------------
	// datamachine/titles-match
	// -----------------------------------------------------------------------

	public function test_titles_match_ability_returns_match(): void {
		$ability = wp_get_ability( 'datamachine/titles-match' );
		$result  = $ability->execute( array(
			'title1' => 'Andy Frasco & the U.N.',
			'title2' => 'Andy Frasco',
		) );

		$this->assertTrue( $result['match'] );
		$this->assertArrayHasKey( 'score', $result );
		$this->assertArrayHasKey( 'strategy', $result );
	}

	public function test_titles_match_ability_returns_no_match(): void {
		$ability = wp_get_ability( 'datamachine/titles-match' );
		$result  = $ability->execute( array(
			'title1' => 'Best Restaurants in Austin',
			'title2' => 'Live Music Venues in Nashville',
		) );

		$this->assertFalse( $result['match'] );
	}

	// -----------------------------------------------------------------------
	// datamachine/check-duplicate — published posts
	// -----------------------------------------------------------------------

	public function test_check_duplicate_finds_published_post(): void {
		// Create a test post.
		$post_id = self::factory()->post->create( array(
			'post_title'  => 'Best Phish Shows of All Time',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$ability = wp_get_ability( 'datamachine/check-duplicate' );
		$result  = $ability->execute( array(
			'title'     => 'Best Phish Shows of All Time',
			'post_type' => 'post',
			'scope'     => 'published',
		) );

		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( 'published_post', $result['source'] );
		$this->assertSame( $post_id, $result['match']['post_id'] );
	}

	public function test_check_duplicate_clears_when_no_match(): void {
		$ability = wp_get_ability( 'datamachine/check-duplicate' );
		$result  = $ability->execute( array(
			'title'     => 'Unique Title That Does Not Exist Anywhere ' . uniqid(),
			'post_type' => 'post',
			'scope'     => 'published',
		) );

		$this->assertSame( 'clear', $result['verdict'] );
	}

	public function test_check_duplicate_respects_post_type(): void {
		// Create a post with type 'post'.
		self::factory()->post->create( array(
			'post_title'  => 'TypeTest Duplicate Title',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$ability = wp_get_ability( 'datamachine/check-duplicate' );

		// Check against 'page' type — should be clear.
		$result = $ability->execute( array(
			'title'     => 'TypeTest Duplicate Title',
			'post_type' => 'page',
			'scope'     => 'published',
		) );

		$this->assertSame( 'clear', $result['verdict'] );
	}

	public function test_check_duplicate_requires_title(): void {
		$ability = wp_get_ability( 'datamachine/check-duplicate' );
		$result  = $ability->execute( array(
			'title'     => '',
			'post_type' => 'post',
		) );

		$this->assertSame( 'error', $result['verdict'] );
	}

	// -----------------------------------------------------------------------
	// datamachine/check-duplicate — fuzzy matching
	// -----------------------------------------------------------------------

	public function test_check_duplicate_catches_fuzzy_match(): void {
		self::factory()->post->create( array(
			'post_title'  => 'Andy Frasco & the U.N. — Growing Pains Tour with Candi Jenkins',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$ability = wp_get_ability( 'datamachine/check-duplicate' );
		$result  = $ability->execute( array(
			'title'     => 'Andy Frasco',
			'post_type' => 'post',
			'scope'     => 'published',
		) );

		$this->assertSame( 'duplicate', $result['verdict'] );
	}

	public function test_check_duplicate_finds_published_post_by_source_url(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Original Bonnaroo Story',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		update_post_meta( $post_id, PostTracking::SOURCE_URL_META_KEY, 'https://www.reddit.com/r/bonnaroo/comments/abc123/original_story/' );

		$ability = wp_get_ability( 'datamachine/check-duplicate' );
		$result  = $ability->execute(
			array(
				'title'      => 'Completely Rewritten Bonnaroo Headline',
				'post_type'  => 'post',
				'scope'      => 'published',
				'source_url' => 'https://www.reddit.com/r/bonnaroo/comments/abc123/original_story/',
			)
		);

		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( 'published_post_source_url', $result['source'] );
		$this->assertSame( $post_id, $result['match']['post_id'] );
	}

	// -----------------------------------------------------------------------
	// Strategy registry
	// -----------------------------------------------------------------------

	public function test_extension_strategy_runs_before_core(): void {
		// Register a strategy that always returns duplicate.
		add_filter( 'datamachine_duplicate_strategies', function ( $strategies ) {
			$strategies[] = array(
				'id'        => 'test_always_dup',
				'post_type' => 'post',
				'callback'  => function ( $input ) {
					return array(
						'verdict' => 'duplicate',
						'source'  => 'test_extension',
						'match'   => array( 'reason' => 'test' ),
						'reason'  => 'Caught by test extension strategy',
					);
				},
				'priority'  => 5,
			);
			return $strategies;
		} );

		$ability = wp_get_ability( 'datamachine/check-duplicate' );
		$result  = $ability->execute( array(
			'title'     => 'Anything',
			'post_type' => 'post',
			'scope'     => 'published',
		) );

		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( 'test_extension', $result['source'] );
		$this->assertSame( 'test_always_dup', $result['strategy'] );

		// Clean up filter.
		remove_all_filters( 'datamachine_duplicate_strategies' );
	}

	public function test_extension_strategy_scoped_to_post_type(): void {
		// Register a strategy only for 'event' post type.
		add_filter( 'datamachine_duplicate_strategies', function ( $strategies ) {
			$strategies[] = array(
				'id'        => 'test_event_only',
				'post_type' => 'event',
				'callback'  => function ( $input ) {
					return array(
						'verdict' => 'duplicate',
						'source'  => 'event_strategy',
						'match'   => array(),
						'reason'  => 'Event duplicate',
					);
				},
				'priority'  => 5,
			);
			return $strategies;
		} );

		$ability = wp_get_ability( 'datamachine/check-duplicate' );

		// Check against 'post' — event strategy should NOT run.
		$result = $ability->execute( array(
			'title'     => 'Unique Test Title ' . uniqid(),
			'post_type' => 'post',
			'scope'     => 'published',
		) );

		$this->assertSame( 'clear', $result['verdict'] );

		// Clean up filter.
		remove_all_filters( 'datamachine_duplicate_strategies' );
	}
}
