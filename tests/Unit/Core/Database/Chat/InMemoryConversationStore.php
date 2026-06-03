<?php
/**
 * In-memory ConversationStoreInterface implementation for tests.
 *
 * Reference aggregate adapter demonstrating how a third-party store slots
 * into the `datamachine_conversation_store` filter without depending on `$wpdb`.
 * Stays in lockstep with {@see \DataMachine\Core\Database\Chat\Chat}'s
 * observable shape so the chat abilities work identically against it.
 *
 * @package DataMachine\Tests\Unit\Core\Database\Chat
 */

namespace DataMachine\Tests\Unit\Core\Database\Chat;

use DataMachine\Core\Database\Chat\ConversationStoreInterface;
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

class InMemoryConversationStore implements ConversationStoreInterface {

	/**
	 * Session rows keyed by session_id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $sessions = array();

	/** @var array<string, array{token: string, expires_at: int}> */
	private array $locks = array();

	/** @var int|null Test-controlled clock. */
	private ?int $now = null;

	/** @var int Lock token counter. */
	private int $lock_counter = 0;

	public function set_clock( int $now ): void {
		$this->now = $now;
	}

	public function create_session( ...$args ): string {
		list( $workspace, $user_id, $agent, $metadata, $context ) = $this->normalize_create_session_args( $args );
		$agent_id   = is_int( $agent ) ? $agent : 0;
		$agent_slug = is_string( $agent ) ? sanitize_title( $agent ) : '';

		$session_id = 'mem-' . bin2hex( random_bytes( 6 ) );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$metadata   = array_merge( $metadata, $workspace->to_array() );
		if ( '' !== $agent_slug ) {
			$metadata['agent_slug'] = $agent_slug;
		}

		$this->sessions[ $session_id ] = array(
			'session_id'     => $session_id,
			'workspace_type' => $workspace->workspace_type,
			'workspace_id'   => $workspace->workspace_id,
			'user_id'        => $user_id,
			'agent_id'       => $agent_id > 0 ? $agent_id : null,
			'agent_slug'     => '' !== $agent_slug ? $agent_slug : null,
			'title'          => '',
			'messages'       => array(),
			'metadata'       => $metadata,
			'provider'       => '',
			'model'          => '',
			'context'        => $context,
			'created_at'     => $now,
			'updated_at'     => $now,
			'last_read_at'   => null,
			'expires_at'     => null,
		);

		return $session_id;
	}

