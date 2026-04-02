<?php
/**
 * WP-CLI Chat Command
 *
 * Provides CLI access to chat session management operations.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.40.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Core\Database\Chat\Chat as ChatDatabase;

defined( 'ABSPATH' ) || exit;

class ChatCommand extends BaseCommand {

	/**
	 * List chat sessions for a user.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : User ID to list sessions for. Defaults to current user.
	 *
	 * [--context=<type>]
	 * : Filter by execution context (chat, pipeline, system).
	 *
	 * [--limit=<n>]
	 * : Maximum number of sessions to return.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--offset=<n>]
	 * : Pagination offset.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # List sessions for user 1
	 *     wp datamachine chat list --user=1
	 *
	 *     # List chat-context sessions
	 *     wp datamachine chat list --user=1 --context=chat
	 *
	 *     # Get session IDs only
	 *     wp datamachine chat list --user=1 --format=ids
	 *
	 * @subcommand list
	 */
	public function list_sessions( array $args, array $assoc_args ): void {
		$user_id = $this->get_user_id( $assoc_args );
		$limit   = min( 100, max( 1, (int) ( $assoc_args['limit'] ?? 20 ) ) );
		$offset  = max( 0, (int) ( $assoc_args['offset'] ?? 0 ) );
		$context = ! empty( $assoc_args['context'] ) ? sanitize_text_field( $assoc_args['context'] ) : null;

		$chat_db  = new ChatDatabase();
		$sessions = $chat_db->get_user_sessions( $user_id, $limit, $offset, $context );
		$total    = $chat_db->get_user_session_count( $user_id, $context );

		if ( empty( $sessions ) ) {
			WP_CLI::log( 'No chat sessions found.' );
			return;
		}

		// Flatten for display.
		$display_items = array();
		foreach ( $sessions as $session ) {
			$metadata        = $session['metadata'] ?? array();
			$display_items[] = array(
				'session_id'    => $session['session_id'],
				'title'         => $session['title'] ?? '(untitled)',
				'context'       => $session['context'] ?? 'chat',
				'message_count' => $metadata['message_count'] ?? 0,
				'created_at'    => $metadata['started_at'] ?? $session['created_at'] ?? '-',
			);
		}

		$fields = array( 'session_id', 'title', 'context', 'message_count', 'created_at' );
		$this->format_items( $display_items, $fields, $assoc_args, 'session_id' );

		$format = $assoc_args['format'] ?? 'table';
		$this->output_pagination( $offset, count( $sessions ), $total, $format, 'sessions' );
	}

	/**
	 * Get a specific chat session with conversation.
	 *
	 * ## OPTIONS
	 *
	 * <session_id>
	 * : Session ID to retrieve.
	 *
	 * [--user=<id>]
	 * : User ID for ownership verification. Defaults to current user.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Get a session
	 *     wp datamachine chat get abc123-def456
	 *
	 *     # Get as JSON
	 *     wp datamachine chat get abc123-def456 --format=json
	 */
	public function get( array $args, array $assoc_args ): void {
		$session_id = $args[0] ?? null;

		if ( empty( $session_id ) ) {
			WP_CLI::error( 'session_id is required.' );
			return;
		}

		$user_id = $this->get_user_id( $assoc_args );
		$chat_db = new ChatDatabase();

		$session = $chat_db->get_session( $session_id );

		if ( ! $session ) {
			WP_CLI::error( 'Session not found.' );
			return;
		}

		// Verify ownership (CLI bypasses this for admin users).
		if ( (int) $session['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Access denied. Session belongs to another user.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format || 'yaml' === $format ) {
			$this->format_items( array( $session ), array_keys( $session ), $assoc_args );
			return;
		}

		// Table format - show metadata and messages summary.
		$metadata = $session['metadata'] ?? array();
		$messages = $session['messages'] ?? array();

		WP_CLI::log( WP_CLI::colorize( '%BSession Metadata:%n' ) );
		WP_CLI::log( "  Session ID:   {$session['session_id']}" );
		WP_CLI::log( '  Title:        ' . ( $session['title'] ?? '(untitled)' ) );
		WP_CLI::log( '  Context:      ' . ( $session['context'] ?? 'chat' ) );
		WP_CLI::log( "  User ID:      {$session['user_id']}" );
		WP_CLI::log( '  Started:      ' . ( $metadata['started_at'] ?? '-' ) );
		WP_CLI::log( '  Messages:     ' . count( $messages ) );

		if ( ! empty( $messages ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%BConversation:%n' ) );

			$display_messages = array();
			foreach ( $messages as $msg ) {
				$role      = $msg['role'] ?? 'unknown';
				$content   = $msg['content'] ?? '';
				$truncated = mb_strlen( $content ) > 100
					? mb_substr( $content, 0, 97 ) . '...'
					: $content;

				$display_messages[] = array(
					'role'    => strtoupper( $role ),
					'content' => $truncated,
				);
			}

			$this->format_items( $display_messages, array( 'role', 'content' ), array( 'format' => 'table' ) );
		}
	}

	/**
	 * Create a new chat session.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : User ID who owns the session. Defaults to current user.
	 *
	 * [--agent-id=<id>]
	 * : First-class agent ID for this session.
	 *
	 * [--context=<type>]
	 * : Execution context (chat, pipeline, system).
	 * ---
	 * default: chat
	 * ---
	 *
	 * [--source=<source>]
	 * : Session source identifier (e.g., cli, chat).
	 * ---
	 * default: cli
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a session for user 1
	 *     wp datamachine chat create --user=1
	 *
	 *     # Create a session with agent ID
	 *     wp datamachine chat create --user=1 --agent-id=5
	 */
	public function create( array $args, array $assoc_args ): void {
		$user_id  = $this->get_user_id( $assoc_args );
		$agent_id = (int) ( $assoc_args['agent-id'] ?? 0 );
		$context  = sanitize_text_field( $assoc_args['context'] ?? 'chat' );
		$source   = sanitize_text_field( $assoc_args['source'] ?? 'cli' );

		$metadata = array(
			'started_at'    => current_time( 'mysql', true ),
			'message_count' => 0,
			'source'        => $source,
		);

		$chat_db    = new ChatDatabase();
		$session_id = $chat_db->create_session( $user_id, $agent_id, $metadata, $context );

		if ( empty( $session_id ) ) {
			WP_CLI::error( 'Failed to create chat session.' );
			return;
		}

		WP_CLI::success( "Created chat session: {$session_id}" );
	}

	/**
	 * Delete a chat session.
	 *
	 * ## OPTIONS
	 *
	 * <session_id>
	 * : Session ID to delete.
	 *
	 * [--user=<id>]
	 * : User ID for ownership verification. Defaults to current user.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete a session (with confirmation)
	 *     wp datamachine chat delete abc123-def456
	 *
	 *     # Delete without confirmation
	 *     wp datamachine chat delete abc123-def456 --yes
	 */
	public function delete( array $args, array $assoc_args ): void {
		$session_id = $args[0] ?? null;

		if ( empty( $session_id ) ) {
			WP_CLI::error( 'session_id is required.' );
			return;
		}

		$user_id = $this->get_user_id( $assoc_args );
		$chat_db = new ChatDatabase();

		$session = $chat_db->get_session( $session_id );

		if ( ! $session ) {
			WP_CLI::error( 'Session not found.' );
			return;
		}

		// Verify ownership (CLI bypasses this for admin users).
		if ( (int) $session['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Access denied. Session belongs to another user.' );
			return;
		}

		// Confirm deletion.
		if ( ! isset( $assoc_args['yes'] ) ) {
			$message_count = count( $session['messages'] ?? array() );
			WP_CLI::confirm( "Delete session {$session_id} ({$message_count} messages)?" );
		}

		$deleted = $chat_db->delete_session( $session_id );

		if ( ! $deleted ) {
			WP_CLI::error( 'Failed to delete session.' );
			return;
		}

		WP_CLI::success( "Deleted session: {$session_id}" );
	}

	/**
	 * Generate a title for a chat session using AI.
	 *
	 * ## OPTIONS
	 *
	 * <session_id>
	 * : Session ID to generate title for.
	 *
	 * [--force]
	 * : Force regeneration even if title already exists.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate title for a session
	 *     wp datamachine chat title abc123-def456
	 *
	 *     # Force regeneration
	 *     wp datamachine chat title abc123-def456 --force
	 */
	public function title( array $args, array $assoc_args ): void {
		$session_id = $args[0] ?? null;

		if ( empty( $session_id ) ) {
			WP_CLI::error( 'session_id is required.' );
			return;
		}

		$force = isset( $assoc_args['force'] );

		$ability = wp_get_ability( 'datamachine/generate-session-title' );

		if ( ! $ability ) {
			WP_CLI::error( 'Session title ability not available.' );
			return;
		}

		$result = $ability->execute( array(
			'session_id' => $session_id,
			'force'      => $force,
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? $result['message'] ?? 'Failed to generate title.' );
			return;
		}

		WP_CLI::success( sprintf(
			'Title generated (%s): %s',
			$result['method'] ?? 'unknown',
			$result['title']
		) );
	}

	/**
	 * Get user ID from args or current user.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return int User ID.
	 */
	private function get_user_id( array $assoc_args ): int {
		if ( isset( $assoc_args['user'] ) ) {
			return (int) $assoc_args['user'];
		}

		return get_current_user_id() ? get_current_user_id() : 1;
	}
}
