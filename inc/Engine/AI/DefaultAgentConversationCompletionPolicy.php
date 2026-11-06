<?php
/**
 * Default runtime completion policy.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision;
use AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Generic policy: tool calls alone do not complete the loop.
 */
class DefaultAgentConversationCompletionPolicy implements WP_Agent_Conversation_Completion_Policy, NaturalCompletionPolicyInterface {

	/** @var DataMachineCompletionAssertions Generic completion assertions. */
	private DataMachineCompletionAssertions $assertions;

	/**
	 * @param DataMachineCompletionAssertions|null $assertions Optional completion assertions.
	 */
	public function __construct( ?DataMachineCompletionAssertions $assertions = null ) {
		$this->assertions = $assertions ?? new DataMachineCompletionAssertions();
	}

	/**
	 * @inheritDoc
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision {
		unset( $runtime_context, $turn_count );

		$this->assertions->recordToolResult( $tool_name, $tool_def, $tool_result );

		return WP_Agent_Conversation_Completion_Decision::incomplete();
	}

	/**
	 * @inheritDoc
	 */
	public function recordNaturalCompletion( array $messages, string $assistant_text, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision {
		if ( $this->looksLikeToolActionText( $assistant_text ) ) {
			return WP_Agent_Conversation_Completion_Decision::incomplete(
				'AIConversationLoop: Assistant emitted tool action prose without a tool call, nudging continuation',
				array(
					'turn_count'           => $turn_count,
					'continuation_message' => 'Your previous response described a tool action but did not make a valid tool call. Use the available tool call format for the action, or provide a final answer if no tool is needed.',
				)
			);
		}

		$evaluation = $this->assertions->evaluate( $runtime_context, $assistant_text );
		if ( $evaluation['complete'] ) {
			return WP_Agent_Conversation_Completion_Decision::complete(
				$this->assertions->hasAssertions() ? 'AIConversationLoop: Natural completion assertions satisfied' : '',
				array(
					'turn_count' => $turn_count,
					'satisfied'  => $evaluation['satisfied'],
				)
			);
		}

		return WP_Agent_Conversation_Completion_Decision::incomplete(
			'AIConversationLoop: Natural completion assertions missing, nudging continuation',
			array(
				'turn_count'           => $turn_count,
				'missing'              => $evaluation['missing'],
				'satisfied'            => $evaluation['satisfied'],
				'required'             => $this->assertions->required(),
				'continuation_message' => DataMachineCompletionAssertions::buildNudge( $evaluation['missing'], $messages ),
			)
		);
	}

	/**
	 * Detect internal tool-action prose that should not be treated as a final answer.
	 */
	private function looksLikeToolActionText( string $assistant_text ): bool {
		return 1 === preg_match( '/^\s*AI ACTION\s*\(Turn\s+\d+\):\s*Executing\s+/i', $assistant_text );
	}
}
