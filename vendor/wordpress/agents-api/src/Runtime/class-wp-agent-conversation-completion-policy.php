<?php
/**
 * Runtime completion policy contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a tool result completes a conversation run.
 */
interface WP_Agent_Conversation_Completion_Policy {

	/**
	 * Record a tool result and decide whether the runtime should stop.
	 *
	 * @param string     $tool_name       Tool name from the assistant response.
	 * @param array<string, mixed>|null $tool_def    Tool definition from the active tool set.
	 * @param array<string, mixed>      $tool_result Tool execution result.
	 * @param array<string, mixed>      $runtime_context Caller-owned runtime context.
	 * @param int        $turn_count      Current turn count.
	 * @return WP_Agent_Conversation_Completion_Decision Completion decision.
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision;
}
