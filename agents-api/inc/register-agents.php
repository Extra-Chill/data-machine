<?php
/**
 * Agent registration helper.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_register_agent' ) ) {
	/**
	 * Register an agent definition.
	 *
	 * Call from inside a `wp_agents_api_init` action callback. The registry
	 * collects definitions without deciding whether they should be materialized.
	 *
	 * @param string|WP_Agent $agent Agent slug or definition object.
	 * @param array           $args  Registration arguments.
	 * @return WP_Agent|null Registered agent, or null on invalid arguments.
	 */
	function wp_register_agent( $agent, array $args = array() ): ?WP_Agent {
		return WP_Agents_Registry::get_instance()->register_agent( $agent, $args );
	}
}

if ( ! function_exists( 'wp_get_agent' ) ) {
	/**
	 * Retrieves a registered agent object.
	 *
	 * @param string $slug Agent slug.
	 * @return WP_Agent|null Registered agent, or null when not registered.
	 */
	function wp_get_agent( string $slug ): ?WP_Agent {
		return WP_Agents_Registry::get_registered( $slug );
	}
}

if ( ! function_exists( 'wp_get_agents' ) ) {
	/**
	 * Retrieves all registered agent objects.
	 *
	 * @return array<string, WP_Agent>
	 */
	function wp_get_agents(): array {
		return WP_Agents_Registry::get_all_registered();
	}
}

if ( ! function_exists( 'wp_has_agent' ) ) {
	/**
	 * Checks whether an agent is registered.
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	function wp_has_agent( string $slug ): bool {
		return WP_Agents_Registry::has( $slug );
	}
}

if ( ! function_exists( 'wp_unregister_agent' ) ) {
	/**
	 * Unregisters an agent definition.
	 *
	 * @param string $slug Agent slug.
	 * @return WP_Agent|null Removed agent, or null when not registered.
	 */
	function wp_unregister_agent( string $slug ): ?WP_Agent {
		return WP_Agents_Registry::unregister( $slug );
	}
}
