<?php
/**
 * WP_Agent_Package_Capability_Checker service.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Capability_Checker' ) ) {
	/**
	 * Compares a package declaration with host-supported capabilities.
	 */
	final class WP_Agent_Package_Capability_Checker {

		/**
		 * Builds a compatibility report for a package and host runtime.
		 *
		 * @param WP_Agent_Package  $package           Package declaration.
		 * @param array<int,string> $host_capabilities Capabilities supported by the host.
		 * @param array<string,mixed> $args             Optional known artifact type overrides.
		 * @return WP_Agent_Package_Capability_Report
		 */
		public static function check( WP_Agent_Package $package, array $host_capabilities, array $args = array() ): WP_Agent_Package_Capability_Report {
			$host_capabilities      = self::normalize_string_list( $host_capabilities );
			$known_artifact_types   = self::known_artifact_types( $args );
			$required_capabilities  = $package->get_capabilities();
			$unknown_artifact_types = array();
			$unsupported_artifacts  = array();

			foreach ( $package->get_artifacts() as $artifact ) {
				$artifact_requires     = $artifact->get_requires();
				$required_capabilities = array_merge( $required_capabilities, $artifact_requires );
				$unsupported_requires  = array_values( array_diff( $artifact_requires, $host_capabilities ) );
				$artifact_type         = $artifact->get_type();
				$unknown_artifact_type = ! in_array( $artifact_type, $known_artifact_types, true );

				if ( $unknown_artifact_type ) {
					$unknown_artifact_types[] = $artifact_type;
				}

				if ( $unknown_artifact_type || ! empty( $unsupported_requires ) ) {
					$unsupported_artifacts[] = array(
						'artifact_key'             => $artifact_type . ':' . $artifact->get_slug(),
						'artifact_type'            => $artifact_type,
						'artifact_slug'            => $artifact->get_slug(),
						'unknown_artifact_type'    => $unknown_artifact_type,
						'unsupported_capabilities' => self::normalize_string_list( $unsupported_requires ),
					);
				}
			}

			$required_capabilities    = self::normalize_string_list( $required_capabilities );
			$unsupported_capabilities = array_values( array_diff( $required_capabilities, $host_capabilities ) );

			return new WP_Agent_Package_Capability_Report(
				$required_capabilities,
				$host_capabilities,
				$unsupported_capabilities,
				$unknown_artifact_types,
				$unsupported_artifacts
			);
		}

		/**
		 * Resolves known artifact types from explicit args or the artifact registry.
		 *
		 * @param array<string,mixed> $args Check arguments.
		 * @return array<int,string>
		 */
		private static function known_artifact_types( array $args ): array {
			if ( isset( $args['known_artifact_types'] ) ) {
				return self::normalize_string_list( is_array( $args['known_artifact_types'] ) ? array_values( $args['known_artifact_types'] ) : array() );
			}

			return self::normalize_string_list( array_keys( wp_get_agent_package_artifact_types() ) );
		}

		/**
		 * Normalizes a capability or type list.
		 *
		 * @param array<int,mixed> $values Raw values.
		 * @return array<int,string>
		 */
		private static function normalize_string_list( array $values ): array {
			$prepared = array();
			foreach ( $values as $value ) {
				$value = is_scalar( $value ) ? trim( strtolower( (string) $value ) ) : '';
				if ( '' !== $value ) {
					$prepared[] = $value;
				}
			}

			$prepared = array_values( array_unique( $prepared ) );
			sort( $prepared );

			return $prepared;
		}
	}
}
