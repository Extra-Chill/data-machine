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
	 * @return \WP_Agent_Package
	 */
	public static function from_directory( AgentBundleDirectory $directory ): \WP_Agent_Package {
		$manifest = $directory->manifest()->to_array();

		return \WP_Agent_Package::from_array(
			array(
				'slug'      => (string) $manifest['bundle_slug'],
				'version'   => (string) $manifest['bundle_version'],
				'agent'     => self::agent_from_manifest( $manifest ),
				'artifacts' => self::artifacts_from_directory( $directory ),
				'meta'      => self::meta_from_manifest( $manifest ),
			)
		);
	}

	/**
	 * Build a package from a legacy bundle array.
	 *
	 * @param array<string,mixed> $bundle Legacy bundle array.
	 * @return \WP_Agent_Package
	 */
	public static function from_legacy_bundle( array $bundle ): \WP_Agent_Package {
		return self::from_directory( AgentBundleLegacyAdapter::from_legacy_bundle( $bundle ) );
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
			$artifacts[] = self::artifact(
				'datamachine/pipeline',
				(string) $document['slug'],
				(string) $document['name'],
				BundleSchema::PIPELINES_DIR . '/' . $document['slug'] . '.json',
				array( 'step_count' => count( is_array( $document['steps'] ?? null ) ? $document['steps'] : array() ) )
			);
		}

		foreach ( $directory->flows() as $flow ) {
			$document    = $flow->to_array();
			$artifacts[] = self::artifact(
				'datamachine/flow',
				(string) $document['slug'],
				(string) $document['name'],
				BundleSchema::FLOWS_DIR . '/' . $document['slug'] . '.json',
				array(
					'pipeline_slug' => (string) ( $document['pipeline_slug'] ?? '' ),
					'step_count'    => count( is_array( $document['steps'] ?? null ) ? $document['steps'] : array() ),
				)
			);
		}

		foreach ( self::artifact_file_maps( $directory ) as $directory_name => $definition ) {
			foreach ( $definition['files'] as $relative_path => $payload ) {
				$slug        = self::slug_from_path( (string) $relative_path );
				$artifacts[] = self::artifact(
					$definition['type'],
					$slug,
					$slug,
					$directory_name . '/' . ltrim( (string) $relative_path, '/' ),
					array( 'payload_kind' => is_array( $payload ) ? 'json' : 'text' )
				);
			}
		}

		return $artifacts;
	}

	/**
	 * Build artifact file directory definitions.
	 *
	 * @param AgentBundleDirectory $directory Bundle directory.
	 * @return array<string,array{type:string,files:array<string,array|string>}>
	 */
	private static function artifact_file_maps( AgentBundleDirectory $directory ): array {
		return array(
			BundleSchema::PROMPTS_DIR       => array(
				'type'  => 'datamachine/prompt',
				'files' => $directory->prompts(),
			),
			BundleSchema::RUBRICS_DIR       => array(
				'type'  => 'datamachine/rubric',
				'files' => $directory->rubrics(),
			),
			BundleSchema::TOOL_POLICIES_DIR => array(
				'type'  => 'datamachine/tool-policy',
				'files' => $directory->tool_policies(),
			),
			BundleSchema::AUTH_REFS_DIR     => array(
				'type'  => 'datamachine/auth-ref',
				'files' => $directory->auth_refs(),
			),
			BundleSchema::SEED_QUEUES_DIR   => array(
				'type'  => 'datamachine/queue-seed',
				'files' => $directory->seed_queues(),
			),
		);
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
	private static function artifact( string $type, string $slug, string $label, string $source, array $meta = array() ): array {
		return array(
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
	}

	/**
	 * Build an artifact slug from a package-relative path.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private static function slug_from_path( string $path ): string {
		$basename = basename( str_replace( '\\', '/', $path ) );
		$slug     = preg_replace( '/\.[^.]+$/', '', $basename );

		return is_string( $slug ) && '' !== $slug ? $slug : $basename;
	}
}
