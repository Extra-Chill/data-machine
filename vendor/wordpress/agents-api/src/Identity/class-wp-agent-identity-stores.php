<?php
/**
 * Host-store discovery for materialized agent identities.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the host-provided materialized agent identity store.
 */
final class WP_Agent_Identity_Stores {

	/**
	 * Resolve the host-provided identity store.
	 *
	 * Host plugins can pass a store directly in `$context['identity_store']`
	 * or provide one through the `wp_agent_identity_store` filter.
	 *
	 * @param array<string,mixed> $context Host-owned request context.
	 * @return WP_Agent_Identity_Store|null
	 */
	public static function get_store( array $context = array() ): ?WP_Agent_Identity_Store {
		if ( isset( $context['identity_store'] ) && $context['identity_store'] instanceof WP_Agent_Identity_Store ) {
			return $context['identity_store'];
		}

		$store = function_exists( 'apply_filters' ) ? apply_filters( 'wp_agent_identity_store', null, $context ) : null;
		return $store instanceof WP_Agent_Identity_Store ? $store : null;
	}
}
