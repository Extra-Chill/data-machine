<?php
/**
 * Built-in agent conversation runner.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter that runs Data Machine's existing conversation loop implementation.
 */
class BuiltInAgentConversationRunner implements AgentConversationRunnerInterface {

	/**
	 * Run an AI conversation request through the legacy loop implementation.
	 *
	 * @param AgentConversationRequest $request Conversation request.
	 * @return array Raw AIConversationLoop result shape.
	 */
	public function run( AgentConversationRequest $request ): array {
		$loop = new AIConversationLoop();

		return $loop->execute( ...$request->toLegacyArgs() );
	}
}
