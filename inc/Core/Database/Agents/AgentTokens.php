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

class AgentTokens extends BaseRepository implements \WP_Agent_Token_Store {

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

		if ( self::is_sqlite() && self::database_table_exists( $table_name, $wpdb ) ) {
			return;
		}

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
	public function create_bearer_token( int $agent_id, string $agent_slug, string $label = '', ?array $capabilities = null, ?string $expires_at = null ): ?array {
		// Generate cryptographically random token.
		$random_bytes = bin2hex( random_bytes( 32 ) );
		$raw_token    = self::TOKEN_PREFIX . $agent_slug . '_' . $random_bytes;
		$token_hash   = \WP_Agent_Token::hash_token( $raw_token );
		$prefix       = substr( $raw_token, 0, 12 );
		$agents_repo  = new Agents();
		$agent        = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return null;
		}

		$scope = self::normalize_capability_payload( $capabilities );

		try {
			$token = $this->create_token(
				new \WP_Agent_Token(
					1,
					(string) $agent_id,
					(int) $agent['owner_id'],
					$token_hash,
					$prefix,
					sanitize_text_field( $label ),
					$scope['allowed_capabilities'],
					$expires_at,
					null,
					current_time( 'mysql', true ),
					null,
					null,
					array_filter(
						array(
							'agent_slug'        => $agent_slug,
							'datamachine_scope' => $scope['stored_payload'],
						)
					)
				)
			);
		} catch ( \Throwable $e ) {
			$this->log_db_error( 'Create agent token', array( 'agent_id' => $agent_id ) );
			return null;
		}

