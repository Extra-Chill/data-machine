<?php
/**
 * Data Machine handler completion policy.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Preserves pipeline handler completion behavior outside the generic turn loop.
 */
class DataMachineHandlerCompletionPolicy implements AgentConversationCompletionPolicyInterface {

	/** @var array Required handler slugs configured by the adjacent pipeline step. */
	private array $configured_handlers;

	/** @var array Handler slugs that have completed successfully. */
	private array $executed_handler_slugs = array();

	/**
	 * @param array $configured_handlers Required handler slugs.
	 */
	public function __construct( array $configured_handlers = array() ) {
		$this->configured_handlers = array_values( $configured_handlers );
	}

	/**
	 * @inheritDoc
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, string $mode, int $turn_count ): AgentConversationCompletionDecision {
		$is_handler_tool = is_array( $tool_def ) && isset( $tool_def['handler'] );

		if ( 'pipeline' !== $mode || ! $is_handler_tool || ! ( $tool_result['success'] ?? false ) ) {
			return AgentConversationCompletionDecision::incomplete();
		}

		$handler_slug = $tool_def['handler'] ?? null;
		if ( $handler_slug ) {
			$this->executed_handler_slugs[] = $handler_slug;
		}

		if ( empty( $this->configured_handlers ) ) {
			return AgentConversationCompletionDecision::complete(
				'AIConversationLoop: Handler tool executed (legacy mode), ending conversation',
				array(
					'tool_name'  => $tool_name,
					'turn_count' => $turn_count,
				)
			);
		}

		$remaining = array_diff( $this->configured_handlers, array_unique( $this->executed_handler_slugs ) );
		if ( empty( $remaining ) ) {
			return AgentConversationCompletionDecision::complete(
				'AIConversationLoop: All configured handlers executed, ending conversation',
				array(
					'tool_name'           => $tool_name,
					'turn_count'          => $turn_count,
					'executed_handlers'   => array_unique( $this->executed_handler_slugs ),
					'configured_handlers' => $this->configured_handlers,
				)
			);
		}

		return AgentConversationCompletionDecision::incomplete(
			'AIConversationLoop: Handler executed, waiting for remaining handlers',
			array(
				'tool_name'          => $tool_name,
				'remaining_handlers' => array_values( $remaining ),
			)
		);
	}
}
