<?php
/**
 * Tests for AIStep AI payload sanitization and result processing.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\AI
 */

namespace DataMachine\Tests\Unit\Core\Steps\AI;

use DataMachine\Core\Steps\AI\AIStep;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Engine\AI\DataPacketPromptProjector;
use DataMachine\Engine\AI\Tools\ToolResultFinder;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use function DataMachine\Engine\AI\datamachine_with_conversation_metadata;

class AIStepTest extends TestCase {

	private static ?array $filter_baseline = null;

	protected function setUp(): void {
		parent::setUp();
		datamachine_test_prepare_site();
		self::$filter_baseline ??= self::capture_filter_ids();
	}

	protected function tearDown(): void {
		self::remove_test_filters();
		parent::tearDown();
	}

	private static function capture_filter_ids(): array {
		global $wp_filter;
		$hook = 'datamachine_ai_project_data_packet';
		$ids = array();
		if ( isset( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				$ids[ $priority ] = array_keys( $callbacks );
			}
		}
		return $ids;
	}

	private static function remove_test_filters(): void {
		global $wp_filter;
		$hook = 'datamachine_ai_project_data_packet';
		if ( null === self::$filter_baseline || ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			$known = self::$filter_baseline[ $priority ] ?? array();
			foreach ( $callbacks as $id => $callback ) {
				if ( ! in_array( $id, $known, true ) ) {
					remove_filter( $hook, $callback['function'], $priority );
				}
			}
		}
	}

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

		$sanitized = DataPacketPromptProjector::project( $data_packets );

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

		$sanitized = DataPacketPromptProjector::project( $data_packets );

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

		$this->assertSame( $data_packets, DataPacketPromptProjector::project( $data_packets ) );
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

	public function test_resolve_execution_modes_uses_flow_override_then_pipeline_config(): void {
		$method = new ReflectionMethod( AIStep::class, 'resolveExecutionModes' );
		$method->setAccessible( true );

		$this->assertSame(
			array( 'rl_task' ),
			$method->invoke( null, array( 'agent_modes' => array( 'pipeline' ) ), array( 'agent_modes' => array( 'rl_task' ) ) )
		);
		$this->assertSame(
			array( 'eval' ),
			$method->invoke( null, array( 'agent_modes' => array( 'Eval' ) ), array( 'agent_modes' => array() ) )
		);
		$this->assertSame(
			array( ToolPolicyResolver::MODE_PIPELINE ),
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

	public function test_prompt_projection_recursively_redacts_tokens_after_filters_and_retains_safe_identity(): void {
		$disposition_id = hash( 'sha256', 'safe-packet' );
		$canonical      = array(
			array(
				'type'     => 'fetch',
				'data'     => array( 'title' => 'Claimed packet' ),
				'metadata' => array(
					'_datamachine_packet_disposition_id' => $disposition_id,
					'_datamachine_item_claim' => array(
						'disposition_id' => $disposition_id,
						'ownership_token' => 'top-secret-token',
					),
					'_datamachine_item_claims' => array(
						array( 'ownership_token' => 'nested-secret-token' ),
					),
				),
			),
		);
		add_filter(
			'datamachine_ai_project_data_packet',
			static fn( array $projected, array $packet ): array => $packet,
			10,
			2
		);

		$projected = DataPacketPromptProjector::project( $canonical );
		$encoded   = wp_json_encode( $projected );

		$this->assertStringNotContainsString( 'top-secret-token', $encoded );
		$this->assertStringNotContainsString( 'nested-secret-token', $encoded );
		$this->assertSame( $disposition_id, $projected[0]['metadata']['_datamachine_packet_disposition_id'] );
		$this->assertSame( $disposition_id, $canonical[0]['metadata']['_datamachine_item_claim']['disposition_id'] );
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
					'result'          => array( 'success' => true, 'result' => array( 'post_id' => 123 ) ),
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
		$this->assertArrayHasKey( 'tool_result_envelope', $result[0]['metadata'] );
		$this->assertArrayHasKey( 'tool_result_data', $result[0]['metadata'] );
		$this->assertArrayNotHasKey( 'tool_result', $result[0]['metadata'] );
		$this->assertSame( array( 'post_id' => 123 ), $result[0]['metadata']['tool_result_data'] );

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
				'metadata' => array( 'source_type' => 'source_api' ),
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

		$this->assertSame( 'source_api', $result[0]['metadata']['source_type'] );
	}

	public function test_process_loop_results_correlates_non_contiguous_outputs_by_disposition_id(): void {
		$method = new ReflectionMethod( AIStep::class, 'processLoopResults' );
		$method->setAccessible( true );
		$claims = array();
		foreach ( array( 'a', 'b', 'c', 'd' ) as $item ) {
			$claims[ $item ] = array(
				'identity_scope'  => 'fetch-step',
				'source_type'     => 'fixture',
				'item_identifier' => $item,
				'ownership_token' => 'owner-' . $item,
				'disposition_id'  => ProcessedItems::disposition_identity( 'fetch-step', 'fixture', $item ),
			);
		}
		$tool_results = array();
		foreach ( array( 'd', 'b' ) as $item ) {
			$tool_results[] = array(
				'tool_name'       => 'fixture_upsert',
				'result'          => array( 'success' => true, 'disposition_id' => $claims[ $item ]['disposition_id'] ),
				'parameters'      => array( 'title' => strtoupper( $item ) ),
				'is_handler_tool' => true,
			);
		}

		$result = $method->invoke(
			null,
			array( 'messages' => array(), 'tool_execution_results' => $tool_results ),
			array( array( 'metadata' => array( 'source_type' => 'fixture' ) ) ),
			array(
				'flow_step_id' => 'ai-step',
				'engine_data'  => array( ProcessedItems::CLAIMS_METADATA_KEY => array_values( $claims ) ),
			),
			array( 'fixture_upsert' => array( 'handler' => 'fixture_upsert', 'handler_config' => array() ) )
		);

		$this->assertSame(
			array( $claims['d']['disposition_id'], $claims['b']['disposition_id'] ),
			array_column( array_column( $result, 'metadata' ), 'disposition_id' )
		);
		$this->assertSame( 'd', $result[0]['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ]['item_identifier'] );
		$this->assertSame( 'b', $result[1]['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ]['item_identifier'] );
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
		$this->assertFalse( $result[0]['metadata']['step_execution_success'] );
		$this->assertSame( 'ai_response_without_tool_result', $result[0]['metadata']['failure_reason'] );
	}

	public function test_process_loop_results_emits_completion_assertion_packet_when_final_turn_is_empty(): void {
		$method = new ReflectionMethod( AIStep::class, 'processLoopResults' );
		$method->setAccessible( true );

		$loop_result = datamachine_with_conversation_metadata(
			array(
				'messages'               => array(),
				'tool_execution_results' => array(),
			),
			array(
				'completion_assertions_complete'  => true,
				'completion_assertions_satisfied' => array(
					'complete_when_any' => array( 'design_comment_and_labels' ),
				),
				'completion_assertions_missing'   => array(),
			)
		);

		$result = $method->invoke(
			null,
			$loop_result,
			array(
				array(
					'type'     => 'fetch',
					'metadata' => array( 'source_type' => 'github_issue' ),
				),
			),
			array( 'flow_step_id' => 'design_ai_step' ),
			array()
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'ai_completion_assertions', $result[0]['type'] );
		$this->assertSame( 'ai_completion_assertions', $result[0]['metadata']['source_type'] );
		$this->assertSame( array( 'design_comment_and_labels' ), $result[0]['metadata']['completion_assertions_satisfied']['complete_when_any'] );
	}
}
