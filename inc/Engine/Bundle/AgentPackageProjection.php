<?php
/**
 * Data Machine package projection helpers.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Projects Data Machine bundle documents into Core-shaped agent packages.
 */
final class AgentPackageProjection {

	/**
	 * Build a package from a bundle directory value object.
	 *
	 * @param AgentBundleDirectory $directory Bundle directory.
	 * @return object
	 */
	public static function from_directory( AgentBundleDirectory $directory ): object {
		$manifest      = $directory->manifest()->to_array();
		$package_class = 'WP_Agent_Package';

		return $package_class::from_array(
			array(
				'slug'         => (string) $manifest['bundle_slug'],
				'version'      => (string) $manifest['bundle_version'],
				'agent'        => self::agent_from_manifest( $manifest ),
				'capabilities' => self::string_list( is_array( $manifest['capabilities'] ?? null ) ? $manifest['capabilities'] : array() ),
				'artifacts'    => self::artifacts_from_directory( $directory ),
				'meta'         => self::meta_from_manifest( $manifest ),
			)
		);
	}

	/**
	 * Build a package from a bundle array.
	 *
	 * @param array<string,mixed> $bundle Legacy bundle array.
	 * @return object
	 */
	public static function from_array_bundle( array $bundle ): object {
		return self::from_directory( AgentBundleArrayAdapter::from_array_bundle( $bundle ) );
	}

	/**
	 * Build agent package metadata from a bundle manifest.
	 *
	 * @param array<string,mixed> $manifest Bundle manifest.
	 * @return array<string,mixed>
	 */
	private static function agent_from_manifest( array $manifest ): array {
		$agent = is_array( $manifest['agent'] ?? null ) ? $manifest['agent'] : array();

		return array(
			'slug'           => (string) ( $agent['slug'] ?? '' ),
			'label'          => (string) ( $agent['label'] ?? ( $agent['slug'] ?? '' ) ),
			'description'    => (string) ( $agent['description'] ?? '' ),
			'default_config' => is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array(),
			'meta'           => array(
				'package_source' => 'data-machine',
			),
		);
	}

	/**
	 * Build package metadata from a bundle manifest.
	 *
	 * @param array<string,mixed> $manifest Bundle manifest.
	 * @return array<string,mixed>
	 */
	private static function meta_from_manifest( array $manifest ): array {
		return array(
			'schema_version'  => (int) ( $manifest['schema_version'] ?? BundleSchema::VERSION ),
			'exported_at'     => (string) ( $manifest['exported_at'] ?? '' ),
			'exported_by'     => (string) ( $manifest['exported_by'] ?? '' ),
			'source_ref'      => (string) ( $manifest['source_ref'] ?? '' ),
			'source_revision' => (string) ( $manifest['source_revision'] ?? '' ),
			'included'        => is_array( $manifest['included'] ?? null ) ? $manifest['included'] : array(),
			'materializer'    => 'data-machine',
		);
	}

