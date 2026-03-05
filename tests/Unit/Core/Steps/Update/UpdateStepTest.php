<?php
/**
 * UpdateStep tests.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\Update
 */

namespace DataMachine\Tests\Unit\Core\Steps\Update;

use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Update\UpdateStep;
use WP_UnitTestCase;

class UpdateStepTest extends WP_UnitTestCase {

	/**
	 * Build payload for UpdateStep execution.
	 *
	 * @param array $flow_step_config Flow step config.
	 * @param array $data_packets Existing data packets.
	 * @return array
	 */
	private function buildPayload( array $flow_step_config, array $data_packets = array() ): array {
		$flow_step_id = 'step_update_1';

		$engine = new EngineData(
			array(
				'job'         => array(
					'job_id'      => 123,
					'flow_id'     => 1,
					'pipeline_id' => 1,
				),
				'flow_config' => array(
					$flow_step_id => $flow_step_config,
				),
			),
			123
		);

		return array(
			'job_id'       => 123,
			'flow_step_id' => $flow_step_id,
			'data'         => $data_packets,
			'engine'       => $engine,
		);
	}

	public function test_missing_required_handler_tool_sets_explicit_failure_reason(): void {
		$step = new UpdateStep();

		$result = $step->execute(
			$this->buildPayload(
				array(
					'handler_slugs'   => array( 'upsert_event' ),
					'handler_configs' => array(),
				)
			)
		);

		$this->assertNotEmpty( $result );
		$last = $result[ array_key_last( $result ) ];

		$this->assertSame( 'update', $last['type'] ?? '' );
		$this->assertSame( 'required_handler_tool_not_called', $last['metadata']['failure_reason'] ?? '' );
		$this->assertTrue( (bool) ( $last['metadata']['missing_handler_tool'] ?? false ) );
		$this->assertSame( array( 'upsert_event' ), $last['metadata']['required_handler_slugs'] ?? array() );
	}

	public function test_required_handler_slugs_allows_non_first_handler_when_configured(): void {
		$step = new UpdateStep();

		$data_packets = array(
			array(
				'type'     => 'tool_result',
				'metadata' => array(
					'handler_tool' => 'publish_post',
					'tool_success' => true,
					'tool_result'  => array(
						'success' => true,
					),
				),
			),
		);

		$result = $step->execute(
			$this->buildPayload(
				array(
					'handler_slugs'           => array( 'upsert_event', 'publish_post' ),
					'required_handler_slugs'  => array( 'publish_post' ),
					'handler_configs'         => array(),
				),
				$data_packets
			)
		);

		$this->assertNotEmpty( $result );
		$last = $result[ array_key_last( $result ) ];

		$this->assertSame( 'update', $last['type'] ?? '' );
		$this->assertSame( 'publish_post', $last['metadata']['handler'] ?? '' );
		$this->assertTrue( (bool) ( $last['metadata']['success'] ?? false ) );
		$this->assertArrayNotHasKey( 'failure_reason', $last['metadata'] ?? array() );
	}
}
