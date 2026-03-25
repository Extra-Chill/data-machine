<?php
/**
 * Agent Tokens Repository
 *
 * Per-agent bearer tokens for runtime authentication.
 * Tokens are stored as SHA-256 hashes — the raw token is only returned once on creation.
 * Each agent can have multiple tokens (e.g., one for Kimaki, one for CI, one for dev).
 *
 * Token format: datamachine_{agent_slug}_{32_random_hex_bytes}
 * The prefix is human-readable so you can identify which agent a token belongs to.
 *
 * @package DataMachine\Core\Database\Agents
 * @since 0.47.0
 */

namespace DataMachine\Core\Database\Agents;

use DataMachine\Core\Database\BaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentTokens extends BaseRepository {

	/**
	 * Table name (without prefix).
	 */
	const TABLE_NAME = 'datamachine_agent_tokens';

	/**
	 * Token prefix for identification.
	 */
	const TOKEN_PREFIX = 'datamachine_';

	/**
	 * Use network-level prefix so tokens are shared across the multisite network.
	 *
	 * @return string
	 */
	protected static function get_table_prefix(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	/**
	 * Create agent_tokens table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->base_prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			token_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_id BIGINT(20) UNSIGNED NOT NULL,
			token_hash VARCHAR(64) NOT NULL,
			token_prefix VARCHAR(12) NOT NULL,
			label VARCHAR(200) NOT NULL DEFAULT '',
			capabilities TEXT NULL,
			last_used_at DATETIME NULL,
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (token_id),
			UNIQUE KEY token_hash (token_hash),
			KEY agent_id (agent_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create a new token for an agent.
	 *
	 * Generates a cryptographically random token, stores its SHA-256 hash,
	 * and returns the raw token. The raw token is NEVER stored and cannot
	 * be retrieved again.
	 *
	 * @param int         $agent_id     Agent ID.
	 * @param string      $agent_slug   Agent slug (used in token prefix).
	 * @param string      $label        Human-readable label (e.g., "kimaki-prod").
	 * @param array|null  $capabilities Allowed capabilities (null = all agent capabilities).
	 * @param string|null $expires_at   Expiry datetime string (null = never).
	 * @return array{token_id: int, raw_token: string, token_prefix: string}|null Created token data or null on failure.
	 */
	public function create_token( int $agent_id, string $agent_slug, string $label = '', ?array $capabilities = null, ?string $expires_at = null ): ?array {
		// Generate cryptographically random token.
		$random_bytes = bin2hex( random_bytes( 32 ) );
		$raw_token    = self::TOKEN_PREFIX . $agent_slug . '_' . $random_bytes;
		$token_hash   = hash( 'sha256', $raw_token );
		$prefix       = substr( $raw_token, 0, 12 );

		$insert_data = array(
			'agent_id'     => $agent_id,
			'token_hash'   => $token_hash,
			'token_prefix' => $prefix,
			'label'        => sanitize_text_field( $label ),
			'capabilities' => null !== $capabilities ? wp_json_encode( $capabilities ) : null,
			'expires_at'   => $expires_at,
			'created_at'   => current_time( 'mysql', true ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert( $this->table_name, $insert_data, $formats );

		if ( false === $result ) {
			$this->log_db_error( 'Create agent token', array( 'agent_id' => $agent_id ) );
			return null;
		}

		return array(
			'token_id'     => (int) $this->wpdb->insert_id,
			'raw_token'    => $raw_token,
			'token_prefix' => $prefix,
		);
	}

	/**
	 * Resolve a raw bearer token to its stored record.
	 *
	 * Uses constant-time hash comparison via the database index on token_hash.
	 * The DB lookup itself is O(1) via the UNIQUE index, and we validate the
	 * hash match is exact.
	 *
	 * @param string $raw_token Raw bearer token from Authorization header.
	 * @return array|null Token record (with agent_id) or null if not found/expired.
	 */
	public function resolve_token( string $raw_token ): ?array {
		$token_hash = hash( 'sha256', $raw_token );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE token_hash = %s',
				$this->table_name,
				$token_hash
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return null;
		}

		// Check expiry.
		if ( ! empty( $row['expires_at'] ) ) {
			$expires_timestamp = strtotime( $row['expires_at'] );
			if ( $expires_timestamp && $expires_timestamp < time() ) {
				return null;
			}
		}

		// Decode capabilities JSON.
		if ( ! empty( $row['capabilities'] ) ) {
			$row['capabilities'] = json_decode( $row['capabilities'], true );
		}

		return $row;
	}

	/**
	 * Update the last_used_at timestamp for a token.
	 *
	 * Called on every successful authentication via this token.
	 *
	 * @param int $token_id Token ID.
	 * @return void
	 */
	public function touch_last_used( int $token_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->update(
			$this->table_name,
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'token_id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Revoke (delete) a specific token.
	 *
	 * @param int $token_id Token ID.
	 * @param int $agent_id Agent ID (for authorization — ensures token belongs to this agent).
	 * @return bool True if deleted, false if not found or wrong agent.
	 */
	public function revoke_token( int $token_id, int $agent_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table_name,
			array(
				'token_id' => $token_id,
				'agent_id' => $agent_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Revoke all tokens for an agent.
	 *
	 * Used when an agent is deleted or deactivated.
	 *
	 * @param int $agent_id Agent ID.
	 * @return int Number of tokens revoked.
	 */
	public function revoke_all_for_agent( int $agent_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table_name,
			array( 'agent_id' => $agent_id ),
			array( '%d' )
		);

		return false !== $result ? $result : 0;
	}

	/**
	 * List all tokens for an agent.
	 *
	 * Never returns the token hash (that's a secret). Returns metadata only.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array[] Array of token metadata rows.
	 */
	public function list_tokens( int $agent_id ): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT token_id, agent_id, token_prefix, label, capabilities, last_used_at, expires_at, created_at FROM %i WHERE agent_id = %d ORDER BY created_at DESC',
				$this->table_name,
				$agent_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $results ) {
			return array();
		}

		foreach ( $results as &$row ) {
			if ( ! empty( $row['capabilities'] ) ) {
				$row['capabilities'] = json_decode( $row['capabilities'], true );
			}
		}

		return $results;
	}

	/**
	 * Get a single token's metadata by ID.
	 *
	 * @param int $token_id Token ID.
	 * @return array|null Token metadata or null.
	 */
	public function get_token( int $token_id ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT token_id, agent_id, token_prefix, label, capabilities, last_used_at, expires_at, created_at FROM %i WHERE token_id = %d',
				$this->table_name,
				$token_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return null;
		}

		if ( ! empty( $row['capabilities'] ) ) {
			$row['capabilities'] = json_decode( $row['capabilities'], true );
		}

		return $row;
	}

	/**
	 * Count tokens for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return int Token count.
	 */
	public function count_tokens( int $agent_id ): int {
		return $this->count_rows( 'agent_id = %d', array( $agent_id ) );
	}
}
