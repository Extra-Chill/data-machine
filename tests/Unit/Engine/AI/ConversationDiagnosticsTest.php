<?php
/**
 * Data Machine conversation diagnostics tests.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\DataMachineCompletionAssertions;
use AgentsAPI\AI\WP_Agent_Run_Outcome;
use function DataMachine\Engine\AI\datamachine_conversation_diagnostics;
use function DataMachine\Engine\AI\datamachine_with_conversation_metadata;
use WP_UnitTestCase;

class ConversationDiagnosticsTest extends WP_UnitTestCase {

	public function test_canonical_success_remains_complete(): void {
		$metadata = $this->diagnostics(
			array(
				'completed'   => true,
				'turn_count'  => 1,
				'run_outcome' => array( 'status' => WP_Agent_Run_Outcome::STATUS_COMPLETED ),
			)
		);

		$this->assertTrue( $metadata['completed'] );
	}

	public function test_failed_and_interrupted_results_remain_incomplete(): void {
		$failed = $this->diagnostics(
			array(
				'completed'   => false,
				'error'       => 'Provider failed.',
				'run_outcome' => array( 'status' => WP_Agent_Run_Outcome::STATUS_FAILED ),
			)
		);
		$this->assertFalse( $failed['completed'] );

		$interrupted = $this->diagnostics(
			array(
				'completed'   => false,
				'interrupted' => array( 'reason' => 'operator' ),
				'run_outcome' => array( 'status' => WP_Agent_Run_Outcome::STATUS_INTERRUPTED ),
			)
		);
		$this->assertFalse( $interrupted['completed'] );
		$this->assertSame( array( 'reason' => 'operator' ), $interrupted['interrupted'] );
	}

	public function test_silent_and_explicit_turn_budgets_share_diagnostics(): void {
		$silent = $this->diagnostics(
			array(
				'completed'   => true,
				'turn_count'  => 5,
				'run_outcome' => array( 'status' => WP_Agent_Run_Outcome::STATUS_COMPLETED ),
			),
			array( array( 'name' => 'inspect' ) ),
			5
		);
		$this->assertFalse( $silent['completed'] );
		$this->assertTrue( $silent['max_turns_reached'] );

		$explicit = $this->diagnostics(
			array(
				'completed'   => false,
				'budget'      => 'conversation_turns',
				'turn_count'  => 5,
				'run_outcome' => array( 'status' => WP_Agent_Run_Outcome::STATUS_BUDGET_EXCEEDED ),
			),
			array(),
			5
		);
		$this->assertFalse( $explicit['completed'] );
		$this->assertTrue( $explicit['max_turns_reached'] );

		$explicit_turns = $this->diagnostics(
			array(
				'completed'   => false,
				'budget'      => 'turns',
				'turn_count'  => 5,
				'run_outcome' => array( 'status' => WP_Agent_Run_Outcome::STATUS_BUDGET_EXCEEDED ),
			),
			array(),
			5
		);
		$this->assertTrue( $explicit_turns['max_turns_reached'] );
	}

	public function test_runtime_tool_pending_projects_request_metadata(): void {
		$request = array( 'id' => 'tool-call-1', 'name' => 'browser' );
		$metadata = $this->diagnostics(
			array(
				'completed'            => false,
				'runtime_tool_pending' => $request,
				'run_outcome'          => array( 'status' => WP_Agent_Run_Outcome::STATUS_RUNTIME_TOOL_PENDING ),
			)
		);

		$this->assertFalse( $metadata['completed'] );
		$this->assertSame( array( $request ), $metadata['runtime_tool_pending_requests'] );
	}

	public function test_metadata_projection_preserves_canonical_result_fields(): void {
		$request = array( 'id' => 'tool-call-1', 'name' => 'browser' );
		$result  = datamachine_with_conversation_metadata(
			array(
				'completed'            => false,
				'runtime_tool_pending' => $request,
				'max_turns_reached'    => true,
			),
			array(
				'completed'             => false,
				'max_turns_reached'     => true,
			)
		);

		$this->assertFalse( $result['completed'] );
		$this->assertSame( $request, $result['runtime_tool_pending'] );
		$this->assertArrayNotHasKey( 'max_turns_reached', $result );
		$this->assertTrue( $result['metadata']['datamachine']['max_turns_reached'] );
	}

	public function test_completion_nudges_and_assertions_are_preserved(): void {
		$assertions = new DataMachineCompletionAssertions(
			array(
				'required_engine_data_keys' => array( 'ready' ),
			)
		);
		$metadata = datamachine_conversation_diagnostics(
			array(
				'completed'     => true,
				'turn_count'    => 3,
				'final_content' => 'Complete.',
				'run_outcome'   => array( 'status' => WP_Agent_Run_Outcome::STATUS_COMPLETED ),
			),
			array(),
			array(),
			array(),
			array(
				array(
					'completion_nudge'              => 'Continue.',
					'completion_assertions_required' => array( 'ready' ),
				)
			),
			true,
			5,
			$assertions,
			array(
				'engine_data' => array( 'ready' => true ),
			)
		);

		$this->assertSame( 1, $metadata['completion_nudge_count'] );
		$this->assertSame( 'Continue.', $metadata['completion_nudge'] );
		$this->assertTrue( $metadata['completion_assertions_complete'] );
		$this->assertTrue( $metadata['completed'] );
	}

	private function diagnostics( array $result, array $last_tool_calls = array(), int $turn_ceiling = 5 ): array {
		return datamachine_conversation_diagnostics(
			$result,
			$last_tool_calls,
			$last_tool_calls,
			array(),
			array(),
			false,
			$turn_ceiling,
			new DataMachineCompletionAssertions(),
			array()
		);
	}
}
