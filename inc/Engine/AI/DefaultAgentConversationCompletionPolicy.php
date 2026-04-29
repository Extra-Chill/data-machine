<?php
/**
 * Default runtime completion policy.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Generic policy: tool calls alone do not complete the loop.
 */
class DefaultAgentConversationCompletionPolicy implements AgentConversationCompletionPolicyInterface {

	/**
	 * @inheritDoc
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, string $mode, int $turn_count ): AgentConversationCompletionDecision {
		unset( $tool_name, $tool_def, $tool_result, $mode, $turn_count );

		return AgentConversationCompletionDecision::incomplete();
	}
}
