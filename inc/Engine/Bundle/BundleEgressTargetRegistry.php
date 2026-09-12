<?php
/**
 * Bundle egress target registry.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Registry for bundle run-artifact egress target vocabulary.
 */
final class BundleEgressTargetRegistry {

	/** @return string[] */
	public static function targets(): array {
		$targets = function_exists( 'apply_filters' )
			? (array) apply_filters( 'datamachine_bundle_run_artifact_egress_targets', BundleSchema::RUN_ARTIFACT_EGRESS_TARGETS )
			: BundleSchema::RUN_ARTIFACT_EGRESS_TARGETS;

		$normalized = array();
		foreach ( $targets as $target ) {
			if ( ! is_string( $target ) ) {
				continue;
			}
			$target = strtolower( trim( $target ) );
			$target = preg_replace( '/[^a-z0-9_-]+/', '-', $target );
			$target = trim( is_string( $target ) ? $target : '', '-' );
			if ( '' !== $target ) {
				$normalized[] = $target;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_STRING );
		return $normalized;
	}
}
