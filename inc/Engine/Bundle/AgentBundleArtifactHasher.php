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
		if ( is_string( $artifact ) ) {
			$normalized = $artifact;
		} elseif ( is_array( $artifact ) ) {
			$normalized = BundleSchema::encode_json( $artifact );
		} else {
			$encoded = wp_json_encode( $artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $encoded ) ) {
				throw new BundleValidationException( 'Unable to encode bundle artifact for hashing.' );
			}
			$normalized = $encoded;
		}

		return hash( 'sha256', $normalized );
	}
}
