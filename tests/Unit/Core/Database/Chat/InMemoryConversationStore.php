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
use DataMachine\Engine\AI\AgentMessageEnvelope;

class InMemoryConversationStore implements ConversationStoreInterface {

	/**
	 * Session rows keyed by session_id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $sessions = array();

	public function create_session( int $user_id, int $agent_id = 0, array $metadata = array(), string $context = 'chat' ): string {
		$session_id = 'mem-' . bin2hex( random_bytes( 6 ) );
		$now        = gmdate( 'Y-m-d H:i:s' );

		$this->sessions[ $session_id ] = array(
			'session_id'   => $session_id,
			'user_id'      => $user_id,
			'agent_id'     => $agent_id > 0 ? $agent_id : null,
			'title'        => null,
			'messages'     => array(),
			'metadata'     => $metadata,
			'provider'     => null,
			'model'        => null,
			'context'      => $context,
			'created_at'   => $now,
			'updated_at'   => $now,
			'last_read_at' => null,
			'expires_at'   => null,
		);

		return $session_id;
	}

	public function get_session( string $session_id ): ?array {
		return $this->sessions[ $session_id ] ?? null;
	}

	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '' ): bool {
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
				'agent_slug'    => null,
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

	public function get_recent_pending_session( int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		$best   = null;

		foreach ( $this->sessions as $session ) {
			if ( (int) $session['user_id'] !== $user_id ) {
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
			$msg = AgentMessageEnvelope::normalize( $msg );
			if ( ( $msg['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}
			$type = $msg['type'] ?? AgentMessageEnvelope::TYPE_TEXT;
			if ( AgentMessageEnvelope::TYPE_TOOL_CALL === $type || AgentMessageEnvelope::TYPE_TOOL_RESULT === $type ) {
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
		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
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
