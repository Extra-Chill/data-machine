<?php
/**
 * WP_Agent_Package_Artifact_Status vocabulary.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Artifact_Status' ) ) {
	/**
	 * Status values for package-installed artifact drift.
	 */
	final class WP_Agent_Package_Artifact_Status {

		public const CLEAN    = 'clean';
		public const MODIFIED = 'modified';
		public const MISSING  = 'missing';
		public const ORPHANED = 'orphaned';

		/**
		 * Classifies installed/current hash state.
		 *
		 * @param string|null $installed_hash Install-time artifact hash.
		 * @param string|null $current_hash Current artifact hash.
		 * @return string Status value.
		 */
		public static function classify( ?string $installed_hash, ?string $current_hash ): string {
			$installed_hash = self::normalize_hash( $installed_hash );
			$current_hash   = self::normalize_hash( $current_hash );

			if ( null === $installed_hash && null === $current_hash ) {
				return self::MISSING;
			}

			if ( null === $installed_hash ) {
				return self::ORPHANED;
			}

			if ( null === $current_hash ) {
				return self::MISSING;
			}

			return hash_equals( $installed_hash, $current_hash ) ? self::CLEAN : self::MODIFIED;
		}

		/**
		 * Checks whether a status is valid.
		 *
		 * @param string $status Candidate status.
		 * @return bool
		 */
		public static function is_valid( string $status ): bool {
			return in_array( $status, array( self::CLEAN, self::MODIFIED, self::MISSING, self::ORPHANED ), true );
		}

		/**
		 * Prevents construction.
		 */
		private function __construct() {}

		/**
		 * Normalizes empty hashes to null.
		 *
		 * @param string|null $hash Raw hash.
		 * @return string|null
		 */
		private static function normalize_hash( ?string $hash ): ?string {
			$hash = null === $hash ? '' : trim( $hash );
			return '' === $hash ? null : $hash;
		}
	}
}
