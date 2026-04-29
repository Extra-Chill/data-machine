<?php
/**
 * Agent conversation runner interface.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transport-neutral runner boundary for conversation execution.
 */
interface AgentConversationRunnerInterface {

	/**
	 * Run an AI conversation request.
	 *
	 * @param AgentConversationRequest $request Conversation request.
	 * @return array Raw AIConversationLoop result shape.
	 */
	public function run( AgentConversationRequest $request ): array;
}
