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
		$slug = $agent instanceof WP_Agent ? $agent->get_slug() : (string) $agent;
		if ( ! doing_action( 'wp_agents_api_init' ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				sprintf(
					'Agents must be registered on the %1$s action. The agent %2$s was not registered.',
					'<code>wp_agents_api_init</code>',
					'<code>' . esc_html( $slug ) . '</code>'
				),
				'0.102.8'
			);
			return null;
		}

		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->register( $agent, $args );
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
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->get_registered( $slug );
	}
}

if ( ! function_exists( 'wp_get_agents' ) ) {
	/**
	 * Retrieves all registered agent objects.
	 *
	 * @return array<string, WP_Agent>
	 */
	function wp_get_agents(): array {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return array();
		}

		return $registry->get_all_registered();
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
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return false;
		}

		return $registry->is_registered( $slug );
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
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->unregister( $slug );
	}
}
