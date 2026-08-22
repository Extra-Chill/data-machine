<?php
/**
 * Generic chat run-control primitives.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Sessions;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for canonical chat run-control contracts.
 */
class WP_Agent_Chat_Run_Control {

	public const STATUS_QUEUED               = 'queued';
	public const STATUS_RUNNING              = 'running';
	public const STATUS_CANCELLING           = 'cancelling';
	public const STATUS_CANCELLED            = 'cancelled';
	public const STATUS_COMPLETED            = 'completed';
	public const STATUS_FAILED               = 'failed';
	public const STATUS_RUNTIME_TOOL_PENDING = 'runtime_tool_pending';
	public const STATUS_APPROVAL_REQUIRED    = 'approval_required';
	public const STATUS_BUDGET_EXCEEDED      = 'budget_exceeded';
	public const STATUS_STALLED              = 'stalled';
	public const STATUS_INTERRUPTED          = 'interrupted';
	private const OPTION_KEY                 = 'agents_api_chat_run_control';
	/** @var array<string,string> */
	private static array $active_claim_tokens = array();

	/** @return string[] */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_CANCELLING,
			self::STATUS_CANCELLED,
			self::STATUS_COMPLETED,
			self::STATUS_FAILED,
			self::STATUS_RUNTIME_TOOL_PENDING,
			self::STATUS_APPROVAL_REQUIRED,
			self::STATUS_BUDGET_EXCEEDED,
			self::STATUS_STALLED,
			self::STATUS_INTERRUPTED,
		);
	}

	/**
	 * Resolve canonical workspace, principal, and conversation store context.
	 *
	 * @param array<mixed> $input Ability input.
	 * @return array{workspace:?WP_Agent_Workspace_Scope,owner:?array{type:string,key:string},conversation_store:?WP_Agent_Conversation_Store}|\WP_Error
	 */
	public static function context_from_input( array $input ) {
		$workspace = null;
		if ( array_key_exists( 'workspace', $input ) ) {
			if ( ! is_array( $input['workspace'] ) ) {
				return new \WP_Error( 'agents_chat_run_invalid_workspace', 'workspace must be a canonical workspace object.' );
			}
			try {
				$workspace = WP_Agent_Workspace_Scope::from_array( WP_Agent_Run_Control::string_keyed_array( $input['workspace'] ) );
			} catch ( \InvalidArgumentException $error ) {
				return new \WP_Error( 'agents_chat_run_invalid_workspace', $error->getMessage() );
			}
		}

		$principal = null;
		try {
			if ( ( $input['principal'] ?? null ) instanceof WP_Agent_Execution_Principal ) {
				$principal = $input['principal'];
			} elseif ( is_array( $input['principal'] ?? null ) ) {
				$principal = WP_Agent_Execution_Principal::from_array( WP_Agent_Run_Control::string_keyed_array( $input['principal'] ) );
			} else {
				$request_context                    = WP_Agent_Run_Control::string_keyed_array( $input );
				$request_context['request_context'] = WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST;
				$principal                         = WP_Agent_Execution_Principal::resolve( $request_context );
			}
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'agents_chat_run_invalid_principal', $error->getMessage() );
		}

		if ( ! $principal instanceof WP_Agent_Execution_Principal ) {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			if ( $user_id > 0 ) {
				$agent     = self::string_value( $input['agent'] ?? '__wordpress_user__' );
				$principal = WP_Agent_Execution_Principal::user_session( $user_id, '' !== $agent ? $agent : '__wordpress_user__' );
			}
		}

		$owner         = $principal instanceof WP_Agent_Execution_Principal ? $principal->conversation_owner() : null;
		$claimed_owner = is_array( $input['session_owner'] ?? null ) ? WP_Agent_Run_Control::string_keyed_array( $input['session_owner'] ) : null;
		if ( is_array( $claimed_owner ) ) {
			$claimed_owner = array(
				'type' => sanitize_key( self::string_value( $claimed_owner['type'] ?? '' ) ),
				'key'  => trim( self::string_value( $claimed_owner['key'] ?? '' ) ),
			);
			if ( '' === $claimed_owner['type'] || '' === $claimed_owner['key'] ) {
				return new \WP_Error( 'agents_chat_run_invalid_owner', 'session_owner type and key are required.' );
			}
			if ( is_array( $owner ) && $claimed_owner !== $owner ) {
				return new \WP_Error( 'agents_chat_run_owner_forbidden', 'session_owner must match the authenticated conversation principal.' );
			}
			$owner = $owner ?? $claimed_owner;
		}

		if ( $workspace instanceof WP_Agent_Workspace_Scope && ! is_array( $owner ) ) {
			return new \WP_Error( 'agents_chat_run_owner_required', 'Explicit workspace run control requires an authenticated conversation owner.' );
		}
		if ( $workspace instanceof WP_Agent_Workspace_Scope && ! WP_Agent_Run_Control::supports_workspace_state() ) {
			return new \WP_Error( 'agents_chat_run_workspace_unsupported', 'The registered run-control store does not support explicit workspaces.' );
		}

		return array(
			'workspace'          => $workspace,
			'owner'              => $owner,
			'conversation_store' => WP_Agent_Conversation_Sessions::get_store( WP_Agent_Run_Control::string_keyed_array( $input ) ),
		);
	}

	/**
	 * Generate an opaque client-addressable run ID.
	 */
	public static function generate_run_id( string $prefix = 'run_' ): string {
		return WP_Agent_Run_Control::generate_run_id( $prefix );
	}

	public static function activate_run_claim( string $run_id, string $claim_token ): void {
		self::$active_claim_tokens[ $run_id ] = $claim_token;
	}

	public static function deactivate_run_claim( string $run_id, string $claim_token ): void {
		if ( isset( self::$active_claim_tokens[ $run_id ] ) && hash_equals( self::$active_claim_tokens[ $run_id ], $claim_token ) ) {
			unset( self::$active_claim_tokens[ $run_id ] );
		}
	}

	/**
	 * Normalize a run status payload returned by a runtime.
	 *
	 * @param array<string,mixed> $run Raw run status.
	 * @return array<string,mixed>
	 */
	public static function normalize_run( array $run ): array {
		$run_id     = trim( self::string_value( $run['run_id'] ?? null ) );
		$session_id = trim( self::string_value( $run['session_id'] ?? null ) );
		$status     = self::normalize_status( $run['status'] ?? self::STATUS_RUNNING );

		if ( '' === $run_id ) {
			throw new \InvalidArgumentException( 'run_id must be a non-empty string' );
		}

		if ( '' === $session_id ) {
			throw new \InvalidArgumentException( 'session_id must be a non-empty string' );
		}

		$normalized = array(
			'run_id'     => $run_id,
			'session_id' => $session_id,
			'status'     => $status,
			'started_at' => self::string_value( $run['started_at'] ?? null ),
			'updated_at' => self::string_value( $run['updated_at'] ?? null ),
			'metadata'   => isset( $run['metadata'] ) && is_array( $run['metadata'] ) ? $run['metadata'] : array(),
		);

		if ( isset( $run['queued_message_id'] ) ) {
			$normalized['queued_message_id'] = self::string_value( $run['queued_message_id'] );
		}

		if ( isset( $run['position'] ) ) {
			$normalized['position'] = max( 0, self::int_value( $run['position'] ) );
		}

		if ( isset( $run['cancelled'] ) ) {
			$normalized['cancelled'] = (bool) $run['cancelled'];
		}

		return $normalized;
	}

	/**
	 * Normalize status values while keeping the public vocabulary bounded.
	 */
	public static function normalize_status( mixed $status ): string {
		$status = WP_Agent_Run_Control::normalize_status( $status );
		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_RUNNING;
	}

	/**
	 * Start or update an addressable chat run in the default store.
	 *
	 * @param string              $run_id     Run ID.
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $metadata   Run metadata.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|\WP_Error Normalized run.
	 */
	public static function start_run( string $run_id, string $session_id, array $metadata = array(), ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null, ?WP_Agent_Conversation_Store $conversation_store = null ) {
		$canonical_owner = self::session_owner_fingerprint( $session_id, $workspace, $owner, $conversation_store );
		if ( $canonical_owner instanceof \WP_Error ) {
			return $canonical_owner;
		}
		$claim_token = trim( self::string_value( $metadata['_claim_token'] ?? '' ) );
		if ( '' === $claim_token && 'conversation_loop' === self::string_value( $metadata['source'] ?? '' ) ) {
			$claim_token = self::$active_claim_tokens[ $run_id ] ?? '';
		}
		unset( $metadata['_claim_token'] );

		try {
			$result = self::mutate_state(
				static function ( array $state ) use ( $run_id, $session_id, $metadata, $canonical_owner, $claim_token ): array {
					$existing = $state['runs'][ $run_id ] ?? null;
					if ( is_array( $existing ) && ! self::fingerprint_matches( $existing['_owner'] ?? null, $canonical_owner ) ) {
						return array(
							'state'  => $state,
							'result' => new \WP_Error( 'agents_chat_run_owner_forbidden', 'The run_id is already bound to another session or owner.' ),
						);
					}
					if ( is_array( $existing ) ) {
						$stored_claim = self::string_value( $existing['_claim_token'] ?? '' );
						if ( '' !== $claim_token && '' !== $stored_claim && hash_equals( $stored_claim, $claim_token ) ) {
							if ( ! empty( $existing['_session_pending'] ) ) {
								$existing['session_id'] = $session_id;
								$existing['metadata']   = $metadata;
								$existing['updated_at'] = self::now();
								unset( $existing['_session_pending'] );
								$state['runs'][ $run_id ] = $existing;
								return array( 'state' => $state, 'result' => self::normalize_run( $existing ) );
							}
							if ( $session_id !== self::string_value( $existing['session_id'] ?? '' ) ) {
								return array(
									'state'  => $state,
									'result' => new \WP_Error( 'agents_chat_run_owner_forbidden', 'The run_id is already bound to another session or owner.' ),
								);
							}
							return array( 'state' => $state, 'result' => self::normalize_run( $existing ) );
						}
						if ( $session_id !== self::string_value( $existing['session_id'] ?? '' ) ) {
							return array(
								'state'  => $state,
								'result' => new \WP_Error( 'agents_chat_run_owner_forbidden', 'The run_id is already bound to another session or owner.' ),
							);
						}
						return array(
							'state'  => $state,
							'result' => new \WP_Error( 'agents_chat_run_already_started', 'The run_id has already been claimed for execution.' ),
						);
					}

					$now = self::now();
					$run = array(
						'run_id'       => $run_id,
						'session_id'   => $session_id,
						'status'       => self::STATUS_RUNNING,
						'started_at'   => $now,
						'updated_at'   => $now,
						'metadata'     => $metadata,
						'_owner'       => $canonical_owner,
						'_claim_token' => $claim_token,
					);
					$state['runs'][ $run_id ] = $run;
					$state = self::record_event( $state, $run_id, 'run_started', array( 'status' => self::STATUS_RUNNING ) );
					return array(
						'state'  => $state,
						'result' => self::normalize_run( $run ),
					);
				},
				$workspace,
				'' === $canonical_owner
			);
		} catch ( \RuntimeException $error ) {
			return self::atomic_unavailable( $error );
		}
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			throw new \RuntimeException( 'Atomic chat run creation returned an invalid result.' );
		}
		return self::normalize_run( WP_Agent_Run_Control::string_keyed_array( $result ) );
	}

	/**
	 * Reserve a run before a handler creates and returns its canonical session.
	 *
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function claim_pending_run( string $run_id, string $claim_token, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ) {
		$fingerprint = self::owner_fingerprint( $owner );
		if ( $workspace instanceof WP_Agent_Workspace_Scope && '' === $fingerprint ) {
			return new \WP_Error( 'agents_chat_run_owner_required', 'Explicit workspace run control requires an authenticated conversation owner.' );
		}
		try {
			$result = self::mutate_state(
				static function ( array $state ) use ( $run_id, $claim_token, $fingerprint ): array {
					if ( isset( $state['runs'][ $run_id ] ) ) {
						return array( 'state' => $state, 'result' => new \WP_Error( 'agents_chat_run_already_started', 'The run_id has already been claimed for execution.' ) );
					}
					$now = self::now();
					$run = array(
						'run_id'           => $run_id,
						'session_id'       => 'pending:' . $run_id,
						'status'           => self::STATUS_RUNNING,
						'started_at'       => $now,
						'updated_at'       => $now,
						'metadata'         => array(),
						'_owner'           => $fingerprint,
						'_claim_token'     => $claim_token,
						'_session_pending' => true,
					);
					$state['runs'][ $run_id ] = $run;
					$state = self::record_event( $state, $run_id, 'run_started', array( 'status' => self::STATUS_RUNNING ) );
					return array( 'state' => $state, 'result' => self::normalize_run( $run ) );
				},
				$workspace,
				'' === $fingerprint
			);
		} catch ( \RuntimeException $error ) {
			return self::atomic_unavailable( $error );
		}
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		return is_array( $result ) ? self::normalize_run( WP_Agent_Run_Control::string_keyed_array( $result ) ) : new \WP_Error( 'agents_chat_run_atomic_unavailable', 'Atomic pending run claim returned an invalid result.' );
	}

	/**
	 * Complete a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @param string $status Terminal status.
	 * @return array<string,mixed>|null|\WP_Error Normalized run, null when absent, or a storage error.
	 */
	public static function finish_run( string $run_id, string $status = self::STATUS_COMPLETED, ?WP_Agent_Workspace_Scope $workspace = null ) {
		$allow_legacy = null === $workspace && ( ! class_exists( '\wpdb' ) || self::run_is_unowned( $run_id, $workspace ) );
		try {
			$result = self::mutate_state(
			static function ( array $state ) use ( $run_id, $status ): array {
				$run = $state['runs'][ $run_id ] ?? null;
				if ( ! is_array( $run ) ) {
					return array( 'state' => $state, 'result' => null );
				}
				$run['status']     = self::normalize_status( $status );
				$run['updated_at'] = self::now();
				if ( self::STATUS_CANCELLED === $run['status'] ) {
					$run['cancelled'] = true;
				}
				$state['runs'][ $run_id ] = $run;
				$state = self::record_event( $state, $run_id, 'run_finished', array( 'status' => $run['status'] ) );
				return array( 'state' => $state, 'result' => self::normalize_run( $run ) );
			},
				$workspace,
				$allow_legacy
			);
		} catch ( \RuntimeException $error ) {
			return self::atomic_unavailable( $error );
		}
		return is_array( $result ) ? self::normalize_run( WP_Agent_Run_Control::string_keyed_array( $result ) ) : null;
	}

	/**
	 * Read a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|null Normalized run, or null when absent.
	 */
	public static function get_run( string $run_id, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ): ?array {
		if ( ! self::can_access_run( $run_id, $workspace, $owner ) ) {
			return null;
		}

		$run = WP_Agent_Run_Control::get_run( self::OPTION_KEY, $run_id, $workspace );
		return null === $run ? null : self::normalize_run( $run );
	}

	/**
	 * Request cancellation of a stored chat run.
	 *
	 * @param string $run_id Run ID.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|null|\WP_Error Normalized run, null when absent, or a storage error.
	 */
	public static function request_cancel( string $run_id, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ) {
		if ( ! self::can_access_run( $run_id, $workspace, $owner ) ) {
			return null;
		}

		try {
			$result = self::mutate_state(
			static function ( array $state ) use ( $run_id, $owner ): array {
				$run = $state['runs'][ $run_id ] ?? null;
				if ( ! is_array( $run ) || ! self::owner_matches( $run['_owner'] ?? '', $owner ) ) {
					return array( 'state' => $state, 'result' => null );
				}
				$terminal          = in_array( self::normalize_status( $run['status'] ?? '' ), array( self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_BUDGET_EXCEEDED, self::STATUS_STALLED, self::STATUS_INTERRUPTED ), true );
				$run['status']     = $terminal ? self::normalize_status( $run['status'] ?? '' ) : self::STATUS_CANCELLING;
				$run['cancelled']  = ! $terminal;
				$run['updated_at'] = self::now();
				$state['runs'][ $run_id ] = $run;
				$state = self::record_event( $state, $run_id, 'cancel_requested', array( 'status' => $run['status'] ) );
				return array( 'state' => $state, 'result' => self::normalize_run( $run ) );
			},
				$workspace,
				null === $workspace && '' === self::owner_fingerprint( $owner )
			);
		} catch ( \RuntimeException $error ) {
			return self::atomic_unavailable( $error );
		}
		return is_array( $result ) ? self::normalize_run( WP_Agent_Run_Control::string_keyed_array( $result ) ) : null;
	}

	/**
	 * Return a cancellation interrupt when one was requested for the run.
	 *
	 * @param string $run_id     Run ID.
	 * @param string $session_id Session ID.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|null Interrupt message or null.
	 */
	public static function cancellation_interrupt_for_run( string $run_id, string $session_id = '', ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ): ?array {
		$run = self::get_run( $run_id, $workspace, $owner );
		if ( null === $run || self::STATUS_CANCELLING !== $run['status'] ) {
			return null;
		}

		$resolved_session_id = '' !== $session_id ? $session_id : self::string_value( $run['session_id'] ?? null );

		return self::cancellation_interrupt_message( $run_id, $resolved_session_id );
	}

	/**
	 * Queue a follow-up message for a chat session.
	 *
	 * @param array<string,mixed> $input Canonical queue input.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|\WP_Error Queue result.
	 */
	public static function queue_message( array $input, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null, ?WP_Agent_Conversation_Store $conversation_store = null ) {
		$session_id = trim( self::string_value( $input['session_id'] ?? null ) );
		if ( '' === $session_id ) {
			throw new \InvalidArgumentException( 'session_id must be a non-empty string' );
		}

		$canonical_owner = self::session_owner_fingerprint( $session_id, $workspace, $owner, $conversation_store );
		if ( $canonical_owner instanceof \WP_Error ) {
			return $canonical_owner;
		}

		try {
			$result = self::mutate_state(
			static function ( array $state ) use ( $input, $session_id, $canonical_owner ): array {
				$target = self::queue_target_from_state( $input, $state, $canonical_owner );
				if ( $target instanceof \WP_Error ) {
					return array( 'state' => $state, 'result' => $target );
				}
				$queued_id = 'queued_' . str_replace( 'run_', '', self::generate_run_id() );
				$item = array(
					'queued_message_id' => $queued_id,
					'session_id'        => $session_id,
					'run_id'            => $target['run_id'],
					'agent'             => sanitize_title( self::string_value( $input['agent'] ?? null ) ),
					'message'           => self::string_value( $input['message'] ?? null ),
					'attachments'       => is_array( $input['attachments'] ?? null ) ? $input['attachments'] : array(),
					'client_context'    => is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array(),
					'created_at'        => self::now(),
					'_owner'            => $canonical_owner,
				);
				$state['queues'][ $session_id ]   = array_values( $state['queues'][ $session_id ] ?? array() );
				$state['queues'][ $session_id ][] = $item;
				$position = count( $state['queues'][ $session_id ] );
				return array(
					'state'  => $state,
					'result' => self::normalize_run( array(
						'run_id'            => $target['run_id'],
						'session_id'        => $session_id,
						'status'            => self::STATUS_QUEUED,
						'updated_at'        => self::now(),
						'queued_message_id' => $queued_id,
						'position'          => $position,
					) ),
				);
			},
				$workspace,
				'' === $canonical_owner
			);
		} catch ( \RuntimeException $error ) {
			return self::atomic_unavailable( $error );
		}
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			throw new \RuntimeException( 'Atomic chat queue mutation returned an invalid result.' );
		}
		return self::normalize_run( WP_Agent_Run_Control::string_keyed_array( $result ) );
	}

	/**
	 * Claim queued messages for a session.
	 *
	 * @param string $session_id Session ID.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<int,array<string,mixed>>|\WP_Error Queued items or a storage error.
	 */
	public static function claim_queued_messages( string $session_id, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null, ?WP_Agent_Conversation_Store $conversation_store = null ): array|\WP_Error {
		$canonical_owner = self::session_owner_fingerprint( $session_id, $workspace, $owner, $conversation_store );
		if ( $canonical_owner instanceof \WP_Error ) {
			return $canonical_owner;
		}

		try {
			$result = self::mutate_state(
			static function ( array $state ) use ( $session_id, $canonical_owner ): array {
				$items = array_values( array_filter(
					$state['queues'][ $session_id ] ?? array(),
					static fn( array $item ): bool => self::fingerprint_matches( $item['_owner'] ?? null, $canonical_owner )
				) );
				unset( $state['queues'][ $session_id ] );
				return array(
					'state'  => $state,
					'result' => array_map( array( self::class, 'public_queue_item' ), $items ),
				);
			},
				$workspace,
				'' === $canonical_owner
			);
		} catch ( \RuntimeException $error ) {
			return self::atomic_unavailable( $error );
		}
		if ( ! is_array( $result ) ) {
			return array();
		}
		$items = array();
		foreach ( $result as $item ) {
			if ( is_array( $item ) ) {
				$items[] = WP_Agent_Run_Control::string_keyed_array( $item );
			}
		}
		return $items;
	}

	/**
	 * Check whether queue input resolves to a session owned by the principal.
	 *
	 * @param array<string,mixed>      $input Canonical queue input.
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 */
	public static function can_queue_message( array $input, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null, ?WP_Agent_Conversation_Store $conversation_store = null ): bool {
		return ! ( self::queue_target( $input, $workspace, $owner, $conversation_store ) instanceof \WP_Error );
	}

	/**
	 * List lifecycle events for a principal-owned run.
	 *
	 * @param array<string,mixed>|null $owner Canonical conversation owner.
	 * @return array<string,mixed>|null
	 */
	public static function list_events( string $run_id, string $cursor = '', int $limit = 100, ?WP_Agent_Workspace_Scope $workspace = null, ?array $owner = null ): ?array {
		if ( ! self::can_access_run( $run_id, $workspace, $owner ) ) {
			return null;
		}

		return WP_Agent_Run_Control::list_events( self::OPTION_KEY, $run_id, $cursor, $limit, $workspace );
	}

	/**
	 * Build the interrupt message shape consumed by WP_Agent_Conversation_Loop.
	 *
	 * Runtimes that cannot abort an in-flight provider request immediately can
	 * persist this message for their loop-level `interrupt_source` to return.
	 *
	 * @param string              $run_id     Run to cancel.
	 * @param string              $session_id Session containing the run.
	 * @param array<string,mixed> $metadata   Additional runtime metadata.
	 * @return array<string,mixed>
	 */
	public static function cancellation_interrupt_message(
		string $run_id,
		string $session_id = '',
		array $metadata = array()
	): array {
		return WP_Agent_Message::text(
			'user',
			'Cancel this run.',
			array_merge(
				$metadata,
				array(
					'type'             => 'chat_run_interrupt',
					'interrupt_action' => 'cancel',
					'run_id'           => $run_id,
					'session_id'       => $session_id,
				)
			)
		);
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	private static function state( ?WP_Agent_Workspace_Scope $workspace = null ): array {
		return WP_Agent_Run_Control::state( self::OPTION_KEY, $workspace );
	}

	private static function string_value( mixed $value ): string {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (string) $value : '';
	}

	private static function int_value( mixed $value ): int {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (int) $value : 0;
	}

	/** @param array<string,mixed>|null $owner */
	private static function owner_fingerprint( ?array $owner ): string {
		if ( ! is_string( $owner['type'] ?? null ) || ! is_string( $owner['key'] ?? null ) ) {
			return '';
		}

		return hash( 'sha256', $owner['type'] . ':' . $owner['key'] );
	}

	/** @param array<string,mixed>|null $owner */
	private static function owner_matches( mixed $stored, ?array $owner ): bool {
		$stored = is_string( $stored ) ? $stored : '';
		$fingerprint = self::owner_fingerprint( $owner );
		return '' === $stored ? '' === $fingerprint : '' !== $fingerprint && hash_equals( $stored, $fingerprint );
	}

	private static function fingerprint_matches( mixed $stored, string $expected ): bool {
		return is_string( $stored ) && hash_equals( $expected, $stored );
	}

	/**
	 * Resolve a queue target from stored run state, which is authoritative for
	 * both the session identity and its canonical owner.
	 *
	 * @param array<string,mixed>      $input Queue input.
	 * @param array<string,mixed>|null $owner Resolved principal owner.
	 * @return array{run_id:string,owner_fingerprint:string}|\WP_Error
	 */
	private static function queue_target( array $input, ?WP_Agent_Workspace_Scope $workspace, ?array $owner, ?WP_Agent_Conversation_Store $conversation_store ) {
		$session_id      = trim( self::string_value( $input['session_id'] ?? null ) );
		$canonical_owner = self::session_owner_fingerprint( $session_id, $workspace, $owner, $conversation_store );
		if ( $canonical_owner instanceof \WP_Error ) {
			return $canonical_owner;
		}
		return self::queue_target_from_state( $input, self::state( $workspace ), $canonical_owner );
	}

	/**
	 * @param array<string,mixed> $input Queue input.
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state Stored state.
	 * @return array{run_id:string,owner_fingerprint:string}|\WP_Error
	 */
	private static function queue_target_from_state( array $input, array $state, string $canonical_owner ) {
		$session_id = trim( self::string_value( $input['session_id'] ?? null ) );
		$run_id     = trim( self::string_value( $input['run_id'] ?? null ) );

		if ( '' !== $run_id ) {
			$candidate = $state['runs'][ $run_id ] ?? null;
			if ( ! is_array( $candidate ) || $session_id !== trim( self::string_value( $candidate['session_id'] ?? null ) ) || ! self::fingerprint_matches( $candidate['_owner'] ?? null, $canonical_owner ) ) {
				return new \WP_Error( 'agents_chat_run_not_found', 'No chat run was found for the requested session owner.' );
			}
		} else {
			$latest_key = '';
			foreach ( $state['runs'] as $candidate_run_id => $candidate ) {
				if ( $session_id === trim( self::string_value( $candidate['session_id'] ?? null ) ) && self::fingerprint_matches( $candidate['_owner'] ?? null, $canonical_owner ) ) {
					$candidate_key = self::string_value( $candidate['updated_at'] ?? '' ) . ':' . self::string_value( $candidate['started_at'] ?? '' ) . ':' . $candidate_run_id;
					if ( '' !== $run_id && $candidate_key <= $latest_key ) {
						continue;
					}
					$latest_key = $candidate_key;
					$run_id = $candidate_run_id;
				}
			}
		}

		if ( '' === $run_id ) {
			return new \WP_Error( 'agents_chat_run_not_found', 'No chat run was found for the requested session.' );
		}

		return array(
			'run_id'            => $run_id,
			'owner_fingerprint' => $canonical_owner,
		);
	}

	/**
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state
	 * @param array<string,mixed> $metadata
	 * @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}
	 */
	private static function record_event( array $state, string $run_id, string $type, array $metadata ): array {
		$events = array_values( $state['events'][ $run_id ] ?? array() );
		$events[] = array(
			'id'         => (string) count( $events ),
			'type'       => $type,
			'created_at' => self::now(),
			'metadata'   => $metadata,
		);
		$state['events'][ $run_id ] = $events;
		return $state;
	}

	private static function atomic_unavailable( \RuntimeException $error ): \WP_Error {
		return new \WP_Error( 'agents_chat_run_atomic_unavailable', $error->getMessage() );
	}

	/**
	 * @param callable(array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}):array{state:array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>},result:mixed} $mutation
	 */
	private static function mutate_state( callable $mutation, ?WP_Agent_Workspace_Scope $workspace, bool $allow_unowned_site_legacy ): mixed {
		$store = WP_Agent_Run_Control::store();
		if ( null === $workspace && method_exists( $store, 'mutate_state' ) ) {
			try {
				return $store->mutate_state( self::OPTION_KEY, $mutation );
			} catch ( \RuntimeException $error ) {
				if ( ! $allow_unowned_site_legacy || class_exists( '\wpdb' ) ) {
					throw $error;
				}
			}
		}
		if ( $workspace instanceof WP_Agent_Workspace_Scope && method_exists( $store, 'mutate_workspace_state' ) ) {
			return $store->mutate_workspace_state( self::OPTION_KEY, $workspace, $mutation );
		}
		if ( null === $workspace && $allow_unowned_site_legacy ) {
			$mutated = $mutation( self::state() );
			WP_Agent_Run_Control::save_state( self::OPTION_KEY, $mutated['state'] );
			return $mutated['result'];
		}
		throw new \RuntimeException( 'The registered run-control store does not support atomic chat state mutation.' );
	}

	private static function run_is_unowned( string $run_id, ?WP_Agent_Workspace_Scope $workspace ): bool {
		$run = self::state( $workspace )['runs'][ $run_id ] ?? null;
		return is_array( $run ) && '' === self::string_value( $run['_owner'] ?? '' );
	}

	/**
	 * Resolve ownership from the canonical conversation store.
	 *
	 * @param array<string,mixed>|null $owner Resolved principal owner.
	 * @return string|\WP_Error Canonical owner fingerprint.
	 */
	private static function session_owner_fingerprint( string $session_id, ?WP_Agent_Workspace_Scope $workspace, ?array $owner, ?WP_Agent_Conversation_Store $conversation_store ) {
		$session_id  = trim( $session_id );
		$fingerprint = self::owner_fingerprint( $owner );
		$conversation_store ??= WP_Agent_Conversation_Sessions::get_store();
		if ( '' === $session_id ) {
			return new \WP_Error( 'agents_chat_run_not_found', 'No chat session was requested.' );
		}
		if ( $workspace instanceof WP_Agent_Workspace_Scope && '' === $fingerprint ) {
			return new \WP_Error( 'agents_chat_run_owner_required', 'Explicit workspace run control requires an authenticated conversation owner.' );
		}

		$canonical_workspace = $workspace;
		if ( null === $canonical_workspace && $conversation_store instanceof WP_Agent_Conversation_Store && is_array( $owner ) ) {
			$canonical_workspace = WP_Agent_Workspace_Scope::from_parts( 'site', function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : 'default' );
		}

		if ( $canonical_workspace instanceof WP_Agent_Workspace_Scope && $conversation_store instanceof WP_Agent_Conversation_Store ) {
			if ( ! is_array( $owner ) ) {
				return new \WP_Error( 'agents_chat_run_owner_forbidden', 'The canonical conversation session is not owned by this workspace principal.' );
			}
			$canonical_owner = array(
				'type' => self::string_value( $owner['type'] ?? '' ),
				'key'  => self::string_value( $owner['key'] ?? '' ),
			);
			if ( ! is_array( WP_Agent_Conversation_Sessions::get_owned_session( $conversation_store, $session_id, $canonical_workspace, $canonical_owner ) ) ) {
				return new \WP_Error( 'agents_chat_run_owner_forbidden', 'The canonical conversation session is not owned by this workspace principal.' );
			}
			return $fingerprint;
		}

		if ( $workspace instanceof WP_Agent_Workspace_Scope ) {
			if ( ! $conversation_store instanceof WP_Agent_Conversation_Store ) {
				return new \WP_Error( 'agents_chat_run_session_store_required', 'Explicit workspace run control requires an authoritative conversation session store.' );
			}
		}

		// Omitted-workspace compatibility is limited to ownership already proven
		// by a site-local run. New principal-owned sessions need a canonical store.
		$historical_owners = array();
		foreach ( self::state( $workspace )['runs'] as $run ) {
			if ( $session_id !== self::string_value( $run['session_id'] ?? '' ) ) {
				continue;
			}
			$stored = is_string( $run['_owner'] ?? null ) ? $run['_owner'] : '';
			$historical_owners[ $stored ] = true;
		}

		if ( count( $historical_owners ) > 1 ) {
			return new \WP_Error( 'agents_chat_run_owner_forbidden', 'The session has conflicting historical owner state.' );
		}
		if ( 1 === count( $historical_owners ) ) {
			$stored = (string) array_key_first( $historical_owners );
			if ( ! self::fingerprint_matches( $stored, $fingerprint ) ) {
				return new \WP_Error( 'agents_chat_run_owner_forbidden', 'The session is owned by another conversation principal.' );
			}
			$fingerprint = $stored;
		} elseif ( '' !== $fingerprint ) {
			return new \WP_Error( 'agents_chat_run_session_store_required', 'Principal-owned run control requires an authoritative conversation session store.' );
		}

		return $fingerprint;
	}

	/** @param array<string,mixed>|null $owner */
	private static function can_access_run( string $run_id, ?WP_Agent_Workspace_Scope $workspace, ?array $owner ): bool {
		$state = self::state( $workspace );
		$run   = $state['runs'][ $run_id ] ?? null;
		return is_array( $run ) && self::owner_matches( $run['_owner'] ?? '', $owner );
	}

	/**
	 * @param array<string,mixed> $item Stored queue item.
	 * @return array<string,mixed>
	 */
	private static function public_queue_item( array $item ): array {
		unset( $item['_owner'] );
		return $item;
	}

	private static function now(): string {
		return gmdate( 'c' );
	}
}
