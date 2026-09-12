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

	/** @var bool Whether a successful tool declared a terminal completion signal. */
	private bool $terminal_signal_recorded = false;

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
		$metadata                 = is_array( $tool_result['metadata'] ?? null ) ? $tool_result['metadata'] : array();
		$datamachine_metadata     = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
		$mediated_tool_parameters = is_array( $datamachine_metadata['parameters'] ?? null ) ? $datamachine_metadata['parameters'] : array();
		$this->assertions->recordToolResult(
			$tool_name,
			$tool_def,
			$tool_result,
			! empty( $mediated_tool_parameters ) ? $mediated_tool_parameters : ( is_array( $runtime_context['tool_parameters'] ?? null ) ? $runtime_context['tool_parameters'] : array() )
		);

		if ( ( $tool_result['success'] ?? false ) && $this->hasTerminalCompletionSignal( $tool_def, $tool_result ) ) {
			$this->terminal_signal_recorded = true;

			return WP_Agent_Conversation_Completion_Decision::complete(
				'AIConversationLoop: Tool declared terminal completion signal, ending conversation',
				array(
					'tool_name'         => $tool_name,
					'turn_count'        => $turn_count,
					'completion_signal' => 'terminal',
				)
			);
		}

		$is_handler_tool = is_array( $tool_def ) && isset( $tool_def['handler'] );
		$modes           = is_array( $runtime_context['modes'] ?? null ) ? $runtime_context['modes'] : array( (string) ( $runtime_context['mode'] ?? '' ) );

		if ( ! in_array( 'pipeline', $modes, true ) || ! $is_handler_tool || ! ( $tool_result['success'] ?? false ) ) {
			$completed_assertions = $this->completedAssertionsDecision( $runtime_context, $turn_count, $tool_name );
			if ( null !== $completed_assertions ) {
				return $completed_assertions;
			}

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
		if ( $this->terminal_signal_recorded ) {
			return WP_Agent_Conversation_Completion_Decision::complete(
				'AIConversationLoop: Terminal completion signal already recorded, ending conversation',
				array(
					'turn_count'        => $turn_count,
					'completion_signal' => 'terminal',
				)
			);
		}

		$remaining_handlers = $this->remainingConfiguredHandlers();
		if ( ! empty( $remaining_handlers ) ) {
			return WP_Agent_Conversation_Completion_Decision::incomplete(
				'AIConversationLoop: Configured handler completion missing, nudging continuation',
				array(
					'turn_count'           => $turn_count,
					'remaining_handlers'   => $remaining_handlers,
					'configured_handlers'  => $this->configured_handlers,
					'continuation_message' => DataMachineCompletionAssertions::buildNudge( array( 'handler_slugs' => $remaining_handlers ), $messages ),
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
	 * Whether a tool definition/result pair declares a terminal completion signal.
	 *
	 * Tools can declare `runtime => array( 'completion_signal' => 'terminal' )`
	 * in their definition (or result) to mark a successful execution as a
	 * terminal pipeline outcome — e.g. an explicit item disposition — without
	 * this policy hardcoding tool names.
	 *
	 * @param array|null $tool_def    Tool definition.
	 * @param array      $tool_result Tool execution result.
	 * @return bool
	 */
	private function hasTerminalCompletionSignal( ?array $tool_def, array $tool_result ): bool {
		$definition_runtime = is_array( $tool_def['runtime'] ?? null ) ? $tool_def['runtime'] : array();
		$result_runtime     = is_array( $tool_result['runtime'] ?? null ) ? $tool_result['runtime'] : array();
		$runtime            = array_merge( $definition_runtime, $result_runtime );

		return 'terminal' === (string) ( $runtime['completion_signal'] ?? '' );
	}

	/**
	 * Return configured handler slugs that have not completed yet.
	 *
	 * @return array<int,string>
	 */
	private function remainingConfiguredHandlers(): array {
		if ( empty( $this->configured_handlers ) ) {
			return array();
		}

		return array_values( array_diff( $this->configured_handlers, array_unique( $this->executed_handler_slugs ) ) );
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

	/**
	 * Complete assertion-only pipeline runs as soon as a non-handler tool satisfies them.
	 *
	 * @param array  $runtime_context Caller-owned runtime context.
	 * @param int    $turn_count      Current turn count.
	 * @param string $tool_name       Tool that just ran.
	 * @return WP_Agent_Conversation_Completion_Decision|null
	 */
	private function completedAssertionsDecision( array $runtime_context, int $turn_count, string $tool_name ): ?WP_Agent_Conversation_Completion_Decision {
		if ( ! $this->assertions->hasAssertions() ) {
			return null;
		}

		$evaluation = $this->assertions->evaluate( $runtime_context );
		if ( ! $evaluation['complete'] || empty( $evaluation['satisfied']['complete_when_any'] ) ) {
			return null;
		}

		return WP_Agent_Conversation_Completion_Decision::complete(
			'AIConversationLoop: Completion assertions satisfied by non-handler tool, ending conversation',
			array(
				'tool_name'  => $tool_name,
				'turn_count' => $turn_count,
				'satisfied'  => $evaluation['satisfied'],
			)
		);
	}
}
