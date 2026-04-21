<?php
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
use DataMachine\Core\Database\BaseRepository;

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
			user_id BIGINT(20) UNSIGNED NOT NULL,
			agent_id BIGINT(20) UNSIGNED NULL,
			title VARCHAR(100) NULL,
			messages LONGTEXT NOT NULL,
			metadata LONGTEXT NULL,
			provider VARCHAR(50) NULL,
			model VARCHAR(100) NULL,
			mode VARCHAR(20) NOT NULL DEFAULT 'chat',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			last_read_at DATETIME NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY  (session_id),
			KEY user_id (user_id),
			KEY agent_id (agent_id),
			KEY mode (mode),
			KEY user_mode (user_id, mode),
			KEY created_at (created_at),
			KEY updated_at (updated_at),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
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
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN agent_id BIGINT(20) UNSIGNED NULL AFTER user_id', $table_name ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY agent_id (agent_id)', $table_name ) );
	}

	/**
	 * Ensure the mode column exists, migrating from legacy `context` (or even
	 * older `agent_type`) columns if present.
	 *
	 * Idempotent. Existing rows keep their values under the new `mode` name.
	 *
	 * @return void
	 */
	public static function ensure_mode_column(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( ! self::column_exists( $table_name, 'mode', $wpdb ) ) {
			if ( self::column_exists( $table_name, 'context', $wpdb ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN context mode VARCHAR(20) NOT NULL DEFAULT %s', $table_name, 'chat' ) );
			} elseif ( self::column_exists( $table_name, 'agent_type', $wpdb ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN agent_type mode VARCHAR(20) NOT NULL DEFAULT %s', $table_name, 'chat' ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT %s AFTER model', $table_name, 'chat' ) );
			}
		}

		// Idempotent index normalization: drop legacy indexes, add new — only when needed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$indexes       = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name ) );
		$existing_keys = array_unique( array_column( $indexes, 'Key_name' ) );

		foreach ( array( 'agent_type', 'user_agent', 'context', 'user_context' ) as $legacy_key ) {
			if ( in_array( $legacy_key, $existing_keys, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP KEY ' . $legacy_key, $table_name ) );
			}
		}
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
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN last_read_at DATETIME NULL AFTER updated_at', $table_name ) );
	}

	/**
	 * Check if table exists
	 *
	 * @return bool True if table exists
	 */
	public static function table_exists(): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$query      = $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_var( $query ) === $table_name;
	}

	/**
	 * Get table name with prefix (static context).
	 *
	 * @return string Full table name
	 */
	public static function get_prefixed_table_name(): string {
		global $wpdb;
		return self::sanitize_table_name( $wpdb->prefix . self::TABLE_NAME );
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
	 * @param int    $user_id  WordPress user ID
	 * @param array  $metadata Optional session metadata
	 * @param string $mode  Execution mode (chat, pipeline, system)
	 * @return string Session ID (UUID)
	 */
	public function create_session(
		int $user_id,
		int $agent_id = 0,
		array $metadata = array(),
		string $mode = 'chat'
	): string {
		global $wpdb;

		$session_id = wp_generate_uuid4();
		$table_name = self::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table_name,
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'agent_id'   => $agent_id > 0 ? $agent_id : null,
				'messages'   => wp_json_encode( array() ),
				'metadata'   => wp_json_encode( $metadata ),
				'provider'   => null,
				'model'      => null,
				'mode' => $mode,
				'expires_at' => null,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to create chat session',
				array(
					'user_id' => $user_id,
					'error'   => $wpdb->last_error,
					'mode' => $mode,
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
				'mode' => $mode,
			)
		);

		return $session_id;
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

		$session['messages'] = json_decode( $session['messages'], true ) ?? array();
		$session['metadata'] = json_decode( $session['metadata'], true ) ?? array();

		return $session;
	}

	/**
	 * Update session with new messages and metadata
	 *
	 * @param string $session_id Session UUID
	 * @param array  $messages   Complete messages array
	 * @param array  $metadata   Updated metadata
	 * @param string $provider   AI provider
	 * @param string $model      AI model
	 * @return bool Success
	 */
	public function update_session(
		string $session_id,
		array $messages,
		array $metadata = array(),
		string $provider = '',
		string $model = ''
	): bool {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		$update_data = array(
			'messages' => wp_json_encode( $messages ),
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
					'mode' => 'chat',
				)
			);
			return false;
		}

		return true;
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
					'mode' => 'chat',
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
				'mode' => 'chat',
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
					'mode' => 'chat',
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
		?int $agent_id = null
	): array {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( null !== $agent_id && null !== $mode && '' !== $mode ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d AND mode = %s AND agent_id = %d ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table_name,
					$user_id,
					$mode,
					$agent_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} elseif ( null !== $agent_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d AND agent_id = %d ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table_name,
					$user_id,
					$agent_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} elseif ( null !== $mode && '' !== $mode ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d AND mode = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table_name,
					$user_id,
					$mode,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table_name,
					$user_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

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
			$messages      = json_decode( $session['messages'] ?? '[]', true ) ?? array();
			$first_message = '';
			foreach ( $messages as $msg ) {
				if ( ( $msg['role'] ?? '' ) === 'user' ) {
					$first_message = $msg['content'] ?? '';
					break;
				}
			}

			$last_read_at     = $session['last_read_at'] ?? null;
			$session_agent_id = isset( $session['agent_id'] ) ? (int) $session['agent_id'] : 0;
			$agent_row        = $session_agent_id > 0 ? ( $agents_by_id[ $session_agent_id ] ?? null ) : null;

			$result[] = array(
				'session_id'    => $session['session_id'],
				'title'         => $session['title'] ?? null,
				'mode' => $session['mode'] ?? 'chat',
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
		?int $agent_id = null
	): int {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( null !== $agent_id && null !== $mode && '' !== $mode ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE user_id = %d AND mode = %s AND agent_id = %d',
					$table_name,
					$user_id,
					$mode,
					$agent_id
				)
			);
		} elseif ( null !== $agent_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE user_id = %d AND agent_id = %d',
					$table_name,
					$user_id,
					$agent_id
				)
			);
		} elseif ( null !== $mode && '' !== $mode ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE user_id = %d AND mode = %s',
					$table_name,
					$user_id,
					$mode
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE user_id = %d',
					$table_name,
					$user_id
				)
			);
		}

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
	 * @param int      $user_id WordPress user ID
	 * @param int      $seconds Lookback window in seconds (default 600 = 10 minutes)
	 * @param string $mode Mode filter
	 * @param int|null $token_id Optional token ID for login-scoped deduplication.
	 * @return array|null Session data or null if none found
	 */
	public function get_recent_pending_session(
		int $user_id,
		int $seconds = 600,
		string $mode = 'chat',
		?int $token_id = null
	): ?array {
		global $wpdb;

		$table_name  = self::get_prefixed_table_name();
		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - $seconds );

		$query  = "SELECT * FROM %i
				WHERE user_id = %d
				AND mode = %s
				AND created_at >= %s
				AND (
					(messages = '[]' OR messages = '' OR messages IS NULL)
					OR (metadata LIKE %s)
				)";
		$params = array(
			$table_name,
			$user_id,
			$mode,
			$cutoff_time,
			'%"status":"processing"%',
		);

		if ( null !== $token_id ) {
			$query   .= ' AND metadata LIKE %s';
			$params[] = '%"token_id":' . (int) $token_id . '%';
		}

		$query .= ' ORDER BY created_at DESC LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$session = $wpdb->get_row(
			$wpdb->prepare( $query, $params ),
			ARRAY_A
		);

		if ( ! $session ) {
			return null;
		}

		$session['messages'] = json_decode( $session['messages'], true ) ?? array();
		$session['metadata'] = json_decode( $session['metadata'], true ) ?? array();

		return $session;
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
					'mode' => 'chat',
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
			if ( ( $msg['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}

			// Skip tool call/result messages — only count visible assistant responses.
			$type = $msg['metadata']['type'] ?? 'text';
			if ( 'tool_call' === $type || 'tool_result' === $type ) {
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
	 * Mark a session as read by setting last_read_at to the current time.
	 *
	 * @since 0.62.0
	 *
	 * @param string $session_id Session UUID.
	 * @param int    $user_id    User ID for ownership verification.
	 * @return string|false The new last_read_at value on success, false on failure.
	 */
	public function mark_session_read( string $session_id, int $user_id ) {
		global $wpdb;

		$table_name   = self::get_prefixed_table_name();
		$last_read_at = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table_name,
			array( 'last_read_at' => $last_read_at ),
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
			),
			array( '%s' ),
			array( '%s', '%d' )
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
	 * Cleanup old sessions based on retention period
	 *
	 * @param int $retention_days Days to retain sessions
	 * @return int Number of deleted sessions
	 */
	public function cleanup_old_sessions( int $retention_days ): int {
		global $wpdb;

		$table_name  = self::get_prefixed_table_name();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

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
					'mode' => 'chat',
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
					'mode' => 'chat',
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
				'mode' => isset($row['mode']) ? (string) $row['mode'] : 'chat',
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

/**
 * Register scheduled cleanup action for old chat sessions
 */
add_action(
	'datamachine_cleanup_chat_sessions',
	function () {
		// Route through the factory so a swapped store (e.g. an AI Framework
		// shim) gets its own cleanup semantics. The default MySQL store gates
		// on table existence internally.
		$chat_db = ConversationStoreFactory::get();

		if ( $chat_db instanceof Chat && ! Chat::table_exists() ) {
			return;
		}

		$retention_days = \DataMachine\Core\PluginSettings::get( 'chat_retention_days', 90 );

		$deleted_count = $chat_db->cleanup_old_sessions( $retention_days );

		do_action(
			'datamachine_log',
			'debug',
			'Chat sessions cleanup completed',
			array(
				'sessions_deleted' => $deleted_count,
				'retention_days'   => $retention_days,
			)
		);
	}
);

/**
 * Schedule chat session cleanup after Action Scheduler is initialized.
 * Only check in admin context to avoid database queries on every frontend request.
 */
add_action(
	'action_scheduler_init',
	function () {
		if ( ! is_admin() ) {
			return;
		}
		// Daily cleanup of old sessions
		if ( ! as_next_scheduled_action( 'datamachine_cleanup_chat_sessions', array(), 'datamachine-chat' ) ) {
			as_schedule_recurring_action(
				time() + DAY_IN_SECONDS,
				DAY_IN_SECONDS,
				'datamachine_cleanup_chat_sessions',
				array(),
				'datamachine-chat'
			);
		}
	}
);
