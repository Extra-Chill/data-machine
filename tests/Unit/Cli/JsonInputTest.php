<?php

namespace DataMachine\Tests\Unit\Cli;

use DataMachine\Cli\JsonInput;
use WP_UnitTestCase;

class JsonInputTest extends WP_UnitTestCase {

	public function test_decodes_wp_cli_json_exactly_once(): void {
		$input = array(
			'url'       => 'https://example.com/events/',
			'prompt'    => 'Process start/end.',
			'windows'   => 'C:\\Temp\\events.json',
			'regexp'    => '\\d+\\s+events',
			'backslash' => 'literal \\ value',
		);

		$this->assertSame( $input, JsonInput::decode_array( (string) wp_json_encode( $input ) ) );
	}

	public function test_rejects_invalid_json_and_scalars(): void {
		$this->assertNull( JsonInput::decode_array( '{broken' ) );
		$this->assertNull( JsonInput::decode_array( '"string"' ) );
	}
}
