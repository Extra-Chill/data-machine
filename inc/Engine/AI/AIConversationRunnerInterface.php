<?php
/**
 * AI conversation runner interface.
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
interface AIConversationRunnerInterface {

	/**
	 * Run an AI conversation request.
	 *
	 * @param AIConversationRequest $request Conversation request.
	 * @return array Raw AIConversationLoop result shape.
	 */
	public function run( AIConversationRequest $request ): array;
}
