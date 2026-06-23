<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * Chat Database Operations
 *
 * Unified database component for chat session management including
 * table creation and CRUD operations for persistent conversation storage.
 *
 * @package DataMachine\Core\Database\Chat
 * @since 0.2.0
 */

namespace DataMachine\Core\Database\Chat;

use DataMachine\Core\Admin\DateFormatter;
use DataMachine\Abilities\Chat\ChatTranscriptOwner;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\Database\BaseRepository;
use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Database Manager
 *
 * Implements {@see ConversationStoreInterface} so the conversation
 * storage backend can be swapped via the `datamachine_conversation_store`
 * filter. Resolve via {@see ConversationStoreFactory::get()} rather than
 * instantiating this class directly.
 */
class Chat extends BaseRepository implements ConversationStoreInterface {

	/**
	 * Table name (without prefix)
	 */
	const TABLE_NAME = 'datamachine_chat_sessions';

	/**
	 * Use network-level prefix so chat sessions are shared across the multisite network.
	 *
	 * A user's chat history follows them to every subsite — consistent with the
	 * agent identity, tokens, and access tables, which already use base_prefix.
	 *
	 * @return string
	 */
	protected static function get_table_prefix(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	/**
	 * Create chat sessions table
	 *
	 * Uses dbDelta for safe table creation/updates
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_escaped_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			session_id VARCHAR(50) NOT NULL,
			workspace_type VARCHAR(50) NOT NULL,
			workspace_id VARCHAR(191) NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			owner_type VARCHAR(40) NOT NULL DEFAULT 'user',
			owner_key_hash VARCHAR(64) NOT NULL,
			owner_label VARCHAR(191) NULL,
			agent_id BIGINT(20) UNSIGNED NULL,
			title VARCHAR(100) NULL,
			messages LONGTEXT NOT NULL,
			metadata LONGTEXT NULL,
			provider VARCHAR(50) NULL,
			model VARCHAR(100) NULL,
			provider_response_id VARCHAR(191) NULL,
			mode VARCHAR(20) NOT NULL DEFAULT 'chat',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			last_read_at DATETIME NULL,
			expires_at DATETIME NULL,
			transcript_lock_token VARCHAR(64) NULL,
			transcript_lock_expires_at DATETIME NULL,
			PRIMARY KEY  (session_id),
			KEY workspace (workspace_type, workspace_id),
			KEY user_id (user_id),
			KEY owner (owner_type, owner_key_hash),
			KEY agent_id (agent_id),
			KEY mode (mode),
			KEY user_mode (user_id, mode),
			KEY created_at (created_at),
			KEY updated_at (updated_at),
			KEY expires_at (expires_at),
			KEY transcript_lock_expires_at (transcript_lock_expires_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Migrate legacy per-site chat session tables into the network table.
	 *
	 * Chat sessions used to live in per-site tables (`<prefix>_<N>_datamachine_chat_sessions`)
	 * because the repository defaulted to `$wpdb->prefix`. Now that the table is
	 * network-scoped (`base_prefix`), a user's chat history must follow them across
	 * every subsite. This one-time migration unions every legacy per-site table into
	 * the single network table.
	 *
	 * Idempotent and re-runnable:
	 * - Rows are de-duped on the `session_id` primary key via `INSERT IGNORE`.
	 * - The source site whose prefix equals the network base prefix IS the network
	 *   table, so it is skipped.
	 * - Single-site installs have nothing to union and return early.
	 *
	 * @return int Number of rows copied into the network table.
	 */
	public static function migrate_per_site_tables_to_network(): int {
		global $wpdb;

		// Single-site installs already store sessions in the (base == site) table.
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			return 0;
		}

		// The network table must exist before we can union into it.
		if ( ! self::table_exists() ) {
			return 0;
		}

		$network_table = self::get_prefixed_table_name();
		$base_prefix   = $wpdb->base_prefix;
		$copied        = 0;

		$blog_ids = get_sites(
			array(
				'fields'   => 'ids',
				'archived' => 0,
				'deleted'  => 0,
				'number'   => 0,
			)
		);

		foreach ( $blog_ids as $blog_id ) {
			$site_prefix = $wpdb->get_blog_prefix( (int) $blog_id );
			$site_table  = self::sanitize_table_name( $site_prefix . self::TABLE_NAME );

			// Skip the network table itself (the site whose prefix == base prefix).
			if ( $site_table === $network_table ) {
				continue;
			}

			// Skip sites that never created a per-site sessions table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $site_table ) );
			if ( $exists !== $site_table ) {
				continue;
			}

			// Copy every legacy row into the network table. INSERT IGNORE makes this
			// idempotent: rows whose session_id already lives in the network table are
			// skipped, so re-running never duplicates or overwrites migrated history.
			// Columns are listed explicitly so the copy is resilient to column-order
			// drift between the two tables.
			$columns     = 'session_id, workspace_type, workspace_id, user_id, owner_type, owner_key_hash, owner_label, agent_id, title, messages, metadata, provider, model, provider_response_id, mode, created_at, updated_at, last_read_at, expires_at, transcript_lock_token, transcript_lock_expires_at';
			$column_list = self::intersect_migration_columns( $site_table, $network_table, $columns );
			if ( '' === $column_list ) {
				continue;
			}

			// $column_list is built solely from a fixed allowlist of column names
			// intersected against the live table columns (see
			// intersect_migration_columns), never from user input — so interpolating
			// it into the column list is safe. Table names use %i placeholders.
			$migration_sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO %i ({$column_list}) SELECT {$column_list} FROM %i",
				$network_table,
				$site_table
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $migration_sql );

			$rows    = false !== $result ? (int) $result : 0;
			$copied += $rows;

			if ( $rows > 0 ) {
				do_action(
					'datamachine_log',
					'info',
					'Migrated per-site chat sessions into network table',
					array(
						'blog_id'       => (int) $blog_id,
						'source_table'  => $site_table,
						'network_table' => $network_table,
						'rows_copied'   => $rows,
					)
				);
			}
		}

