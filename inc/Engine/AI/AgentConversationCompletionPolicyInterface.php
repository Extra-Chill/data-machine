<?php
/**
 * Runtime completion policy contract.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a tool result completes a conversation run.
 */
interface AgentConversationCompletionPolicyInterface {

	/**
	 * Record a tool result and decide whether the runtime should stop.
	 *
	 * @param string     $tool_name   Tool name from the model response.
	 * @param array|null $tool_def    Tool definition from the active tool set.
	 * @param array      $tool_result Tool execution result.
	 * @param string     $mode        Runtime mode.
	 * @param int        $turn_count  Current turn count.
	 * @return AgentConversationCompletionDecision Completion decision.
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, string $mode, int $turn_count ): AgentConversationCompletionDecision;
}
