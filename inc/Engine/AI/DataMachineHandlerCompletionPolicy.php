<?php
/**
 * Data Machine handler completion policy.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision;
use AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Preserves pipeline handler completion behavior outside the generic turn loop.
 */
class DataMachineHandlerCompletionPolicy implements WP_Agent_Conversation_Completion_Policy, NaturalCompletionPolicyInterface {

	/** @var array Required handler slugs configured by the adjacent pipeline step. */
	private array $configured_handlers;

	/** @var array Handler slugs that have completed successfully. */
	private array $executed_handler_slugs = array();

	/** @var DataMachineCompletionAssertions Generic completion assertions. */
	private DataMachineCompletionAssertions $assertions;

	/**
	 * @param array                                $configured_handlers Required handler slugs.
	 * @param DataMachineCompletionAssertions|null $assertions          Optional generic assertions.
	 */
	public function __construct( array $configured_handlers = array(), ?DataMachineCompletionAssertions $assertions = null ) {
		$this->configured_handlers = array_values( $configured_handlers );
		$this->assertions          = $assertions ?? new DataMachineCompletionAssertions();
	}

	/**
	 * @inheritDoc
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision {
		$this->assertions->recordToolResult(
			$tool_name,
			$tool_def,
			$tool_result,
			is_array( $runtime_context['tool_parameters'] ?? null ) ? $runtime_context['tool_parameters'] : array()
		);

		$is_handler_tool = is_array( $tool_def ) && isset( $tool_def['handler'] );
		$mode            = (string) ( $runtime_context['mode'] ?? '' );

		if ( 'pipeline' !== $mode || ! $is_handler_tool || ! ( $tool_result['success'] ?? false ) ) {
			$missing_assertions = $this->incompleteAssertionsDecision( $runtime_context, $turn_count );
			if ( null !== $missing_assertions ) {
				return $missing_assertions;
			}

			return WP_Agent_Conversation_Completion_Decision::incomplete();
		}

		$handler_slug = $tool_def['handler'] ?? null;
		if ( $handler_slug ) {
			$this->executed_handler_slugs[] = $handler_slug;
		}

		if ( empty( $this->configured_handlers ) ) {
			$missing_assertions = $this->incompleteAssertionsDecision( $runtime_context, $turn_count );
			if ( null !== $missing_assertions ) {
				return $missing_assertions;
			}

			return WP_Agent_Conversation_Completion_Decision::complete(
				'AIConversationLoop: Handler tool executed without configured handler list, ending conversation',
				array(
					'tool_name'  => $tool_name,
					'turn_count' => $turn_count,
				)
			);
		}

		$remaining = array_diff( $this->configured_handlers, array_unique( $this->executed_handler_slugs ) );
		if ( empty( $remaining ) ) {
			$missing_assertions = $this->incompleteAssertionsDecision( $runtime_context, $turn_count );
			if ( null !== $missing_assertions ) {
				return $missing_assertions;
			}

			return WP_Agent_Conversation_Completion_Decision::complete(
				'AIConversationLoop: All configured handlers executed, ending conversation',
				array(
					'tool_name'           => $tool_name,
					'turn_count'          => $turn_count,
					'executed_handlers'   => array_unique( $this->executed_handler_slugs ),
					'configured_handlers' => $this->configured_handlers,
				)
			);
		}

		return WP_Agent_Conversation_Completion_Decision::incomplete(
			'AIConversationLoop: Handler executed, waiting for remaining handlers',
			array(
				'tool_name'          => $tool_name,
				'remaining_handlers' => array_values( $remaining ),
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function recordNaturalCompletion( array $messages, string $assistant_text, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision {
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
	 * Keep handler-style tools from ending a run before generic completion
	 * assertions are satisfied.
	 *
	 * @param array $runtime_context Caller-owned runtime context.
	 * @param int   $turn_count      Current turn count.
	 * @return WP_Agent_Conversation_Completion_Decision|null
	 */
	private function incompleteAssertionsDecision( array $runtime_context, int $turn_count ): ?WP_Agent_Conversation_Completion_Decision {
		if ( ! $this->assertions->hasAssertions() ) {
			return null;
		}

		$evaluation = $this->assertions->evaluate( $runtime_context );
		if ( $evaluation['complete'] ) {
			return null;
		}

		return WP_Agent_Conversation_Completion_Decision::incomplete(
			'AIConversationLoop: Handler completion assertions missing, nudging continuation',
			array(
				'turn_count'           => $turn_count,
				'missing'              => $evaluation['missing'],
				'satisfied'            => $evaluation['satisfied'],
				'required'             => $this->assertions->required(),
				'continuation_message' => DataMachineCompletionAssertions::buildNudge( $evaluation['missing'], array() ),
			)
		);
	}
}
