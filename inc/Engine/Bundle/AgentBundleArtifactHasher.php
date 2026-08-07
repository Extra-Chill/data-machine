<?php
/**
 * Deterministic hash helper for installed agent bundle artifacts.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Produces stable hashes for bundle artifact state comparisons.
 */
final class AgentBundleArtifactHasher {

	/**
	 * Hash a structured artifact payload.
	 *
	 * @param mixed $artifact Artifact payload.
	 * @return string SHA-256 hash.
	 */
	public static function hash( mixed $artifact ): string {
		return \WP_Agent_Package_Artifact_Hasher::hash( $artifact );
	}
}
