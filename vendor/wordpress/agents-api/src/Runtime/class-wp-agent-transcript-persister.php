<?php
/**
 * Runtime transcript persistence contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Persists a completed or failed conversation transcript when requested.
 */
interface WP_Agent_Transcript_Persister {

	/**
	 * Persist a runtime transcript.
	 *
	 * @param array<mixed>                    $messages Final conversation messages.
	 * @param WP_Agent_Conversation_Request $request  Original conversation request.
	 * @param array<mixed>                    $result   Conversation result so far.
	 * @return string Transcript ID on success, empty string when not persisted.
	 */
	public function persist( array $messages, WP_Agent_Conversation_Request $request, array $result ): string;
}
