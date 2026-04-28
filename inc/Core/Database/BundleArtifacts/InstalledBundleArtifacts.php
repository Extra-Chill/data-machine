<?php
/**
 * Installed agent bundle artifact repository.
 *
 * @package DataMachine\Core\Database\BundleArtifacts
 */

namespace DataMachine\Core\Database\BundleArtifacts;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentBundleInstalledArtifact;
use DataMachine\Engine\Bundle\AgentBundleManifest;

defined( 'ABSPATH' ) || exit;

/**
 * Persists bundle-installed artifact hashes independently of runtime tables.
 */
final class InstalledBundleArtifacts extends BaseRepository {

	public const TABLE_NAME = 'datamachine_bundle_artifacts';

	/**
	 * Create installed bundle artifact tracking table.
	 *
	 * Safe to call during activation or deploy-time migrations; dbDelta is idempotent.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			artifact_record_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			bundle_slug VARCHAR(200) NOT NULL,
			bundle_version VARCHAR(100) NOT NULL,
			artifact_type VARCHAR(50) NOT NULL,
			artifact_id VARCHAR(255) NOT NULL,
			source_path VARCHAR(500) NOT NULL,
			installed_hash CHAR(64) NULL DEFAULT NULL,
			current_hash CHAR(64) NULL DEFAULT NULL,
			local_status VARCHAR(20) NOT NULL DEFAULT 'clean',
			installed_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (artifact_record_id),
			UNIQUE KEY artifact_identity (agent_id, bundle_slug, artifact_type, artifact_id),
			KEY bundle_slug (bundle_slug),
			KEY artifact_type (artifact_type),
			KEY local_status (local_status)
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/**
	 * Persist or replace an installed artifact tracking row.
	 *
	 * @param AgentBundleInstalledArtifact $artifact Installed artifact value object.
	 * @param int                          $agent_id Optional owning agent ID; 0 means global/unscoped.
	 * @return bool
	 */
	public function upsert( AgentBundleInstalledArtifact $artifact, int $agent_id = 0 ): bool {
		$artifact_row = $artifact->to_array();
		$row          = array(
			'agent_id'       => max( 0, $agent_id ),
			'bundle_slug'    => $artifact_row['bundle_slug'],
			'bundle_version' => $artifact_row['bundle_version'],
			'artifact_type'  => $artifact_row['artifact_type'],
			'artifact_id'    => $artifact_row['artifact_id'],
			'source_path'    => $artifact_row['source_path'],
			'installed_hash' => '' !== (string) $artifact_row['installed_hash'] ? $artifact_row['installed_hash'] : null,
			'current_hash'   => '' !== (string) $artifact_row['current_hash'] ? $artifact_row['current_hash'] : null,
			'local_status'   => $artifact_row['status'],
			'installed_at'   => $artifact_row['installed_at'],
			'updated_at'     => $artifact_row['updated_at'],
		);
		$record_id    = $this->existing_record_id( $row );
		$format       = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( null !== $record_id ) {
			$row        = array_merge( array( 'artifact_record_id' => $record_id ), $row );
			$format     = array_merge( array( '%d' ), $format );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->replace( $this->table_name, $row, $format );
		if ( false === $result ) {
			$this->log_db_error( 'upsert installed bundle artifact', $this->safe_log_context( $row ) );
			return false;
		}

		return true;
	}

	/**
	 * Record install-time state from a bundle artifact payload.
	 *
	 * @param AgentBundleManifest $manifest Manifest that owns the artifact.
	 * @param string              $artifact_type Artifact type.
	 * @param string              $artifact_id Stable artifact identifier.
	 * @param string              $source_path Bundle-local source path/key.
	 * @param mixed               $artifact_payload Install-time payload.
	 * @param int                 $agent_id Optional owning agent ID; 0 means global/unscoped.
	 * @param string|null         $timestamp Optional UTC timestamp; defaults to current time.
	 * @return AgentBundleInstalledArtifact
	 */
	public function record_install( AgentBundleManifest $manifest, string $artifact_type, string $artifact_id, string $source_path, mixed $artifact_payload, int $agent_id = 0, ?string $timestamp = null ): AgentBundleInstalledArtifact {
		$timestamp = null !== $timestamp ? $timestamp : gmdate( 'Y-m-d H:i:s' );
		$artifact  = AgentBundleInstalledArtifact::from_installed_payload( $manifest, $artifact_type, $artifact_id, $source_path, $artifact_payload, $timestamp );
		$this->upsert( $artifact, $agent_id );

		return $artifact;
	}

	/**
	 * Find one tracked artifact.
	 */
	public function get( string $bundle_slug, string $artifact_type, string $artifact_id, int $agent_id = 0 ): ?AgentBundleInstalledArtifact {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d AND bundle_slug = %s AND artifact_type = %s AND artifact_id = %s LIMIT 1',
				$this->table_name,
				max( 0, $agent_id ),
				$bundle_slug,
				$artifact_type,
				$artifact_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return $row ? $this->artifact_from_row( $row ) : null;
	}

	/**
	 * List artifacts for one installed bundle.
	 *
	 * @return AgentBundleInstalledArtifact[]
	 */
	public function list_for_bundle( string $bundle_slug, int $agent_id = 0 ): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d AND bundle_slug = %s ORDER BY artifact_type ASC, artifact_id ASC',
				$this->table_name,
				max( 0, $agent_id ),
				$bundle_slug
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'artifact_from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * List artifacts for one agent across bundles.
	 *
	 * @return AgentBundleInstalledArtifact[]
	 */
	public function list_for_agent( int $agent_id ): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d ORDER BY bundle_slug ASC, artifact_type ASC, artifact_id ASC',
				$this->table_name,
				max( 0, $agent_id )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'artifact_from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Refresh current hash/status for an existing tracked artifact.
	 */
	public function refresh_current_payload( string $bundle_slug, string $artifact_type, string $artifact_id, mixed $current_payload, int $agent_id = 0, ?string $timestamp = null ): ?AgentBundleInstalledArtifact {
		$existing = $this->get( $bundle_slug, $artifact_type, $artifact_id, $agent_id );
		if ( null === $existing ) {
			return null;
		}

		$updated = $existing->with_current_payload( $current_payload, null !== $timestamp ? $timestamp : gmdate( 'Y-m-d H:i:s' ) );
		$this->upsert( $updated, $agent_id );

		return $updated;
	}

	/**
	 * Build an orphaned artifact record for runtime state that lacks install metadata.
	 */
	public static function orphaned_artifact( string $bundle_slug, string $bundle_version, string $artifact_type, string $artifact_id, string $source_path, mixed $current_payload, string $timestamp ): AgentBundleInstalledArtifact {
		return AgentBundleInstalledArtifact::from_array(
			array(
				'bundle_slug'    => $bundle_slug,
				'bundle_version' => $bundle_version,
				'artifact_type'  => $artifact_type,
				'artifact_id'    => $artifact_id,
				'source_path'    => $source_path,
				'installed_hash' => '',
				'current_hash'   => AgentBundleArtifactHasher::hash( $current_payload ),
				'installed_at'   => $timestamp,
				'updated_at'     => $timestamp,
			)
		);
	}

	/**
	 * Delete a tracked artifact row.
	 */
	public function delete( string $bundle_slug, string $artifact_type, string $artifact_id, int $agent_id = 0 ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table_name,
			array(
				'agent_id'       => max( 0, $agent_id ),
				'bundle_slug'    => $bundle_slug,
				'artifact_type'  => $artifact_type,
				'artifact_id'    => $artifact_id,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	private function artifact_from_row( array $row ): AgentBundleInstalledArtifact {
		return AgentBundleInstalledArtifact::from_array(
			array(
				'bundle_slug'    => (string) $row['bundle_slug'],
				'bundle_version' => (string) $row['bundle_version'],
				'artifact_type'  => (string) $row['artifact_type'],
				'artifact_id'    => (string) $row['artifact_id'],
				'source_path'    => (string) $row['source_path'],
				'installed_hash' => (string) $row['installed_hash'],
				'current_hash'   => isset( $row['current_hash'] ) ? (string) $row['current_hash'] : null,
				'installed_at'   => (string) $row['installed_at'],
				'updated_at'     => (string) $row['updated_at'],
			)
		);
	}

	private function existing_record_id( array $row ): ?int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT artifact_record_id FROM %i WHERE agent_id = %d AND bundle_slug = %s AND artifact_type = %s AND artifact_id = %s LIMIT 1',
				$this->table_name,
				(int) $row['agent_id'],
				(string) $row['bundle_slug'],
				(string) $row['artifact_type'],
				(string) $row['artifact_id']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return null === $value ? null : (int) $value;
	}

	private function safe_log_context( array $row ): array {
		return array(
			'agent_id'       => (int) $row['agent_id'],
			'bundle_slug'    => (string) $row['bundle_slug'],
			'artifact_type'  => (string) $row['artifact_type'],
			'artifact_id'    => (string) $row['artifact_id'],
			'local_status'   => AgentBundleArtifactStatus::classify( (string) $row['installed_hash'], isset( $row['current_hash'] ) ? (string) $row['current_hash'] : null ),
		);
	}
}