	/**
	 * Build typed artifact declarations from Data Machine bundle documents.
	 *
	 * @param AgentBundleDirectory $directory Bundle directory.
	 * @return array<int,array<string,mixed>>
	 */
	private static function artifacts_from_directory( AgentBundleDirectory $directory ): array {
		$artifacts = array();

		foreach ( $directory->pipelines() as $pipeline ) {
			$document    = $pipeline->to_array();
			$payload     = AgentBundleArtifactPayloads::pipeline_document_payload( $document, (string) $document['slug'] );
			$artifacts[] = self::artifact(
				'datamachine/pipeline',
				(string) $document['slug'],
				(string) $document['name'],
				BundleSchema::PIPELINES_DIR . '/' . $document['slug'] . '.json',
				array( 'step_count' => count( is_array( $document['steps'] ?? null ) ? $document['steps'] : array() ) ),
				array(),
				$payload
			);
		}

		foreach ( $directory->flows() as $flow ) {
			$document      = $flow->to_array();
			$payload       = AgentBundleArtifactPayloads::flow_document_payload( $document, (string) $document['slug'] );
			$run_artifacts = BundleSchema::normalize_run_artifact_egress_policy( $document['run_artifacts'] ?? array() );
			$meta          = array(
				'pipeline_slug' => (string) ( $document['pipeline_slug'] ?? '' ),
				'step_count'    => count( is_array( $document['steps'] ?? null ) ? $document['steps'] : array() ),
			);
			if ( ! empty( $run_artifacts ) ) {
				$meta['run_artifacts'] = $run_artifacts;
			}
			$artifacts[] = self::artifact(
				'datamachine/flow',
				(string) $document['slug'],
				(string) $document['name'],
				BundleSchema::FLOWS_DIR . '/' . $document['slug'] . '.json',
				$meta,
				array(),
				$payload
			);
		}

		foreach ( AgentBundleArtifactDefinitions::file_artifacts() as $directory_name => $definition ) {
			$files = AgentBundleArtifactDefinitions::files_from_directory( $directory, $directory_name );
			foreach ( $files as $relative_path => $payload ) {
				$slug        = AgentBundleArtifactDefinitions::artifact_id_from_payload( $payload, (string) $relative_path );
				$artifacts[] = self::artifact(
					$definition['package_type'],
					$slug,
					$slug,
					$directory_name . '/' . ltrim( (string) $relative_path, '/' ),
					array( 'payload_kind' => is_array( $payload ) ? 'json' : 'text' ),
					array(),
					$payload
				);
			}
		}

		foreach ( $directory->extension_artifacts() as $artifact ) {
			$artifact_id   = (string) ( $artifact['artifact_id'] ?? '' );
			$artifact_type = (string) ( $artifact['artifact_type'] ?? '' );
			$source_path   = (string) ( $artifact['source_path'] ?? '' );
			if ( '' === $artifact_id || '' === $artifact_type || '' === $source_path ) {
				continue;
			}

			$artifacts[] = self::artifact(
				AgentBundleArtifactExtensions::package_artifact_type( $artifact_type ),
				$artifact_id,
				$artifact_id,
				$source_path,
				array(
					'extension_artifact_type' => $artifact_type,
					'payload_kind'            => 'json',
				),
				self::string_list( is_array( $artifact['requires'] ?? null ) ? $artifact['requires'] : array() ),
				$artifact['payload'] ?? null
			);
		}

		return $artifacts;
	}

	/**
	 * Build an artifact declaration.
	 *
	 * @param string              $type   Artifact type.
	 * @param string              $slug   Artifact slug.
	 * @param string              $label  Artifact label.
	 * @param string              $source Source path.
	 * @param array<string,mixed> $meta   Artifact metadata.
	 * @return array<string,mixed>
	 */
	private static function artifact(
		string $type,
		string $slug,
		string $label,
		string $source,
		array $meta = array(),
		array $requires = array(),
		mixed $payload = null
	): array {
		$artifact = array(
			'type'   => $type,
			'slug'   => $slug,
			'label'  => $label,
			'source' => $source,
			'meta'   => array_merge(
				array(
					'materializer' => 'data-machine',
				),
				$meta
			),
		);

		if ( ! empty( $requires ) ) {
			$artifact['requires'] = self::string_list( $requires );
		}

		if ( null !== $payload ) {
			$artifact['checksum'] = AgentBundleArtifactHasher::hash( $payload );
		}

		return $artifact;
	}

	/**
	 * Normalize capability strings for package declarations.
	 *
	 * @param array<int,mixed> $values Raw capability values.
	 * @return array<int,string>
	 */
	private static function string_list( array $values ): array {
		$normalized = array();
		foreach ( $values as $value ) {
			$value = trim( strtolower( (string) $value ) );
			if ( '' !== $value ) {
				$normalized[] = $value;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_STRING );

		return $normalized;
	}
}
