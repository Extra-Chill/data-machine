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
 */
class Chat extends BaseRepository {

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
            title VARCHAR(100) NULL COMMENT 'AI-generated or truncated first message title',
            messages LONGTEXT NOT NULL COMMENT 'JSON array of conversation messages',
            metadata LONGTEXT NULL COMMENT 'JSON object for session metadata',
            provider VARCHAR(50) NULL COMMENT 'AI provider (anthropic, openai, etc)',
            model VARCHAR(100) NULL COMMENT 'AI model identifier',
            agent_type VARCHAR(20) NOT NULL DEFAULT 'chat' COMMENT 'Agent type: chat, pipeline, system',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at DATETIME NULL COMMENT 'Auto-cleanup timestamp',
            PRIMARY KEY  (session_id),
            KEY user_id (user_id),
            KEY agent_type (agent_type),
            KEY user_agent (user_id, agent_type),
            KEY created_at (created_at),
            KEY updated_at (updated_at),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
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
	 * Get table name with prefix
	 *
	 * @return string Full table name
	 */
	public static function get_table_name(): string {
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
		return esc_sql( self::get_table_name() );
	}


	/**
	 * Create new chat session
	 *
	 * @param int    $user_id    WordPress user ID
	 * @param array  $metadata   Optional session metadata
	 * @param string $agent_type Agent type (chat, pipeline, system)
	 * @return string Session ID (UUID)
	 */
	public function create_session(
		int $user_id,
		array $metadata = array(),
		string $agent_type = \DataMachine\Engine\AI\AgentType::CHAT
	): string {
		global $wpdb;

		$session_id = wp_generate_uuid4();
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table_name,
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'messages'   => wp_json_encode( array() ),
				'metadata'   => wp_json_encode( $metadata ),
				'provider'   => null,
				'model'      => null,
				'agent_type' => $agent_type,
				'expires_at' => null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to create chat session',
				array(
					'user_id'    => $user_id,
					'error'      => $wpdb->last_error,
					'agent_type' => $agent_type,
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
				'agent_type' => $agent_type,
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

		$table_name = self::get_table_name();

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

		$table_name = self::get_table_name();

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
					'agent_type' => \DataMachine\Engine\AI\AgentType::CHAT,
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

		$table_name = self::get_table_name();

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
					'agent_type' => \DataMachine\Engine\AI\AgentType::CHAT,
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
				'agent_type' => \DataMachine\Engine\AI\AgentType::CHAT,
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

		$table_name = self::get_table_name();

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
					'agent_type'    => \DataMachine\Engine\AI\AgentType::CHAT,
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * Get all sessions for a user
	 *
	 * @param int    $user_id    WordPress user ID
	 * @param int    $limit      Maximum sessions to return
	 * @param int    $offset     Pagination offset
	 * @param string $agent_type Agent type filter (chat, pipeline, system)
	 * @return array Array of session data
	 */
	public function get_user_sessions(
		int $user_id,
		int $limit = 20,
		int $offset = 0,
		string $agent_type = \DataMachine\Engine\AI\AgentType::CHAT
	): array {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND agent_type = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d',
				$table_name,
				$user_id,
				$agent_type,
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( ! $sessions ) {
			return array();
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

			$result[] = array(
				'session_id'    => $session['session_id'],
				'title'         => $session['title'] ?? null,
				'first_message' => mb_substr( $first_message, 0, 100 ),
				'message_count' => count( $messages ),
				'created_at'    => DateFormatter::format_for_api( $session['created_at'] ?? null ),
				'updated_at'    => DateFormatter::format_for_api( $session['updated_at'] ?? $session['created_at'] ?? null ),
			);
		}

		return $result;
	}

	/**
	 * Get total session count for a user
	 *
	 * @param int    $user_id    WordPress user ID
	 * @param string $agent_type Agent type filter (chat, pipeline, system)
	 * @return int Total session count
	 */
	public function get_user_session_count(
		int $user_id,
		string $agent_type = \DataMachine\Engine\AI\AgentType::CHAT
	): int {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND agent_type = %s',
				$table_name,
				$user_id,
				$agent_type
			)
		);

		return (int) $count;
	}

	/**
	 * Find a recent pending session for deduplication
	 *
	 * Returns the most recent session that:
	 * - Belongs to this user
	 * - Was created within the threshold (default 10 minutes)
	 * - Has 0 messages OR is actively processing (user message added but no AI response)
	 * - Matches the specified agent type
	 *
	 * This prevents duplicate sessions when requests timeout at Cloudflare
	 * but PHP continues executing. On retry, we reuse the pending session
	 * instead of creating a new one.
	 *
	 * @since 0.9.8
	 * @param int    $user_id    WordPress user ID
	 * @param int    $seconds    Lookback window in seconds (default 600 = 10 minutes)
	 * @param string $agent_type Agent type filter (chat, pipeline, system)
	 * @return array|null Session data or null if none found
	 */
	public function get_recent_pending_session(
		int $user_id,
		int $seconds = 600,
		string $agent_type = \DataMachine\Engine\AI\AgentType::CHAT
	): ?array {
		global $wpdb;

		$table_name  = self::get_table_name();
		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - $seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE user_id = %d
				AND agent_type = %s
				AND created_at >= %s
				AND (
					(messages = '[]' OR messages = '' OR messages IS NULL)
					OR (metadata LIKE %s)
				)
				ORDER BY created_at DESC
				LIMIT 1",
				$table_name,
				$user_id,
				$agent_type,
				$cutoff_time,
				'%"status":"processing"%'
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
	 * Update session title
	 *
	 * @param string $session_id Session UUID
	 * @param string $title New title
	 * @return bool Success
	 */
	public function update_title( string $session_id, string $title ): bool {
		global $wpdb;

		$table_name = self::get_table_name();

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
					'agent_type' => \DataMachine\Engine\AI\AgentType::CHAT,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Cleanup old sessions based on retention period
	 *
	 * @param int $retention_days Days to retain sessions
	 * @return int Number of deleted sessions
	 */
	public function cleanup_old_sessions( int $retention_days ): int {
		global $wpdb;

		$table_name  = self::get_table_name();
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
					'agent_type'     => \DataMachine\Engine\AI\AgentType::CHAT,
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

		$table_name  = self::get_table_name();
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
					'agent_type'      => \DataMachine\Engine\AI\AgentType::CHAT,
				)
			);
		}

		return (int) $deleted;
	}
}

/**
 * Register scheduled cleanup action for old chat sessions
 */
add_action(
	'datamachine_cleanup_chat_sessions',
	function () {
		$chat_db        = new Chat();
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
