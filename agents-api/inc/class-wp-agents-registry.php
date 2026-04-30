<?php
/**
 * WP_Agents_Registry facade.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agents_Registry' ) ) {
	/**
	 * WordPress-shaped declarative agent registry.
	 *
	 * The registry collects definitions only. Data Machine consumes these
	 * definitions later when it materializes rows/access/scaffolding.
	 */
	class WP_Agents_Registry {

		/**
		 * Registered agent definitions, keyed by slug.
		 *
		 * @var array<string, array>
		 */
		private static array $agents = array();

		/**
		 * Whether the public registration action has fired.
		 *
		 * @var bool
		 */
		private static bool $registration_fired = false;

		/**
		 * Register an agent definition.
		 *
		 * @param string|WP_Agent $agent Agent slug or definition object.
		 * @param array           $args  Registration arguments when `$agent` is a slug.
		 * @return void
		 */
		public static function register( $agent, array $args = array() ): void {
			if ( $agent instanceof WP_Agent ) {
				$slug = $agent->slug;
				$args = $agent->to_array();
			} else {
				$slug = (string) $agent;
			}

			$slug = sanitize_title( $slug );
			if ( '' === $slug ) {
				return;
			}

			$label = isset( $args['label'] ) ? (string) $args['label'] : '';
			if ( '' === $label ) {
				$label = $slug;
			}

			$memory_seeds = array();
			if ( isset( $args['memory_seeds'] ) && is_array( $args['memory_seeds'] ) ) {
				foreach ( $args['memory_seeds'] as $filename => $path ) {
					$filename = sanitize_file_name( (string) $filename );
					$path     = (string) $path;
					if ( '' !== $filename && '' !== $path ) {
						$memory_seeds[ $filename ] = $path;
					}
				}
			}

			self::$agents[ $slug ] = array(
				'slug'           => $slug,
				'label'          => $label,
				'description'    => isset( $args['description'] ) ? (string) $args['description'] : '',
				'memory_seeds'   => $memory_seeds,
				'owner_resolver' => isset( $args['owner_resolver'] ) && is_callable( $args['owner_resolver'] ) ? $args['owner_resolver'] : null,
				'default_config' => isset( $args['default_config'] ) && is_array( $args['default_config'] ) ? $args['default_config'] : array(),
			);
		}

		/**
		 * Get all registered agent definitions.
		 *
		 * @return array<string, array>
		 */
		public static function get_all(): array {
			self::ensure_fired();
			return self::$agents;
		}

		/**
		 * Get a single registered agent definition by slug.
		 *
		 * @param string $slug Agent slug.
		 * @return array|null Definition, or null if not registered.
		 */
		public static function get( string $slug ): ?array {
			self::ensure_fired();
			$slug = sanitize_title( $slug );
			return self::$agents[ $slug ] ?? null;
		}

		/**
		 * Ensure the public registration action has fired.
		 *
		 * @return void
		 */
		private static function ensure_fired(): void {
			if ( self::$registration_fired ) {
				return;
			}

			self::$registration_fired = true;

			/**
			 * Fires to let plugins register agents.
			 *
			 * Callbacks should call `wp_register_agent()` to contribute one or more
			 * agent definitions. The registry only collects definitions; consumers
			 * decide whether and how to materialize them.
			 */
			do_action( 'wp_agents_api_init' );
		}

		/**
		 * Reset internal state. Test helper only.
		 *
		 * @internal
		 * @return void
		 */
		public static function reset_for_tests(): void {
			self::$agents             = array();
			self::$registration_fired = false;
		}
	}
}
