<?php
/**
 * Tests for AIStep AI payload sanitization and result processing.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\AI
 */

namespace DataMachine\Tests\Unit\Core\Steps\AI;

use DataMachine\Core\Steps\AI\AIStep;
use DataMachine\Engine\AI\DataPacketPromptProjector;
use DataMachine\Engine\AI\Tools\ToolResultFinder;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AIStepTest extends TestCase {

	public function test_sanitize_data_packets_for_ai_removes_file_path_but_keeps_other_file_info(): void {
		$data_packets = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'title'     => 'Test post',
					'body'      => 'Body',
					'file_info' => array(
						'file_path' => '/var/www/extrachill.com/wp-content/uploads/dm-files/test.jpg',
						'file_name' => 'test.jpg',
						'mime_type' => 'image/jpeg',
						'file_size' => 12345,
					),
				),
				'metadata' => array(),
			),
		);

		$sanitized = AIStep::sanitizeDataPacketsForAi( $data_packets );

		$this->assertArrayNotHasKey( 'file_path', $sanitized[0]['data']['file_info'] );
		$this->assertSame( 'test.jpg', $sanitized[0]['data']['file_info']['file_name'] );
		$this->assertSame( 'image/jpeg', $sanitized[0]['data']['file_info']['mime_type'] );
		$this->assertSame( 12345, $sanitized[0]['data']['file_info']['file_size'] );

		// Original packet remains unchanged for runtime behavior.
		$this->assertSame(
			'/var/www/extrachill.com/wp-content/uploads/dm-files/test.jpg',
			$data_packets[0]['data']['file_info']['file_path']
		);
	}

	public function test_sanitize_data_packets_for_ai_drops_empty_file_info_after_redaction(): void {
		$data_packets = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'file_info' => array(
						'file_path' => '/tmp/only-path.png',
					),
				),
				'metadata' => array(),
			),
		);

		$sanitized = AIStep::sanitizeDataPacketsForAi( $data_packets );

		$this->assertArrayNotHasKey( 'file_info', $sanitized[0]['data'] );
	}

	public function test_sanitize_data_packets_for_ai_leaves_packets_without_file_info_unchanged(): void {
		$data_packets = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'title' => 'No file info',
					'body'  => 'Still here',
				),
				'metadata' => array( 'source_type' => 'rss' ),
			),
		);

		$this->assertSame( $data_packets, AIStep::sanitizeDataPacketsForAi( $data_packets ) );
	}

	public function test_merge_completion_assertions_preserves_minimum_successful_tool_counts(): void {
		$method = new ReflectionMethod( AIStep::class, 'mergeCompletionAssertions' );
		$method->setAccessible( true );

		$merged = $method->invoke(
			null,
			array(
				'required_tool_names'             => array( 'create_github_pull_request' ),
				'minimum_successful_tool_counts' => array(
					'create_or_update_github_file' => 3,
					'ignored_zero_count'           => 0,
				),
			),
			array(
				'required_tool_names'             => array( 'comment_github_pull_request' ),
				'minimum_successful_tool_counts' => array(
					'create_or_update_github_file' => 6,
					'custom_tool'                  => '2',
				),
			)
		);

		$this->assertSame(
			array( 'create_github_pull_request', 'comment_github_pull_request' ),
			$merged['required_tool_names']
		);
		$this->assertSame(
			array(
				'create_or_update_github_file' => 6,
				'custom_tool'                  => 2,
			),
			$merged['minimum_successful_tool_counts']
		);
	}

	public function test_resolve_execution_mode_uses_flow_override_then_pipeline_config(): void {
		$method = new ReflectionMethod( AIStep::class, 'resolveExecutionMode' );
		$method->setAccessible( true );

		$this->assertSame(
			'rl_task',
			$method->invoke( null, array( 'agent_mode' => 'pipeline' ), array( 'agent_mode' => 'RL Task' ) )
		);
		$this->assertSame(
			'eval',
			$method->invoke( null, array( 'agent_mode' => 'Eval' ), array( 'agent_mode' => '' ) )
		);
		$this->assertSame(
			ToolPolicyResolver::MODE_PIPELINE,
			$method->invoke( null, array(), array() )
		);
	}

	public function test_prompt_projection_generic_fallback_preserves_unknown_packet_shape(): void {
		$canonical = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'title'     => 'RSS item',
					'body'      => 'Keep body',
					'file_info' => array(
						'file_path' => '/tmp/runtime-only.jpg',
						'mime_type' => 'image/jpeg',
					),
				),
				'metadata' => array(
					'source_type' => 'rss',
					'custom_key'   => 'custom value',
				),
			),
		);

		$projected = DataPacketPromptProjector::project( $canonical );

		$this->assertSame( 'RSS item', $projected[0]['data']['title'] );
		$this->assertSame( 'Keep body', $projected[0]['data']['body'] );
		$this->assertSame( 'rss', $projected[0]['metadata']['source_type'] );
		$this->assertSame( 'custom value', $projected[0]['metadata']['custom_key'] );
		$this->assertArrayNotHasKey( 'file_path', $projected[0]['data']['file_info'] );
		$this->assertSame( '/tmp/runtime-only.jpg', $canonical[0]['data']['file_info']['file_path'] );
	}

	public function test_prompt_projection_does_not_flatten_unknown_json_body_packets(): void {
		$canonical = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'title' => 'Unknown JSON packet',
					'body'  => '{"title":"Nested title","custom":"important"}',
				),
				'metadata' => array( 'source_type' => 'custom_json_feed' ),
			),
		);

		$this->assertSame( $canonical, DataPacketPromptProjector::project( $canonical ) );
	}

	public function test_prompt_projection_filter_can_replace_prompt_packet_without_mutating_canonical(): void {
		$canonical = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'title' => 'Verbose packet',
					'body'  => 'Long source-specific body that an integration understands.',
				),
				'metadata' => array(
					'source_type' => 'integration_owned_source',
					'raw_payload'  => array( 'duplicated' => true ),
				),
			),
		);

		add_filter(
			'datamachine_ai_project_data_packet',
			static function ( array $projected, array $packet ): array {
				if ( 'integration_owned_source' !== ( $packet['metadata']['source_type'] ?? '' ) ) {
					return $projected;
				}

				return array(
					'type'     => $packet['type'],
					'data'     => array( 'title' => $packet['data']['title'] ),
					'metadata' => array( 'source_type' => $packet['metadata']['source_type'] ),
				);
			},
			10,
			2
		);

		$canonical_before = $canonical;
		$projected        = DataPacketPromptProjector::project( $canonical );

		$this->assertSame( $canonical_before, $canonical );
		$this->assertSame( 'Verbose packet', $projected[0]['data']['title'] );
		$this->assertArrayNotHasKey( 'body', $projected[0]['data'] );
		$this->assertArrayNotHasKey( 'raw_payload', $projected[0]['metadata'] );
	}

	public function test_prompt_projection_filter_receives_source_agnostic_context(): void {
		$canonical = array(
			array(
				'type'     => 'fetch',
				'data'     => array( 'title' => 'Context packet' ),
				'metadata' => array( 'source_type' => 'context_source' ),
			),
		);
		$context   = array(
			'job_id'           => 1799,
			'pipeline_id'      => 3,
			'flow_id'          => 2,
			'flow_step_id'     => 'flow_step_ai',
			'pipeline_step_id' => 'pipeline_step_ai',
		);
		$received  = array();

		add_filter(
			'datamachine_ai_project_data_packet',
			static function ( array $projected, array $packet, array $filter_context ) use ( &$received ): array {
				if ( 'context_source' === ( $packet['metadata']['source_type'] ?? '' ) ) {
					$received = $filter_context;
				}

				return $projected;
			},
			10,
			3
		);

		DataPacketPromptProjector::project( $canonical, $context );

		$this->assertSame( $context, $received );
	}

	/**
	 * Test that processLoopResults does NOT carry forward input DataPackets.
	 *
	 * Input packets (e.g., raw HTML from a scraper) should not appear in the
	 * output. Only tool result packets should be returned. Carrying input
	 * packets forward causes the batch scheduler to create ghost child jobs
	 * that fail at the next step.
	 *
	 * @see https://github.com/Extra-Chill/data-machine/issues/832
	 */
	public function test_process_loop_results_does_not_include_input_packets(): void {
		$method = new ReflectionMethod( AIStep::class, 'processLoopResults' );
		$method->setAccessible( true );

		$input_packets = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'title' => 'Raw HTML Event Section',
					'body'  => '<div>Some scraped event HTML</div>',
				),
				'metadata' => array(
					'source_type' => 'universal_web_scraper',
				),
			),
		);

		$loop_result = array(
			'messages'               => array(
				array( 'role' => 'user', 'content' => 'Process this event' ),
				array( 'role' => 'assistant', 'content' => 'I will upsert this event.' ),
			),
			'tool_execution_results' => array(
				array(
					'tool_name'       => 'upsert_event',
					'result'          => array( 'success' => true, 'data' => array( 'post_id' => 123 ) ),
					'parameters'      => array( 'title' => 'Test Event' ),
					'is_handler_tool' => true,
					'turn_count'      => 1,
				),
			),
		);

		$payload = array(
			'flow_step_id' => 'test_step_id',
		);

		$available_tools = array(
			'upsert_event' => array(
				'handler'        => 'upsert_event',
				'handler_config' => array(),
			),
		);

		$result = $method->invoke( null, $loop_result, $input_packets, $payload, $available_tools );

		// Should contain ONLY the handler completion packet, NOT the input packet.
		$this->assertCount( 1, $result, 'processLoopResults should return only tool result packets, not input packets' );
		$this->assertSame( 'ai_handler_complete', $result[0]['type'] );
		$this->assertSame( 'upsert_event', $result[0]['metadata']['tool_name'] );

		// Verify the input packet is NOT in the output.
		foreach ( $result as $packet ) {
			$this->assertNotSame( 'fetch', $packet['type'], 'Input fetch packet should not be in output' );
		}
	}

	/**
	 * Test that processLoopResults preserves source_type from input packets.
	 */
	public function test_process_loop_results_preserves_source_type_from_input(): void {
		$method = new ReflectionMethod( AIStep::class, 'processLoopResults' );
		$method->setAccessible( true );

		$input_packets = array(
			array(
				'type'     => 'fetch',
				'data'     => array( 'title' => 'Test' ),
				'metadata' => array( 'source_type' => 'ticketmaster' ),
			),
		);

		$loop_result = array(
			'messages'               => array(),
			'tool_execution_results' => array(
				array(
					'tool_name'       => 'upsert_event',
					'result'          => array( 'success' => true ),
					'parameters'      => array(),
					'is_handler_tool' => true,
					'turn_count'      => 1,
				),
			),
		);

		$result = $method->invoke(
			null,
			$loop_result,
			$input_packets,
			array( 'flow_step_id' => 'test' ),
			array( 'upsert_event' => array( 'handler' => 'upsert_event', 'handler_config' => array() ) )
		);

		$this->assertSame( 'ticketmaster', $result[0]['metadata']['source_type'] );
	}

	public function test_successful_handler_tool_result_is_findable_by_downstream_handler_slug(): void {
		$method = new ReflectionMethod( AIStep::class, 'processLoopResults' );
		$method->setAccessible( true );

		$loop_result = array(
			'messages'               => array(
				array( 'role' => 'assistant', 'content' => 'I updated the wiki article.' ),
			),
			'tool_execution_results' => array(
				array(
					'tool_name'       => 'wiki_upsert',
					'result'          => array(
						'success' => true,
						'action'  => 'updated',
						'article' => array( 'id' => 538, 'title' => 'WooCommerce Ownership Manager' ),
					),
					'parameters'      => array( 'title' => 'WooCommerce Ownership Manager' ),
					'is_handler_tool' => true,
					'turn_count'      => 2,
				),
			),
		);

		$result = $method->invoke(
			null,
			$loop_result,
			array(
				array(
					'type'     => 'fetch',
					'metadata' => array( 'source_type' => 'mcp' ),
				),
			),
			array( 'flow_step_id' => 'ai_step' ),
			array(
				'wiki_upsert' => array(
					'handler'        => 'wiki_upsert',
					'handler_config' => array( 'fixed_parent_path' => 'woocommerce' ),
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'ai_handler_complete', $result[0]['type'] );
		$this->assertSame( 'wiki_upsert', $result[0]['metadata']['handler_tool'] );

		$found = ToolResultFinder::findHandlerResult( $result, 'wiki_upsert', 'upsert_step', false );
		$this->assertSame( $result[0], $found );
	}

	/**
	 * Test that processLoopResults emits AI response when no tools were called.
	 */
	public function test_process_loop_results_emits_ai_response_when_no_tools(): void {
		$method = new ReflectionMethod( AIStep::class, 'processLoopResults' );
		$method->setAccessible( true );

		$input_packets = array(
			array(
				'type'     => 'fetch',
				'data'     => array( 'title' => 'Test' ),
				'metadata' => array( 'source_type' => 'rss' ),
			),
		);

		$loop_result = array(
			'messages'               => array(
				array( 'role' => 'assistant', 'content' => 'This is my analysis of the content.' ),
			),
			'tool_execution_results' => array(),
		);

		$result = $method->invoke(
			null,
			$loop_result,
			$input_packets,
			array( 'flow_step_id' => 'test' ),
			array()
		);

		// Should emit a single AI response packet, not the input + response.
		$this->assertCount( 1, $result );
		$this->assertSame( 'ai_response', $result[0]['type'] );
	}
}
