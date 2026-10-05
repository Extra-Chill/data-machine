<?php
/**
 * Null transcript persister.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * No-op transcript persistence implementation.
 */
class WP_Agent_Null_Transcript_Persister implements WP_Agent_Transcript_Persister {

	/**
	 * @inheritDoc
	 */
	public function persist( array $messages, WP_Agent_Conversation_Request $request, array $result ): string {
		unset( $messages, $request, $result );

		return '';
	}
}
