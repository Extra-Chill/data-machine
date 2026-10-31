<?php
/**
 * Test Handler ability schema validation.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Handler\TestHandlerAbility;
use WP_UnitTestCase;

class TestHandlerAbilitySchemaTest extends WP_UnitTestCase {

	public function test_compact_and_raw_outputs_validate_against_runtime_schema(): void {
		$schema = TestHandlerAbility::getOutputSchema();

		$compact = array(
			'success'           => true,
			'handler_slug'      => 'example',
			'handler_label'     => 'Example',
			'config_used'       => array(),
			'packets'           => array(),
			'packet_count'      => 0,
			'warnings'          => array(),
			'execution_time_ms' => 1.0,
			'output_mode'       => 'compact',
		);

		$raw                = $compact;
		$raw['output_mode'] = 'raw';
		$raw['limits']      = array(
			'packet_count' => 5,
			'bytes'        => 4096,
		);
		$raw['truncation']  = array(
			'truncated'                 => false,
			'reasons'                   => array(),
			'materialized_packet_count' => 0,
			'returned_packet_count'     => 0,
			'omitted_packet_count'      => 0,
			'returned_bytes'            => 700,
			'materialization_limited'   => true,
			'redacted_fields'           => array(),
			'binary_fields'             => array(),
			'omitted_fields'            => array(),
			'omitted_field_count'       => 0,
		);

		$this->assertTrue( rest_validate_value_from_schema( $compact, $schema, 'result' ) );
		$this->assertTrue( rest_validate_value_from_schema( $raw, $schema, 'result' ) );
	}
}
