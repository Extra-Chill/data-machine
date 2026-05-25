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
		if ( class_exists( '\WP_Agent_Package_Update_Planner' ) ) {
			$plan = \WP_Agent_Package_Update_Planner::plan(
				self::package_artifacts( $installed_artifacts ),
				self::package_artifacts( $current_artifacts ),
				self::package_artifacts( $target_artifacts ),
				$metadata
			);

			return new AgentBundleUpgradePlan( self::bundle_buckets_from_package_plan( $plan ), $metadata );
		}

		$installed = self::index_installed_artifacts( $installed_artifacts );
		$current   = self::index_artifacts( $current_artifacts );
		$target    = self::index_artifacts( $target_artifacts );
		$buckets   = array(
			'auto_apply'     => array(),
			'needs_approval' => array(),
			'warnings'       => array(),
			'no_op'          => array(),
		);

		foreach ( $target as $key => $target_artifact ) {
			$current_artifact   = $current[ $key ] ?? null;
			$installed_artifact = $installed[ $key ] ?? null;
			$current_hash       = self::artifact_hash( $current_artifact );
			$target_hash        = self::artifact_hash( $target_artifact );
			$installed_hash     = isset( $installed_artifact['installed_hash'] ) ? (string) $installed_artifact['installed_hash'] : null;

			if ( null !== $current_hash && hash_equals( $target_hash, $current_hash ) ) {
				$buckets['no_op'][] = self::entry( $key, $target_artifact, $installed_hash, $current_hash, $target_hash, 'already_matches_target', $current_artifact );
				continue;
			}

			if ( null === $current_hash && null !== $installed_hash ) {
				$buckets['warnings'][] = self::entry( $key, $target_artifact, $installed_hash, null, $target_hash, 'missing_local_artifact', $current_artifact );
				continue;
			}

			if ( null === $installed_hash ) {
				$bucket               = null === $current_hash ? 'auto_apply' : 'needs_approval';
				$reason               = null === $current_hash ? 'new_artifact' : 'untracked_local_artifact';
				$buckets[ $bucket ][] = self::entry( $key, $target_artifact, null, $current_hash, $target_hash, $reason, $current_artifact );
				continue;
			}

			if ( null !== $current_hash && hash_equals( $installed_hash, $current_hash ) ) {
				$buckets['auto_apply'][] = self::entry( $key, $target_artifact, $installed_hash, $current_hash, $target_hash, 'local_unchanged_from_installed', $current_artifact );
				continue;
			}

			$buckets['needs_approval'][] = self::entry( $key, $target_artifact, $installed_hash, $current_hash, $target_hash, 'local_modified', $current_artifact );
		}

		foreach ( $installed as $key => $installed_artifact ) {
			if ( isset( $target[ $key ] ) ) {
				continue;
			}
			$buckets['warnings'][] = array(
				'artifact_key'  => $key,
				'artifact_type' => $installed_artifact['artifact_type'],
				'artifact_id'   => $installed_artifact['artifact_id'],
				'source_path'   => $installed_artifact['source_path'],
				'reason'        => 'orphaned_installed_artifact',
				'summary'       => sprintf( '%s %s is not present in the target bundle.', $installed_artifact['artifact_type'], $installed_artifact['artifact_id'] ),
			);
		}

		return new AgentBundleUpgradePlan( $buckets, $metadata );
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
			$artifacts[] = array(
				'artifact_type' => 'pipeline',
				'artifact_id'   => $pipeline->slug(),
				'source_path'   => BundleSchema::PIPELINES_DIR . '/' . $pipeline->slug() . '.json',
				'payload'       => $pipeline->to_array(),
			);
		}

		foreach ( $bundle->flows() as $flow ) {
			$artifacts[] = array(
				'artifact_type' => 'flow',
				'artifact_id'   => $flow->slug(),
				'source_path'   => BundleSchema::FLOWS_DIR . '/' . $flow->slug() . '.json',
				'payload'       => $flow->to_array(),
			);
		}

		foreach ( self::materialized_artifact_directories() as $directory => $type ) {
			$method = str_replace( '-', '_', $directory );
			foreach ( $bundle->{$method}() as $relative_path => $payload ) {
				$artifacts[] = array(
					'artifact_type' => $type,
					'artifact_id'   => self::artifact_id_from_payload( $payload, (string) $relative_path ),
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

	/** @return array<string,string> */
	private static function materialized_artifact_directories(): array {
		return array(
			BundleSchema::PROMPTS_DIR       => 'prompt',
			BundleSchema::RUBRICS_DIR       => 'rubric',
			BundleSchema::TOOL_POLICIES_DIR => 'tool_policy',
			BundleSchema::AUTH_REFS_DIR     => 'auth_ref',
			BundleSchema::SEED_QUEUES_DIR   => 'seed_queue',
		);
	}

	private static function artifact_id_from_relative_path( string $relative_path ): string {
		$relative_path = preg_replace( '/\.(json|md|txt)$/i', '', $relative_path );
		return null === $relative_path ? '' : $relative_path;
	}

	private static function artifact_id_from_payload( mixed $payload, string $relative_path ): string {
		if ( is_array( $payload ) && is_string( $payload['artifact_id'] ?? null ) && '' !== trim( $payload['artifact_id'] ) ) {
			return (string) $payload['artifact_id'];
		}

		return self::artifact_id_from_relative_path( $relative_path );
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
			if ( ! is_array( $row ) ) {
				continue;
			}

			$artifact_type = (string) ( $row['artifact_type'] ?? '' );
			$artifact_id   = (string) ( $row['artifact_id'] ?? '' );
			if ( '' === $artifact_type || '' === $artifact_id ) {
				continue;
			}

			$converted[] = array_merge(
				$row,
				array(
					'artifact_type' => self::package_artifact_type( $artifact_type ),
					'artifact_id'   => $artifact_id,
					'source'        => (string) ( $row['source_path'] ?? ( $row['source'] ?? '' ) ),
				)
			);
		}

		return $converted;
	}

	private static function package_artifact_type( string $type ): string {
		if ( str_contains( $type, '/' ) ) {
			return $type;
		}

		return 'datamachine/' . str_replace( '_', '-', $type );
	}

	private static function bundle_artifact_type( string $type ): string {
		$type = str_starts_with( $type, 'datamachine/' ) ? substr( $type, strlen( 'datamachine/' ) ) : $type;

		return str_replace( '-', '_', $type );
	}

	private static function bundle_buckets_from_package_plan( \WP_Agent_Package_Update_Plan $plan ): array {
		$buckets = array();
		foreach ( $plan->get_buckets() as $bucket => $entries ) {
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

	/** @param array<int,array<string,mixed>|AgentBundleInstalledArtifact> $artifacts */
	private static function index_installed_artifacts( array $artifacts ): array {
		$indexed = array();
		foreach ( $artifacts as $artifact ) {
			$row             = $artifact instanceof AgentBundleInstalledArtifact ? $artifact->to_array() : $artifact;
			$key             = self::artifact_key( (string) ( $row['artifact_type'] ?? '' ), (string) ( $row['artifact_id'] ?? '' ) );
			$indexed[ $key ] = $row;
		}

		ksort( $indexed, SORT_STRING );
		return $indexed;
	}

	/** @param array<int,array<string,mixed>> $artifacts */
	private static function index_artifacts( array $artifacts ): array {
		$indexed = array();
		foreach ( $artifacts as $artifact ) {
			$key             = self::artifact_key( (string) ( $artifact['artifact_type'] ?? '' ), (string) ( $artifact['artifact_id'] ?? '' ) );
			$indexed[ $key ] = $artifact;
		}

		ksort( $indexed, SORT_STRING );
		return $indexed;
	}

	private static function entry( string $key, array $target, ?string $installed_hash, ?string $current_hash, string $target_hash, string $reason, ?array $current ): array {
		return array(
			'artifact_key'   => $key,
			'artifact_type'  => (string) $target['artifact_type'],
			'artifact_id'    => (string) $target['artifact_id'],
			'source_path'    => (string) ( $target['source_path'] ?? '' ),
			'reason'         => $reason,
			'installed_hash' => $installed_hash,
			'current_hash'   => $current_hash,
			'target_hash'    => $target_hash,
			'summary'        => self::summary( $target, $reason ),
			'diff'           => array(
				'before' => self::redact( $current['payload'] ?? null ),
				'after'  => self::redact( $target['payload'] ?? null ),
			),
		);
	}

	private static function artifact_key( string $type, string $id ): string {
		return AgentBundleArtifactExtensions::artifact_key( $type, $id );
	}

	private static function artifact_hash( ?array $artifact ): ?string {
		if ( null === $artifact ) {
			return null;
		}
		if ( isset( $artifact['hash'] ) && '' !== trim( (string) $artifact['hash'] ) ) {
			return (string) $artifact['hash'];
		}
		if ( ! array_key_exists( 'payload', $artifact ) || null === $artifact['payload'] ) {
			return null;
		}
		return AgentBundleArtifactHasher::hash( $artifact['payload'] );
	}

	private static function summary( array $artifact, string $reason ): string {
		return sprintf( '%s %s: %s', (string) $artifact['artifact_type'], (string) $artifact['artifact_id'], str_replace( '_', ' ', $reason ) );
	}

	private static function redact( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$redacted = array();
		foreach ( $value as $key => $child ) {
			$key_string = (string) $key;
			if ( preg_match( '/(secret|token|password|api[_-]?key|authorization|credential)/i', $key_string ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}
			$redacted[ $key ] = self::redact( $child );
		}

		return $redacted;
	}
}
