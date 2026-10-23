<?php
/**
 * Agent context injection policy vocabulary.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Context_Injection_Policy' ) ) {
	/**
	 * Canonical retrieval policy values for memory and context sources.
	 */
	final class WP_Agent_Context_Injection_Policy {

		public const ALWAYS       = 'always';
		public const ON_INTENT    = 'on_intent';
		public const ON_TOOL_NEED = 'on_tool_need';
		public const MANUAL       = 'manual';
		public const NEVER        = 'never';

		/**
		 * Return the supported policy vocabulary.
		 *
		 * @return string[]
		 */
		public static function values(): array {
			return array(
				self::ALWAYS,
				self::ON_INTENT,
				self::ON_TOOL_NEED,
				self::MANUAL,
				self::NEVER,
			);
		}

		/**
		 * Normalize a raw policy string to a known vocabulary value.
		 *
		 * @param mixed  $policy  Raw policy value.
		 * @param string $fallback_policy Default policy when the raw value is invalid.
		 * @return string
		 */
		public static function normalize( $policy, string $fallback_policy = self::ALWAYS ): string {
			$fallback_policy = in_array( $fallback_policy, self::values(), true ) ? $fallback_policy : self::ALWAYS;
			$policy          = self::sanitize_slug( is_string( $policy ) ? $policy : '' );

			return in_array( $policy, self::values(), true ) ? $policy : $fallback_policy;
		}

		/**
		 * Whether a policy should be injected without runtime retrieval decisions.
		 *
		 * @param string $policy Policy value.
		 * @return bool
		 */
		public static function is_always_injected( string $policy ): bool {
			return self::ALWAYS === self::normalize( $policy );
		}

		/**
		 * Whether a policy can be retrieved by a dynamic retrieval layer later.
		 *
		 * @param string $policy Policy value.
		 * @return bool
		 */
		public static function is_retrievable( string $policy ): bool {
			return in_array(
				self::normalize( $policy ),
				array( self::ALWAYS, self::ON_INTENT, self::ON_TOOL_NEED, self::MANUAL ),
				true
			);
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
