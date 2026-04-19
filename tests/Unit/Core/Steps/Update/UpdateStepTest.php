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

		// Find the update packet — it's added alongside the original tool_result.
		$update_packet = null;
		foreach ( $result as $packet ) {
			if ( ( $packet['type'] ?? '' ) === 'update' ) {
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
	 * Batch children created by PipelineBatchScheduler each carry their own
	 * ai_handler_complete packet. If such a child reaches UpdateStep without
	 * the expected handler result (e.g. upstream filter regression or AI not
	 * calling the handler tool), it must NOT be silenced via the legacy
	 * fan-out skip path — that produced 8,030 orphaned jobs with status
	 * 'completed_no_items' across 7 days on events.extrachill.com.
	 *
	 * The parent job's engine_data['batch'] flag is the signal that this
	 * child owns its own packet (set by PipelineBatchScheduler::fanOut()).
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

		$step = new UpdateStep();

		$result = $step->execute(
			$this->buildPayload(
				array(
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
		$this->assertSame( 'update', $last['type'] ?? '' );
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

	/**
	 * Legacy fan-out siblings (no batch flag on parent) still skip silently.
	 *
	 * Preserves the original safety-net behavior for the legacy fan-out model
	 * where multiple packets land in one job and only one sibling owns the
	 * handler result.
	 */
	public function test_legacy_fanout_child_without_batch_parent_skips_silently(): void {
		$parent_job_id = 999003;
		$child_job_id  = 999004;

		// Parent has NO batch flag — legacy fan-out scenario.
		datamachine_set_engine_data( $parent_job_id, array() );

		$step = new UpdateStep();

		$result = $step->execute(
			$this->buildPayload(
				array(
					'handler_slugs'   => array( 'upsert_event' ),
					'handler_configs' => array(),
				),
				array(),
				array(
					'job_id'        => $child_job_id,
					'parent_job_id' => $parent_job_id,
				)
			)
		);

		$this->assertNotEmpty( $result );
		$last = $result[ array_key_last( $result ) ];

		$this->assertSame( 'update', $last['type'] ?? '' );
		$this->assertTrue(
			(bool) ( $last['metadata']['fanout_sibling_handled'] ?? false ),
			'Legacy fan-out children should still skip silently as a safety net.'
		);
		$this->assertTrue( (bool) ( $last['metadata']['success'] ?? false ) );
	}
}
