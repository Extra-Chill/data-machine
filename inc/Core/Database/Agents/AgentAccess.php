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
	 * Valid access roles.
	 *
	 * - admin: full control (create/edit/delete pipelines, flows, agent config)
	 * - operator: run flows, view jobs, manage queue
	 * - viewer: read-only access to pipelines, flows, jobs
	 */
	const VALID_ROLES = array( 'admin', 'operator', 'viewer' );

	/**
	 * Create agent_access table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
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
		dbDelta( $sql );
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
	public function grant_access( int $agent_id, int $user_id, string $role = 'viewer' ): bool {
		if ( ! in_array( $role, self::VALID_ROLES, true ) ) {
			return false;
		}

		$existing = $this->get_access( $agent_id, $user_id );

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

			$success = false !== $result;
		} else {
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

			$success = false !== $result;
		}

		if ( $success && class_exists( '\\DataMachine\\Engine\\AuditLogger' ) ) {
			\DataMachine\Engine\AuditLogger::record(
				'agent.access.grant',
				'allowed',
				array(
					'agent_id'      => $agent_id,
					'resource_type' => 'agent',
					'resource_id'   => $agent_id,
					'metadata'      => array(
						'granted_user_id' => $user_id,
						'role'            => $role,
						'was_update'      => null !== $existing,
					),
				)
			);
		}

		return $success;
	}

	/**
	 * Revoke a user's access to an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $user_id  WordPress user ID.
	 * @return bool True on success.
	 */
	public function revoke_access( int $agent_id, int $user_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table_name,
			array(
				'agent_id' => $agent_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);

		$success = false !== $result && $result > 0;

		if ( $success && class_exists( '\\DataMachine\\Engine\\AuditLogger' ) ) {
			\DataMachine\Engine\AuditLogger::record(
				'agent.access.revoke',
				'allowed',
				array(
					'agent_id'      => $agent_id,
					'resource_type' => 'agent',
					'resource_id'   => $agent_id,
					'metadata'      => array(
						'revoked_user_id' => $user_id,
					),
				)
			);
		}

		return $success;
	}

	/**
	 * Get a specific user's access grant for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $user_id  WordPress user ID.
	 * @return array|null Access row or null.
	 */
	public function get_access( int $agent_id, int $user_id ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d AND user_id = %d',
				$this->table_name,
				$agent_id,
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return $row ?: null;
	}

	/**
	 * Get all agent IDs a user has access to.
	 *
	 * @param int         $user_id      WordPress user ID.
	 * @param string|null $minimum_role Minimum role to filter by (null = any role).
	 * @return int[] Array of agent IDs.
	 */
	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null ): array {
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

		return array_map( 'intval', $results ?: array() );
	}

	/**
	 * Get all users with access to an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array[] Array of access rows.
	 */
	public function get_users_for_agent( int $agent_id ): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE agent_id = %d ORDER BY granted_at ASC',
				$this->table_name,
				$agent_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return $results ?: array();
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
		$access = $this->get_access( $agent_id, $user_id );

		if ( ! $access ) {
			return false;
		}

		return $this->role_meets_minimum( $access['role'], $minimum_role );
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
		return $this->grant_access( $agent_id, $owner_id, 'admin' );
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
		$hierarchy = array( 'viewer', 'operator', 'admin' );
		$index     = array_search( $role, $hierarchy, true );

		if ( false === $index ) {
			return array();
		}

		return array_slice( $hierarchy, $index );
	}

	/**
	 * Check if a role meets the minimum requirement.
	 *
	 * @param string $actual_role   The role the user has.
	 * @param string $minimum_role  The minimum required role.
	 * @return bool
	 */
	private function role_meets_minimum( string $actual_role, string $minimum_role ): bool {
		$hierarchy = array( 'viewer' => 0, 'operator' => 1, 'admin' => 2 );

		$actual_level  = $hierarchy[ $actual_role ] ?? -1;
		$minimum_level = $hierarchy[ $minimum_role ] ?? 0;

		return $actual_level >= $minimum_level;
	}
}
