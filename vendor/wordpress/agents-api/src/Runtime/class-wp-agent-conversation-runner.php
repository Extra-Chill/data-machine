<?php
/**
 * Agent conversation runner interface.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Transport-neutral runner boundary for conversation execution.
 */
interface WP_Agent_Conversation_Runner {

	/**
	 * Run an agent conversation request.
	 *
	 * @param WP_Agent_Conversation_Request $request Conversation request.
	 * @return array<string, mixed> Raw conversation result shape.
	 */
	public function run( WP_Agent_Conversation_Request $request ): array;
}
