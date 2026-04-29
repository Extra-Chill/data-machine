<?php
/**
 * Built-in AI conversation runner.
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
class BuiltInAIConversationRunner implements AIConversationRunnerInterface {

	/**
	 * Run an AI conversation request through the legacy loop implementation.
	 *
	 * @param AIConversationRequest $request Conversation request.
	 * @return array Raw AIConversationLoop result shape.
	 */
	public function run( AIConversationRequest $request ): array {
		$loop = new AIConversationLoop();

		return $loop->execute( ...$request->toLegacyArgs() );
	}
}
