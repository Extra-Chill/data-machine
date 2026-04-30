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
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static ?self $instance = null;

		/**
		 * Registered agent definitions, keyed by slug.
		 *
		 * @var array<string, WP_Agent>
		 */
		private array $registered_agents = array();

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
		 * @return WP_Agent|null Registered agent, or null on invalid arguments.
		 */
		public static function register( $agent, array $args = array() ): ?WP_Agent {
			return self::get_instance()->register_agent( $agent, $args );
		}

		/**
		 * Register an agent definition on this registry instance.
		 *
		 * Duplicate slugs intentionally remain last-wins while the registry is
		 * in-repo: Data Machine uses hook priority for fresh-install overrides.
		 *
		 * @param string|WP_Agent $agent Agent slug or definition object.
		 * @param array           $args  Registration arguments when `$agent` is a slug.
		 * @return WP_Agent|null Registered agent, or null on invalid arguments.
		 */
		public function register_agent( $agent, array $args = array() ): ?WP_Agent {
			try {
				$agent = $agent instanceof WP_Agent ? $agent : new WP_Agent( (string) $agent, $args );
			} catch ( InvalidArgumentException $e ) {
				$this->doing_it_wrong( __METHOD__, $e->getMessage() );
				return null;
			}

			$this->registered_agents[ $agent->get_slug() ] = $agent;
			return $agent;
		}

		/**
		 * Get all registered agent definitions.
		 *
		 * @return array<string, array>
		 */
		public static function get_all(): array {
			self::ensure_fired();
			return array_map(
				static fn( WP_Agent $agent ): array => $agent->to_array(),
				self::get_instance()->registered_agents
			);
		}

		/**
		 * Get all registered agent objects.
		 *
		 * @return array<string, WP_Agent>
		 */
		public static function get_all_registered(): array {
			self::ensure_fired();
			return self::get_instance()->registered_agents;
		}

		/**
		 * Get a single registered agent definition by slug.
		 *
		 * @param string $slug Agent slug.
		 * @return array|null Definition, or null if not registered.
		 */
		public static function get( string $slug ): ?array {
			self::ensure_fired();
			$slug  = sanitize_title( $slug );
			$agent = self::get_instance()->registered_agents[ $slug ] ?? null;
			return $agent instanceof WP_Agent ? $agent->to_array() : null;
		}

		/**
		 * Get a single registered agent object by slug.
		 *
		 * @param string $slug Agent slug.
		 * @return WP_Agent|null Agent object, or null when not registered.
		 */
		public static function get_registered( string $slug ): ?WP_Agent {
			self::ensure_fired();
			$slug = sanitize_title( $slug );
			return self::get_instance()->registered_agents[ $slug ] ?? null;
		}

		/**
		 * Check whether an agent is registered.
		 *
		 * @param string $slug Agent slug.
		 * @return bool
		 */
		public static function has( string $slug ): bool {
			self::ensure_fired();
			$slug = sanitize_title( $slug );
			return isset( self::get_instance()->registered_agents[ $slug ] );
		}

		/**
		 * Check whether an agent is registered.
		 *
		 * @param string $slug Agent slug.
		 * @return bool
		 */
		public static function is_registered( string $slug ): bool {
			return self::has( $slug );
		}

		/**
		 * Unregister an agent definition.
		 *
		 * @param string $slug Agent slug.
		 * @return WP_Agent|null Removed agent, or null when not registered.
		 */
		public static function unregister( string $slug ): ?WP_Agent {
			self::ensure_fired();
			$slug = sanitize_title( $slug );
			if ( ! isset( self::get_instance()->registered_agents[ $slug ] ) ) {
				self::get_instance()->doing_it_wrong( __METHOD__, sprintf( 'Agent "%s" not found.', $slug ) );
				return null;
			}

			$agent = self::get_instance()->registered_agents[ $slug ];
			unset( self::get_instance()->registered_agents[ $slug ] );

			return $agent;
		}

		/**
		 * Retrieve the registry singleton.
		 *
		 * @return self
		 */
		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
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
			self::$instance           = new self();
			self::$registration_fired = false;
		}

		/**
		 * Emit a WordPress-style invalid-usage notice when available.
		 *
		 * @param string $function_name Function or method name.
		 * @param string $message       Notice message.
		 * @return void
		 */
		private function doing_it_wrong( string $function_name, string $message ): void {
			if ( function_exists( '_doing_it_wrong' ) ) {
				$function_name = function_exists( 'esc_html' ) ? esc_html( $function_name ) : $function_name;
				$message       = function_exists( 'esc_html' ) ? esc_html( $message ) : $message;
				_doing_it_wrong( $function_name, $message, '0.71.0' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong receives a message, not direct output.
			}
		}
	}
}
