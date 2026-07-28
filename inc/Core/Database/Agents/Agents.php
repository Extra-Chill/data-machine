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

	private const DEFAULT_INSTANCE_KEY       = 'default';
	private const IDENTITY_INDEX_NAME        = 'agent_identity_scope_hash';
	private const PROVISIONING_LEASE_SECONDS = 300;

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
			instance_key LONGTEXT NOT NULL,
			instance_key_hash CHAR(64) NOT NULL,
			provisioning_token CHAR(64) NOT NULL DEFAULT '',
			provisioning_started_at DATETIME NULL DEFAULT NULL,
			provisioned_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
			site_scope BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			agent_config LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (agent_id),
			UNIQUE KEY agent_identity_scope_hash (agent_slug, owner_id, instance_key_hash),
			KEY owner_id (owner_id),
			KEY site_scope (site_scope)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Migrate legacy slug-only identity storage to the Agents API scope tuple.
	 *
	 * Existing rows become their owner's default materialized instance. Full
	 * instance keys remain lossless in LONGTEXT while a fixed-width SHA-256
	 * digest provides portable MySQL/SQLite uniqueness.
	 */
	public static function ensure_identity_scope_schema(): void {
		global $wpdb;

		$table_name         = $wpdb->base_prefix . self::TABLE_NAME;
		$had_provisioned_at = BaseRepository::column_exists( $table_name, 'provisioned_at', $wpdb );
		self::ensure_identity_column( $wpdb, $table_name, 'instance_key', 'LONGTEXT NULL' );
		self::ensure_identity_column( $wpdb, $table_name, 'instance_key_hash', 'CHAR(64) NULL' );
		self::ensure_identity_column( $wpdb, $table_name, 'provisioning_token', "CHAR(64) NOT NULL DEFAULT ''" );
		self::ensure_identity_column( $wpdb, $table_name, 'provisioning_started_at', 'DATETIME NULL DEFAULT NULL' );
		// SQLite rejects non-constant defaults when ALTER TABLE adds a column.
		self::ensure_identity_column( $wpdb, $table_name, 'provisioned_at', 'DATETIME NULL DEFAULT NULL' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		self::query_or_throw(
			$wpdb,
			$wpdb->prepare( 'UPDATE %i SET instance_key = %s WHERE instance_key IS NULL OR instance_key = %s', $table_name, self::DEFAULT_INSTANCE_KEY, '' ),
			'backfill legacy agent instance keys'
		);
		if ( ! $had_provisioned_at ) {
			self::query_or_throw(
				$wpdb,
				$wpdb->prepare( 'UPDATE %i SET provisioned_at = %s WHERE provisioned_at IS NULL', $table_name, gmdate( 'Y-m-d H:i:s' ) ),
				'backfill legacy agent provisioning state'
			);
		}

		// Hash in PHP so SQLite and MySQL persist byte-for-byte equivalent digests.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT agent_id, instance_key, instance_key_hash FROM %i', $table_name ), ARRAY_A );
		foreach ( $rows as $row ) {
			$instance_key = (string) ( $row['instance_key'] ?? self::DEFAULT_INSTANCE_KEY );
			$digest       = self::instance_key_hash( $instance_key );
			if ( hash_equals( $digest, (string) ( $row['instance_key_hash'] ?? '' ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( false === $wpdb->update( $table_name, array( 'instance_key_hash' => $digest ), array( 'agent_id' => (int) $row['agent_id'] ), array( '%s' ), array( '%d' ) ) ) {
				throw new \RuntimeException( 'Failed to backfill agent identity scope digest.' );
			}
		}

		if ( ! self::is_sqlite() ) {
			self::query_or_throw( $wpdb, $wpdb->prepare( 'ALTER TABLE %i MODIFY instance_key LONGTEXT NOT NULL', $table_name ), 'make agent instance keys non-null' );
			self::query_or_throw( $wpdb, $wpdb->prepare( 'ALTER TABLE %i MODIFY instance_key_hash CHAR(64) NOT NULL', $table_name ), 'make agent instance digests non-null' );
		}

		$indexes = self::get_identity_indexes( $wpdb, $table_name );
		if ( ! self::has_unique_index( $indexes, array( 'agent_slug', 'owner_id', 'instance_key_hash' ) ) ) {
			$sql = self::is_sqlite()
				? $wpdb->prepare( 'CREATE UNIQUE INDEX %i ON %i (agent_slug, owner_id, instance_key_hash)', self::IDENTITY_INDEX_NAME, $table_name )
				: $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY %i (agent_slug, owner_id, instance_key_hash)', $table_name, self::IDENTITY_INDEX_NAME );
			self::query_or_throw( $wpdb, $sql, 'create agent identity scope index' );
		}

		$indexes = self::get_identity_indexes( $wpdb, $table_name );
		if ( ! self::has_unique_index( $indexes, array( 'agent_slug', 'owner_id', 'instance_key_hash' ) ) ) {
			throw new \RuntimeException( 'Agent identity scope index verification failed.' );
		}

		// Only remove obsolete uniqueness after the digest-backed replacement is verified.
		foreach ( $indexes as $name => $index ) {
			if ( ! $index['unique'] || ! in_array( $index['columns'], array( array( 'agent_slug' ), array( 'agent_slug', 'owner_id', 'instance_key' ) ), true ) ) {
				continue;
			}
			$sql = self::is_sqlite()
				? $wpdb->prepare( 'DROP INDEX %i', $name )
				: $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table_name, $name );
			self::query_or_throw( $wpdb, $sql, 'remove obsolete agent identity index' );
		}

		$indexes = self::get_identity_indexes( $wpdb, $table_name );
		if ( ! self::has_unique_index( $indexes, array( 'agent_slug', 'owner_id', 'instance_key_hash' ) ) || self::has_unique_index( $indexes, array( 'agent_slug' ) ) ) {
			throw new \RuntimeException( 'Agent identity schema migration did not reach a valid final state.' );
		}
	}

	private static function ensure_identity_column( \wpdb $wpdb, string $table_name, string $column, string $definition ): void {
		if ( BaseRepository::column_exists( $table_name, $column, $wpdb ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column and definition are private schema constants, while the table identifier is prepared.
		self::query_or_throw( $wpdb, $wpdb->prepare( "ALTER TABLE %i ADD COLUMN {$column} {$definition}", $table_name ), "add agent identity column {$column}" );
	}

	private static function query_or_throw( \wpdb $wpdb, string $sql, string $operation ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $wpdb->query( $sql ) ) {
			throw new \RuntimeException( sprintf( 'Failed to %s: %s', esc_html( $operation ), esc_html( (string) $wpdb->last_error ) ) );
		}
	}

	/** @return array<string,array{unique:bool,columns:string[]}> */
	private static function get_identity_indexes( \wpdb $wpdb, string $table_name ): array {
		$indexes = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$index_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name ), ARRAY_A );
		foreach ( $index_rows as $index_row ) {
			$name = (string) ( $index_row['Key_name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$indexes[ $name ]['unique'] = 0 === (int) ( $index_row['Non_unique'] ?? 1 );
			$indexes[ $name ]['columns'][ (int) ( $index_row['Seq_in_index'] ?? 0 ) ] = (string) ( $index_row['Column_name'] ?? '' );
		}
		foreach ( $indexes as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		return $indexes;
	}

	private static function has_unique_index( array $indexes, array $columns ): bool {
		foreach ( $indexes as $index ) {
			if ( $index['unique'] && $columns === $index['columns'] ) {
				return true;
			}
		}
		return false;
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
				'SELECT * FROM %i WHERE agent_slug = %s ORDER BY agent_id ASC LIMIT 1',
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

	/** Resolve an agent by its complete normalized materialized identity scope. */
	public function get_by_identity_scope( string $agent_slug, int $owner_id, string $instance_key = 'default' ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_slug = %s AND owner_id = %d AND instance_key_hash = %s LIMIT 1',
				$this->table_name,
				$agent_slug,
				$owner_id,
				self::instance_key_hash( $instance_key )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return null;
		}
		if ( ! hash_equals( $instance_key, (string) ( $row['instance_key'] ?? '' ) ) ) {
			throw new \UnexpectedValueException( 'Agent identity scope digest conflicts with persisted instance key.' );
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
			$scope                = $data['site_scope'];
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
			'agent_slug'        => $agent_slug,
			'agent_name'        => $agent_name,
			'owner_id'          => $owner_id,
			'instance_key'      => self::DEFAULT_INSTANCE_KEY,
			'instance_key_hash' => self::instance_key_hash( self::DEFAULT_INSTANCE_KEY ),
			'agent_config'      => wp_json_encode( AgentConfigFactory::normalize( $agent_config ) ),
		);
		$formats = array( '%s', '%s', '%d', '%s', '%s', '%s' );

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

	/**
	 * Atomically materialize one complete identity scope.
	 *
	 * @return array{agent_id:int,created:bool}
	 */
	public function create_identity_if_missing( string $agent_slug, string $agent_name, int $owner_id, string $instance_key, array $agent_config = array() ): array {
		$existing = $this->get_by_identity_scope( $agent_slug, $owner_id, $instance_key );
		if ( $existing ) {
			return array(
				'agent_id' => (int) $existing['agent_id'],
				'created'  => false,
			);
		}

		// The composite unique key serializes concurrent materialization attempts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$data     = array(
			'agent_slug'              => $agent_slug,
			'agent_name'              => $agent_name,
			'owner_id'                => $owner_id,
			'instance_key'            => $instance_key,
			'instance_key_hash'       => self::instance_key_hash( $instance_key ),
			'provisioning_token'      => '',
			'provisioning_started_at' => null,
			'provisioned_at'          => null,
			'agent_config'            => wp_json_encode( AgentConfigFactory::normalize( $agent_config ) ),
		);
		$formats  = array( '%s', '%s', '%d', '%s', '%s', '%s', null, null, '%s' );
		$inserted = $this->insert_identity_row( $data, $formats );

		if ( false !== $inserted && $this->wpdb->insert_id > 0 ) {
			return array(
				'agent_id' => (int) $this->wpdb->insert_id,
				'created'  => true,
			);
		}

		$existing = $this->get_by_identity_scope( $agent_slug, $owner_id, $instance_key );
		return array(
			'agent_id' => (int) ( $existing['agent_id'] ?? 0 ),
			'created'  => false,
		);
	}

	/**
	 * @param array<string,mixed>    $data    Identity row data.
	 * @param array<int,string|null> $formats Identity row formats.
	 */
	protected function insert_identity_row( array $data, array $formats ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $this->wpdb->insert( $this->table_name, $data, $formats );
	}

	public function claim_identity_provisioning( int $agent_id, string $token ): bool {
		$now    = gmdate( 'Y-m-d H:i:s' );
		$expiry = gmdate( 'Y-m-d H:i:s', time() - self::PROVISIONING_LEASE_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- The complete conditional update is prepared immediately below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET provisioning_token = %s, provisioning_started_at = %s WHERE agent_id = %d AND provisioned_at IS NULL AND (provisioning_token = '' OR provisioning_token IS NULL OR provisioning_started_at IS NULL OR provisioning_started_at < %s)",
				$this->table_name,
				$token,
				$now,
				$agent_id,
				$expiry
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return 1 === $updated;
	}

	public function complete_identity_provisioning( int $agent_id, string $token ): bool {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- The complete conditional update is prepared immediately below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET provisioned_at = %s, provisioning_token = '', provisioning_started_at = NULL WHERE agent_id = %d AND provisioning_token = %s AND provisioned_at IS NULL",
				$this->table_name,
				gmdate( 'Y-m-d H:i:s' ),
				$agent_id,
				$token
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return 1 === $updated;
	}

	public function release_identity_provisioning( int $agent_id, string $token ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- The complete conditional update is prepared immediately below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET provisioning_token = '', provisioning_started_at = NULL WHERE agent_id = %d AND provisioning_token = %s AND provisioned_at IS NULL",
				$this->table_name,
				$agent_id,
				$token
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function instance_key_hash( string $instance_key ): string {
		return hash( 'sha256', $instance_key );
	}

	private static function decode_agent_config( mixed $value ): array {
		if ( is_array( $value ) ) {
			return AgentConfigFactory::normalize( $value );
		}

		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : array();
		return AgentConfigFactory::normalize( is_array( $decoded ) ? $decoded : array() );
	}
}
