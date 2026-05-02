<?php
/**
 * Default runtime completion policy.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\AgentConversationCompletionDecision;
use AgentsAPI\AI\AgentConversationCompletionPolicyInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Generic policy: tool calls alone do not complete the loop.
 */
class DefaultAgentConversationCompletionPolicy implements AgentConversationCompletionPolicyInterface {

	/**
	 * @inheritDoc
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): AgentConversationCompletionDecision {
		unset( $tool_name, $tool_def, $tool_result, $runtime_context, $turn_count );

		return AgentConversationCompletionDecision::incomplete();
	}
}
