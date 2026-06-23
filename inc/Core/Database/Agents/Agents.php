<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * Agents Repository
 *
 * First-class agent identity storage for layered architecture migration.
 *
 * @package DataMachine\Core\Database\Agents
 * @since 0.36.1
 */

namespace DataMachine\Core\Database\Agents;

use DataMachine\Core\Agents\AgentConfigFactory;
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
	 * Use network-level prefix so agents are shared across the multisite network.
	 *
	 * @return string
	 */
	protected static function get_table_prefix(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	/**
	 * Create agents table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->base_prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::is_sqlite() && self::database_table_exists( $table_name, $wpdb ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
			agent_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_slug VARCHAR(200) NOT NULL,
			agent_name VARCHAR(200) NOT NULL,
			owner_id BIGINT(20) UNSIGNED NOT NULL,
			site_scope BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			agent_config LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (agent_id),
			UNIQUE KEY agent_slug (agent_slug),
			KEY owner_id (owner_id),
			KEY site_scope (site_scope)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ensure site_scope column exists on existing installs.
	 *
	 * @return void
	 */
	public static function ensure_site_scope_column(): void {
		global $wpdb;

		$table_name = $wpdb->base_prefix . self::TABLE_NAME;

		if ( ! BaseRepository::column_exists( $table_name, 'site_scope', $wpdb ) ) {
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it. Column position
			// is cosmetic — both engines accept the bare ADD COLUMN form.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN site_scope BIGINT(20) UNSIGNED NULL DEFAULT NULL', $table_name ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY site_scope (site_scope)', $table_name ) );
		}
	}

	/**
	 * Get agent by agent ID.
	 *
	 * @since 0.41.0
	 * @param int $agent_id Agent ID.
	 * @return array|null Agent row or null if not found.
	 */
	public function get_agent( int $agent_id ): ?array {
		if ( $agent_id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d',
				$this->table_name,
				$agent_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return null;
		}

		$row['agent_config'] = self::decode_agent_config( $row['agent_config'] ?? null );

		return $row;
	}

	/**
	 * Get agent by owner ID.
	 *
	 * Returns the first agent owned by the user (oldest by agent_id). For
	 * multi-agent contexts, prefer {@see self::get_all_by_owner_id()}.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return array|null
	 */
	public function get_by_owner_id( int $owner_id ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE owner_id = %d ORDER BY agent_id ASC LIMIT 1',
				$this->table_name,
				$owner_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return null;
		}

		$row['agent_config'] = self::decode_agent_config( $row['agent_config'] ?? null );

		return $row;
	}

	/**
	 * Get all agents owned by a user.
	 *
	 * Replaces the legacy `get_all() + array_filter()` pattern. Issues a single
	 * indexed query against the `owner_id` key instead of fetching the whole
	 * table and filtering in PHP.
	 *
	 * @since 0.69.2
	 *
	 * @param int $owner_id Owner user ID.
	 * @return array List of agent rows owned by the user (may be empty).
	 */
	public function get_all_by_owner_id( int $owner_id ): array {
		if ( $owner_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE owner_id = %d ORDER BY agent_id ASC',
				$this->table_name,
				$owner_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['agent_config'] = self::decode_agent_config( $row['agent_config'] ?? null );
		}

		return $rows;
	}

	/**
	 * Batch fetch agents by ID.
	 *
	 * Replaces the N+1 pattern of looping `get_agent()` calls. Returns rows in
	 * the same order they were requested when present; missing IDs are silently
	 * dropped.
	 *
	 * @since 0.69.2
	 *
	 * @param int[] $agent_ids Agent IDs to fetch.
	 * @return array List of agent rows (may be empty if no IDs match).
	 */
	public function get_agents_by_ids( array $agent_ids ): array {
		// Sanitize: dedupe, cast to int, drop non-positive values.
		$agent_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $agent_ids ),
					static fn( $id ) => $id > 0
				)
			)
		);

		if ( empty( $agent_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $agent_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE agent_id IN ({$placeholders}) ORDER BY agent_id ASC",
				array_merge( array( $this->table_name ), $agent_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['agent_config'] = self::decode_agent_config( $row['agent_config'] ?? null );
		}

		return $rows;
	}

	/**
	 * Get agent by slug.
	 *
	 * @param string $agent_slug Agent slug.
	 * @return array|null
	 */
	public function get_by_slug( string $agent_slug ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_slug = %s LIMIT 1',
				$this->table_name,
				$agent_slug
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return null;
		}

		$row['agent_config'] = self::decode_agent_config( $row['agent_config'] ?? null );

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
	 * Update an agent's mutable fields.
	 *
	 * Only updates fields that are present in the $data array.
	 * Allowed fields: agent_name, agent_config, site_scope.
	 *
	 * Changing `site_scope` is an explicit, intentional operation: callers must
	 * pass the `site_scope` key to move an agent between network-wide (`null`)
	 * and site-specific (positive int). It is never mutated as a side effect of
	 * an agent_name/agent_config update.
	 *
	 * @since 0.43.0
	 * @since 0.57.0 Added explicit site_scope support.
	 * @param int   $agent_id Agent ID.
	 * @param array $data     Associative array of fields to update.
	 * @return bool True on success, false on DB failure or no valid fields.
	 */
	public function update_agent( int $agent_id, array $data ): bool {
		$allowed = array( 'agent_name', 'agent_config' );
		$update  = array();
		$formats = array();

		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}

			if ( 'agent_config' === $field ) {
				$update[ $field ] = is_array( $data[ $field ] ) ? wp_json_encode( AgentConfigFactory::normalize( $data[ $field ] ) ) : (string) $data[ $field ];
				$formats[]        = '%s';
			} else {
				$update[ $field ] = (string) $data[ $field ];
				$formats[]        = '%s';
			}
		}

		// site_scope is a nullable column, handled outside the string-cast loop
		// so `null` (network-wide) round-trips correctly instead of becoming "".
		if ( array_key_exists( 'site_scope', $data ) ) {
			$scope              = $data['site_scope'];
			$update['site_scope'] = ( null === $scope ) ? null : (int) $scope;
			$formats[]            = ( null === $scope ) ? null : '%d';
		}

		if ( empty( $update ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->table_name,
			$update,
			array( 'agent_id' => $agent_id ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get all agents, optionally filtered by site scope.
	 *
	 * Mirrors WordPress core's multisite user scoping pattern:
	 * - Default (no args): returns ALL agents (network-wide view)
	 * - With site_id: returns agents scoped to that site OR network-wide (site_scope IS NULL)
	 *
	 * @since 0.38.0
	 * @since 0.57.0 Added $args parameter with site_id filtering.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int|null $site_id  Blog ID to filter by. Agents with this site_scope
	 *                              OR site_scope IS NULL (network-wide) are returned.
	 *                              Default null (no filtering — all agents).
	 *     @type int|null $owner_id User ID to filter by. Returns only agents owned by
	 *                              this user. Combines with site_id when both present.
	 *                              Default null (no owner filtering).
	 * }
	 * @return array List of agent rows.
	 */
	public function get_all( array $args = array() ): array {
		$site_id  = $args['site_id'] ?? null;
		$owner_id = $args['owner_id'] ?? null;

		$where        = array();
		$where_values = array();

		if ( null !== $site_id ) {
			$where[]        = '(site_scope = %d OR site_scope IS NULL)';
			$where_values[] = (int) $site_id;
		}

		if ( null !== $owner_id ) {
			$where[]        = 'owner_id = %d';
			$where_values[] = (int) $owner_id;
		}

		if ( ! empty( $where ) ) {
			$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY agent_id ASC';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					$sql,
					array_merge( array( $this->table_name ), $where_values )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare( 'SELECT * FROM %i ORDER BY agent_id ASC', $this->table_name ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['agent_config'] = self::decode_agent_config( $row['agent_config'] ?? null );
		}

		return $rows;
	}

	/**
	 * Create an agent if slug does not exist.
	 *
	 * Network-wide scope is first-class and the default: when `$site_scope` is
	 * omitted (the `false` sentinel) the `site_scope` column is left unset and
	 * falls to its DB default of `NULL` (network-wide). Pass an explicit `null`
	 * to force network-wide, or a positive integer to scope to a single blog.
	 *
	 * @since 0.57.0 Added explicit $site_scope parameter.
	 *
	 * @param string        $agent_slug   Agent slug.
	 * @param string        $agent_name   Display name.
	 * @param int           $owner_id     Owner user ID.
	 * @param array         $agent_config Agent configuration.
	 * @param int|null|false $site_scope  Scope to set on create. `null` = network-wide,
	 *                                    positive int = a specific blog, `false` = use
	 *                                    the column default (network-wide). Default false.
	 * @return int Agent ID.
	 */
	public function create_if_missing( string $agent_slug, string $agent_name, int $owner_id, array $agent_config = array(), int|null|false $site_scope = false ): int {
		$existing = $this->get_by_slug( $agent_slug );

		if ( $existing ) {
			return (int) $existing['agent_id'];
		}

		$data    = array(
			'agent_slug'   => $agent_slug,
			'agent_name'   => $agent_name,
			'owner_id'     => $owner_id,
			'agent_config' => wp_json_encode( AgentConfigFactory::normalize( $agent_config ) ),
		);
		$formats = array( '%s', '%s', '%d', '%s' );

		// Only write site_scope when the caller is intentional about it. The
		// `false` sentinel leaves the column to its DB default (NULL = network-wide).
		if ( false !== $site_scope ) {
			$data['site_scope'] = ( null === $site_scope ) ? null : (int) $site_scope;
			$formats[]          = ( null === $site_scope ) ? null : '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$this->wpdb->insert(
			$this->table_name,
			$data,
			$formats
		);

		return (int) $this->wpdb->insert_id;
	}

	private static function decode_agent_config( mixed $value ): array {
		if ( is_array( $value ) ) {
			return AgentConfigFactory::normalize( $value );
		}

		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : array();
		return AgentConfigFactory::normalize( is_array( $decoded ) ? $decoded : array() );
	}
}
