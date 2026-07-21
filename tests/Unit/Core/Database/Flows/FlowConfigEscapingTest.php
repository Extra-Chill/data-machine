<?php

namespace DataMachine\Tests\Unit\Core\Database\Flows;

use DataMachine\Core\Database\Flows\FlowConfigEscaping;
use WP_UnitTestCase;

class FlowConfigEscapingTest extends WP_UnitTestCase {

	public function test_repairs_only_literal_json_solidus_escapes(): void {
		$config = array(
			'step' => array(
				'url'    => 'https:\\/\\/example.com\\/events',
				'prompt' => 'Process start\\/end.',
				'path'   => 'C:\\Temp\\events.json',
				'regexp' => '\\d+\\s+events',
			),
		);

		$result = FlowConfigEscaping::repair( $config );

		$this->assertSame( 'https://example.com/events', $result['config']['step']['url'] );
		$this->assertSame( 'Process start/end.', $result['config']['step']['prompt'] );
		$this->assertSame( 'C:\\Temp\\events.json', $result['config']['step']['path'] );
		$this->assertSame( '\\d+\\s+events', $result['config']['step']['regexp'] );
		$this->assertSame( array( 'step.url', 'step.prompt' ), array_column( $result['changes'], 'path' ) );
	}

	public function test_repair_is_idempotent(): void {
		$first  = FlowConfigEscaping::repair( array( 'prompt' => 'start\\/end' ) );
		$second = FlowConfigEscaping::repair( $first['config'] );

		$this->assertSame( array( 'prompt' => 'start/end' ), $second['config'] );
		$this->assertSame( array(), $second['changes'] );
	}
}
