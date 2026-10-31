<?php
/**
 * Tool executor mediation contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Executes prepared tool calls for a host runtime.
 */
interface WP_Agent_Tool_Executor {

	/**
	 * Execute a prepared tool call.
	 *
	 * Agents API prepares and validates the call; concrete runtimes own how a
	 * declaration maps to abilities, callbacks, remote tools, approvals, or any
	 * other product-specific execution path.
	 *
	 * @param array<mixed> $tool_call       Normalized prepared tool call.
	 * @param array<mixed> $tool_definition Tool declaration selected for the call.
	 * @param array<mixed> $context         Host runtime context for this invocation.
	 * @return array<mixed> Raw or normalized tool execution result.
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array;
}
