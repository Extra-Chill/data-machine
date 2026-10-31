<?php
/**
 * Event Tool Guard Tests
 *
 * Covers the `datamachine_resolved_tools` filter that strips generic
 * content-writing tools from events AI steps so the model can only publish
 * through the adjacent `upsert_event` upsert handler (issue #412).
 *
 * The guard keys off the events pipeline shape — an AI step adjacent to an
 * `upsert` step whose handler is `upsert_event` — and removes the DM core
 * ability/handler tools that can mint an arbitrary `post` outside EventUpsert
 * (`upsert_post`, `insert_content`, `wordpress_publish`). It must preserve the
 * events publish path (`upsert_event`), the fetch disposition tools
 * (`reject_source`, `defer_item`), and read/research tools.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.46.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;

use function DataMachineEvents\Core\event_ai_blocked_tools;
use function DataMachineEvents\Core\is_event_upsert_ai_step;
use function DataMachineEvents\Core\strip_event_ai_content_tools;

class EventToolGuardTest extends WP_UnitTestCase {

	/**
	 * A resolved-tools args blob for a pipeline AI step whose NEXT step is the
	 * events upsert — the canonical `event_import → ai → upsert` shape.
	 */
	private function eventsAiArgs( array $overrides = array() ): array {
		return array_merge(
			array(
				'modes'                => array( 'pipeline' ),
				'previous_step_config' => array(
					'step_type'      => 'event_import',
					'handler_slugs'  => array( 'ticketmaster' ),
					'flow_step_id'   => 'import_1',
				),
				'next_step_config'     => array(
					'step_type'      => 'upsert',
					'handler_slugs'  => array( 'upsert_event' ),
					'flow_step_id'   => 'upsert_1',
				),
				'pipeline_step_id'     => 'ai_1',
			),
			$overrides
		);
	}

	/**
	 * A sample resolved tool set mimicking what ToolPolicyResolver hands the
	 * filter: the events handler tool, the fetch dispositions, read/research
	 * tools, and the generic content-writing tools that must be stripped.
	 */
	private function sampleTools(): array {
		return array(
			'upsert_event'    => array( 'handler' => 'upsert_event' ),
			'reject_source'   => array( 'handler' => 'fetch_disposition' ),
			'defer_item'      => array( 'handler' => 'fetch_disposition' ),
			'web_fetch'       => array(),
			'query_posts'     => array(),
			// Generic content-writing tools — the leak vector from issue #412.
			'upsert_post'     => array( 'ability' => 'datamachine/upsert-post' ),
			'insert_content'  => array( 'ability' => 'datamachine/insert-content' ),
			'wordpress_publish' => array( 'handler' => 'wordpress_publish' ),
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// event_ai_blocked_tools
	// ────────────────────────────────────────────────────────────────────

	public function test_default_block_list_targets_generic_content_writers(): void {
		$blocked = event_ai_blocked_tools();

		$this->assertContains( 'upsert_post', $blocked );
		$this->assertContains( 'insert_content', $blocked );
		$this->assertContains( 'wordpress_publish', $blocked );

		// The events publish path and dispositions must never be in the strip list.
		$this->assertNotContains( 'upsert_event', $blocked );
		$this->assertNotContains( 'reject_source', $blocked );
		$this->assertNotContains( 'defer_item', $blocked );
	}

	public function test_block_list_is_filterable(): void {
		$added = static function ( $tools ) {
			$tools[] = 'custom_leaky_tool';
			return $tools;
		};
		add_filter( 'data_machine_events_event_ai_blocked_tools', $added );

		$blocked = event_ai_blocked_tools();

		remove_filter( 'data_machine_events_event_ai_blocked_tools', $added );

		$this->assertContains( 'custom_leaky_tool', $blocked );
	}

	// ────────────────────────────────────────────────────────────────────
	// is_event_upsert_ai_step
	// ────────────────────────────────────────────────────────────────────

	public function test_detects_events_ai_step_via_next_upsert(): void {
		$this->assertTrue( is_event_upsert_ai_step( $this->eventsAiArgs() ) );
	}

	public function test_detects_events_ai_step_via_previous_upsert(): void {
		$args = array(
			'modes'                => array( 'pipeline' ),
			'previous_step_config' => array(
				'step_type'     => 'upsert',
				'handler_slugs' => array( 'upsert_event' ),
			),
			'next_step_config'     => array(
				'step_type'     => 'event_import',
				'handler_slugs' => array( 'ticketmaster' ),
			),
		);

		$this->assertTrue( is_event_upsert_ai_step( $args ) );
	}

	public function test_ignores_non_pipeline_mode(): void {
		$args = $this->eventsAiArgs( array( 'modes' => array( 'chat' ) ) );

		// Chat/system resolution must never be narrowed by this pipeline guard.
		$this->assertFalse( is_event_upsert_ai_step( $args ) );
	}

	public function test_ignores_ai_step_adjacent_to_non_event_upsert(): void {
		// A generic wordpress_publish upsert/publish step is NOT an events step.
		$args = array(
			'modes'                => array( 'pipeline' ),
			'next_step_config'     => array(
				'step_type'     => 'publish',
				'handler_slugs' => array( 'wordpress_publish' ),
			),
		);

		$this->assertFalse( is_event_upsert_ai_step( $args ) );
	}

	public function test_ignores_step_when_no_adjacent_handlers(): void {
		$this->assertFalse( is_event_upsert_ai_step( array( 'modes' => array( 'pipeline' ) ) ) );
	}

	public function test_ignores_upsert_step_without_event_handler(): void {
		$args = array(
			'modes'                => array( 'pipeline' ),
			'next_step_config'     => array(
				'step_type'     => 'upsert',
				'handler_slugs' => array( 'some_other_upsert' ),
			),
		);

		$this->assertFalse( is_event_upsert_ai_step( $args ) );
	}

	// ────────────────────────────────────────────────────────────────────
	// strip_event_ai_content_tools
	// ────────────────────────────────────────────────────────────────────

	public function test_strips_generic_content_tools_from_events_ai_step(): void {
		$tools = strip_event_ai_content_tools( $this->sampleTools(), $this->eventsAiArgs() );

		$this->assertArrayNotHasKey( 'upsert_post', $tools );
		$this->assertArrayNotHasKey( 'insert_content', $tools );
		$this->assertArrayNotHasKey( 'wordpress_publish', $tools );
	}

	public function test_preserves_events_publish_and_disposition_tools(): void {
		$tools = strip_event_ai_content_tools( $this->sampleTools(), $this->eventsAiArgs() );

		$this->assertArrayHasKey( 'upsert_event', $tools );
		$this->assertArrayHasKey( 'reject_source', $tools );
		$this->assertArrayHasKey( 'defer_item', $tools );
	}

	public function test_preserves_read_and_research_tools(): void {
		$tools = strip_event_ai_content_tools( $this->sampleTools(), $this->eventsAiArgs() );

		$this->assertArrayHasKey( 'web_fetch', $tools );
		$this->assertArrayHasKey( 'query_posts', $tools );
	}

	public function test_leaves_tools_unchanged_for_non_events_step(): void {
		$args = array(
			'modes'                => array( 'pipeline' ),
			'next_step_config'     => array(
				'step_type'     => 'publish',
				'handler_slugs' => array( 'wordpress_publish' ),
			),
		);

		$tools = strip_event_ai_content_tools( $this->sampleTools(), $args );

		// A blog-publish pipeline keeps its generic tools — only events is narrowed.
		$this->assertArrayHasKey( 'upsert_post', $tools );
		$this->assertArrayHasKey( 'insert_content', $tools );
		$this->assertArrayHasKey( 'wordpress_publish', $tools );
	}

	public function test_leaves_tools_unchanged_for_chat_mode(): void {
		$args = $this->eventsAiArgs( array( 'modes' => array( 'chat' ) ) );

		$tools = strip_event_ai_content_tools( $this->sampleTools(), $args );

		$this->assertArrayHasKey( 'upsert_post', $tools );
	}

	public function test_no_op_when_block_list_emptied(): void {
		// An operator who filters the strip list down to nothing opts out of the
		// guard; the resolved set must pass through verbatim.
		$clear = static function () {
			return array();
		};
		add_filter( 'data_machine_events_event_ai_blocked_tools', $clear );

		$tools = strip_event_ai_content_tools( $this->sampleTools(), $this->eventsAiArgs() );

		remove_filter( 'data_machine_events_event_ai_blocked_tools', $clear );

		$this->assertArrayHasKey( 'upsert_post', $tools );
		$this->assertArrayHasKey( 'insert_content', $tools );
	}

	public function test_actually_hooks_the_resolved_tools_filter(): void {
		// The plugin bootstrap registers the filter; verify the callback is
		// wired so the guard is live on real AI step resolutions, not just
		// callable as a pure function.
		$this->assertTrue(
			has_filter( 'datamachine_resolved_tools', 'DataMachineEvents\Core\apply_event_tool_guard' ) !== false,
			'apply_event_tool_guard must be hooked on datamachine_resolved_tools'
		);
	}
}
