<?php
/**
 * Helpers for bundle-owned file artifacts materialized in reserved directories.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Converts reserved prompt/rubric bundle files into upgrade artifact envelopes.
 */
final class AgentBundleMaterializedArtifacts {

	/** @return array<int,array<string,mixed>> */
	public static function from_array_bundle( array $bundle ): array {
		$artifacts = array();
		foreach (
			array(
				'prompt_artifacts' => array( 'prompt', BundleSchema::PROMPTS_DIR ),
				'rubric_artifacts' => array( 'rubric', BundleSchema::RUBRICS_DIR ),
			) as $bundle_key => $settings
		) {
			$files = is_array( $bundle[ $bundle_key ] ?? null ) ? $bundle[ $bundle_key ] : array();
			foreach ( $files as $relative_path => $payload ) {
				$relative_path = self::normalize_relative_path( (string) $relative_path );
				if ( '' === $relative_path ) {
					continue;
				}

				$artifacts[] = array(
					'artifact_type' => $settings[0],
					'artifact_id'   => self::artifact_id_from_path( $relative_path ),
					'source_path'   => $settings[1] . '/' . $relative_path,
					'payload'       => $payload,
				);
			}
		}

		return $artifacts;
	}

	public static function current_payload_from_record( ?array $record ): mixed {
		if ( ! is_array( $record ) ) {
			return null;
		}
		if ( array_key_exists( 'current_payload', $record ) ) {
			return $record['current_payload'];
		}
		if ( array_key_exists( 'installed_payload', $record ) ) {
			return $record['installed_payload'];
		}
		if ( array_key_exists( 'payload', $record ) ) {
			return $record['payload'];
		}

		return null;
	}

	private static function normalize_relative_path( string $relative_path ): string {
		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );
		if ( '' === $relative_path || str_contains( $relative_path, '..' ) ) {
			return '';
		}

		return $relative_path;
	}

	private static function artifact_id_from_path( string $relative_path ): string {
		$id = preg_replace( '/\.(json|md|txt)$/i', '', $relative_path );
		return '' === (string) $id ? $relative_path : (string) $id;
	}
}
