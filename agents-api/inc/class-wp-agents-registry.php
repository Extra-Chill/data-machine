<?php
/**
 * WP_Agents_Registry facade.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agents_Registry' ) ) {
	/**
	 * Manages the registration and lookup of agents.
	 */
	final class WP_Agents_Registry {

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static ?self $instance = null;

		/**
		 * Whether the public registration hook has fired.
		 *
		 * @var bool
		 */
		private static bool $initialized = false;

		/**
		 * Registered agent definitions, keyed by slug.
		 *
		 * @var array<string, WP_Agent>
		 */
		private array $registered_agents = array();

		/**
		 * Register an agent definition on this registry instance.
		 *
		 * @param string|WP_Agent $agent Agent slug or definition object.
		 * @param array           $args  Registration arguments when `$agent` is a slug.
		 * @return WP_Agent|null Registered agent, or null on invalid arguments.
		 */
		public function register( $agent, array $args = array() ): ?WP_Agent {
			try {
				$agent = $agent instanceof WP_Agent ? $agent : new WP_Agent( (string) $agent, $args );
			} catch ( InvalidArgumentException $e ) {
				$this->notice_invalid_registration( __METHOD__, $e->getMessage() );
				return null;
			}

			if ( $this->is_registered( $agent->get_slug() ) ) {
				$this->notice_invalid_registration( __METHOD__, sprintf( 'Agent "%s" is already registered.', $agent->get_slug() ) );
				return null;
			}

			$this->registered_agents[ $agent->get_slug() ] = $agent;
			return $agent;
		}

		/**
		 * Get all registered agent objects.
		 *
		 * @return array<string, WP_Agent>
		 */
		public function get_all_registered(): array {
			return $this->registered_agents;
		}

		/**
		 * Get a single registered agent object by slug.
		 *
		 * @param string $slug Agent slug.
		 * @return WP_Agent|null Agent object, or null when not registered.
		 */
		public function get_registered( string $slug ): ?WP_Agent {
			$slug = sanitize_title( $slug );
			if ( ! $this->is_registered( $slug ) ) {
				$this->notice_invalid_registration( __METHOD__, sprintf( 'Agent "%s" not found.', $slug ) );
				return null;
			}

			return $this->registered_agents[ $slug ];
		}

		/**
		 * Check whether an agent is registered.
		 *
		 * @param string $slug Agent slug.
		 * @return bool
		 */
		public function is_registered( string $slug ): bool {
			$slug = sanitize_title( $slug );
			return isset( $this->registered_agents[ $slug ] );
		}

		/**
		 * Unregister an agent definition.
		 *
		 * @param string $slug Agent slug.
		 * @return WP_Agent|null Removed agent, or null when not registered.
		 */
		public function unregister( string $slug ): ?WP_Agent {
			$slug = sanitize_title( $slug );
			if ( ! $this->is_registered( $slug ) ) {
				$this->notice_invalid_registration( __METHOD__, sprintf( 'Agent "%s" not found.', $slug ) );
				return null;
			}

			$agent = $this->registered_agents[ $slug ];
			unset( $this->registered_agents[ $slug ] );

			return $agent;
		}

		/**
		 * Initialize the registry and fire the public registration hook.
		 *
		 * This is wired to WordPress' `init` action by the module bootstrap so
		 * `wp_agents_api_init` follows the same deterministic lifecycle shape as
		 * the Abilities API's registration hook. Late reads may still initialize
		 * the registry object, but they do not reopen the registration window.
		 *
		 * @return self|null Registry instance, or null when init has not fired.
		 */
		public static function init(): ?self {
			$registry = self::get_instance();
			if ( null === $registry || self::$initialized ) {
				return $registry;
			}

			self::$initialized = true;

			/**
			 * Fires to let plugins register agents.
			 *
			 * Callbacks should call `wp_register_agent()` to contribute one or more
			 * agent definitions. The registry only collects definitions; consumers
			 * decide whether and how to materialize them.
			 */
			do_action( 'wp_agents_api_init', $registry );

			return $registry;
		}

		/**
		 * Retrieve the registry singleton.
		 *
		 * @return self|null Registry instance, or null when init has not fired.
		 */
		public static function get_instance(): ?self {
			$init_started = function_exists( 'did_action' ) && did_action( 'init' );
			$doing_init   = function_exists( 'doing_action' ) && doing_action( 'init' );

			if ( ! $init_started && ! $doing_init ) {
				if ( function_exists( '_doing_it_wrong' ) ) {
					_doing_it_wrong(
						__METHOD__,
						'Agents API should not be initialized before the <code>init</code> action has fired.',
						'0.102.8'
					);
				}
				return null;
			}

			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Reset internal state. Test helper only.
		 *
		 * @internal
		 * @return void
		 */
		public static function reset_for_tests(): void {
			self::$instance    = null;
			self::$initialized = false;
		}

		/**
		 * Wakeup magic method.
		 *
		 * @throws LogicException If the registry object is unserialized.
		 */
		public function __wakeup(): void {
			throw new LogicException( __CLASS__ . ' should never be unserialized.' );
		}

		/**
		 * Sleep magic method.
		 *
		 * @throws LogicException If the registry object is serialized.
		 */
		public function __sleep(): array {
			throw new LogicException( __CLASS__ . ' should never be serialized.' );
		}

		/**
		 * Emit a WordPress-style invalid-usage notice when available.
		 *
		 * @param string $function_name Function or method name.
		 * @param string $message       Notice message.
		 * @return void
		 */
		private function notice_invalid_registration( string $function_name, string $message ): void {
			if ( function_exists( '_doing_it_wrong' ) ) {
				$function_name = function_exists( 'esc_html' ) ? esc_html( $function_name ) : $function_name;
				$message       = function_exists( 'esc_html' ) ? esc_html( $message ) : $message;
				_doing_it_wrong( $function_name, $message, '0.71.0' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong receives a message, not direct output.
			}
		}
	}
}
