<?php
/**
 * WP_Agent_Package_Artifact_Callbacks helper.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Artifact_Callbacks' ) ) {
	/**
	 * Dispatches artifact lifecycle callbacks registered by artifact type owners.
	 */
	final class WP_Agent_Package_Artifact_Callbacks {

		/**
		 * Runs an artifact type validation callback when registered.
		 *
		 * @param WP_Agent_Package_Artifact $artifact Artifact declaration.
		 * @param array<string,mixed>       $context Consumer context.
		 * @return mixed|null Callback result, or null when no callback is registered.
		 */
		public static function validate( WP_Agent_Package_Artifact $artifact, array $context = array() ) {
			return self::invoke( $artifact, 'get_validate_callback', $context );
		}

		/**
		 * Runs an artifact type diff callback when registered.
		 *
		 * @param WP_Agent_Package_Artifact $artifact Artifact declaration.
		 * @param array<string,mixed>       $context Consumer context.
		 * @return mixed|null Callback result, or null when no callback is registered.
		 */
		public static function diff( WP_Agent_Package_Artifact $artifact, array $context = array() ) {
			return self::invoke( $artifact, 'get_diff_callback', $context );
		}

		/**
		 * Runs an artifact type import callback when registered.
		 *
		 * @param WP_Agent_Package_Artifact $artifact Artifact declaration.
		 * @param array<string,mixed>       $context Consumer context.
		 * @return mixed|null Callback result, or null when no callback is registered.
		 */
		public static function import( WP_Agent_Package_Artifact $artifact, array $context = array() ) {
			return self::invoke( $artifact, 'get_import_callback', $context );
		}

		/**
		 * Runs an artifact type delete callback when registered.
		 *
		 * @param WP_Agent_Package_Artifact $artifact Artifact declaration.
		 * @param array<string,mixed>       $context Consumer context.
		 * @return mixed|null Callback result, or null when no callback is registered.
		 */
		public static function delete( WP_Agent_Package_Artifact $artifact, array $context = array() ) {
			return self::invoke( $artifact, 'get_delete_callback', $context );
		}

		/**
		 * @param array<string,mixed> $context Consumer context.
		 * @return mixed|null Callback result, or null when no callback is registered.
		 */
		private static function invoke( WP_Agent_Package_Artifact $artifact, string $getter, array $context ): mixed {
			$type = wp_get_agent_package_artifact_type( $artifact->get_type() );
			if ( null === $type || ! method_exists( $type, $getter ) ) {
				return null;
			}

			$callback = $type->{$getter}();
			if ( ! is_callable( $callback ) ) {
				return null;
			}

			return call_user_func( $callback, $artifact, $context );
		}

		/**
		 * Prevents construction.
		 */
		private function __construct() {}
	}
}
