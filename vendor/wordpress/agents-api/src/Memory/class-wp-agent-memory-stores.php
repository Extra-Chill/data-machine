<?php
/**
 * Host-store discovery for generic agent memory.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the host-provided agent memory store.
 */
final class WP_Agent_Memory_Stores {

	/**
	 * Resolve the host-provided memory store.
	 *
	 * Host plugins can pass a store directly in `$context['memory_store']`
	 * or provide one through the `wp_agent_memory_store` filter.
	 *
	 * @param array<string,mixed> $context Host-owned request context.
	 * @return WP_Agent_Memory_Store|null
	 */
	public static function get_store( array $context = array() ): ?WP_Agent_Memory_Store {
		if ( isset( $context['memory_store'] ) && $context['memory_store'] instanceof WP_Agent_Memory_Store ) {
			return $context['memory_store'];
		}

		$store = function_exists( 'apply_filters' ) ? apply_filters( 'wp_agent_memory_store', null, $context ) : null;
		return $store instanceof WP_Agent_Memory_Store ? $store : null;
	}
}
