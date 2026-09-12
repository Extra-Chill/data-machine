<?php
/**
 * Data Machine natural completion policy extension.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision;

defined( 'ABSPATH' ) || exit;

/**
 * Optional extension point for policies that need to inspect no-tool completion.
 */
interface NaturalCompletionPolicyInterface {

	/**
	 * Decide whether a no-tool assistant response may naturally complete the run.
	 *
	 * @param array  $messages        Current conversation messages.
	 * @param string $assistant_text  Latest assistant text.
	 * @param array  $runtime_context Caller-owned runtime context.
	 * @param int    $turn_count      Current turn count.
	 * @return WP_Agent_Conversation_Completion_Decision Completion decision.
	 */
	public function recordNaturalCompletion( array $messages, string $assistant_text, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision;
}
