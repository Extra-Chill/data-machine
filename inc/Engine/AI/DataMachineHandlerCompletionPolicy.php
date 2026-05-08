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
class DataMachineHandlerCompletionPolicy implements WP_Agent_Conversation_Completion_Policy {

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
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision {
		$is_handler_tool = is_array( $tool_def ) && isset( $tool_def['handler'] );
		$mode            = (string) ( $runtime_context['mode'] ?? '' );

		if ( 'pipeline' !== $mode || ! $is_handler_tool || ! ( $tool_result['success'] ?? false ) ) {
			return WP_Agent_Conversation_Completion_Decision::incomplete();
		}

		$handler_slug = $tool_def['handler'] ?? null;
		if ( $handler_slug ) {
			$this->executed_handler_slugs[] = $handler_slug;
		}

		if ( empty( $this->configured_handlers ) ) {
			return WP_Agent_Conversation_Completion_Decision::complete(
				'AIConversationLoop: Handler tool executed (legacy mode), ending conversation',
				array(
					'tool_name'  => $tool_name,
					'turn_count' => $turn_count,
				)
			);
		}

		$remaining = array_diff( $this->configured_handlers, array_unique( $this->executed_handler_slugs ) );
		if ( empty( $remaining ) ) {
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
}