		return array(
			'token_id'     => $token->token_id,
			'raw_token'    => $raw_token,
			'token_prefix' => $token->token_prefix,
		);
	}

	/**
	 * Create a token metadata record for a pre-hashed token.
	 */
	public function create_token( \WP_Agent_Token $token ): \WP_Agent_Token {
		$agent_id = (int) $token->agent_id;

		$scope_payload = isset( $token->metadata['datamachine_scope'] ) && is_array( $token->metadata['datamachine_scope'] )
			? $token->metadata['datamachine_scope']
			: $token->allowed_capabilities;

		$insert_data = array(
			'agent_id'     => $agent_id,
			'token_hash'   => $token->token_hash,
			'token_prefix' => $token->token_prefix,
			'label'        => sanitize_text_field( $token->label ),
			'capabilities' => null !== $scope_payload ? wp_json_encode( $scope_payload ) : null,
			'expires_at'   => $token->expires_at,
			'created_at'   => $token->created_at ?? current_time( 'mysql', true ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert( $this->table_name, $insert_data, $formats );

		if ( false === $result ) {
			$this->log_db_error( 'Create agent token', array( 'agent_id' => $agent_id ) );
			throw new \RuntimeException( 'datamachine_agent_token_insert_failed' );
		}

		$created = $this->get_token( (int) $this->wpdb->insert_id );
		return $created ?? $token;
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
	public function resolve_token_hash( string $token_hash ): ?\WP_Agent_Token {

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

		return $this->token_from_row( $row );
	}

	/**
	 * Update the last_used_at timestamp for a token.
	 *
	 * Called on every successful authentication via this token.
	 *
	 * @param int $token_id Token ID.
	 * @return void
	 */
	public function touch_token( int $token_id, ?string $used_at = null ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->update(
			$this->table_name,
			array( 'last_used_at' => $used_at ?? current_time( 'mysql', true ) ),
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
	public function revoke_token( int $token_id, string $agent_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table_name,
			array(
				'token_id' => $token_id,
				'agent_id' => (int) $agent_id,
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
	public function revoke_all_tokens_for_agent( string $agent_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table_name,
			array( 'agent_id' => (int) $agent_id ),
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
	public function list_tokens( string $agent_id ): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT token_id, agent_id, token_hash, token_prefix, label, capabilities, last_used_at, expires_at, created_at FROM %i WHERE agent_id = %d ORDER BY created_at DESC',
				$this->table_name,
				(int) $agent_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $results ) {
			return array();
		}

		return array_values( array_filter( array_map( array( $this, 'token_from_row' ), $results ) ) );
	}

	/**
	 * Get a single token's metadata by ID.
	 *
	 * @param int $token_id Token ID.
	 * @return array|null Token metadata or null.
	 */
	public function get_token( int $token_id ): ?\WP_Agent_Token {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT token_id, agent_id, token_hash, token_prefix, label, capabilities, last_used_at, expires_at, created_at FROM %i WHERE token_id = %d',
				$this->table_name,
				$token_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return $row ? $this->token_from_row( $row ) : null;
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

	/**
	 * Convert a persisted token row into the Agents API token contract.
	 *
	 * @param array<string,mixed> $row Database row.
	 */
	private function token_from_row( array $row ): ?\WP_Agent_Token {
		$agent_id    = (int) ( $row['agent_id'] ?? 0 );
		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return null;
		}

		$capabilities = null;
		$metadata     = array( 'agent_slug' => (string) $agent['agent_slug'] );
		if ( ! empty( $row['capabilities'] ) ) {
			$decoded = json_decode( (string) $row['capabilities'], true );
			$scope   = is_array( $decoded ) ? self::normalize_capability_payload( $decoded ) : self::normalize_capability_payload( null );

			$capabilities = $scope['allowed_capabilities'];
			if ( null !== $scope['stored_payload'] ) {
				$metadata['datamachine_scope'] = $scope['stored_payload'];
			}
		}

		return new \WP_Agent_Token(
			(int) ( $row['token_id'] ?? 0 ),
			(string) $agent_id,
			(int) $agent['owner_id'],
			(string) ( $row['token_hash'] ?? str_repeat( '0', 64 ) ),
			(string) ( $row['token_prefix'] ?? '' ),
			(string) ( $row['label'] ?? '' ),
			$capabilities,
			isset( $row['expires_at'] ) ? (string) $row['expires_at'] : null,
			isset( $row['last_used_at'] ) ? (string) $row['last_used_at'] : null,
			isset( $row['created_at'] ) ? (string) $row['created_at'] : null,
			null,
			null,
			$metadata
		);
	}

	/**
	 * Normalize legacy flat capability arrays and structured Data Machine scopes.
	 *
	 * The database column remains the source of truth. Structured payloads are
	 * preserved for audit/UI while Agents API receives only raw WP capability
	 * strings for its generic capability ceiling object.
	 *
	 * @param array|null $payload Stored or requested capability payload.
	 * @return array{allowed_capabilities: ?array<int,string>, stored_payload: ?array}
	 */
	public static function normalize_capability_payload( ?array $payload ): array {
		if ( null === $payload ) {
			return array(
				'allowed_capabilities' => null,
				'stored_payload'       => null,
			);
		}

		$is_structured = array_keys( $payload ) !== range( 0, count( $payload ) - 1 );
		if ( ! $is_structured ) {
			$capabilities = self::normalize_string_list( $payload );
			return array(
				'allowed_capabilities' => $capabilities,
				'stored_payload'       => $capabilities,
			);
		}

		$capabilities = self::normalize_string_list( $payload['capabilities'] ?? array() );
		$stored       = array(
			'scope'              => sanitize_key( (string) ( $payload['scope'] ?? '' ) ),
			'label'              => sanitize_text_field( (string) ( $payload['label'] ?? '' ) ),
			'ability_categories' => self::normalize_string_list( $payload['ability_categories'] ?? array() ),
			'ability_allow'      => self::normalize_string_list( $payload['ability_allow'] ?? array() ),
			'ability_deny'       => self::normalize_string_list( $payload['ability_deny'] ?? array() ),
			'capabilities'       => $capabilities,
		);

		return array(
			'allowed_capabilities' => $capabilities,
			'stored_payload'       => $stored,
		);
	}

	/**
	 * Return a human-readable scope label for token audit surfaces.
	 *
	 * @param array|null $payload Token capability payload.
	 */
	public static function scope_label( ?array $payload ): string {
		$scope = self::normalize_capability_payload( $payload );
		if ( null === $scope['stored_payload'] ) {
			return __( 'Full owner ceiling', 'data-machine' );
		}

		$stored = $scope['stored_payload'];
		if ( isset( $stored['label'] ) && '' !== $stored['label'] ) {
			return (string) $stored['label'];
		}

		if ( isset( $stored['scope'] ) && '' !== $stored['scope'] ) {
			return ucwords( str_replace( '_', ' ', (string) $stored['scope'] ) );
		}

		return __( 'Custom scope', 'data-machine' );
	}

	/**
	 * @param mixed $value Raw list-like value.
	 * @return array<int,string>
	 */
	private static function normalize_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return array_values( array_unique( $items ) );
	}
}