	/**
	 * @param array $args Raw create-session arguments.
	 * @return array{0:WP_Agent_Workspace_Scope,1:int,2:int|string,3:array,4:string}
	 */
	private function normalize_create_session_args( array $args ): array {
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
			WP_Agent_Workspace_Scope::from_parts( 'site', '1' ),
			(int) ( $args[0] ?? 0 ),
			is_string( $args[1] ?? null ) ? (string) $args[1] : (int) ( $args[1] ?? 0 ),
			is_array( $args[2] ?? null ) ? $args[2] : array(),
			(string) ( $args[3] ?? 'chat' ),
		);
	}

	/**
	 * @param array $args Raw pending-session arguments.
	 * @return array{0:WP_Agent_Workspace_Scope,1:int,2:int,3:string,4:int|null}
	 */
	private function normalize_recent_pending_session_args( array $args ): array {
		if ( isset( $args[0] ) && $args[0] instanceof WP_Agent_Workspace_Scope ) {
			return array(
				$args[0],
				(int) ( $args[1] ?? 0 ),
				(int) ( $args[2] ?? 600 ),
				(string) ( $args[3] ?? 'chat' ),
				isset( $args[4] ) ? (int) $args[4] : null,
			);
		}

		return array(
			WP_Agent_Workspace_Scope::from_parts( 'site', '1' ),
			(int) ( $args[0] ?? 0 ),
			(int) ( $args[1] ?? 600 ),
			(string) ( $args[2] ?? 'chat' ),
			isset( $args[3] ) ? (int) $args[3] : null,
		);
	}

	public function get_session( string $session_id ): ?array {
		$session = $this->sessions[ $session_id ] ?? null;
		if ( null === $session ) {
			return null;
		}

		if ( ! array_key_exists( 'agent_slug', $session ) ) {
			$session['agent_slug'] = null;
		}

		return $session;
	}

	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		$include_messages = (bool) ( $args['include_messages'] ?? true );
		$limit            = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$offset           = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$rows             = array();

		foreach ( $this->sessions as $session ) {
			if ( $session['workspace_type'] !== $workspace->workspace_type || $session['workspace_id'] !== $workspace->workspace_id || (int) $session['user_id'] !== $user_id ) {
				continue;
			}
			if ( is_string( $args['context'] ?? null ) && '' !== $args['context'] && $session['context'] !== $args['context'] ) {
				continue;
			}
			if ( is_string( $args['agent_slug'] ?? null ) && '' !== $args['agent_slug'] && ( $session['agent_slug'] ?? '' ) !== sanitize_title( $args['agent_slug'] ) ) {
				continue;
			}

			$row = $session;
			if ( ! $include_messages ) {
				unset( $row['messages'] );
			}
			$rows[] = $row;
		}

		usort( $rows, static fn( $a, $b ) => strcmp( $b['updated_at'], $a['updated_at'] ) );

		return array_slice( $rows, $offset, $limit );
	}

	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}

		$this->sessions[ $session_id ]['messages']   = $messages;
		$this->sessions[ $session_id ]['metadata']   = $metadata;
		$this->sessions[ $session_id ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		if ( '' !== $provider ) {
			$this->sessions[ $session_id ]['provider'] = $provider;
		}
		if ( '' !== $model ) {
			$this->sessions[ $session_id ]['model'] = $model;
		}
		if ( null !== $provider_response_id ) {
			$this->sessions[ $session_id ]['provider_response_id'] = $provider_response_id;
		}

		return true;
	}

	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return null;
		}

		$now    = $this->now ?? time();
		$active = $this->locks[ $session_id ] ?? null;
		if ( null !== $active && $active['expires_at'] > $now ) {
			return null;
		}

		$token                       = 'mem-lock-' . ++$this->lock_counter;
		$this->locks[ $session_id ] = array(
			'token'      => $token,
			'expires_at' => $now + max( 1, $ttl_seconds ),
		);

		return $token;
	}

	public function release_session_lock( string $session_id, string $lock_token ): bool {
		$active = $this->locks[ $session_id ] ?? null;
		if ( null === $active || $active['token'] !== $lock_token ) {
			return false;
		}

		unset( $this->locks[ $session_id ] );
		return true;
	}

	public function delete_session( string $session_id ): bool {
		unset( $this->sessions[ $session_id ] );
		return true;
	}

	public function get_user_sessions( int $user_id, int $limit = 20, int $offset = 0, ?string $context = null, ?int $agent_id = null ): array {
		$rows = array();

		foreach ( $this->sessions as $session ) {
			if ( (int) $session['user_id'] !== $user_id ) {
				continue;
			}
			if ( null !== $context && $session['context'] !== $context ) {
				continue;
			}
			if ( null !== $agent_id && (int) ( $session['agent_id'] ?? 0 ) !== $agent_id ) {
				continue;
			}

			$messages      = $session['messages'];
			$first_message = '';
			foreach ( $messages as $msg ) {
				if ( ( $msg['role'] ?? '' ) === 'user' ) {
					$first_message = $msg['content'] ?? '';
					break;
				}
			}

			$rows[] = array(
				'session_id'    => $session['session_id'],
				'title'         => $session['title'],
				'context'       => $session['context'],
				'first_message' => is_string( $first_message ) ? mb_substr( $first_message, 0, 100 ) : '',
				'message_count' => count( $messages ),
				'unread_count'  => $this->count_unread( $messages, $session['last_read_at'] ),
				'agent_id'      => $session['agent_id'],
				'agent_slug'    => $session['agent_slug'] ?? null,
				'agent_name'    => null,
				'created_at'    => $session['created_at'],
				'updated_at'    => $session['updated_at'],
			);
		}

		// ORDER BY updated_at DESC
		usort( $rows, static fn( $a, $b ) => strcmp( $b['updated_at'], $a['updated_at'] ) );

		return array_slice( $rows, $offset, $limit );
	}

	public function get_user_session_count( int $user_id, ?string $context = null, ?int $agent_id = null ): int {
		$count = 0;
		foreach ( $this->sessions as $session ) {
			if ( (int) $session['user_id'] !== $user_id ) {
				continue;
			}
			if ( null !== $context && $session['context'] !== $context ) {
				continue;
			}
			if ( null !== $agent_id && (int) ( $session['agent_id'] ?? 0 ) !== $agent_id ) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	public function get_recent_pending_session( ...$args ): ?array {
		list( $workspace, $user_id, $seconds, $context, $token_id ) = $this->normalize_recent_pending_session_args( $args );

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		$best   = null;

		foreach ( $this->sessions as $session ) {
			if ( $session['workspace_type'] !== $workspace->workspace_type || $session['workspace_id'] !== $workspace->workspace_id || (int) $session['user_id'] !== $user_id ) {
				continue;
			}
			if ( $session['context'] !== $context ) {
				continue;
			}
			if ( $session['created_at'] < $cutoff ) {
				continue;
			}
			$is_empty     = empty( $session['messages'] );
			$is_pending   = ( $session['metadata']['status'] ?? '' ) === 'processing';
			$token_match  = null === $token_id || (int) ( $session['metadata']['token_id'] ?? 0 ) === $token_id;
			if ( ( $is_empty || $is_pending ) && $token_match ) {
				if ( null === $best || $session['created_at'] > $best['created_at'] ) {
					$best = $session;
				}
			}
		}

		return $best;
	}

	public function update_title( string $session_id, string $title ): bool {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}
		$this->sessions[ $session_id ]['title'] = $title;
		return true;
	}

	public function count_unread( array $messages, ?string $last_read_at ): int {
		$count = 0;
		foreach ( $messages as $msg ) {
			$msg = WP_Agent_Message::normalize( $msg );
			if ( ( $msg['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}
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

	public function mark_session_read( string $session_id, int $user_id ) {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}
		if ( (int) $this->sessions[ $session_id ]['user_id'] !== $user_id ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$this->sessions[ $session_id ]['last_read_at'] = $now;
		return $now;
	}

	public function cleanup_expired_sessions(): int {
		$now     = gmdate( 'Y-m-d H:i:s' );
		$deleted = 0;
		foreach ( $this->sessions as $id => $session ) {
			if ( ! empty( $session['expires_at'] ) && $session['expires_at'] < $now ) {
				unset( $this->sessions[ $id ] );
				++$deleted;
			}
		}
		return $deleted;
	}

	public function cleanup_old_sessions( int $retention_days ): int {
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$deleted = 0;
		foreach ( $this->sessions as $id => $session ) {
			if ( $session['updated_at'] < $cutoff ) {
				unset( $this->sessions[ $id ] );
				++$deleted;
			}
		}
		return $deleted;
	}

	public function cleanup_orphaned_sessions( int $hours = 1 ): int {
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 3600 ) );
		$deleted = 0;
		foreach ( $this->sessions as $id => $session ) {
			if ( $session['created_at'] < $cutoff && empty( $session['messages'] ) ) {
				unset( $this->sessions[ $id ] );
				++$deleted;
			}
		}
		return $deleted;
	}

	public function list_sessions_for_day( string $date ): array {
		$result = array();

		foreach ( $this->sessions as $session ) {
			if ( substr( (string) $session['created_at'], 0, 10 ) !== $date ) {
				continue;
			}
			$result[] = array(
				'session_id' => (string) $session['session_id'],
				'title'      => $session['title'],
				'context'    => (string) $session['context'],
				'created_at' => (string) $session['created_at'],
			);
		}

		usort( $result, static fn( $a, $b ) => strcmp( $a['created_at'], $b['created_at'] ) );

		return $result;
	}

	public function get_storage_metrics(): ?array {
		// In-memory fixture reports row count only; on-disk size is meaningless.
		return array(
			'rows'    => count( $this->sessions ),
			'size_mb' => '0.0',
		);
	}
}