		return $copied;
	}

	/**
	 * Build the column list shared by both the legacy and network tables.
	 *
	 * Different installs created the chat table at different schema versions, so a
	 * legacy per-site table may be missing columns the network table has (or vice
	 * versa). Copying only the intersection keeps the INSERT…SELECT valid on every
	 * install without assuming a single column set.
	 *
	 * @param string $source_table Legacy per-site table name (prefixed).
	 * @param string $target_table Network table name (prefixed).
	 * @param string $columns      Comma-separated candidate column list.
	 * @return string Comma-separated intersection, or '' when session_id is absent.
	 */
	private static function intersect_migration_columns( string $source_table, string $target_table, string $columns ): string {
		global $wpdb;

		$candidates = array_map( 'trim', explode( ',', $columns ) );
		$shared     = array();

		foreach ( $candidates as $column ) {
			if ( '' === $column ) {
				continue;
			}

			if ( self::column_exists( $source_table, $column, $wpdb ) && self::column_exists( $target_table, $column, $wpdb ) ) {
				$shared[] = $column;
			}
		}

		// session_id is the primary key and the de-dupe anchor — without it the
		// migration is meaningless, so bail rather than copy an unkeyed subset.
		if ( ! in_array( 'session_id', $shared, true ) ) {
			return '';
		}

		return implode( ', ', $shared );
	}

	/**
	 * Ensure transcript owner columns exist and migrate legacy rows to user ownership.
	 *
	 * @return void
	 */
	public static function ensure_owner_columns(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( ! self::column_exists( $table_name, 'owner_type', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN owner_type VARCHAR(40) NOT NULL DEFAULT %s', $table_name, 'user' ) );
		}

		if ( ! self::column_exists( $table_name, 'owner_key_hash', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN owner_key_hash VARCHAR(64) NULL', $table_name ) );
		}

		if ( ! self::column_exists( $table_name, 'owner_label', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN owner_label VARCHAR(191) NULL', $table_name ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET owner_type = %s WHERE owner_type IS NULL OR owner_type = %s',
				$table_name,
				'user',
				''
			)
		);

		// Backfill user-owned hashes row-by-row because the value is intentionally
		// derived through PHP's hash() to stay engine-independent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT session_id, user_id FROM %i WHERE owner_key_hash IS NULL OR owner_key_hash = %s', $table_name, '' ), ARRAY_A );
		foreach ( $rows as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$owner   = ChatTranscriptOwner::user_owner( $user_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$table_name,
				array(
					'owner_type'     => $owner['owner_type'],
					'owner_key_hash' => $owner['owner_key_hash'],
					'owner_label'    => $owner['owner_label'],
				),
				array( 'session_id' => (string) $row['session_id'] ),
				array( '%s', '%s', '%s' ),
				array( '%s' )
			);
		}
	}

	/**
	 * Ensure workspace columns exist for Agents API-scoped transcript rows.
	 *
	 * Existing rows are stamped with the current site scope because the prefixed
	 * WordPress table was already site-local before this generic boundary existed.
	 *
	 * @return void
	 */
	public static function ensure_workspace_columns(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();
		$workspace  = WordPressWorkspaceScope::current();

		if ( ! self::column_exists( $table_name, 'workspace_type', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN workspace_type VARCHAR(50) NULL', $table_name ) );
		}

		if ( ! self::column_exists( $table_name, 'workspace_id', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN workspace_id VARCHAR(191) NULL', $table_name ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET workspace_type = %s, workspace_id = %s WHERE workspace_type IS NULL OR workspace_type = %s OR workspace_id IS NULL OR workspace_id = %s',
				$table_name,
				$workspace->workspace_type,
				$workspace->workspace_id,
				'',
				''
			)
		);
	}

	/**
	 * Ensure agent_id column exists for layered architecture migration.
	 *
	 * dbDelta can miss edge cases on existing installs, so we perform an explicit
	 * column check and ALTER as a safety net.
	 *
	 * @since 0.36.1
	 * @return void
	 */
	public static function ensure_agent_id_column(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( self::column_exists( $table_name, 'agent_id', $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it. Column position
		// is cosmetic — both engines accept the bare ADD COLUMN form.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN agent_id BIGINT(20) UNSIGNED NULL', $table_name ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY agent_id (agent_id)', $table_name ) );
	}

	/**
	 * Ensure the mode column and indexes exist.
	 *
	 * @return void
	 */
	public static function ensure_mode_column(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( ! self::column_exists( $table_name, 'mode', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it. Column position
			// is cosmetic — both engines accept the bare ADD COLUMN form.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT %s', $table_name, 'chat' ) );
		}

		// Idempotent index normalization: add current indexes when needed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$indexes       = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name ) );
		$existing_keys = array_unique( array_column( $indexes, 'Key_name' ) );

		if ( ! in_array( 'mode', $existing_keys, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY mode (mode)', $table_name ) );
		}
		if ( ! in_array( 'user_mode', $existing_keys, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY user_mode (user_id, mode)', $table_name ) );
		}
	}

	/**
	 * Ensure last_read_at column exists for unread message tracking.
	 *
	 * dbDelta can miss edge cases on existing installs, so we perform an explicit
	 * column check and ALTER as a safety net.
	 *
	 * @since 0.62.0
	 * @return void
	 */
	public static function ensure_last_read_at_column(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( self::column_exists( $table_name, 'last_read_at', $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it. Column position
		// is cosmetic — both engines accept the bare ADD COLUMN form.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN last_read_at DATETIME NULL', $table_name ) );
	}

	/**
	 * Ensure transcript lock columns exist for Agents API single-writer locking.
	 *
	 * @return void
	 */
	public static function ensure_transcript_lock_columns(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( ! self::column_exists( $table_name, 'transcript_lock_token', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN transcript_lock_token VARCHAR(64) NULL', $table_name ) );
		}

		if ( ! self::column_exists( $table_name, 'transcript_lock_expires_at', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN transcript_lock_expires_at DATETIME NULL', $table_name ) );
		}
	}

	/**
	 * Check if table exists
	 *
	 * @return bool True if table exists
	 */
	public static function table_exists( string $table_name = '', ?\wpdb $wpdb = null ): bool {
		if ( '' === $table_name ) {
			$table_name = self::get_prefixed_table_name();
		}

		return parent::database_table_exists( $table_name, $wpdb );
	}

	/**
	 * Get table name with prefix (static context).
	 *
	 * @return string Full table name
	 */
	public static function get_prefixed_table_name(): string {
		return self::sanitize_table_name( self::get_table_prefix() . self::TABLE_NAME );
	}

	/**
	 * Sanitize table name to alphanumeric and underscore.
	 */
	private static function sanitize_table_name( string $table_name ): string {
		return preg_replace( '/[^A-Za-z0-9_]/', '', $table_name );
	}

	/**
	 * Get sanitized table name for queries.
	 */
	private static function get_escaped_table_name(): string {
		return esc_sql( self::get_prefixed_table_name() );
	}


	/**
	 * Create new chat session
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the session.
	 * @param int                 $user_id   WordPress user ID.
	 * @param string              $agent_slug Registered agent slug.
	 * @param array               $metadata  Optional session metadata.
	 * @param string              $context   Execution context (chat, pipeline, system).
	 * @return string Session ID (UUID)
	 */
	public function create_session( ...$args ): string {
		global $wpdb;

		list( $workspace, $user_id, $agent, $metadata, $context ) = self::normalize_create_session_args( $args );
		$owner = self::normalize_owner_from_metadata( $metadata, $user_id );

		try {
			$identity   = self::resolve_agent_identity_for_session( $agent );
			$agent_id   = $identity['agent_id'];
			$agent_slug = $identity['agent_slug'];
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to resolve transcript session agent identity',
				array(
					'user_id' => $user_id,
					'agent'   => is_scalar( $agent ) ? (string) $agent : gettype( $agent ),
					'error'   => $e->getMessage(),
					'mode'    => $context,
				)
			);
			return '';
		}

		$session_id = wp_generate_uuid4();
		$table_name = self::get_prefixed_table_name();
		$metadata   = array_merge(
			$metadata,
			array(
				'workspace_type' => $workspace->workspace_type,
				'workspace_id'   => $workspace->workspace_id,
			)
		);
		if ( '' !== $agent_slug ) {
			$metadata['agent_slug'] = $agent_slug;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table_name,
			array(
				'session_id'     => $session_id,
				'workspace_type' => $workspace->workspace_type,
				'workspace_id'   => $workspace->workspace_id,
				'user_id'        => $user_id,
				'owner_type'     => $owner['owner_type'],
				'owner_key_hash' => $owner['owner_key_hash'],
				'owner_label'    => $owner['owner_label'],
				'agent_id'       => $agent_id > 0 ? $agent_id : null,
				'messages'       => wp_json_encode( array() ),
				'metadata'       => wp_json_encode( $metadata ),
				'provider'       => null,
				'model'          => null,
				'mode'           => $context,
				'expires_at'     => null,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to create chat session',
				array(
					'user_id' => $user_id,
					'error'   => $wpdb->last_error,
					'mode'    => $context,
				)
			);
			return '';
		}

		do_action(
			'datamachine_log',
			'debug',
			'Chat session created',
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'agent_id'   => $agent_id,
				'mode'       => $context,
			)
		);

		return $session_id;
	}

	/**
	 * Create a new chat session for a canonical Agents API principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace  Workspace owning the session.
	 * @param array{type:string,key:string} $owner      Canonical principal owner.
	 * @param string                        $agent_slug Registered agent slug.
	 * @param array                         $metadata   Optional session metadata.
	 * @param string                        $context    Execution context.
	 * @return string Session ID (UUID), or empty string on failure.
	 */
	public function create_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		unset( $workspace );

		$transcript_owner = self::principal_owner_to_transcript_owner( $owner );
		if ( null === $transcript_owner ) {
			return '';
		}

		$metadata['transcript_owner'] = $transcript_owner;
		return $this->create_session( WordPressWorkspaceScope::current(), (int) $transcript_owner['user_id'], $agent_slug, $metadata, $context );
	}

	/**
	 * Normalize create-session arguments across current and workspace-aware contracts.
	 *
	 * @param array $args Raw method arguments.
	 * @return array{0:WP_Agent_Workspace_Scope,1:int,2:int|string,3:array,4:string}
	 */
	private static function normalize_create_session_args( array $args ): array {
		if ( isset( $args[0] ) && $args[0] instanceof WP_Agent_Workspace_Scope ) {
			return array(
				$args[0],
				(int) ( $args[1] ?? 0 ),
				is_string( $args[2] ?? null ) ? (string) $args[2] : (int) ( $args[2] ?? 0 ),
				is_array( $args[3] ?? null ) ? $args[3] : array(),
				(string) ( $args[4] ?? 'chat' ),
			);
		}

		return array(
			WordPressWorkspaceScope::current(),
			(int) ( $args[0] ?? 0 ),
			is_string( $args[1] ?? null ) ? (string) $args[1] : (int) ( $args[1] ?? 0 ),
			is_array( $args[2] ?? null ) ? $args[2] : array(),
			(string) ( $args[3] ?? 'chat' ),
		);
	}

	/**
	 * Normalize transcript owner metadata for storage.
	 *
	 * @param array $metadata Session metadata.
	 * @param int   $user_id  Compatibility user ID.
	 * @return array{owner_type:string,owner_key_hash:string,owner_label:string}
	 */
	private static function normalize_owner_from_metadata( array &$metadata, int $user_id ): array {
		$owner = is_array( $metadata['transcript_owner'] ?? null ) ? $metadata['transcript_owner'] : ChatTranscriptOwner::user_owner( $user_id );

		if ( empty( $owner['owner_key_hash'] ) && ! empty( $owner['owner_key'] ) ) {
			$owner['owner_key_hash'] = ChatTranscriptOwner::hash_owner_key( (string) $owner['owner_key'] );
		}

		$normalized = array(
			'owner_type'     => sanitize_key( (string) ( $owner['owner_type'] ?? 'user' ) ),
			'owner_key_hash' => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $owner['owner_key_hash'] ?? '' ) ) ),
			'owner_label'    => mb_substr( sanitize_text_field( (string) ( $owner['owner_label'] ?? '' ) ), 0, 191 ),
		);

		if ( '' === $normalized['owner_type'] || '' === $normalized['owner_key_hash'] ) {
			$fallback   = ChatTranscriptOwner::user_owner( $user_id );
			$normalized = array(
				'owner_type'     => $fallback['owner_type'],
				'owner_key_hash' => $fallback['owner_key_hash'],
				'owner_label'    => $fallback['owner_label'],
			);
		}

		$metadata['transcript_owner'] = $normalized;

		return $normalized;
	}

	/**
	 * Add owner constraints to a WHERE fragment.
	 *
	 * @param array      $where      WHERE fragments.
	 * @param array      $query_args Query args.
	 * @param array|null $owner      Optional owner array.
	 * @return void
	 */
	private static function append_owner_where( array &$where, array &$query_args, ?array $owner ): void {
		if ( empty( $owner['owner_type'] ) || empty( $owner['owner_key_hash'] ) ) {
			return;
		}

		$where[]      = 'owner_type = %s';
		$query_args[] = sanitize_key( (string) $owner['owner_type'] );
		$where[]      = 'owner_key_hash = %s';
		$query_args[] = (string) $owner['owner_key_hash'];
	}

	/**
	 * Check whether a loaded session row belongs to an owner.
	 *
	 * @param array $session Session row.
	 * @param array $owner   Owner array.
	 * @return bool
	 */
	public function session_matches_owner( array $session, array $owner ): bool {
		$session_owner_type = (string) ( $session['owner_type'] ?? '' );
		$session_owner_hash = (string) ( $session['owner_key_hash'] ?? '' );

		if ( '' === $session_owner_type || '' === $session_owner_hash ) {
			$legacy             = ChatTranscriptOwner::user_owner( absint( $session['user_id'] ?? 0 ) );
			$session_owner_type = $legacy['owner_type'];
			$session_owner_hash = $legacy['owner_key_hash'];
		}

		return (string) ( $owner['owner_type'] ?? '' ) === $session_owner_type
			&& (string) ( $owner['owner_key_hash'] ?? '' ) === $session_owner_hash;
	}

	/**
	 * Resolve the generic transcript agent slug to Data Machine's stored agent ID.
	 *
	 * Integer input is retained as a Data Machine-internal compatibility path for
	 * callers that have not crossed the generic Agents API boundary.
	 *
	 * @param int|string $agent Agent slug, agent ID, or empty value.
	 * @return array{agent_id:int,agent_slug:string}
	 */
	private static function resolve_agent_identity_for_session( int|string $agent ): array {
		if ( is_int( $agent ) || is_numeric( $agent ) ) {
			$agent_id = (int) $agent;
			if ( $agent_id <= 0 ) {
				return array(
					'agent_id'   => 0,
					'agent_slug' => '',
				);
			}

			$identity = ( new AgentIdentityResolver() )->resolve_agent_identity( $agent_id );
			return array(
				'agent_id'   => $identity->agent_id,
				'agent_slug' => $identity->agent_slug,
			);
		}

		$agent_slug = AgentIdentityResolver::normalize_agent_slug( $agent );
		if ( '' === $agent_slug ) {
			return array(
				'agent_id'   => 0,
				'agent_slug' => '',
			);
		}

		$identity = ( new AgentIdentityResolver() )->resolve_agent_identity( $agent_slug );
		return array(
			'agent_id'   => $identity->agent_id,
			'agent_slug' => $identity->agent_slug,
		);
	}

	/**
	 * Normalize pending-session arguments across current and workspace-aware contracts.
	 *
	 * @param array $args Raw method arguments.
	 * @return array{0:WP_Agent_Workspace_Scope,1:int,2:int,3:string,4:int|null,5:array|null,6:bool}
	 */
	private static function normalize_recent_pending_session_args( array $args ): array {
		if ( isset( $args[0] ) && $args[0] instanceof WP_Agent_Workspace_Scope ) {
			return array(
				$args[0],
				(int) ( $args[1] ?? 0 ),
				(int) ( $args[2] ?? 600 ),
				(string) ( $args[3] ?? 'chat' ),
				isset( $args[4] ) ? (int) $args[4] : null,
				is_array( $args[5] ?? null ) ? $args[5] : null,
				! empty( $args[6] ),
			);
		}

		return array(
			WordPressWorkspaceScope::current(),
			(int) ( $args[0] ?? 0 ),
			(int) ( $args[1] ?? 600 ),
			(string) ( $args[2] ?? 'chat' ),
			isset( $args[3] ) ? (int) $args[3] : null,
			is_array( $args[4] ?? null ) ? $args[4] : null,
			! empty( $args[5] ),
		);
	}

	/**
	 * Retrieve session data
	 *
	 * @param string $session_id Session UUID
	 * @return array|null Session data or null if not found
	 */
	public function get_session( string $session_id ): ?array {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$session = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %s',
				$table_name,
				$session_id
			),
			ARRAY_A
		);

		if ( ! $session ) {
			return null;
		}

		$session['messages']   = self::normalize_messages( json_decode( $session['messages'], true ) ?? array() );
		$session['metadata']   = json_decode( $session['metadata'], true ) ?? array();
		$session['agent_slug'] = self::resolve_agent_slug_from_session_row( $session );
		$session['title']      = is_string( $session['title'] ?? null ) ? $session['title'] : '';
		$session['provider']   = is_string( $session['provider'] ?? null ) ? $session['provider'] : '';
		$session['model']      = is_string( $session['model'] ?? null ) ? $session['model'] : '';

		return $session;
	}

	/**
	 * List transcript sessions for a workspace/user pair.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the sessions.
	 * @param int                      $user_id   WordPress user ID owning the sessions.
	 * @param array                    $args      Optional filters/pagination.
	 * @return array<int,array<string,mixed>> Session rows.
	 */
	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		global $wpdb;

		$table_name       = self::get_prefixed_table_name();
		$include_messages = (bool) ( $args['include_messages'] ?? true );
		$limit            = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$offset           = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$owner_only       = ! empty( $args['owner_only'] ) && is_array( $args['transcript_owner'] ?? null );
		$where            = array(
			'workspace_type = %s',
			'workspace_id = %s',
		);
		$query_args       = array(
			$table_name,
			$workspace->workspace_type,
			$workspace->workspace_id,
		);

		if ( ! $owner_only ) {
			$where[]      = 'user_id = %d';
			$query_args[] = $user_id;
		}

		if ( is_string( $args['context'] ?? null ) && '' !== $args['context'] ) {
			$where[]      = 'mode = %s';
			$query_args[] = $args['context'];
		}

		self::append_owner_where( $where, $query_args, is_array( $args['transcript_owner'] ?? null ) ? $args['transcript_owner'] : null );

		if ( is_string( $args['agent_slug'] ?? null ) && '' !== $args['agent_slug'] ) {
			try {
				$identity = self::resolve_agent_identity_for_session( $args['agent_slug'] );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
				return array();
			}

			$where[]      = 'agent_id = %d';
			$query_args[] = $identity['agent_id'];
		}

		$select = $include_messages ? '*' : 'session_id, workspace_type, workspace_id, user_id, owner_type, owner_key_hash, owner_label, agent_id, title, metadata, provider, model, provider_response_id, mode, created_at, updated_at, last_read_at, expires_at';
		$sql    = 'SELECT ' . $select . ' FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';

		$query_args[] = $limit;
		$query_args[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$sessions = $wpdb->get_results( $wpdb->prepare( $sql, ...$query_args ), ARRAY_A );

		if ( ! $sessions ) {
			return array();
		}

		foreach ( $sessions as &$session ) {
			if ( $include_messages ) {
				$session['messages'] = self::normalize_messages( json_decode( $session['messages'] ?? '[]', true ) ?? array() );
			}
			$session['metadata']   = json_decode( $session['metadata'] ?? '[]', true ) ?? array();
			$session['agent_slug'] = self::resolve_agent_slug_from_session_row( $session );
			$session['title']      = is_string( $session['title'] ?? null ) ? $session['title'] : '';
			$session['provider']   = is_string( $session['provider'] ?? null ) ? $session['provider'] : '';
			$session['model']      = is_string( $session['model'] ?? null ) ? $session['model'] : '';
		}
		unset( $session );

		return $sessions;
	}

	/**
	 * List chat sessions for a canonical Agents API principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace Workspace owning the sessions.
	 * @param array{type:string,key:string} $owner     Canonical principal owner.
	 * @param array                         $args      Optional filters/pagination.
	 * @return array<int,array<string,mixed>> Session rows.
	 */
	public function list_sessions_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, array $args = array() ): array {
		unset( $workspace );

		$transcript_owner = self::principal_owner_to_transcript_owner( $owner );
		if ( null === $transcript_owner ) {
			return array();
		}

		$args['transcript_owner'] = $transcript_owner;
		$args['owner_only']       = true;
		return $this->list_sessions( WordPressWorkspaceScope::current(), (int) $transcript_owner['user_id'], $args );
	}

	/**
	 * Read one transcript session for a canonical Agents API principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace  Workspace owning the session.
	 * @param array{type:string,key:string} $owner      Canonical principal owner.
	 * @param string                        $session_id Session ID.
	 * @return array<string,mixed>|null Session row, or null when missing/not owned.
	 */
	public function get_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $session_id ): ?array {
		unset( $workspace );

		$transcript_owner = self::principal_owner_to_transcript_owner( $owner );
		if ( null === $transcript_owner ) {
			return null;
		}

		$session = $this->get_session( $session_id );
		if ( ! is_array( $session ) || ! self::session_matches_owner( $session, $transcript_owner ) ) {
			return null;
		}

		return $session;
	}

	/**
	 * Resolve the generic transcript agent slug from a stored session row.
	 *
	 * @param array<string,mixed> $session Stored session row.
	 * @return string|null Agent slug when resolvable.
	 */
	private static function resolve_agent_slug_from_session_row( array $session ): ?string {
		$agent_id = isset( $session['agent_id'] ) ? (int) $session['agent_id'] : 0;
		if ( $agent_id <= 0 ) {
			return null;
		}

		try {
			return ( new AgentIdentityResolver() )->resolve_agent_slug( $agent_id );
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Update session with new messages and metadata
	 *
	 * @param string $session_id Session UUID
	 * @param array  $messages   Complete messages array
	 * @param array  $metadata   Updated metadata
	 * @param string $provider   AI provider
	 * @param string $model      AI model
	 * @param string|null $provider_response_id Provider-side response/state ID.
	 * @return bool Success
	 */
	public function update_session(
		string $session_id,
		array $messages,
		array $metadata = array(),
		string $provider = '',
		string $model = '',
		?string $provider_response_id = null
	): bool {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		try {
			$normalized_messages = WP_Agent_Message::normalize_many( $messages );
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to normalize chat session messages for update',
				array(
					'session_id' => $session_id,
					'error'      => $e->getMessage(),
					'mode'       => 'chat',
				)
			);
			return false;
		}

		$update_data = array(
			'messages' => wp_json_encode( $normalized_messages ),
			'metadata' => wp_json_encode( $metadata ),
		);

		$update_format = array( '%s', '%s' );

		if ( ! empty( $provider ) ) {
			$update_data['provider'] = $provider;
			$update_format[]         = '%s';
		}

		if ( ! empty( $model ) ) {
			$update_data['model'] = $model;
			$update_format[]      = '%s';
		}

		if ( null !== $provider_response_id ) {
			$update_data['provider_response_id'] = $provider_response_id;
			$update_format[]                     = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table_name,
			$update_data,
			array( 'session_id' => $session_id ),
			$update_format,
			array( '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to update chat session',
				array(
					'session_id' => $session_id,
					'error'      => $wpdb->last_error,
					'mode'       => 'chat',
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Acquire an advisory transcript lock for a chat session.
	 *
	 * @param string $session_id   Session UUID.
	 * @param int    $ttl_seconds  Lock TTL in seconds.
	 * @return string|null Lock token, or null when another active writer owns it.
	 */
	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();
		$token      = $this->generate_lock_token();
		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + max( 1, $ttl_seconds ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				SET transcript_lock_token = %s, transcript_lock_expires_at = %s
				WHERE session_id = %s
				AND (
					transcript_lock_token IS NULL
					OR transcript_lock_token = %s
					OR transcript_lock_expires_at IS NULL
					OR transcript_lock_expires_at <= %s
				)',
				$table_name,
				$token,
				$expires_at,
				$session_id,
				'',
				$now
			)
		);

		if ( 1 !== (int) $result ) {
			return null;
		}

		return $token;
	}

	/**
	 * Release an advisory transcript lock if the active token matches.
	 *
	 * @param string $session_id  Session UUID.
	 * @param string $lock_token  Token returned by acquire_session_lock().
	 * @return bool True when the active lock was released.
	 */
	public function release_session_lock( string $session_id, string $lock_token ): bool {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				SET transcript_lock_token = NULL, transcript_lock_expires_at = NULL
				WHERE session_id = %s AND transcript_lock_token = %s',
				$table_name,
				$session_id,
				$lock_token
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Generate an opaque lock ownership token.
	 *
	 * @return string
	 */
	private function generate_lock_token(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			unset( $e );
			return str_replace( '-', '', wp_generate_uuid4() ) . str_replace( '.', '', uniqid( '', true ) );
		}
	}

	/**
	 * Delete session
	 *
	 * @param string $session_id Session UUID
	 * @return bool Success
	 */
	public function delete_session( string $session_id ): bool {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table_name,
			array( 'session_id' => $session_id ),
			array( '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to delete chat session',
				array(
					'session_id' => $session_id,
					'error'      => $wpdb->last_error,
					'mode'       => 'chat',
				)
			);
			return false;
		}

		do_action(
			'datamachine_log',
			'debug',
			'Chat session deleted',
			array(
				'session_id' => $session_id,
				'mode'       => 'chat',
			)
		);

		return true;
	}

	/**
	 * Cleanup expired sessions
	 *
	 * @return int Number of deleted sessions
	 */
	public function cleanup_expired_sessions(): int {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at IS NOT NULL AND expires_at < %s',
				$table_name,
				current_time( 'mysql', true )
			)
		);

		if ( $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Cleaned up expired chat sessions',
				array(
					'deleted_count' => $deleted,
					'mode'          => 'chat',
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * Get all sessions for a user
	 *
	 * @param int         $user_id  WordPress user ID
	 * @param int         $limit    Maximum sessions to return
	 * @param int         $offset   Pagination offset
	 * @param string|null $mode  Optional mode filter
	 * @param int|null    $agent_id Optional agent ID filter (null = no filter)
	 * @return array Array of session data
	 */
	public function get_user_sessions(
		int $user_id,
		int $limit = 20,
		int $offset = 0,
		?string $mode = null,
		?int $agent_id = null,
		?array $transcript_owner = null
	): array {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();
		$where      = array( 'user_id = %d' );
		$params     = array( $table_name, $user_id );

		if ( null !== $mode && '' !== $mode ) {
			$where[]  = 'mode = %s';
			$params[] = $mode;
		}

		if ( null !== $agent_id ) {
			$where[]  = 'agent_id = %d';
			$params[] = $agent_id;
		}

		self::append_owner_where( $where, $params, $transcript_owner );

		$params[] = $limit;
		$params[] = $offset;

		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$sessions = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		if ( ! $sessions ) {
			return array();
		}

		// Batch-load the agents referenced by these sessions so each row can
		// expose agent_name/agent_slug without an N+1 query inside the loop.
		$agent_ids_in_sessions = array();
		foreach ( $sessions as $session ) {
			$session_agent_id = isset( $session['agent_id'] ) ? (int) $session['agent_id'] : 0;
			if ( $session_agent_id > 0 ) {
				$agent_ids_in_sessions[] = $session_agent_id;
			}
		}

		$agents_by_id = array();
		if ( ! empty( $agent_ids_in_sessions ) ) {
			$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
			foreach ( $agents_repo->get_agents_by_ids( $agent_ids_in_sessions ) as $agent_row ) {
				$agents_by_id[ (int) $agent_row['agent_id'] ] = $agent_row;
			}
		}

		$result = array();
		foreach ( $sessions as $session ) {
			$messages      = self::normalize_messages( json_decode( $session['messages'] ?? '[]', true ) ?? array() );
			$first_message = '';
			foreach ( $messages as $msg ) {
				if ( ( $msg['role'] ?? '' ) === 'user' ) {
					$first_message = self::message_content_text( $msg );
					break;
				}
			}

			$last_read_at     = $session['last_read_at'] ?? null;
			$session_agent_id = isset( $session['agent_id'] ) ? (int) $session['agent_id'] : 0;
			$agent_row        = $session_agent_id > 0 ? ( $agents_by_id[ $session_agent_id ] ?? null ) : null;

			$result[] = array(
				'session_id'    => $session['session_id'],
				'title'         => $session['title'] ?? null,
				'mode'          => $session['mode'] ?? 'chat',
				'first_message' => mb_substr( $first_message, 0, 100 ),
				'message_count' => count( $messages ),
				'unread_count'  => $this->count_unread( $messages, $last_read_at ),
				'agent_id'      => $session_agent_id > 0 ? $session_agent_id : null,
				'agent_slug'    => $agent_row ? (string) $agent_row['agent_slug'] : null,
				'agent_name'    => $agent_row ? (string) $agent_row['agent_name'] : null,
				'created_at'    => DateFormatter::format_for_api( $session['created_at'] ?? null ),
				'updated_at'    => DateFormatter::format_for_api( $session['updated_at'] ?? $session['created_at'] ?? null ),
			);
		}

		return $result;
	}

	/**
	 * Get total session count for a user
	 *
	 * @param int         $user_id  WordPress user ID
	 * @param string|null $mode  Optional mode filter
	 * @param int|null    $agent_id Optional agent ID filter (null = no filter)
	 * @return int Total session count
	 */
	public function get_user_session_count(
		int $user_id,
		?string $mode = null,
		?int $agent_id = null,
		?array $transcript_owner = null
	): int {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();
		$where      = array( 'user_id = %d' );
		$params     = array( $table_name, $user_id );

		if ( null !== $mode && '' !== $mode ) {
			$where[]  = 'mode = %s';
			$params[] = $mode;
		}

		if ( null !== $agent_id ) {
			$where[]  = 'agent_id = %d';
			$params[] = $agent_id;
		}

		self::append_owner_where( $where, $params, $transcript_owner );

		$sql = 'SELECT COUNT(*) FROM %i WHERE ' . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );

		return (int) $count;
	}

	/**
	 * Find a recent pending session for deduplication
	 *
	 * Returns the most recent session that:
	 * - Belongs to this user
	 * - Was created within the threshold (default 10 minutes)
	 * - Has 0 messages OR is actively processing (user message added but no AI response)
	 * - Matches the specified mode
	 *
	 * This prevents duplicate sessions when requests timeout at Cloudflare
	 * but PHP continues executing. On retry, we reuse the pending session
	 * instead of creating a new one.
	 *
	 * @since 0.9.8
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the session.
	 * @param int                 $user_id   WordPress user ID.
	 * @param int                 $seconds   Lookback window in seconds (default 600 = 10 minutes).
	 * @param string              $context   Context filter.
	 * @param int|null            $token_id  Optional token ID for login-scoped deduplication.
	 * @return array|null Session data or null if none found
	 */
	public function get_recent_pending_session( ...$args ): ?array {
		global $wpdb;

		list( $workspace, $user_id, $seconds, $context, $token_id, $transcript_owner, $owner_only ) = self::normalize_recent_pending_session_args( $args );

		$table_name  = self::get_prefixed_table_name();
		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - $seconds );

		$query  = "SELECT * FROM %i
				WHERE workspace_type = %s
				AND workspace_id = %s
				AND mode = %s
				AND created_at >= %s
				AND (
					(messages = '[]' OR messages = '' OR messages IS NULL)
					OR (metadata LIKE %s)
				)";
		$params = array(
			$table_name,
			$workspace->workspace_type,
			$workspace->workspace_id,
			$context,
			$cutoff_time,
			'%"status":"processing"%',
		);

		if ( ! $owner_only ) {
			$query   .= ' AND user_id = %d';
			$params[] = $user_id;
		}

		$query   .= ' AND metadata LIKE %s AND metadata LIKE %s';
		$params[] = '%"workspace_type":"' . $wpdb->esc_like( $workspace->workspace_type ) . '"%';
		$params[] = '%"workspace_id":"' . $wpdb->esc_like( $workspace->workspace_id ) . '"%';

		if ( null !== $token_id ) {
			$query   .= ' AND metadata LIKE %s';
			$params[] = '%"token_id":' . (int) $token_id . '%';
		}

		if ( is_array( $transcript_owner ) && ! empty( $transcript_owner['owner_type'] ) && ! empty( $transcript_owner['owner_key_hash'] ) ) {
			$query   .= ' AND owner_type = %s AND owner_key_hash = %s';
			$params[] = sanitize_key( (string) $transcript_owner['owner_type'] );
			$params[] = (string) $transcript_owner['owner_key_hash'];
		}

		$query .= ' ORDER BY created_at DESC LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$session = $wpdb->get_row(
			$wpdb->prepare( $query, $params ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( ! $session ) {
			return null;
		}

		$session['messages']   = self::normalize_messages( json_decode( $session['messages'], true ) ?? array() );
		$session['metadata']   = json_decode( $session['metadata'], true ) ?? array();
		$session['agent_slug'] = self::resolve_agent_slug_from_session_row( $session );

		return $session;
	}

	/**
	 * Find a recent pending session for a canonical Agents API principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace Workspace owning the session.
	 * @param array{type:string,key:string} $owner     Canonical principal owner.
	 * @param int                           $seconds   Lookback window in seconds.
	 * @param string                        $context   Context filter.
	 * @param int|null                      $token_id  Optional token ID for token-scoped deduplication.
	 * @return array|null Session data or null if none found.
	 */
	public function get_recent_pending_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		$transcript_owner = self::principal_owner_to_transcript_owner( $owner );
		if ( null === $transcript_owner ) {
			return null;
		}

		return $this->get_recent_pending_session( $workspace, (int) $transcript_owner['user_id'], $seconds, $context, $token_id, $transcript_owner, true );
	}

	/**
	 * Convert an Agents API principal owner into Data Machine's stored owner shape.
	 *
	 * @param array{type:string,key:string} $owner Canonical principal owner.
	 * @return array{owner_type:string,owner_key:string,owner_key_hash:string,owner_label:string,user_id:int}|null
	 */
	private static function principal_owner_to_transcript_owner( array $owner ): ?array {
		$transcript_owner = ChatTranscriptOwner::resolve_for_request(
			array(
				'transcript_owner' => array(
					'type' => (string) ( $owner['type'] ?? '' ),
					'key'  => (string) ( $owner['key'] ?? '' ),
				),
			),
			WP_Agent_Execution_Principal::OWNER_TYPE_USER === (string) ( $owner['type'] ?? '' ) ? absint( $owner['key'] ?? 0 ) : 0
		);

		return $transcript_owner instanceof WP_Error ? null : $transcript_owner;
	}

	/**
	 * Update session title
	 *
	 * @param string $session_id Session UUID
	 * @param string $title New title
	 * @return bool Success
	 */
	public function update_title( string $session_id, string $title ): bool {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table_name,
			array( 'title' => $title ),
			array( 'session_id' => $session_id ),
			array( '%s' ),
			array( '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to update chat session title',
				array(
					'session_id' => $session_id,
					'error'      => $wpdb->last_error,
					'mode'       => 'chat',
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Count unread assistant messages in a session.
	 *
	 * Counts assistant messages whose metadata.timestamp is newer than
	 * the given last_read_at value. If last_read_at is NULL, all assistant
	 * messages are considered unread.
	 *
	 * @since 0.62.0
	 *
	 * @param array       $messages    Decoded messages array from the session.
	 * @param string|null $last_read_at ISO 8601 or MySQL datetime string, or null if never read.
	 * @return int Number of unread assistant messages.
	 */
	public function count_unread( array $messages, ?string $last_read_at ): int {
		$count = 0;

		foreach ( $messages as $msg ) {
			$msg = WP_Agent_Message::normalize( $msg );
			if ( ( $msg['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}

			// Skip tool call/result messages — only count visible assistant responses.
			$type = $msg['type'] ?? WP_Agent_Message::TYPE_TEXT;
			if ( WP_Agent_Message::TYPE_TOOL_CALL === $type || WP_Agent_Message::TYPE_TOOL_RESULT === $type ) {
				continue;
			}

			if ( null === $last_read_at ) {
				++$count;
				continue;
			}

			$timestamp = $msg['metadata']['timestamp'] ?? null;
			if ( $timestamp && strtotime( $timestamp ) > strtotime( $last_read_at ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Normalize a decoded message list to the canonical Data Machine envelope.
	 *
	 * @param array $messages Decoded messages.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_messages( array $messages ): array {
		try {
			return WP_Agent_Message::normalize_many( $messages );
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'Chat: Failed to normalize stored messages',
				array( 'error' => $e->getMessage() )
			);
			return array();
		}
	}

	/**
	 * Render envelope content to a summary-safe string.
	 *
	 * @param array $message Message envelope.
	 * @return string Summary text.
	 */
	private static function message_content_text( array $message ): string {
		$content = $message['content'] ?? '';
		if ( is_string( $content ) ) {
			return $content;
		}

		return (string) wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Mark a session as read by setting last_read_at to the current time.
	 *
	 * @since 0.62.0
	 *
	 * @param string $session_id Session UUID.
	 * @param int    $user_id    User ID for ownership verification.
	 * @return string|false The new last_read_at value on success, false on failure.
	 */
	public function mark_session_read( string $session_id, int $user_id, ?array $transcript_owner = null ) {
		global $wpdb;

		$table_name   = self::get_prefixed_table_name();
		$last_read_at = (string) current_time( 'mysql', true );
		$where        = array(
			'session_id' => $session_id,
			'user_id'    => $user_id,
		);
		$where_format = array( '%s', '%d' );

		if ( is_array( $transcript_owner ) && ! empty( $transcript_owner['owner_type'] ) && ! empty( $transcript_owner['owner_key_hash'] ) ) {
			$where['owner_type']     = sanitize_key( (string) $transcript_owner['owner_type'] );
			$where['owner_key_hash'] = (string) $transcript_owner['owner_key_hash'];
			$where_format[]          = '%s';
			$where_format[]          = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table_name,
			array( 'last_read_at' => $last_read_at ),
			$where,
			array( '%s' ),
			$where_format
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to mark chat session as read',
				array(
					'session_id' => $session_id,
					'user_id'    => $user_id,
					'error'      => $wpdb->last_error,
				)
			);
			return false;
		}

		return $last_read_at;
	}

	/**
	 * Count old sessions based on retention period.
	 *
	 * @param int  $retention_days               Days to retain sessions.
	 * @param bool $exclude_pipeline_transcripts Whether pipeline transcripts are counted separately.
	 * @return int Number of matching sessions.
	 */
	public function count_old_sessions( int $retention_days, bool $exclude_pipeline_transcripts = false ): int {
		global $wpdb;

		$table_name  = self::get_prefixed_table_name();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		if ( $exclude_pipeline_transcripts ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i
					WHERE updated_at < %s
					AND NOT (mode = %s AND metadata LIKE %s)',
					$table_name,
					$cutoff_date,
					'pipeline',
					'%"source":"pipeline_transcript"%'
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE updated_at < %s',
				$table_name,
				$cutoff_date
			)
		);
	}

	/**
	 * Cleanup old sessions based on retention period
	 *
	 * @param int $retention_days Days to retain sessions
	 * @return int Number of deleted sessions
	 */
	public function cleanup_old_sessions( int $retention_days ): int {
		global $wpdb;

		$table_name  = self::get_prefixed_table_name();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE updated_at < %s',
				$table_name,
				$cutoff_date
			)
		);

		if ( $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Cleaned up old chat sessions',
				array(
					'deleted_count'  => $deleted,
					'retention_days' => $retention_days,
					'cutoff_date'    => $cutoff_date,
					'mode'           => 'chat',
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * Count pipeline transcript sessions older than the retention window.
	 *
	 * @since next
	 * @param int $retention_days Days to retain pipeline transcripts.
	 * @return int Number of matching transcript sessions.
	 */
	public function count_old_pipeline_transcripts( int $retention_days ): int {
		global $wpdb;

		if ( $retention_days <= 0 ) {
			return 0;
		}

		$table_name  = self::get_prefixed_table_name();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE mode = %s
				AND metadata LIKE %s
				AND updated_at < %s',
				$table_name,
				'pipeline',
				'%"source":"pipeline_transcript"%',
				$cutoff_date
			)
		);
	}

	/**
	 * Cleanup pipeline transcript sessions older than the retention window.
	 *
	 * Pipeline transcripts are written by AIConversationLoop when persistence
	 * is enabled. They live in the same chat_sessions table with
	 * `mode='pipeline'` and `metadata.source='pipeline_transcript'`. This
	 * cleanup is independent from the human chat retention so transcripts
	 * can have a tighter TTL (default 30 days) without shortening human
	 * chat retention (default 90 days).
	 *
	 * Idempotent. Safe to call from a recurring action.
	 *
	 * @since next
	 * @param int $retention_days Days to retain pipeline transcripts.
	 * @return int Number of deleted transcript sessions.
	 */
	public function cleanup_pipeline_transcripts( int $retention_days ): int {
		global $wpdb;

		if ( $retention_days <= 0 ) {
			return 0;
		}

		$table_name  = self::get_prefixed_table_name();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE mode = %s
				AND metadata LIKE %s
				AND updated_at < %s',
				$table_name,
				'pipeline',
				'%"source":"pipeline_transcript"%',
				$cutoff_date
			)
		);

		if ( $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Cleaned up old pipeline transcript sessions',
				array(
					'deleted_count'  => $deleted,
					'retention_days' => $retention_days,
					'cutoff_date'    => $cutoff_date,
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * Cleanup orphaned sessions from timeout failures
	 *
	 * Deletes sessions that:
	 * - Are older than the threshold (default 1 hour)
	 * - Have 0 messages (empty - orphaned from request timeouts)
	 *
	 * These sessions were created when requests timed out at Cloudflare
	 * before the AI could respond. They serve no purpose and clutter the UI.
	 *
	 * @since 0.9.8
	 * @param int $hours Hours threshold for orphaned sessions (default 1)
	 * @return int Number of deleted sessions
	 */
	public function cleanup_orphaned_sessions( int $hours = 1 ): int {
		global $wpdb;

		$table_name  = self::get_prefixed_table_name();
		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 3600 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i 
				WHERE created_at < %s 
				AND (messages = '[]' OR messages = '' OR messages IS NULL)",
				$table_name,
				$cutoff_time
			)
		);

		if ( $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Cleaned up orphaned chat sessions',
				array(
					'deleted_count'   => $deleted,
					'hours_threshold' => $hours,
					'cutoff_time'     => $cutoff_time,
					'mode'            => 'chat',
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * List lightweight session summaries for a single calendar day.
	 *
	 * Used by the Daily Memory Task so it can summarize "today's chats"
	 * without loading the full messages blob for every row.
	 *
	 * @param string $date Date string in `Y-m-d` format.
	 * @return array<int, array{session_id: string, title: string|null, mode: string, created_at: string}>
	 */
	public function list_sessions_for_day( string $date ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$table_name = self::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT session_id, title, mode, created_at
				 FROM %i
				 WHERE DATE(created_at) = %s
				 ORDER BY created_at ASC',
				$table_name,
				$date
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			$result[] = array(
				'session_id' => (string) $row['session_id'],
				'title'      => isset( $row['title'] ) ? (string) $row['title'] : null,
				'mode'       => isset($row['mode']) ? (string) $row['mode'] : 'chat',
				'created_at' => (string) $row['created_at'],
			);
		}

		return $result;
	}

	/**
	 * Storage metrics for the retention CLI.
	 *
	 * Returns the row count and on-disk size for the MySQL-backed chat
	 * sessions table. SQLite installs report rows but cannot compute
	 * table size, so `size_mb` is `'0.0'` there.
	 *
	 * @return array{rows: int, size_mb: string}|null
	 */
	public function get_storage_metrics(): ?array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array(
				'rows'    => 0,
				'size_mb' => '0.0',
			);
		}

		$table_name = self::get_prefixed_table_name();

		if ( self::is_sqlite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name )
			);
			return array(
				'rows'    => $count,
				'size_mb' => '0.0',
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT table_rows,
					ROUND((data_length + index_length) / 1024 / 1024, 1) AS size_mb
				FROM information_schema.tables
				WHERE table_schema = DATABASE()
				AND table_name = %s',
				$table_name
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array(
				'rows'    => 0,
				'size_mb' => '0.0',
			);
		}

		return array(
			'rows'    => (int) $row['table_rows'],
			'size_mb' => (string) $row['size_mb'],
		);
	}
}
