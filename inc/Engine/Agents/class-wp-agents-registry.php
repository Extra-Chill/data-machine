<?php
/**
 * WP_Agents_Registry facade.
 *
 * @package DataMachine\Engine\Agents
 * @since   0.99.0
 */

use DataMachine\Engine\Agents\AgentRegistry;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agents_Registry' ) ) {
	/**
	 * WordPress-shaped facade for the in-place agent registry.
	 *
	 * Data Machine remains the materialization consumer. This facade only
	 * contributes definitions to the side-effect-free registry.
	 *
	 * @since 0.99.0
	 */
	class WP_Agents_Registry {

		/**
		 * Register an agent definition.
		 *
		 * @param string|WP_Agent $agent Agent slug or definition object.
		 * @param array           $args  Registration arguments when `$agent` is a slug.
		 * @return void
		 */
		public static function register( $agent, array $args = array() ): void {
			if ( $agent instanceof WP_Agent ) {
				AgentRegistry::register( $agent->slug, $agent->to_array() );
				return;
			}

			AgentRegistry::register( (string) $agent, $args );
		}

		/**
		 * Get all registered agent definitions.
		 *
		 * @return array<string, array>
		 */
		public static function get_all(): array {
			return AgentRegistry::get_all();
		}

		/**
		 * Get a single registered agent definition by slug.
		 *
		 * @param string $slug Agent slug.
		 * @return array|null Definition, or null if not registered.
		 */
		public static function get( string $slug ): ?array {
			return AgentRegistry::get( $slug );
		}
	}
}
