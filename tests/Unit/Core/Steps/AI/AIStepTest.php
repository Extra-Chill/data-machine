<?php
/**
 * Tests for AIStep AI payload sanitization and result processing.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\AI
 */

namespace DataMachine\Tests\Unit\Core\Steps\AI;

use DataMachine\Core\Steps\AI\AIStep;
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
