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
					'mode'     => $context,
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
					'mode'     => $context,
				);
			},
			10,
			3
		);

		$result = ToolResultFinder::findHandlerResult( array(), 'upsert_event', 'flow_step_1', false );

		$this->assertNull( $result );
		$this->assertSame( array(), $logged );
	}
}
