<?php
/**
 * Installed bundle artifact status classifier.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies local artifact state against installed bundle metadata.
 */
final class AgentBundleArtifactStatus {

	public const CLEAN    = 'clean';
	public const MODIFIED = 'modified';
	public const MISSING  = 'missing';
	public const ORPHANED = 'orphaned';

	/**
	 * Classify an artifact by installed and current hash presence.
	 *
	 * @param string|null $installed_hash Hash recorded when the bundle installed the artifact.
	 * @param string|null $current_hash Current runtime artifact hash, or null when missing.
	 * @return string One of clean, modified, missing, orphaned.
	 */
	public static function classify( ?string $installed_hash, ?string $current_hash ): string {
		$installed_hash = self::normalize_hash( $installed_hash );
		$current_hash   = self::normalize_hash( $current_hash );

		if ( null === $installed_hash && null !== $current_hash ) {
			return self::ORPHANED;
		}

		if ( null === $installed_hash || null === $current_hash ) {
			return self::MISSING;
		}

		return hash_equals( $installed_hash, $current_hash ) ? self::CLEAN : self::MODIFIED;
	}

	private static function normalize_hash( ?string $hash ): ?string {
		$hash = null === $hash ? '' : trim( $hash );
		return '' === $hash ? null : $hash;
	}
}
