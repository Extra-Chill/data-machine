<?php
/**
 * WP_Agent_Package_Artifact_Hasher helper.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Artifact_Hasher' ) ) {
	/**
	 * Produces deterministic hashes for package artifact payloads.
	 */
	final class WP_Agent_Package_Artifact_Hasher {

		/**
		 * Hash a JSON-friendly artifact payload.
		 *
		 * Associative array key order and pretty-print formatting do not affect
		 * hashes. List order remains significant.
		 *
		 * @param mixed $payload Artifact payload.
		 * @return string SHA-256 hash.
		 */
		public static function hash( $payload ): string {
			return hash( 'sha256', self::normalize_payload( $payload ) );
		}

		/**
		 * Normalizes a payload to a deterministic string.
		 *
		 * @param mixed $payload Artifact payload.
		 * @return string
		 */
		public static function normalize_payload( $payload ): string {
			if ( is_string( $payload ) ) {
				return $payload;
			}

			$encoded = wp_json_encode( self::sort_recursive( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $encoded ) ) {
				throw new InvalidArgumentException( 'Agent package artifact payload must be JSON serializable.' );
			}

			return $encoded;
		}

		/**
		 * Recursively sorts associative arrays while preserving list order.
		 *
		 * @param mixed $value Raw value.
		 * @return mixed
		 */
		private static function sort_recursive( $value ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			$sorted = array();
			foreach ( $value as $key => $child ) {
				$sorted[ $key ] = self::sort_recursive( $child );
			}

			if ( ! array_is_list( $sorted ) ) {
				ksort( $sorted, SORT_STRING );
			}

			return $sorted;
		}

		/**
		 * Prevents construction.
		 */
		private function __construct() {}
	}
}
