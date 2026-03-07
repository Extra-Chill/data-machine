<?php
/**
 * Agents Repository
 *
 * First-class agent identity storage for layered architecture migration.
 *
 * @package DataMachine\Core\Database\Agents
 * @since 0.36.1
 */

namespace DataMachine\Core\Database\Agents;

use DataMachine\Core\Database\BaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agents extends BaseRepository {

	/**
	 * Table name (without prefix)
	 */
	const TABLE_NAME = 'datamachine_agents';

	/**
	 * Create agents table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			agent_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_slug VARCHAR(200) NOT NULL,
			agent_name VARCHAR(200) NOT NULL,
			owner_id BIGINT(20) UNSIGNED NOT NULL,
			agent_config LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (agent_id),
			UNIQUE KEY agent_slug (agent_slug),
			KEY owner_id (owner_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get agent by owner ID.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return array|null
	 */
	public function get_by_owner_id( int $owner_id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE owner_id = %d ORDER BY agent_id ASC LIMIT 1',
				$this->table_name,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		if ( ! empty( $row['agent_config'] ) ) {
			$row['agent_config'] = json_decode( $row['agent_config'], true ) ? json_decode( $row['agent_config'], true ) : array();
		}

		return $row;
	}

	/**
	 * Get agent by slug.
	 *
	 * @param string $agent_slug Agent slug.
	 * @return array|null
	 */
	public function get_by_slug( string $agent_slug ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_slug = %s LIMIT 1',
				$this->table_name,
				$agent_slug
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		if ( ! empty( $row['agent_config'] ) ) {
			$row['agent_config'] = json_decode( $row['agent_config'], true ) ? json_decode( $row['agent_config'], true ) : array();
		}

		return $row;
	}

	/**
	 * Update an agent's slug.
	 *
	 * Pure data operation — no validation, no filesystem side effects.
	 *
	 * @since 0.38.0
	 * @param int    $agent_id Agent ID.
	 * @param string $new_slug New slug value.
	 * @return bool True on success, false on DB failure.
	 */
	public function update_slug( int $agent_id, string $new_slug ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->table_name,
			array( 'agent_slug' => $new_slug ),
			array( 'agent_id' => $agent_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get all agents.
	 *
	 * @since 0.38.0
	 * @return array List of agent rows.
	 */
	public function get_all(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i ORDER BY agent_id ASC', $this->table_name ),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			if ( ! empty( $row['agent_config'] ) ) {
				$decoded              = json_decode( $row['agent_config'], true );
				$row['agent_config'] = is_array( $decoded ) ? $decoded : array();
			}
		}

		return $rows;
	}

	/**
	 * Create an agent if slug does not exist.
	 *
	 * @param string $agent_slug Agent slug.
	 * @param string $agent_name Display name.
	 * @param int    $owner_id Owner user ID.
	 * @param array  $agent_config Agent configuration.
	 * @return int Agent ID.
	 */
	public function create_if_missing( string $agent_slug, string $agent_name, int $owner_id, array $agent_config = array() ): int {
		$existing = $this->get_by_slug( $agent_slug );

		if ( $existing ) {
			return (int) $existing['agent_id'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$this->wpdb->insert(
			$this->table_name,
			array(
				'agent_slug'   => $agent_slug,
				'agent_name'   => $agent_name,
				'owner_id'     => $owner_id,
				'agent_config' => wp_json_encode( $agent_config ),
				'status'       => 'active',
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}
}
