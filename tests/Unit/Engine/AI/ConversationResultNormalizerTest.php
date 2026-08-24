<?php
/**
 * Conversation result normalization tests.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\ConversationResultNormalizer;
use DataMachine\Engine\AI\DataMachineCompletionAssertions;
use DataMachine\Engine\AI\DataMachineConversationStatus;
use WP_UnitTestCase;

class ConversationResultNormalizerTest extends WP_UnitTestCase {

	public function test_status_less_success_is_not_overridden(): void {
		$normalized = $this->normalize( array( 'turn_count' => 1 ) );

		$this->assertSame( '', $normalized['status'] );
		$this->assertFalse( $normalized['status_overridden'] );
		$this->assertTrue( $normalized['metadata']['completed'] );
	}

	public function test_failed_and_interrupted_results_remain_incomplete(): void {
		$failed = $this->normalize(
			array(
				'status' => DataMachineConversationStatus::FAILED,
				'error'  => 'Provider failed.',
			)
		);
		$this->assertFalse( $failed['metadata']['completed'] );

		$interrupted = $this->normalize(
			array(
				'status'      => DataMachineConversationStatus::INTERRUPTED,
				'interrupted' => array( 'reason' => 'operator' ),
			)
		);
		$this->assertFalse( $interrupted['metadata']['completed'] );
		$this->assertSame( array( 'reason' => 'operator' ), $interrupted['metadata']['interrupted'] );
	}

	public function test_silent_and_explicit_turn_budgets_share_diagnostics(): void {
		$silent = $this->normalize(
			array(
				'status'     => 'completed',
				'turn_count' => 5,
			),
			array( array( 'name' => 'inspect' ) ),
			5
		);
		$this->assertFalse( $silent['metadata']['completed'] );
		$this->assertTrue( $silent['metadata']['max_turns_reached'] );

		$explicit = $this->normalize(
			array(
				'status'     => DataMachineConversationStatus::BUDGET_EXCEEDED,
				'budget'     => 'conversation_turns',
				'turn_count' => 5,
			),
			array(),
			5
		);
		$this->assertFalse( $explicit['metadata']['completed'] );
		$this->assertTrue( $explicit['metadata']['max_turns_reached'] );
	}

	public function test_runtime_tool_pending_is_the_only_status_override(): void {
		$request = array( 'id' => 'tool-call-1', 'name' => 'browser' );
		$normalized = $this->normalize(
			array(
				'status'               => 'completed',
				'runtime_tool_pending' => $request,
			)
		);

		$this->assertSame( DataMachineConversationStatus::RUNTIME_TOOL_PENDING, $normalized['status'] );
		$this->assertTrue( $normalized['status_overridden'] );
		$this->assertFalse( $normalized['metadata']['completed'] );
		$this->assertSame( array( $request ), $normalized['metadata']['runtime_tool_pending_requests'] );
	}

	public function test_completion_nudges_and_assertions_are_preserved(): void {
		$assertions = new DataMachineCompletionAssertions(
			array(
				'required_engine_data_keys' => array( 'ready' ),
			)
		);
		$normalized = ConversationResultNormalizer::normalize(
			array(
				'status'        => 'completed',
				'turn_count'    => 3,
				'final_content' => 'Complete.',
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

		$this->assertSame( 1, $normalized['metadata']['completion_nudge_count'] );
		$this->assertSame( 'Continue.', $normalized['metadata']['completion_nudge'] );
		$this->assertTrue( $normalized['metadata']['completion_assertions_complete'] );
		$this->assertTrue( $normalized['metadata']['completed'] );
	}

	private function normalize( array $result, array $last_tool_calls = array(), int $turn_ceiling = 5 ): array {
		return ConversationResultNormalizer::normalize(
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
