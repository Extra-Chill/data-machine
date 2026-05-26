<?php
/**
 * ToolResultFinder unit tests.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\Tools
 */

namespace DataMachine\Tests\Unit\Engine\AI\Tools;

use DataMachine\Engine\AI\Tools\ToolResultFinder;
use PHPUnit\Framework\TestCase;

class ToolResultFinderTest extends TestCase {

	public function test_find_handler_result_logs_error_by_default_when_missing(): void {
		$logged = array();

		add_action(
			'datamachine_log',
			function ( $level, $message, $context ) use ( &$logged ) {
				$logged[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			},
			10,
			3
		);

		$result = ToolResultFinder::findHandlerResult( array(), 'upsert_event', 'flow_step_1' );

		$this->assertNull( $result );
		$this->assertNotEmpty( $logged );
		$this->assertSame( 'error', $logged[0]['level'] );
		$this->assertSame( 'AI did not execute handler tool', $logged[0]['message'] );
		$this->assertSame( 'upsert_event', $logged[0]['context']['handler'] );
	}

	public function test_find_handler_result_can_skip_error_logging_when_missing(): void {
		$logged = array();

		add_action(
			'datamachine_log',
			function ( $level, $message, $context ) use ( &$logged ) {
				$logged[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			},
			10,
			3
		);

		$result = ToolResultFinder::findHandlerResult( array(), 'upsert_event', 'flow_step_1', false );

		$this->assertNull( $result );
		$this->assertSame( array(), $logged );
	}

	public function test_find_handler_result_accepts_canonical_tool_result_envelope(): void {
		$packet = array(
			'type'     => 'tool_result',
			'metadata' => array(
				'handler_tool'         => 'upsert_event',
				'tool_result_envelope' => array(
					'success'   => true,
					'tool_name' => 'upsert_event',
					'data'      => array( 'post_id' => 123 ),
				),
				'tool_result_data'     => array( 'post_id' => 123 ),
			),
		);

		$result = ToolResultFinder::findHandlerResult( array( $packet ), 'upsert_event', 'flow_step_1', false );

		$this->assertSame( $packet, $result );
	}

	public function test_find_handler_result_rejects_failed_canonical_envelope(): void {
		$packet = array(
			'type'     => 'tool_result',
			'metadata' => array(
				'handler_tool'         => 'upsert_event',
				'tool_result_envelope' => array(
					'success' => false,
					'error'   => 'rejected',
				),
			),
		);

		$result = ToolResultFinder::findHandlerResult( array( $packet ), 'upsert_event', 'flow_step_1', false );

		$this->assertNull( $result );
	}

	public function test_find_handler_result_falls_back_to_tool_name_for_legacy_handler_packets(): void {
		$packet = array(
			'type'     => 'ai_handler_complete',
			'metadata' => array(
				'tool_name'            => 'wiki_upsert',
				'tool_result_envelope' => array( 'success' => true ),
			),
		);

		$result = ToolResultFinder::findHandlerResult( array( $packet ), 'wiki_upsert', 'flow_step_1', false );

		$this->assertSame( $packet, $result );
	}
}
