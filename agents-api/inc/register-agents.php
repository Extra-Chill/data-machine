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
	 * @return void
	 */
	function wp_register_agent( $agent, array $args = array() ): void {
		WP_Agents_Registry::register( $agent, $args );
	}
}
