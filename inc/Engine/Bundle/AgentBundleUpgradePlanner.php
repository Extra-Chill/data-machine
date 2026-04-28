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

		return $artifacts;
	}

	/** @param array<int,array<string,mixed>|AgentBundleInstalledArtifact> $artifacts */
	private static function index_installed_artifacts( array $artifacts ): array {
		$indexed = array();
		foreach ( $artifacts as $artifact ) {
			$row = $artifact instanceof AgentBundleInstalledArtifact ? $artifact->to_array() : $artifact;
			if ( ! is_array( $row ) ) {
				continue;
			}
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
			if ( ! is_array( $artifact ) ) {
				continue;
			}
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
		return sanitize_key( $type ) . ':' . $id;
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
