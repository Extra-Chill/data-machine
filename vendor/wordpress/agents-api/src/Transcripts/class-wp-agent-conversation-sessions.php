<?php
/**
 * Host-store discovery for generic conversation sessions.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the host-provided conversation transcript/session store.
 */
final class WP_Agent_Conversation_Sessions {

	/**
	 * Resolve the host-provided conversation store.
	 *
	 * Host plugins can pass a store directly in `$context['conversation_store']`
	 * or provide one through the `wp_agent_conversation_store` filter.
	 *
	 * @param array<string,mixed> $context Host-owned request context.
	 * @return WP_Agent_Conversation_Store|null
	 */
	public static function get_store( array $context = array() ): ?WP_Agent_Conversation_Store {
		if ( isset( $context['conversation_store'] ) && $context['conversation_store'] instanceof WP_Agent_Conversation_Store ) {
			return $context['conversation_store'];
		}

		$store = function_exists( 'apply_filters' ) ? apply_filters( 'wp_agent_conversation_store', null, $context ) : null;
		return $store instanceof WP_Agent_Conversation_Store ? $store : null;
	}
}
