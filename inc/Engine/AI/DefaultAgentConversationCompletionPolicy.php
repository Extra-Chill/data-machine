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
class DefaultAgentConversationCompletionPolicy implements WP_Agent_Conversation_Completion_Policy {

	/**
	 * @inheritDoc
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision {
		unset( $tool_name, $tool_def, $tool_result, $runtime_context, $turn_count );

		return WP_Agent_Conversation_Completion_Decision::incomplete();
	}
}
