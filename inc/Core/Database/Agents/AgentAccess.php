<?php
/**
 * Agent Access Repository
 *
 * Many-to-many access grants between WordPress users and agents.
 * Supports role-based access: admin, operator, viewer.
 *
 * @package DataMachine\Core\Database\Agents
 * @since 0.41.0
 */

namespace DataMachine\Core\Database\Agents;

use DataMachine\Core\Database\BaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentAccess extends BaseRepository {

	/**
	 * Table name (without prefix).
	 */
	const TABLE_NAME = 'datamachine_agent_access';

	/**
	 * Audience/principal access grants live beside user grants so the legacy
	 * agent/user unique key remains untouched on existing installs.
	 */
	const PRINCIPAL_TABLE_NAME = 'datamachine_agent_principal_access';

	/**
	 * Valid access roles.
	 *
	 * - admin: full control (create/edit/delete pipelines, flows, agent config)
	 * - operator: run flows, view jobs, manage queue
	 * - viewer: read-only access to pipelines, flows, jobs
	 */
	const VALID_ROLES = array( \WP_Agent_Access_Grant::ROLE_ADMIN, \WP_Agent_Access_Grant::ROLE_OPERATOR, \WP_Agent_Access_Grant::ROLE_VIEWER );

	/**
	 * Use network-level prefix so access grants are shared across the multisite network.
	 *
	 * @return string
	 */
	protected static function get_table_prefix(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	/**
	 * Create agent_access table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->base_prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'viewer',
			granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY agent_user (agent_id, user_id),
			KEY agent_id (agent_id),
			KEY user_id (user_id),
			KEY role (role)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! self::is_sqlite() || ! self::database_table_exists( $table_name, $wpdb ) ) {
			dbDelta( $sql );
		}

		$principal_table_name = $wpdb->base_prefix . self::PRINCIPAL_TABLE_NAME;
		$principal_sql        = "CREATE TABLE {$principal_table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_id BIGINT(20) UNSIGNED NOT NULL,
			principal_type VARCHAR(40) NOT NULL,
			principal_id VARCHAR(191) NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'viewer',
			granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY agent_principal (agent_id, principal_type, principal_id),
			KEY agent_id (agent_id),
			KEY principal (principal_type, principal_id),
			KEY role (role)
		) {$charset_collate};";

		if ( ! self::is_sqlite() || ! self::database_table_exists( $principal_table_name, $wpdb ) ) {
			dbDelta( $principal_sql );
		}
	}

	/**
	 * Grant an audience/non-user principal access to an agent.
	 *
	 * @return array<string,mixed> Persisted grant row in contract-friendly shape.
	 */
	public function grant_principal_access( string $agent_id, string $principal_type, string $principal_id, string $role ): array {
		$agent_id       = (int) $agent_id;
		$principal_type = $this->normalize_principal_type( $principal_type );
		$principal_id   = $this->normalize_principal_id( $principal_id );

		if ( $agent_id <= 0 || '' === $principal_type || '' === $principal_id || ! \WP_Agent_Access_Grant::is_valid_role( $role ) ) {
			throw new \InvalidArgumentException( 'invalid_datamachine_agent_principal_access_grant' );
		}

		$existing = $this->get_principal_access( (string) $agent_id, $principal_type, $principal_id );
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->update(
				$this->principal_table_name(),
				array( 'role' => $role ),
				array(
					'agent_id'       => $agent_id,
					'principal_type' => $principal_type,
					'principal_id'   => $principal_id,
				),
				array( '%s' ),
				array( '%d', '%s', '%s' )
			);

			if ( false === $result ) {
				throw new \RuntimeException( 'datamachine_agent_principal_access_update_failed' );
			}

			return $this->get_principal_access( (string) $agent_id, $principal_type, $principal_id ) ?? $existing;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert(
			$this->principal_table_name(),
			array(
				'agent_id'       => $agent_id,
				'principal_type' => $principal_type,
				'principal_id'   => $principal_id,
				'role'           => $role,
				'granted_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'datamachine_agent_principal_access_insert_failed' );
		}

		return $this->get_principal_access( (string) $agent_id, $principal_type, $principal_id ) ?? array(
			'agent_id'       => (string) $agent_id,
			'principal_type' => $principal_type,
			'principal_id'   => $principal_id,
			'role'           => $role,
		);
	}

	/**
	 * Revoke an audience/non-user principal grant.
	 */
	public function revoke_principal_access( string $agent_id, string $principal_type, string $principal_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->principal_table_name(),
			array(
				'agent_id'       => (int) $agent_id,
				'principal_type' => $this->normalize_principal_type( $principal_type ),
				'principal_id'   => $this->normalize_principal_id( $principal_id ),
			),
			array( '%d', '%s', '%s' )
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Fetch an audience/non-user principal grant.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_principal_access( string $agent_id, string $principal_type, string $principal_id, ?string $workspace_id = null ): ?array {
		unset( $workspace_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d AND principal_type = %s AND principal_id = %s',
				$this->principal_table_name(),
				(int) $agent_id,
				$this->normalize_principal_type( $principal_type ),
				$this->normalize_principal_id( $principal_id )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return $row ? self::principal_grant_from_row( $row ) : null;
	}

	/**
	 * Get agent IDs accessible to an audience/non-user principal.
	 *
	 * @return int[]
	 */
	public function get_agent_ids_for_principal( string $principal_type, string $principal_id, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		unset( $workspace_id );

		if ( null !== $minimum_role ) {
			$allowed_roles = $this->roles_at_or_above( $minimum_role );
			if ( empty( $allowed_roles ) ) {
				return array();
			}

			$placeholders = implode( ',', array_fill( 0, count( $allowed_roles ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$results = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT agent_id FROM %i WHERE principal_type = %s AND principal_id = %s AND role IN ({$placeholders})",
					array_merge( array( $this->principal_table_name(), $this->normalize_principal_type( $principal_type ), $this->normalize_principal_id( $principal_id ) ), $allowed_roles )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$results = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT agent_id FROM %i WHERE principal_type = %s AND principal_id = %s',
					$this->principal_table_name(),
					$this->normalize_principal_type( $principal_type ),
					$this->normalize_principal_id( $principal_id )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		}

		return array_map( 'intval', $results ? $results : array() );
	}

	/**
	 * List principal grants for an agent.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_principals_for_agent( string $agent_id, ?string $workspace_id = null ): array {
		unset( $workspace_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d ORDER BY granted_at ASC',
				$this->principal_table_name(),
				(int) $agent_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( self::class, 'principal_grant_from_row' ), $results ? $results : array() );
	}

	/**
	 * Grant a user access to an agent.
	 *
	 * If the user already has access, updates the role.
	 *
	 * @param int    $agent_id Agent ID.
	 * @param int    $user_id  WordPress user ID.
	 * @param string $role     Access role (admin, operator, viewer).
	 * @return bool True on success.
	 */
	public function grant_access( \WP_Agent_Access_Grant $grant ): \WP_Agent_Access_Grant {
		$agent_id = (int) $grant->agent_id;
		$user_id  = $grant->user_id;
		$role     = $grant->role;

		if ( $agent_id <= 0 ) {
			throw new \InvalidArgumentException( 'invalid_datamachine_agent_access_grant_agent_id' );
		}

		$existing = $this->get_access( $grant->agent_id, $user_id, $grant->workspace_id );

		if ( $existing ) {
			// Update existing role.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->update(
				$this->table_name,
				array( 'role' => $role ),
				array(
					'agent_id' => $agent_id,
					'user_id'  => $user_id,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);

			if ( false === $result ) {
				throw new \RuntimeException( 'datamachine_agent_access_update_failed' );
			}

			$updated = $this->get_access( $grant->agent_id, $user_id, $grant->workspace_id );
			return $updated ?? $grant;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert(
			$this->table_name,
			array(
				'agent_id'   => $agent_id,
				'user_id'    => $user_id,
				'role'       => $role,
				'granted_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'datamachine_agent_access_insert_failed' );
		}

		$created = $this->get_access( $grant->agent_id, $user_id, $grant->workspace_id );
		return $created ?? $grant;
	}

	/**
	 * Revoke a user's access to an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $user_id  WordPress user ID.
	 * @return bool True on success.
	 */
	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table_name,
			array(
				'agent_id' => (int) $agent_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Get a specific user's access grant for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $user_id  WordPress user ID.
	 * @return array|null Access row or null.
	 */
	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?\WP_Agent_Access_Grant {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d AND user_id = %d',
				$this->table_name,
				(int) $agent_id,
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return $row ? self::grant_from_row( $row ) : null;
	}

	/**
	 * Get all agent IDs a user has access to.
	 *
	 * @param int         $user_id      WordPress user ID.
	 * @param string|null $minimum_role Minimum role to filter by (null = any role).
	 * @return int[] Array of agent IDs.
	 */
	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		if ( null !== $minimum_role ) {
			$allowed_roles = $this->roles_at_or_above( $minimum_role );
			if ( empty( $allowed_roles ) ) {
				return array();
			}

			$placeholders = implode( ',', array_fill( 0, count( $allowed_roles ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$results = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT agent_id FROM %i WHERE user_id = %d AND role IN ({$placeholders})",
					array_merge( array( $this->table_name, $user_id ), $allowed_roles )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$results = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT agent_id FROM %i WHERE user_id = %d',
					$this->table_name,
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		}

		return array_map( 'intval', $results ? $results : array() );
	}

	/**
	 * Get all users with access to an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array[] Array of access rows.
	 */
	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d ORDER BY granted_at ASC',
				$this->table_name,
				(int) $agent_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( self::class, 'grant_from_row' ), $results ? $results : array() );
	}

	/**
	 * Check if a user can access an agent with at least the given role.
	 *
	 * @param int    $agent_id     Agent ID.
	 * @param int    $user_id      WordPress user ID.
	 * @param string $minimum_role Minimum required role.
	 * @return bool True if user has the required access level.
	 */
	public function user_can_access( int $agent_id, int $user_id, string $minimum_role = 'viewer' ): bool {
		$access = $this->get_access( (string) $agent_id, $user_id );

		if ( ! $access ) {
			return false;
		}

		return $access->role_meets( $minimum_role );
	}

	/**
	 * Bootstrap access grants for an agent's owner.
	 *
	 * Called when an agent is first created to ensure the owner has admin access.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $owner_id Owner user ID.
	 * @return bool True on success.
	 */
	public function bootstrap_owner_access( int $agent_id, int $owner_id ): bool {
		if ( $agent_id <= 0 || $owner_id <= 0 ) {
			return false;
		}

		try {
			$this->grant_access( new \WP_Agent_Access_Grant( (string) $agent_id, $owner_id, \WP_Agent_Access_Grant::ROLE_ADMIN ) );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Convert a persisted access row into the Agents API grant contract.
	 *
	 * @param array<string,mixed> $row Database row.
	 */
	private static function grant_from_row( array $row ): \WP_Agent_Access_Grant {
		return new \WP_Agent_Access_Grant(
			(string) ( $row['agent_id'] ?? '' ),
			(int) ( $row['user_id'] ?? 0 ),
			(string) ( $row['role'] ?? \WP_Agent_Access_Grant::ROLE_VIEWER ),
			null,
			isset( $row['id'] ) ? (int) $row['id'] : null,
			null,
			isset( $row['granted_at'] ) ? (string) $row['granted_at'] : null,
			array( 'source' => 'datamachine_agent_access' )
		);
	}

	/**
	 * Convert a persisted principal access row into a stable array contract.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private static function principal_grant_from_row( array $row ): array {
		return array(
			'grant_id'       => isset( $row['id'] ) ? (int) $row['id'] : null,
			'agent_id'       => (string) ( $row['agent_id'] ?? '' ),
			'principal_type' => (string) ( $row['principal_type'] ?? '' ),
			'principal_id'   => (string) ( $row['principal_id'] ?? '' ),
			'role'           => (string) ( $row['role'] ?? \WP_Agent_Access_Grant::ROLE_VIEWER ),
			'workspace_id'   => null,
			'granted_at'     => isset( $row['granted_at'] ) ? (string) $row['granted_at'] : null,
			'metadata'       => array( 'source' => self::PRINCIPAL_TABLE_NAME ),
		);
	}

	/**
	 * Return the full principal grants table name.
	 */
	private function principal_table_name(): string {
		return static::get_table_prefix() . self::PRINCIPAL_TABLE_NAME;
	}

	/**
	 * Normalize a non-user principal type.
	 */
	private function normalize_principal_type( string $principal_type ): string {
		return sanitize_key( $principal_type );
	}

	/**
	 * Normalize a non-user principal identifier.
	 */
	private function normalize_principal_id( string $principal_id ): string {
		return sanitize_title( $principal_id );
	}

	/**
	 * Get roles at or above the given role level.
	 *
	 * Role hierarchy: admin > operator > viewer.
	 *
	 * @param string $role Minimum role.
	 * @return string[] Roles that meet or exceed the minimum.
	 */
	private function roles_at_or_above( string $role ): array {
		$hierarchy = \WP_Agent_Access_Grant::roles();
		$index     = array_search( $role, $hierarchy, true );

		if ( false === $index ) {
			return array();
		}

		return array_slice( $hierarchy, $index );
	}
}
