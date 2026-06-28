<?php
/**
 * Agent memory layer vocabulary.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Memory_Layer' ) ) {
	/**
	 * Canonical memory/context source layers.
	 */
	final class WP_Agent_Memory_Layer {

		public const WORKSPACE = 'workspace';
		public const AGENT     = 'agent';
		public const USER      = 'user';
		public const NETWORK   = 'network';
		public const SHARED    = 'shared';

		/**
		 * Return supported layer values.
		 *
		 * @return string[]
		 */
		public static function values(): array {
			return array(
				self::WORKSPACE,
				self::AGENT,
				self::USER,
				self::NETWORK,
				self::SHARED,
			);
		}

		/**
		 * Normalize a raw layer string.
		 *
		 * @param mixed  $layer   Raw layer value.
		 * @param string $fallback_layer Default layer when invalid.
		 * @return string
		 */
		public static function normalize( $layer, string $fallback_layer = self::WORKSPACE ): string {
			$fallback_layer = in_array( $fallback_layer, self::values(), true ) ? $fallback_layer : self::WORKSPACE;
			$layer          = self::sanitize_slug( is_string( $layer ) ? $layer : '' );

			return in_array( $layer, self::values(), true ) ? $layer : $fallback_layer;
		}

		/**
		 * Small local sanitizer so the substrate can run in pure-PHP tests.
		 *
		 * @param string $value Raw slug.
		 * @return string
		 */
		private static function sanitize_slug( string $value ): string {
			$value = strtolower( $value );
			$value = preg_replace( '/[^a-z0-9_\-]+/', '_', $value );

			return trim( is_string( $value ) ? $value : '', '_' );
		}
	}
}
