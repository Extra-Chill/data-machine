<?php
/**
 * Tests for the queue topic validator.
 *
 * @package DataMachine\Tests\Unit\Core\Similarity
 */

namespace DataMachine\Tests\Unit\Core\Similarity;

use DataMachine\Core\Similarity\QueueTopicValidator;
use DataMachine\Core\Similarity\SimilarityEngine;
use WP_UnitTestCase;

class QueueTopicValidatorTest extends WP_UnitTestCase {

	private QueueTopicValidator $validator;

	public function setUp(): void {
		parent::setUp();
		$this->validator = new QueueTopicValidator();
	}

	/**
	 * Test validate returns error when topic is missing.
	 */
	public function test_validate_requires_topic(): void {
		$result = $this->validator->validate( array() );

		$this->assertSame( 'error', $result['verdict'] );
		$this->assertStringContainsString( 'topic', $result['reason'] );
	}

	/**
	 * Test validate returns clear for unique topic.
	 */
	public function test_validate_returns_clear_for_unique_topic(): void {
		$result = $this->validator->validate( array( 'topic' => 'Completely Unique Topic That Definitely Does Not Exist Anywhere' ) );

		$this->assertSame( 'clear', $result['verdict'] );
		$this->assertArrayHasKey( 'reason', $result );
	}

	/**
	 * Test validate detects duplicate published post.
	 */
	public function test_validate_detects_duplicate_post(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'The Spiritual Meaning of Blue Jays',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$result = $this->validator->validate( array( 'topic' => 'Spiritual Meaning of Blue Jays' ) );

		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( 'published_post', $result['source'] );
		$this->assertSame( $post_id, $result['match']['post_id'] );
		$this->assertGreaterThanOrEqual( 0.65, $result['match']['similarity'] );
		$this->assertArrayHasKey( 'reason', $result );
		$this->assertStringContainsString( 'Rejected', $result['reason'] );
	}

	/**
	 * Test validate ignores draft posts.
	 */
	public function test_validate_ignores_draft_posts(): void {
		$this->factory->post->create(
			array(
				'post_title'  => 'Draft About Mysterious Platypus Facts',
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);

		$result = $this->validator->validate( array( 'topic' => 'Mysterious Platypus Facts' ) );

		$this->assertSame( 'clear', $result['verdict'] );
	}

	/**
	 * Test custom threshold overrides default.
	 */
	public function test_validate_respects_custom_threshold(): void {
		$this->factory->post->create(
			array(
				'post_title'  => 'Why Do Crows Caw at Night',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Very high threshold — should clear even with partial match.
		$result = $this->validator->validate(
			array(
				'topic'                => 'Crows Cawing',
				'similarity_threshold' => '0.99',
			)
		);

		$this->assertSame( 'clear', $result['verdict'] );
	}

	/**
	 * Test tokenize strips stop words and short words.
	 */
	public function test_tokenize_strips_stop_words(): void {
		$tokens = SimilarityEngine::tokenize( 'The Spiritual Meaning of a Blue Jay' );

		$this->assertContains( 'spiritual', $tokens );
		$this->assertContains( 'meaning', $tokens );
		$this->assertContains( 'blue', $tokens );
		$this->assertContains( 'jay', $tokens );
		$this->assertNotContains( 'the', $tokens );
		$this->assertNotContains( 'of', $tokens );
		$this->assertNotContains( 'a', $tokens );
	}

	/**
	 * Test tokenize returns unique words.
	 */
	public function test_tokenize_returns_unique_words(): void {
		$tokens = SimilarityEngine::tokenize( 'blue blue blue jay jay' );

		$this->assertCount( 2, $tokens );
		$this->assertContains( 'blue', $tokens );
		$this->assertContains( 'jay', $tokens );
	}

	/**
	 * Test jaccard similarity with identical sets.
	 */
	public function test_jaccard_identical_sets(): void {
		$score = SimilarityEngine::jaccard( array( 'blue', 'jay' ), array( 'blue', 'jay' ) );
		$this->assertSame( 1.0, $score );
	}

	/**
	 * Test jaccard similarity with no overlap.
	 */
	public function test_jaccard_no_overlap(): void {
		$score = SimilarityEngine::jaccard( array( 'blue', 'jay' ), array( 'red', 'cardinal' ) );
		$this->assertSame( 0.0, $score );
	}

	/**
	 * Test jaccard similarity with partial overlap.
	 */
	public function test_jaccard_partial_overlap(): void {
		$score = SimilarityEngine::jaccard( array( 'blue', 'jay', 'meaning' ), array( 'blue', 'jay', 'symbolism' ) );
		$this->assertSame( 0.5, $score ); // 2 intersect / 4 union.
	}

	/**
	 * Test jaccard with empty sets returns 0.
	 */
	public function test_jaccard_empty_sets(): void {
		$this->assertSame( 0.0, SimilarityEngine::jaccard( array(), array( 'word' ) ) );
		$this->assertSame( 0.0, SimilarityEngine::jaccard( array( 'word' ), array() ) );
		$this->assertSame( 0.0, SimilarityEngine::jaccard( array(), array() ) );
	}
}
