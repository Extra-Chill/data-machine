<?php
/**
 * Transcript store resolution.
 *
 * Data Machine no longer ships its own conversation store. Pipeline
 * transcripts and runtime-tool session projections resolve whatever store
 * the host registered through the Agents API `wp_agent_conversation_store`
 * filter (for example the opt-in Agents API CPT store). When no store is
 * registered, transcript persistence is skipped.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Sessions;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use DataMachine\Core\Agents\AgentIdentityResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the Agents API conversation store for Data Machine transcripts.
 */
final class TranscriptStore {

	/**
	 * Resolve the host-registered Agents API conversation store.
	 *
	 * @return WP_Agent_Conversation_Store|null Store, or null when none is registered.
	 */
	public static function get(): ?WP_Agent_Conversation_Store {
		if ( ! class_exists( WP_Agent_Conversation_Sessions::class ) ) {
			return null;
		}

		return WP_Agent_Conversation_Sessions::get_store( array( 'source' => 'datamachine' ) );
	}

	/**
	 * Resolve an agent slug for transcript ownership metadata.
	 *
	 * @param int $agent_id Agent ID.
	 * @return string Agent slug, or empty string when unresolvable.
	 */
	public static function resolve_agent_slug( int $agent_id ): string {
		if ( $agent_id <= 0 ) {
			return '';
		}

		try {
			return ( new AgentIdentityResolver() )->resolve_agent_slug( $agent_id );
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'Unable to resolve agent slug for transcript store call',
				array(
					'agent_id' => $agent_id,
					'error'    => $e->getMessage(),
				)
			);
			return '';
		}
	}
}
