<?php
/**
 * Read-only agent bundle upgrade planner.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Compares installed, current, and target artifact state without mutating storage.
 */
final class AgentBundleUpgradePlanner {

	/**
	 * Plan an upgrade from plain artifact arrays.
	 *
	 * Artifact arrays accept: artifact_type, artifact_id, source_path, payload, hash.
	 * Installed artifacts may also be AgentBundleInstalledArtifact instances.
	 *
	 * @param array<int,array<string,mixed>|AgentBundleInstalledArtifact> $installed_artifacts Install-time tracking rows.
	 * @param array<int,array<string,mixed>>                              $current_artifacts Current local artifact state.
	 * @param array<int,array<string,mixed>>                              $target_artifacts Target bundle artifact state.
	 * @param array<string,mixed>                                         $metadata Optional plan metadata.
	 */
	public static function plan( array $installed_artifacts, array $current_artifacts, array $target_artifacts, array $metadata = array() ): AgentBundleUpgradePlan {
		$planner_class = 'WP_Agent_Package_Update_Planner';
		$plan          = $planner_class::plan(
			self::package_artifacts( $installed_artifacts ),
			self::package_artifacts( $current_artifacts ),
			self::package_artifacts( $target_artifacts ),
			$metadata
		);

		return new AgentBundleUpgradePlan( self::bundle_buckets_from_package_plan( $plan ), $metadata );
	}

	/** @return array<int,array<string,mixed>> */
	public static function artifacts_from_bundle( AgentBundleDirectory $bundle ): array {
		$artifacts   = array();
		$manifest    = $bundle->manifest();
		$artifacts[] = array(
			'artifact_type' => 'agent',
			'artifact_id'   => $manifest->agent_slug(),
			'source_path'   => BundleSchema::MANIFEST_FILE,
			'payload'       => $manifest->to_array()['agent'],
		);

		foreach ( $bundle->memory_files() as $relative_path => $contents ) {
			$artifacts[] = array(
				'artifact_type' => 'memory',
				'artifact_id'   => $relative_path,
				'source_path'   => BundleSchema::MEMORY_DIR . '/' . $relative_path,
				'payload'       => $contents,
			);
		}

		foreach ( $bundle->pipelines() as $pipeline ) {
			$document    = $pipeline->to_array();
			$artifacts[] = array(
				'artifact_type' => 'pipeline',
				'artifact_id'   => $pipeline->slug(),
				'source_path'   => BundleSchema::PIPELINES_DIR . '/' . $pipeline->slug() . '.json',
				'payload'       => AgentBundleArtifactPayloads::pipeline_document_payload( $document, $pipeline->slug() ),
			);
		}

		foreach ( $bundle->flows() as $flow ) {
			$document    = $flow->to_array();
			$artifacts[] = array(
				'artifact_type' => 'flow',
				'artifact_id'   => $flow->slug(),
				'source_path'   => BundleSchema::FLOWS_DIR . '/' . $flow->slug() . '.json',
				'payload'       => AgentBundleArtifactPayloads::flow_document_payload( $document, $flow->slug() ),
			);
		}

		foreach ( AgentBundleArtifactDefinitions::file_artifacts() as $directory => $definition ) {
			$method = str_replace( '-', '_', $directory );
			foreach ( $bundle->{$method}() as $relative_path => $payload ) {
				$artifacts[] = array(
					'artifact_type' => $definition['artifact_type'],
					'artifact_id'   => AgentBundleArtifactDefinitions::artifact_id_from_payload( $payload, (string) $relative_path ),
					'source_path'   => $directory . '/' . $relative_path,
					'payload'       => $payload,
				);
			}
		}

		foreach ( $bundle->extension_artifacts() as $artifact ) {
			$artifacts[] = $artifact;
		}

		return $artifacts;
	}

	/**
	 * Convert Data Machine artifact rows to Agents API package artifact rows.
	 *
	 * @param array<int,array<string,mixed>|AgentBundleInstalledArtifact> $artifacts Bundle artifacts.
	 * @return array<int,array<string,mixed>> Package artifacts.
	 */
	private static function package_artifacts( array $artifacts ): array {
		$converted = array();
		foreach ( $artifacts as $artifact ) {
			$row = $artifact instanceof AgentBundleInstalledArtifact ? $artifact->to_array() : $artifact;

			$artifact_type = (string) ( $row['artifact_type'] ?? '' );
			$artifact_id   = (string) ( $row['artifact_id'] ?? '' );
			if ( '' === $artifact_type || '' === $artifact_id ) {
				continue;
			}

			$converted[] = array_merge(
				$row,
				array(
					'artifact_type' => AgentBundleArtifactDefinitions::package_artifact_type( $artifact_type ),
					'artifact_id'   => $artifact_id,
					'source'        => (string) ( $row['source_path'] ?? ( $row['source'] ?? '' ) ),
				)
			);
		}

		return $converted;
	}

	public static function package_artifact_type( string $type ): string {
		return AgentBundleArtifactDefinitions::package_artifact_type( $type );
	}

	public static function bundle_artifact_type( string $type ): string {
		return AgentBundleArtifactDefinitions::bundle_artifact_type( $type );
	}

	private static function bundle_buckets_from_package_plan( object $plan ): array {
		$buckets = array();
		foreach ( call_user_func( array( $plan, 'get_buckets' ) ) as $bucket => $entries ) {
			$buckets[ $bucket ] = array_map( array( self::class, 'bundle_entry_from_package_entry' ), $entries );
		}

		return $buckets;
	}

	/** @param array<string,mixed> $entry */
	private static function bundle_entry_from_package_entry( array $entry ): array {
		$artifact_type = self::bundle_artifact_type( (string) ( $entry['artifact_type'] ?? '' ) );
		$artifact_id   = (string) ( $entry['artifact_id'] ?? '' );
		$reason        = (string) ( $entry['reason'] ?? '' );

		$converted = array_merge(
			$entry,
			array(
				'artifact_key'  => AgentBundleArtifactExtensions::artifact_key( $artifact_type, $artifact_id ),
				'artifact_type' => $artifact_type,
				'artifact_id'   => $artifact_id,
				'source_path'   => (string) ( $entry['source'] ?? ( $entry['source_path'] ?? '' ) ),
				'summary'       => sprintf( '%s %s: %s', $artifact_type, $artifact_id, str_replace( '_', ' ', $reason ) ),
			)
		);

		unset( $converted['source'] );

		return $converted;
	}
}
