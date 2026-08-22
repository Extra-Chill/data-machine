<?php
/**
 * Host-store discovery for generic conversation sessions.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

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

	/**
	 * Resolve a session only when its canonical store proves workspace ownership.
	 *
	 * @param array{type:string,key:string} $owner Canonical conversation owner.
	 * @return array<string,mixed>|null Session row, or null when absent/not owned.
	 */
	public static function get_owned_session( WP_Agent_Conversation_Store $store, string $session_id, WP_Agent_Workspace_Scope $workspace, array $owner ): ?array {
		if ( $store instanceof WP_Agent_Principal_Conversation_Session_Reader ) {
			return $store->get_session_for_owner( $workspace, $owner, $session_id );
		}

		$session = $store->get_session( $session_id );
		if ( ! is_array( $session ) || ! self::session_matches_workspace( $session, $workspace ) || ! self::session_matches_owner( $session, $owner ) ) {
			return null;
		}

		return $session;
	}

	/** @param array<string,mixed> $session */
	private static function session_matches_workspace( array $session, WP_Agent_Workspace_Scope $workspace ): bool {
		return self::string_value( $session['workspace_type'] ?? null ) === $workspace->workspace_type
			&& self::string_value( $session['workspace_id'] ?? null ) === $workspace->workspace_id;
	}

	/**
	 * @param array<string,mixed>           $session Session row.
	 * @param array{type:string,key:string} $owner   Canonical conversation owner.
	 */
	private static function session_matches_owner( array $session, array $owner ): bool {
		$owner_type = $session['owner_type'] ?? $session['principal_owner_type'] ?? null;
		$owner_key  = $session['owner_key'] ?? $session['principal_owner_key'] ?? null;
		if ( null !== $owner_type || null !== $owner_key ) {
			return self::string_value( $owner_type ) === $owner['type'] && self::string_value( $owner_key ) === $owner['key'];
		}

		$user_id = $session['user_id'] ?? 0;
		return 'user' === $owner['type'] && is_scalar( $user_id ) && (int) $user_id === (int) $owner['key'];
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
