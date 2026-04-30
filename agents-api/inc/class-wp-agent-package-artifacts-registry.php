<?php
/**
 * WP_Agent_Package_Artifacts_Registry facade.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Artifacts_Registry' ) ) {
	/**
	 * Manages package artifact type registration and lookup.
	 */
	final class WP_Agent_Package_Artifacts_Registry {

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static ?self $instance = null;

		/**
		 * Registered artifact types, keyed by type slug.
		 *
		 * @var array<string, WP_Agent_Package_Artifact_Type>
		 */
		private array $registered_types = array();

		/**
		 * Register an artifact type.
		 *
		 * @param string|WP_Agent_Package_Artifact_Type $type Artifact type slug or object.
		 * @param array                                 $args Registration arguments when `$type` is a slug.
		 * @return WP_Agent_Package_Artifact_Type|null Registered type, or null on invalid arguments.
		 */
		public function register( $type, array $args = array() ): ?WP_Agent_Package_Artifact_Type {
			try {
				$type = $type instanceof WP_Agent_Package_Artifact_Type ? $type : new WP_Agent_Package_Artifact_Type( (string) $type, $args );
			} catch ( InvalidArgumentException $e ) {
				$this->notice_invalid_registration( __METHOD__, $e->getMessage() );
				return null;
			}

			if ( $this->is_registered( $type->get_type() ) ) {
				$this->notice_invalid_registration( __METHOD__, sprintf( 'Agent package artifact type "%s" is already registered.', $type->get_type() ) );
				return null;
			}

			$this->registered_types[ $type->get_type() ] = $type;
			return $type;
		}

		/**
		 * Get all registered artifact types.
		 *
		 * @return array<string, WP_Agent_Package_Artifact_Type>
		 */
		public function get_all_registered(): array {
			return $this->registered_types;
		}

		/**
		 * Get a registered artifact type by slug.
		 *
		 * @param string $type Artifact type slug.
		 * @return WP_Agent_Package_Artifact_Type|null Artifact type, or null when not registered.
		 */
		public function get_registered( string $type ): ?WP_Agent_Package_Artifact_Type {
			try {
				$type = WP_Agent_Package_Artifact::prepare_type( $type );
			} catch ( InvalidArgumentException $e ) {
				$this->notice_invalid_registration( __METHOD__, $e->getMessage() );
				return null;
			}

			if ( ! $this->is_registered( $type ) ) {
				$this->notice_invalid_registration( __METHOD__, sprintf( 'Agent package artifact type "%s" not found.', $type ) );
				return null;
			}

			return $this->registered_types[ $type ];
		}

		/**
		 * Check whether an artifact type is registered.
		 *
		 * @param string $type Artifact type slug.
		 * @return bool
		 */
		public function is_registered( string $type ): bool {
			try {
				$type = WP_Agent_Package_Artifact::prepare_type( $type );
			} catch ( InvalidArgumentException $e ) {
				return false;
			}

			return isset( $this->registered_types[ $type ] );
		}

		/**
		 * Unregister an artifact type.
		 *
		 * @param string $type Artifact type slug.
		 * @return WP_Agent_Package_Artifact_Type|null Removed type, or null when not registered.
		 */
		public function unregister( string $type ): ?WP_Agent_Package_Artifact_Type {
			try {
				$type = WP_Agent_Package_Artifact::prepare_type( $type );
			} catch ( InvalidArgumentException $e ) {
				$this->notice_invalid_registration( __METHOD__, $e->getMessage() );
				return null;
			}

			if ( ! $this->is_registered( $type ) ) {
				$this->notice_invalid_registration( __METHOD__, sprintf( 'Agent package artifact type "%s" not found.', $type ) );
				return null;
			}

			$artifact_type = $this->registered_types[ $type ];
			unset( $this->registered_types[ $type ] );

			return $artifact_type;
		}

		/**
		 * Retrieve the registry singleton.
		 *
		 * @return self|null Registry instance, or null when init has not fired.
		 */
		public static function get_instance(): ?self {
			if ( function_exists( 'did_action' ) && ! did_action( 'init' ) ) {
				_doing_it_wrong(
					__METHOD__,
					'Agent package artifact types should not be initialized before the <code>init</code> action has fired.',
					'0.102.8'
				);
				return null;
			}

			if ( null === self::$instance ) {
				self::$instance = new self();

				/**
				 * Fires to let plugins register package artifact types.
				 *
				 * Callbacks should call `wp_register_agent_package_artifact_type()` to
				 * contribute type metadata and lifecycle callbacks. The registry only
				 * collects definitions; consumers decide when to invoke callbacks.
				 */
				do_action( 'wp_agent_package_artifacts_init', self::$instance );
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
			self::$instance = null;
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
				_doing_it_wrong( $function_name, $message, '0.102.8' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong receives a message, not direct output.
			}
		}
	}
}
