<?php
/**
 * Shared bundle artifact definitions.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Central definition map for first-class bundle artifact files.
 */
final class AgentBundleArtifactDefinitions {

	/**
	 * Bundle file artifact definitions keyed by bundle directory.
	 *
	 * @return array<string,array{artifact_type:string,package_type:string}>
	 */
	public static function file_artifacts(): array {
		return array(
			BundleSchema::PROMPTS_DIR       => array(
				'artifact_type' => 'prompt',
				'package_type'  => 'datamachine/prompt',
			),
			BundleSchema::RUBRICS_DIR       => array(
				'artifact_type' => 'rubric',
				'package_type'  => 'datamachine/rubric',
			),
			BundleSchema::TOOL_POLICIES_DIR => array(
				'artifact_type' => 'tool_policy',
				'package_type'  => 'datamachine/tool-policy',
			),
			BundleSchema::AUTH_REFS_DIR     => array(
				'artifact_type' => 'auth_ref',
				'package_type'  => 'datamachine/auth-ref',
			),
			BundleSchema::SEED_QUEUES_DIR   => array(
				'artifact_type' => 'seed_queue',
				'package_type'  => 'datamachine/queue-seed',
			),
		);
	}

	/** @return string[] */
	public static function file_artifact_directories(): array {
		return array_keys( self::file_artifacts() );
	}

	/** @return string[] */
	public static function file_artifact_types(): array {
		return array_values( array_map( static fn( array $definition ): string => $definition['artifact_type'], self::file_artifacts() ) );
	}

	public static function package_artifact_type( string $type ): string {
		$type = self::normalize_artifact_type( $type );
		if ( str_contains( $type, '/' ) ) {
			return $type;
		}

		foreach ( self::file_artifacts() as $definition ) {
			if ( $type === $definition['artifact_type'] ) {
				return $definition['package_type'];
			}
		}

		return 'datamachine/' . str_replace( '_', '-', $type );
	}

	public static function bundle_artifact_type( string $type ): string {
		if ( str_starts_with( $type, 'datamachine-extension/' ) ) {
			return self::normalize_artifact_type( substr( $type, strlen( 'datamachine-extension/' ) ) );
		}

		foreach ( self::file_artifacts() as $definition ) {
			if ( $type === $definition['package_type'] ) {
				return $definition['artifact_type'];
			}
		}

		$type = self::normalize_artifact_type( $type );
		if ( str_contains( $type, '/' ) && ! str_starts_with( $type, 'datamachine/' ) ) {
			return $type;
		}

		$type = str_starts_with( $type, 'datamachine/' ) ? substr( $type, strlen( 'datamachine/' ) ) : $type;

		return str_replace( '-', '_', $type );
	}

	public static function artifact_id_from_payload( mixed $payload, string $relative_path ): string {
		if ( is_array( $payload ) && is_string( $payload['artifact_id'] ?? null ) && '' !== trim( $payload['artifact_id'] ) ) {
			return (string) $payload['artifact_id'];
		}

		return self::artifact_id_from_relative_path( $relative_path );
	}

	public static function artifact_id_from_relative_path( string $relative_path ): string {
		$relative_path = preg_replace( '/\.(json|md|txt)$/i', '', $relative_path );
		return null === $relative_path ? '' : $relative_path;
	}

	/** @return array<int,array<string,mixed>> */
	public static function file_artifact_rows_from_bundle( array $bundle ): array {
		$artifacts = array();
		$files     = is_array( $bundle['artifact_files'] ?? null ) ? $bundle['artifact_files'] : array();

		foreach ( self::file_artifacts() as $directory => $definition ) {
			foreach ( is_array( $files[ $directory ] ?? null ) ? $files[ $directory ] : array() as $relative_path => $payload ) {
				$artifacts[] = array(
					'artifact_type' => $definition['artifact_type'],
					'artifact_id'   => self::artifact_id_from_payload( $payload, (string) $relative_path ),
					'source_path'   => $directory . '/' . ltrim( (string) $relative_path, '/' ),
					'payload'       => $payload,
				);
			}
		}

		return $artifacts;
	}

	/**
	 * Return the decoded file map for a first-class artifact directory.
	 *
	 * @return array<string,array|string>
	 */
	public static function files_from_directory( AgentBundleDirectory $directory, string $artifact_directory ): array {
		return match ( $artifact_directory ) {
			BundleSchema::PROMPTS_DIR       => $directory->prompts(),
			BundleSchema::RUBRICS_DIR       => $directory->rubrics(),
			BundleSchema::TOOL_POLICIES_DIR => $directory->tool_policies(),
			BundleSchema::AUTH_REFS_DIR     => $directory->auth_refs(),
			BundleSchema::SEED_QUEUES_DIR   => $directory->seed_queues(),
			default                         => array(),
		};
	}

	private static function normalize_artifact_type( string $type ): string {
		$type       = strtolower( trim( str_replace( '\\', '/', $type ) ) );
		$normalized = preg_replace( '/[^a-z0-9_\.\/-]+/', '', $type );
		$normalized = preg_replace( '#/+#', '/', is_string( $normalized ) ? $normalized : '' );

		return trim( is_string( $normalized ) ? $normalized : '', '/' );
	}
}
