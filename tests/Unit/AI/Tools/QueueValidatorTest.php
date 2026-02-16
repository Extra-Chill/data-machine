<?php
/**
 * Tests for QueueValidator global tool.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\Global\QueueValidator;
use WP_UnitTestCase;

class QueueValidatorTest extends WP_UnitTestCase {

	private QueueValidator $validator;

	public function setUp(): void {
		parent::setUp();
		$this->validator = new QueueValidator();
	}

	/**
	 * Test handle_tool_call returns error when topic is missing.
	 */
	public function test_handle_tool_call_requires_topic(): void {
		$result = $this->validator->handle_tool_call( array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'topic', $result['error'] );
		$this->assertSame( 'queue_validator', $result['tool_name'] );
	}

	/**
	 * Test handle_tool_call returns clear for unique topic.
	 */
	public function test_handle_tool_call_returns_clear_for_unique_topic(): void {
		$result = $this->validator->handle_tool_call( array( 'topic' => 'Completely Unique Topic That Definitely Does Not Exist Anywhere' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'clear', $result['verdict'] );
		$this->assertSame( 'queue_validator', $result['tool_name'] );
		$this->assertArrayHasKey( 'reason', $result );
	}

	/**
	 * Test handle_tool_call detects duplicate published post.
	 */
	public function test_handle_tool_call_detects_duplicate_post(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'The Spiritual Meaning of Blue Jays',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$result = $this->validator->handle_tool_call( array( 'topic' => 'Spiritual Meaning of Blue Jays' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'duplicate', $result['verdict'] );
		$this->assertSame( 'published_post', $result['source'] );
		$this->assertSame( $post_id, $result['match']['post_id'] );
		$this->assertGreaterThanOrEqual( 0.65, $result['match']['similarity'] );
		$this->assertArrayHasKey( 'reason', $result );
		$this->assertStringContainsString( 'Rejected', $result['reason'] );
	}

	/**
	 * Test handle_tool_call ignores draft posts.
	 */
	public function test_handle_tool_call_ignores_draft_posts(): void {
		$this->factory->post->create(
			array(
				'post_title'  => 'Draft About Mysterious Platypus Facts',
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);

		$result = $this->validator->handle_tool_call( array( 'topic' => 'Mysterious Platypus Facts' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'clear', $result['verdict'] );
	}

	/**
	 * Test custom threshold overrides default.
	 */
	public function test_handle_tool_call_respects_custom_threshold(): void {
		$this->factory->post->create(
			array(
				'post_title'  => 'Why Do Crows Caw at Night',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Very high threshold — should clear even with partial match.
		$result = $this->validator->handle_tool_call(
			array(
				'topic'                => 'Crows Cawing',
				'similarity_threshold' => '0.99',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'clear', $result['verdict'] );
	}

	/**
	 * Test tokenize strips stop words and short words.
	 */
	public function test_tokenize_strips_stop_words(): void {
		$tokens = $this->validator->tokenize( 'The Spiritual Meaning of a Blue Jay' );

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
		$tokens = $this->validator->tokenize( 'blue blue blue jay jay' );

		$this->assertCount( 2, $tokens );
		$this->assertContains( 'blue', $tokens );
		$this->assertContains( 'jay', $tokens );
	}

	/**
	 * Test jaccard similarity with identical sets.
	 */
	public function test_jaccard_identical_sets(): void {
		$score = $this->validator->jaccard( array( 'blue', 'jay' ), array( 'blue', 'jay' ) );
		$this->assertSame( 1.0, $score );
	}

	/**
	 * Test jaccard similarity with no overlap.
	 */
	public function test_jaccard_no_overlap(): void {
		$score = $this->validator->jaccard( array( 'blue', 'jay' ), array( 'red', 'cardinal' ) );
		$this->assertSame( 0.0, $score );
	}

	/**
	 * Test jaccard similarity with partial overlap.
	 */
	public function test_jaccard_partial_overlap(): void {
		$score = $this->validator->jaccard( array( 'blue', 'jay', 'meaning' ), array( 'blue', 'jay', 'symbolism' ) );
		$this->assertSame( 0.5, $score ); // 2 intersect / 4 union.
	}

	/**
	 * Test jaccard with empty sets returns 0.
	 */
	public function test_jaccard_empty_sets(): void {
		$this->assertSame( 0.0, $this->validator->jaccard( array(), array( 'word' ) ) );
		$this->assertSame( 0.0, $this->validator->jaccard( array( 'word' ), array() ) );
		$this->assertSame( 0.0, $this->validator->jaccard( array(), array() ) );
	}

	/**
	 * Test tool definition has required fields.
	 */
	public function test_get_tool_definition(): void {
		$def = $this->validator->getToolDefinition();

		$this->assertSame( QueueValidator::class, $def['class'] );
		$this->assertSame( 'handle_tool_call', $def['method'] );
		$this->assertArrayHasKey( 'description', $def );
		$this->assertArrayHasKey( 'parameters', $def );
		$this->assertArrayHasKey( 'topic', $def['parameters'] );
		$this->assertTrue( $def['parameters']['topic']['required'] );
		$this->assertArrayNotHasKey( 'requires_config', $def );
	}
}
