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

	public const CLEAN    = \WP_Agent_Package_Artifact_Status::CLEAN;
	public const MODIFIED = \WP_Agent_Package_Artifact_Status::MODIFIED;
	public const MISSING  = \WP_Agent_Package_Artifact_Status::MISSING;
	public const ORPHANED = \WP_Agent_Package_Artifact_Status::ORPHANED;

	/**
	 * Classify an artifact by installed and current hash presence.
	 *
	 * @param string|null $installed_hash Hash recorded when the bundle installed the artifact.
	 * @param string|null $current_hash Current runtime artifact hash, or null when missing.
	 * @return string One of clean, modified, missing, orphaned.
	 */
	public static function classify( ?string $installed_hash, ?string $current_hash ): string {
		return \WP_Agent_Package_Artifact_Status::classify( $installed_hash, $current_hash );
	}
}
