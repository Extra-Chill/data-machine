<?php
/**
 * UpsertStep tests.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\Upsert
 */

namespace DataMachine\Tests\Unit\Core\Steps\Upsert;

use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Upsert\UpsertStep;
use WP_UnitTestCase;

class UpsertStepTest extends WP_UnitTestCase {

	/**
	 * Build payload for UpsertStep execution.
	 *
	 * @param array $flow_step_config Flow step config.
	 * @param array $data_packets Existing data packets.
	 * @return array
	 */
	private function buildPayload( array $flow_step_config, array $data_packets = array(), array $job_context_overrides = array() ): array {
		$flow_step_id = 'step_update_1';

		$job_context = array_merge(
			array(
				'job_id'      => 123,
				'flow_id'     => 1,
				'pipeline_id' => 1,
			),
			$job_context_overrides
		);

		$engine = new EngineData(
			array(
				'job'         => $job_context,
				'flow_config' => array(
					$flow_step_id => $flow_step_config,
				),
			),
			(int) $job_context['job_id']
		);

		return array(
			'job_id'       => (int) $job_context['job_id'],
			'flow_step_id' => $flow_step_id,
			'data'         => $data_packets,
			'engine'       => $engine,
		);
	}

	public function test_missing_required_handler_tool_sets_explicit_failure_reason(): void {
		$step = new UpsertStep();

		$result = $step->execute(
			$this->buildPayload(
				array(
					'step_type'       => 'upsert',
					'handler_slugs'   => array( 'upsert_event' ),
					'handler_configs' => array(),
				)
			)
		);

		$this->assertNotEmpty( $result );
		$last = $result[ array_key_last( $result ) ];

		$this->assertSame( 'upsert', $last['type'] ?? '' );
		$this->assertSame( 'required_handler_tool_not_called', $last['metadata']['failure_reason'] ?? '' );
		$this->assertTrue( (bool) ( $last['metadata']['missing_handler_tool'] ?? false ) );
		$this->assertSame( array( 'upsert_event' ), $last['metadata']['required_handler_slugs'] ?? array() );
	}

	public function test_required_handler_slugs_allows_non_first_handler_when_configured(): void {
		$step = new UpsertStep();

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
					'step_type'               => 'upsert',
					'handler_slugs'           => array( 'upsert_event', 'publish_post' ),
					'required_handler_slugs'  => array( 'publish_post' ),
					'handler_configs'         => array(),
				),
				$data_packets
			)
		);

		$this->assertNotEmpty( $result );

		// Find the update packet — it's added alongside the original tool_result.
		$update_packet = null;
		foreach ( $result as $packet ) {
			if ( ( $packet['type'] ?? '' ) === 'upsert' ) {
				$update_packet = $packet;
				break;
			}
		}

		$this->assertNotNull( $update_packet, 'Expected an update packet in results' );
		$this->assertSame( 'publish_post', $update_packet['metadata']['handler'] ?? '' );
		$this->assertTrue( (bool) ( $update_packet['metadata']['success'] ?? false ) );
		$this->assertArrayNotHasKey( 'failure_reason', $update_packet['metadata'] ?? array() );
	}

	/**
	 * Regression test for issue #1096.
	 *
	 * A missing handler result at the upsert step is always a real failure —
	 * there is no longer a silent-skip path. Every child job created by
	 * PipelineBatchScheduler carries its own packet, so a missing handler
	 * result means the AI didn't call the tool (or an upstream filter
	 * regression dropped it). The legacy "sibling handled it" fan-out model
	 * has been removed entirely; see commit removing isLegacyFanOutChild().
	 */
	public function test_batch_child_with_missing_handler_produces_real_failure(): void {
		$parent_job_id = 999001;
		$child_job_id  = 999002;

		// Mark parent as a batch parent — the exact structure
		// PipelineBatchScheduler::fanOut() writes to engine_data.
		datamachine_set_engine_data(
			$parent_job_id,
			array(
				'batch'       => true,
				'batch_total' => 5,
			)
		);

		$step = new UpsertStep();

		$result = $step->execute(
			$this->buildPayload(
				array(
					'step_type'       => 'upsert',
					'handler_slugs'   => array( 'upsert_event' ),
					'handler_configs' => array(),
				),
				array(), // No handler result packet.
				array(
					'job_id'        => $child_job_id,
					'parent_job_id' => $parent_job_id,
				)
			)
		);

		$this->assertNotEmpty( $result );
		$last = $result[ array_key_last( $result ) ];

		// Must be a real failure, NOT a fan-out skip packet.
		$this->assertSame( 'upsert', $last['type'] ?? '' );
		$this->assertSame(
			'required_handler_tool_not_called',
			$last['metadata']['failure_reason'] ?? '',
			'Batch children with missing handler results must fail loudly, not skip silently.'
		);
		$this->assertTrue( (bool) ( $last['metadata']['missing_handler_tool'] ?? false ) );
		$this->assertArrayNotHasKey(
			'fanout_sibling_handled',
			$last['metadata'] ?? array(),
			'Batch child must not be treated as a sibling-handled fan-out skip.'
		);

		// Clean up engine data.
		datamachine_set_engine_data( $parent_job_id, array() );
	}
}
