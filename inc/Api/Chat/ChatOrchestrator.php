<?php
/**
 * Chat Orchestrator
 *
 * AI conversation orchestration extracted from Chat.php. Handles the
 * multi-step business logic for chat, continue, and ping flows:
 * session lifecycle, conversation turn execution, and error persistence.
 *
 * This is intentionally NOT an ability — it coordinates multiple operations
 * (ToolManager, AIConversationLoop, session updates) and has
 * side effects. Composition happens here; flat primitives live in abilities.
 *
 * @package DataMachine\Api\Chat
 * @since 0.31.0
 */

namespace DataMachine\Api\Chat;

use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
use DataMachine\Abilities\Chat\ChatTranscriptOwner;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;
use DataMachine\Engine\AI\ConversationManager;
use DataMachine\Engine\AI\DataMachineAgentConsentPolicy;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_Error;

use function DataMachine\Engine\AI\datamachine_run_conversation;
use function DataMachine\Engine\AI\datamachine_conversation_metadata;
use function DataMachine\Engine\AI\datamachine_summarize_tool_execution_results;

defined( 'ABSPATH' ) || exit;

class ChatOrchestrator {

	/**
	 * Process a new chat message.
	 *
	 * Handles session resolution (existing, pending dedup, or new), persists
	 * the user message, executes the AI conversation turn, updates session
	 * state, and triggers title generation for new sessions.
	 *
	 * @since 0.31.0
	 *
	 * @param string $message              User message text.
	 * @param string $provider             AI provider identifier.
	 * @param string $model                AI model identifier.
	 * @param int    $user_id              Current user ID.
	 * @param array  $options {
	 *     Optional settings.
	 *
	 *     @type string $session_id           Existing session ID to continue.
	 *     @type int    $selected_pipeline_id Currently selected pipeline ID.
	 *     @type int    $max_turns            Maximum turns allowed.
	 *     @type string $request_id           Idempotency request ID.
	 *     @type int    $calling_user_id      Authenticated acting user. 0 means no human caller.
	 *     @type callable|null $interrupt_source Optional cooperative interrupt source.
	 * }
	 * @return array|WP_Error Response data array or WP_Error on failure.
	 */
	public static function processChat(
		string $message,
		string $provider,
		string $model,
		int $user_id,
		array $options = array()
	): array|WP_Error {
		$session_id           = $options['session_id'] ?? null;
		$selected_pipeline_id = (int) ( $options['selected_pipeline_id'] ?? 0 );
		$max_turns            = $options['max_turns'] ?? PluginSettings::get( 'max_turns', PluginSettings::DEFAULT_MAX_TURNS );
		$request_id           = $options['request_id'] ?? null;
		$interrupt_source     = is_callable( $options['interrupt_source'] ?? null ) ? $options['interrupt_source'] : null;
		$agent_id             = (int) ( $options['agent_id'] ?? 0 );
		$agent_slug           = (string) ( $options['agent_slug'] ?? '' );
		$calling_user_id      = array_key_exists( 'calling_user_id', $options ) ? max( 0, (int) $options['calling_user_id'] ) : $user_id;
		$modes                = ToolPolicyResolver::normalizeModes( ! empty( $options['modes'] ) ? $options['modes'] : array( $options['mode'] ?? ToolPolicyResolver::MODE_CHAT ) );
		$mode                 = implode( ',', $modes );
		$workspace            = $options['workspace'] ?? WordPressWorkspaceScope::current();
		if ( ! $workspace instanceof WP_Agent_Workspace_Scope ) {
			return new WP_Error( 'invalid_transcript_workspace', __( 'A canonical transcript workspace is required.', 'data-machine' ), array( 'status' => 400 ) );
		}
		$transcript_owner     = ChatTranscriptOwner::resolve_for_request( $options, $user_id );
		if ( is_wp_error( $transcript_owner ) ) {
			return $transcript_owner;
		}

		$chat_db                     = ConversationStoreFactory::get();
		$session_metadata            = array();
		$acting_token_id             = \DataMachine\Abilities\PermissionHelper::get_acting_token_id();
		$transcript_consent_decision = DataMachineAgentConsentPolicy::get()->can_store_transcript(
			array(
				'mode'        => $mode,
				'interactive' => true,
				'user_id'     => $user_id,
				'agent_id'    => $agent_id,
			)
		);

		// --- Session resolution ---
		if ( $session_id ) {
			$session = $chat_db->get_session( $session_id );

			if ( ! $session ) {
				return new WP_Error(
					'session_not_found',
					__( 'Session not found', 'data-machine' ),
					array( 'status' => 404 )
				);
			}

			$owns_session = method_exists( $chat_db, 'session_matches_owner' )
				? $chat_db->session_matches_owner( $session, $transcript_owner )
				: ( (int) $session['user_id'] === $user_id );

			if ( ! $owns_session ) {
				return new WP_Error(
					'session_access_denied',
					__( 'Access denied to this session', 'data-machine' ),
					array( 'status' => 403 )
				);
			}

			if ( (string) ( $session['workspace_type'] ?? '' ) !== $workspace->workspace_type || (string) ( $session['workspace_id'] ?? '' ) !== $workspace->workspace_id ) {
				return new WP_Error(
					'session_not_found',
					__( 'Session not found', 'data-machine' ),
					array( 'status' => 404 )
				);
			}

			$messages         = $session['messages'];
			$session_metadata = $session['metadata'] ?? array();
			if ( empty( $options['mode'] ) && ! empty( $session['mode'] ) ) {
				$mode = sanitize_key( (string) $session['mode'] );
			}
		} else {
			// Check for recent pending session to prevent duplicates from timeout retries.
			$pending_session = $chat_db->get_recent_pending_session( $workspace, $user_id, 600, $mode, $acting_token_id, $transcript_owner );

			if ( $pending_session ) {
				$session_id       = $pending_session['session_id'];
				$messages         = $pending_session['messages'];
				$session_metadata = $pending_session['metadata'] ?? array();

				do_action(
					'datamachine_log',
					'info',
					'Chat: Reusing pending session (deduplication)',
					array(
						'session_id'          => $session_id,
						'user_id'             => $user_id,
						'original_created_at' => $pending_session['created_at'],
						'mode'                => $mode,
					)
				);
			} else {
				$create_result = self::createSession( $user_id, '', $agent_id, $mode, $transcript_owner, $workspace );

				if ( is_wp_error( $create_result ) ) {
					return $create_result;
				}

				$session_id = $create_result;
				$messages   = array();
			}
		}

		$lock_token = $chat_db->acquire_session_lock( $session_id );
		if ( null === $lock_token ) {
			return self::sessionLockContentionError();
		}

		// --- Cross-site handoff detection ---
		// Compare the host this session was last active on against the current
		// request host. On a cross-site move, the handoff payload feeds the
		// CrossSiteHandoffDirective so the agent reconciles the move. The
		// current host is re-stamped into metadata below so the comparison is
		// always against the most recent turn.
		$current_host       = self::currentRequestHost();
		$cross_site_handoff = self::buildCrossSiteHandoff( $session_metadata, $current_host );

		// --- Build user message (text or multi-modal with attachments) ---
		$attachments = $options['attachments'] ?? array();

		if ( ! empty( $attachments ) ) {
			$content    = ConversationManager::buildMultiModalContent( $message, $attachments );
			$metadata   = array(
				'type'        => 'multimodal',
				'attachments' => $attachments,
			);
			$messages[] = ConversationManager::buildConversationMessage( 'user', $content, $metadata );
		} else {
			$messages[] = ConversationManager::buildConversationMessage( 'user', $message, array( 'type' => 'text' ) );
		}

		$chat_db->update_session(
			$session_id,
			$messages,
			array_merge(
				$session_metadata,
				array(
					'status'            => 'processing',
					'started_at'        => current_time( 'mysql', true ),
					'message_count'     => count( $messages ),
					'calling_user_id'   => $calling_user_id,
					'last_seen_host'    => '' !== $current_host ? $current_host : ( $session_metadata['last_seen_host'] ?? '' ),
					'consent_decisions' => array(
						'store_transcript' => $transcript_consent_decision->to_array(),
					),
				)
			),
			$provider,
			$model
		);

		// Set request_id transient BEFORE AI loop to prevent duplicate sessions
		// when retries arrive during processing.
		if ( $request_id ) {
			$cache_key = 'datamachine_chat_request_' . $request_id;
			set_transient(
				$cache_key,
				array(
					'session_id' => $session_id,
					'pending'    => true,
				),
				60
			);
		}

		// --- Execute AI conversation turn ---
		// Pass both 'modes' (array of sanitized slugs) and 'mode' (comma-joined
		// string for logging/analytics). executeConversationTurn prefers the
		// array form when present — required so multi-mode contexts like
		// ['cluckin-chuck', 'chat'] survive. Passing only the comma-joined
		// string would route through sanitize_key() in ToolPolicyResolver::
		// normalizeModes which strips the comma and produces a single junk
		// mode like 'cluckin-chuckchat', causing mode-restricted tool
		// allowlists (frontend chat surface guards) to silently no-op.
		$result = self::executeConversationTurn(
			$session_id,
			$messages,
			$provider,
			$model,
			array(
				'single_turn'           => false,
				'max_turns'             => $max_turns,
				'selected_pipeline_id'  => $selected_pipeline_id ? $selected_pipeline_id : null,
				'modes'                 => $modes,
				'mode'                  => $mode,
				'user_id'               => $user_id,
				'calling_user_id'       => $calling_user_id,
				'agent_id'              => $agent_id,
				'agent_slug'            => $agent_slug,
				'interrupt_source'      => $interrupt_source,
				'client_context'        => $options['client_context'] ?? array(),
				'cross_site_handoff'    => $cross_site_handoff,
				'tool_policy'           => is_array( $options['tool_policy'] ?? null ) ? $options['tool_policy'] : null,
				'allow_only'            => is_array( $options['allow_only'] ?? null ) ? $options['allow_only'] : null,
				'completion_assertions' => is_array( $options['completion_assertions'] ?? null ) ? $options['completion_assertions'] : null,
				'event_sink'            => $options['event_sink'] ?? null,
			)
		);

		if ( is_wp_error( $result ) ) {
			$chat_db->release_session_lock( $session_id, $lock_token );
			return $result;
		}

		// --- Update session state ---
		$loop_metadata = datamachine_conversation_metadata( $result );
		$is_completed  = (bool) ( $loop_metadata['completed'] ?? false );

		$metadata = array(
			'status'            => $is_completed ? 'completed' : 'processing',
			'last_activity'     => current_time( 'mysql', true ),
			'message_count'     => count( $result['messages'] ),
			'current_turn'      => $result['turn_count'],
			'has_pending_tools' => ! $is_completed,
			'calling_user_id'   => $calling_user_id,
			'last_seen_host'    => '' !== $current_host ? $current_host : ( $session_metadata['last_seen_host'] ?? '' ),
		);
		if ( ! empty( $loop_metadata['runtime_tool_pending_requests'] ) ) {
			$metadata['runtime_tool_requests'] = array();
			foreach ( (array) $loop_metadata['runtime_tool_pending_requests'] as $request ) {
				if ( is_array( $request ) && ! empty( $request['request_id'] ) ) {
					$metadata['runtime_tool_requests'][ (string) $request['request_id'] ] = $request;
				}
			}
			$metadata['runtime_tool_pending'] = true;
			$metadata['has_pending_tools']    = true;
		}

		// Accumulate token usage across turns in session metadata.
		$turn_usage = $result['usage'] ?? array();
		if ( ! empty( $turn_usage ) && ( $turn_usage['total_tokens'] ?? 0 ) > 0 ) {
			$existing_session  = $chat_db->get_session( $session_id );
			$existing_metadata = ! empty( $existing_session['metadata'] )
				? ( is_array( $existing_session['metadata'] ) ? $existing_session['metadata'] : json_decode( $existing_session['metadata'], true ) )
				: array();
			$existing_usage    = $existing_metadata['token_usage'] ?? array(
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'total_tokens'      => 0,
			);

			$metadata['token_usage'] = array(
				'prompt_tokens'     => (int) $existing_usage['prompt_tokens'] + (int) ( $turn_usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) $existing_usage['completion_tokens'] + (int) ( $turn_usage['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) $existing_usage['total_tokens'] + (int) ( $turn_usage['total_tokens'] ?? 0 ),
			);
		}

		if ( $selected_pipeline_id ) {
			$metadata['selected_pipeline_id'] = $selected_pipeline_id;
		}

		$update_success = $chat_db->update_session(
			$session_id,
			$result['messages'],
			$metadata,
			$provider,
			$model
		);
		$chat_db->release_session_lock( $session_id, $lock_token );

		// --- Title generation for new/untitled sessions ---
		if ( $update_success ) {
			$session = $chat_db->get_session( $session_id );
			if ( $session && empty( $session['title'] ) ) {
				$ability = wp_get_ability( 'datamachine/generate-session-title' );
				if ( $ability ) {
					$ability->execute( array( 'session_id' => $session_id ) );
				}
			}
		}

		// --- Build response data ---
		$response_metadata = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
		if ( empty( $response_metadata ) ) {
			$response_metadata = $metadata;
		} else {
			$response_metadata = array_merge( $metadata, $response_metadata );
		}

		$response_data = array(
			'session_id'             => $session_id,
			'response'               => $result['final_content'],
			'tool_calls'             => $loop_metadata['tool_calls'] ?? $loop_metadata['last_tool_calls'] ?? array(),
			'tool_execution_summary' => $loop_metadata['tool_execution_summary'] ?? datamachine_summarize_tool_execution_results( $result['tool_execution_results'] ?? array(), false ),
			'conversation'           => $result['messages'],
			'metadata'               => $response_metadata,
			'completed'              => $is_completed,
			'max_turns'              => $max_turns,
			'turn_number'            => $result['turn_count'],
		);
		if ( ! empty( $loop_metadata['runtime_tool_pending_requests'] ) ) {
			$response_data['runtime_tool_pending_requests'] = $loop_metadata['runtime_tool_pending_requests'];
		}

		if ( isset( $loop_metadata['warning'] ) ) {
			$response_data['warning'] = $loop_metadata['warning'];
		}

		if ( ! empty( $loop_metadata['max_turns_reached'] ) ) {
			$response_data['max_turns_reached'] = true;
		}

		if ( isset( $loop_metadata['interrupted'] ) ) {
			$response_data['interrupted'] = $loop_metadata['interrupted'];
		}

		/**
		 * Fires after a chat response is fully processed and ready for external delivery.
		 *
		 * Extensions (e.g. chat bridge integrations) can hook here to forward
		 * agent responses to external clients via webhooks, message queues, etc.
		 *
		 * @since 0.51.0
		 *
		 * @param string $session_id    Chat session ID.
		 * @param array  $response_data Complete response data including response text, metadata, and conversation.
		 * @param int    $agent_id      Agent ID that produced the response.
		 * @param int    $user_id       WordPress user ID who owns the session.
		 */
		do_action( 'datamachine_chat_response_complete', $session_id, $response_data, $agent_id, $user_id );

		return $response_data;
	}

	/**
	 * Continue an existing chat session with pending tool calls.
	 *
	 * Loads the session, runs one more AI conversation turn, extracts
	 * only the new messages, and updates the session state.
	 *
	 * @since 0.31.0
	 *
	 * @param string $session_id Session ID to continue.
	 * @param int    $user_id    Current user ID for ownership check.
	 * @param int|null $calling_user_id Original authenticated acting user. Null restores session context.
	 * @return array|WP_Error Response data array or WP_Error on failure.
	 */
	public static function processContinue( string $session_id, int $user_id, ?int $calling_user_id = null ): array|WP_Error {
		$max_turns = PluginSettings::get( 'max_turns', PluginSettings::DEFAULT_MAX_TURNS );

		$chat_db = ConversationStoreFactory::get();
		$session = $chat_db->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error(
				'session_not_found',
				__( 'Session not found', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		if ( (int) $session['user_id'] !== $user_id ) {
			return new WP_Error(
				'session_access_denied',
				__( 'Access denied to this session', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$metadata = $session['metadata'] ?? array();
		$calling_user_id = null !== $calling_user_id
			? max( 0, $calling_user_id )
			: ( array_key_exists( 'calling_user_id', $metadata ) ? max( 0, (int) $metadata['calling_user_id'] ) : $user_id );

		// Short-circuit if session is already completed.
		if ( isset( $metadata['status'] ) && 'completed' === $metadata['status'] && empty( $metadata['has_pending_tools'] ) ) {
			return array(
				'session_id'        => $session_id,
				'new_messages'      => array(),
				'final_content'     => '',
				'tool_calls'        => array(),
				'completed'         => true,
				'turn_number'       => $metadata['current_turn'] ?? 0,
				'max_turns'         => $max_turns,
				'max_turns_reached' => false,
			);
		}

		$messages             = $session['messages'] ?? array();
		$chat_defaults        = PluginSettings::resolveModelForAgentMode( (int) ( $session['agent_id'] ?? 0 ), 'chat' );
		$provider             = $session['provider'] ?? $chat_defaults['provider'];
		$model                = $session['model'] ?? $chat_defaults['model'];
		$message_count_before = count( $messages );
		$selected_pipeline_id = $metadata['selected_pipeline_id'] ?? null;
		$lock_token           = $chat_db->acquire_session_lock( $session_id );
		if ( null === $lock_token ) {
			return self::sessionLockContentionError();
		}

		// Detect a cross-site resume the same way processChat does: compare the
		// host the session was last active on against the current request host.
		$current_host       = self::currentRequestHost();
		$cross_site_handoff = self::buildCrossSiteHandoff( $metadata, $current_host );

		// Recover the original modes array from the stored session.mode column.
		// Sessions created from a multi-mode request (e.g. ['cluckin-chuck',
		// 'chat']) persist as the comma-joined string 'cluckin-chuck,chat'.
		// Pass both forms — the array survives mode-specific tool allowlist
		// filters (see ToolPolicyResolver::normalizeModes which would otherwise
		// sanitize_key the comma away into a junk single mode).
		$stored_mode = (string) ( $session['mode'] ?? ToolPolicyResolver::MODE_CHAT );
		$modes       = ToolPolicyResolver::normalizeModes(
			false !== strpos( $stored_mode, ',' )
				? array_map( 'trim', explode( ',', $stored_mode ) )
				: $stored_mode
		);

		$result = self::executeConversationTurn(
			$session_id,
			$messages,
			$provider,
			$model,
			array(
				'single_turn'          => false,
				'max_turns'            => $max_turns,
				'selected_pipeline_id' => $selected_pipeline_id,
				'modes'                => $modes,
				'mode'                 => $stored_mode,
				'user_id'              => (int) ( $session['user_id'] ?? 0 ),
				'calling_user_id'      => $calling_user_id,
				'agent_id'             => (int) ( $session['agent_id'] ?? 0 ),
				'agent_slug'           => (string) ( $session['agent_slug'] ?? '' ),
				'cross_site_handoff'   => $cross_site_handoff,
			)
		);

		if ( is_wp_error( $result ) ) {
			$chat_db->release_session_lock( $session_id, $lock_token );
			return $result;
		}

		// Extract new messages (added during this turn).
		$new_messages      = array_slice( $result['messages'], $message_count_before );
		$loop_metadata     = datamachine_conversation_metadata( $result );
		$is_completed      = (bool) ( $loop_metadata['completed'] ?? false );
		$current_turn      = ( $metadata['current_turn'] ?? 0 ) + $result['turn_count'];
		$max_turns_reached = $loop_metadata['max_turns_reached'] ?? ( $current_turn >= $max_turns );

		// Update session with new state.
		$updated_metadata = array(
			'status'            => $is_completed ? 'completed' : 'processing',
			'last_activity'     => current_time( 'mysql', true ),
			'message_count'     => count( $result['messages'] ),
			'current_turn'      => $current_turn,
			'has_pending_tools' => ! $is_completed,
			'calling_user_id'   => $calling_user_id,
			'last_seen_host'    => '' !== $current_host ? $current_host : ( $metadata['last_seen_host'] ?? '' ),
		);
		if ( ! empty( $metadata['runtime_tool_requests'] ) ) {
			$updated_metadata['runtime_tool_requests'] = $metadata['runtime_tool_requests'];
		}
		if ( ! empty( $loop_metadata['runtime_tool_pending_requests'] ) ) {
			$updated_metadata['runtime_tool_requests'] = is_array( $updated_metadata['runtime_tool_requests'] ?? null ) ? $updated_metadata['runtime_tool_requests'] : array();
			foreach ( (array) $loop_metadata['runtime_tool_pending_requests'] as $request ) {
				if ( is_array( $request ) && ! empty( $request['request_id'] ) ) {
					$updated_metadata['runtime_tool_requests'][ (string) $request['request_id'] ] = $request;
				}
			}
			$updated_metadata['runtime_tool_pending'] = true;
			$updated_metadata['has_pending_tools']    = true;
		}

		// Accumulate token usage across continuation turns.
		$turn_usage     = $result['usage'] ?? array();
		$existing_usage = $metadata['token_usage'] ?? array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		);
		if ( ! empty( $turn_usage ) && ( $turn_usage['total_tokens'] ?? 0 ) > 0 ) {
			$updated_metadata['token_usage'] = array(
				'prompt_tokens'     => (int) $existing_usage['prompt_tokens'] + (int) ( $turn_usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) $existing_usage['completion_tokens'] + (int) ( $turn_usage['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) $existing_usage['total_tokens'] + (int) ( $turn_usage['total_tokens'] ?? 0 ),
			);
		}

		if ( $selected_pipeline_id ) {
			$updated_metadata['selected_pipeline_id'] = $selected_pipeline_id;
		}

		$chat_db->update_session(
			$session_id,
			$result['messages'],
			$updated_metadata,
			$provider,
			$model
		);
		$chat_db->release_session_lock( $session_id, $lock_token );

		$continue_response = array(
			'session_id'        => $session_id,
			'new_messages'      => $new_messages,
			'final_content'     => $result['final_content'],
			'tool_calls'        => $loop_metadata['last_tool_calls'] ?? array(),
			'completed'         => $is_completed,
			'turn_number'       => $current_turn,
			'max_turns'         => $max_turns,
			'max_turns_reached' => $max_turns_reached,
		);
		if ( ! empty( $loop_metadata['runtime_tool_pending_requests'] ) ) {
			$continue_response['runtime_tool_pending_requests'] = $loop_metadata['runtime_tool_pending_requests'];
		}

		/** This action is documented in inc/Api/Chat/ChatOrchestrator.php */
		do_action( 'datamachine_chat_response_complete', $session_id, $continue_response, (int) ( $session['agent_id'] ?? 0 ), $user_id );

		return $continue_response;
	}

	/**
	 * Resolve the current request's site host.
	 *
	 * Chat sessions are network-wide, so a session can be resumed on a
	 * different subsite than it was last active on. The host is derived from
	 * live request state (the current site's home URL) — never hardcoded — so
	 * cross-site handoff detection stays generic across the network.
	 *
	 * @return string Lowercased host, or '' when not resolvable.
	 */
	private static function currentRequestHost(): string {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}

		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Build the cross-site handoff payload for a resumed session.
	 *
	 * Compares the host the session was last active on (stored per turn in
	 * session metadata) against the current request host. Returns an empty
	 * array when there is no prior host or the user has not changed sites, so
	 * the handoff directive only fires on an actual cross-site move.
	 *
	 * @param array  $session_metadata Loaded session metadata.
	 * @param string $current_host     Current request host.
	 * @return array{previous_host:string,current_host:string}|array{} Handoff payload or empty.
	 */
	private static function buildCrossSiteHandoff( array $session_metadata, string $current_host ): array {
		$previous_host = isset( $session_metadata['last_seen_host'] ) ? strtolower( (string) $session_metadata['last_seen_host'] ) : '';

		if ( '' === $previous_host || '' === $current_host || $previous_host === $current_host ) {
			return array();
		}

		return array(
			'previous_host' => $previous_host,
			'current_host'  => $current_host,
		);
	}

	/**
	 * Build a standard response for an actively locked chat transcript.
	 *
	 * @return WP_Error
	 */
	private static function sessionLockContentionError(): WP_Error {
		return new WP_Error(
			'session_lock_contention',
			__( 'This chat session is already processing another request.', 'data-machine' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Process a webhook ping as a full multi-turn chat session.
	 *
	 * Creates an admin-owned session, runs the AI loop to completion,
	 * generates a title, and returns the result.
	 *
	 * @since 0.31.0
	 *
	 * @param string $message Full message text (with optional prompt/context prepended).
	 * @param string $provider AI provider identifier.
	 * @param string $model    AI model identifier.
	 * @return array|WP_Error Response data array or WP_Error on failure.
	 */
	public static function processPing( string $message, string $provider, string $model ): array|WP_Error {
		// Use admin user for session ownership since this is a system-level request.
		$admin_users = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		$user_id     = ! empty( $admin_users ) ? $admin_users[0]->ID : 1;

		$chat_db = ConversationStoreFactory::get();

		$session_id = self::createSession( $user_id, 'ping' );

		if ( is_wp_error( $session_id ) ) {
			return $session_id;
		}

		$messages   = array();
		$messages[] = ConversationManager::buildConversationMessage( 'user', $message, array( 'type' => 'text' ) );

		// Persist user message.
		$chat_db->update_session(
			$session_id,
			$messages,
			array(
				'status'        => 'processing',
				'started_at'    => current_time( 'mysql', true ),
				'message_count' => count( $messages ),
			),
			$provider,
			$model
		);

		$result = self::executeConversationTurn(
			$session_id,
			$messages,
			$provider,
			$model,
			array(
				'modes'           => array( ToolPolicyResolver::MODE_CHAT ),
				'user_id'         => $user_id,
				// Ping is a system-level health check; there is no human caller
				// even though the session is recorded under the admin user_id.
				'calling_user_id' => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update session to completed with ping source and token usage.
		$ping_metadata = array(
			'status'        => 'completed',
			'last_activity' => current_time( 'mysql', true ),
			'message_count' => count( $result['messages'] ),
			'source'        => 'ping',
		);

		$ping_usage = $result['usage'] ?? array();
		if ( ! empty( $ping_usage ) && ( $ping_usage['total_tokens'] ?? 0 ) > 0 ) {
			$ping_metadata['token_usage'] = $ping_usage;
		}

		$chat_db->update_session(
			$session_id,
			$result['messages'],
			$ping_metadata,
			$provider,
			$model
		);

		// Generate title.
		$ability = wp_get_ability( 'datamachine/generate-session-title' );
		if ( $ability ) {
			$ability->execute( array( 'session_id' => $session_id ) );
		}

		do_action(
			'datamachine_log',
			'info',
			'Chat ping completed',
			array(
				'session_id' => $session_id,
				'turns'      => $result['turn_count'],
				'mode'       => 'chat',
			)
		);

		$ping_response = array(
			'session_id' => $session_id,
			'response'   => $result['final_content'],
			'turns'      => $result['turn_count'],
			'completed'  => true,
		);

		/** This action is documented in inc/Api/Chat/ChatOrchestrator.php */
		do_action( 'datamachine_chat_response_complete', $session_id, $ping_response, 0, $user_id );

		return $ping_response;
	}

	/**
	 * Create a new chat session.
	 *
	 * Delegates to the canonical create-chat-session ability.
	 *
	 * @since 0.31.0
	 *
	 * @param int    $user_id User ID who owns the session.
	 * @param string $source  Optional source identifier.
	 * @return string|WP_Error Session ID on success, WP_Error on failure.
	 */
	private static function createSession( int $user_id, string $source = '', int $agent_id = 0, string $mode = ToolPolicyResolver::MODE_CHAT, ?array $transcript_owner = null, ?WP_Agent_Workspace_Scope $workspace = null ): string|WP_Error {
		// Comma-joined multi-mode strings ('cluckin-chuck,chat') survive intact
		// here so processContinue can later parse them back into a modes array.
		// Single-mode strings still get sanitize_key'd for the usual safety.
		if ( '' === $mode ) {
			$mode = ToolPolicyResolver::MODE_CHAT;
		} elseif ( false !== strpos( $mode, ',' ) ) {
			$parts = array_filter( array_map(
				static fn( $part ) => sanitize_key( trim( (string) $part ) ),
				explode( ',', $mode )
			) );
			$mode  = implode( ',', $parts );
			if ( '' === $mode ) {
				$mode = ToolPolicyResolver::MODE_CHAT;
			}
		} else {
			$mode = sanitize_key( $mode );
		}

		if ( $agent_id <= 0 ) {
			$agent_id = function_exists( 'datamachine_resolve_or_create_agent_id' )
				? datamachine_resolve_or_create_agent_id( $user_id )
				: 0;
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error(
				'session_creation_ability_unavailable',
				__( 'Chat session creation ability API is unavailable.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		$ability = wp_get_ability( 'agents/create-conversation-session' );

		if ( ! $ability ) {
			return new WP_Error(
				'session_creation_ability_unavailable',
				__( 'Chat session creation ability is unavailable.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		$agent_slug = ConversationStoreFactory::resolve_agent_slug_for_transcript( $agent_id );
		$workspace  = $workspace ?? WordPressWorkspaceScope::current();
		$input      = array(
			'workspace' => $workspace->to_array(),
			'agent'     => $agent_slug,
			'context'   => $mode,
			'principal' => WP_Agent_Execution_Principal::user_session(
				$user_id,
				$agent_slug,
				WP_Agent_Execution_Principal::REQUEST_CONTEXT_CHAT,
				array(),
				$workspace->key()
			),
			'metadata'  => array(
				'started_at'    => current_time( 'mysql', true ),
				'message_count' => 0,
			),
		);

		if ( null !== $transcript_owner ) {
			$input['session_owner'] = $transcript_owner;
		}

		if ( $source ) {
			$input['metadata']['source'] = $source;
		}

		$result = \DataMachine\Abilities\PermissionHelper::run_as_authenticated(
			function () use ( $ability, $input ) {
				return $ability->execute( $input );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$session_id = (string) ( $result['session']['session_id'] ?? '' );

		if ( '' === $session_id ) {
			return new WP_Error(
				'session_creation_failed',
				__( 'Failed to create chat session', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return $session_id;
	}

	/**
	 * Execute a single conversation turn with the AI loop.
	 *
	 * Encapsulates tool loading, AIConversationLoop
	 * execution, error handling, and session error updates.
	 *
	 * @since 0.26.0
	 * @since 0.31.0 Moved from Chat.php to ChatOrchestrator.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $messages   Current conversation messages.
	 * @param string $provider   AI provider identifier.
	 * @param string $model      AI model identifier.
	 * @param array  $options    Optional settings {
	 *     @type bool   $single_turn          Whether to run single turn (default false).
	 *     @type int    $max_turns             Maximum turns allowed (default 25).
	 *     @type int    $selected_pipeline_id  Currently selected pipeline ID.
	 *     @type string $mode                  Agent mode (default 'chat').
	 *     @type int    $calling_user_id       Authenticated acting user. 0 means no human caller.
	 *     @type callable|null $interrupt_source Optional cooperative interrupt source.
	 * }
	 * @return array|WP_Error Result array with messages, final_content, completed, turn_count,
	 *                        last_tool_calls, and optional warning/max_turns_reached keys.
	 *                        WP_Error on failure.
	 */
	public static function executeConversationTurn(
		string $session_id,
		array $messages,
		string $provider,
		string $model,
		array $options = array()
	): array|WP_Error {
		$single_turn          = $options['single_turn'] ?? false;
		$max_turns            = $options['max_turns'] ?? PluginSettings::get( 'max_turns', PluginSettings::DEFAULT_MAX_TURNS );
		$selected_pipeline_id = $options['selected_pipeline_id'] ?? null;
		$modes                = ToolPolicyResolver::normalizeModes( ! empty( $options['modes'] ) ? $options['modes'] : array( $options['mode'] ?? ToolPolicyResolver::MODE_CHAT ) );
		$mode                 = implode( ',', $modes );
		$agent_id             = (int) ( $options['agent_id'] ?? 0 );
		$agent_slug           = (string) ( $options['agent_slug'] ?? '' );
		$interrupt_source     = is_callable( $options['interrupt_source'] ?? null ) ? $options['interrupt_source'] : null;

		$chat_db = ConversationStoreFactory::get();

		try {
			$user_id = $options['user_id'] ?? 0;

			if ( $agent_id <= 0 && $user_id > 0 && function_exists( 'datamachine_resolve_or_create_agent_id' ) ) {
				$agent_id = datamachine_resolve_or_create_agent_id( $user_id );
			}

			if ( '' === $agent_slug && $agent_id > 0 ) {
				$agent_slug = ConversationStoreFactory::resolve_agent_slug_for_transcript( $agent_id );
			}

			// Keep the authenticated acting user separate from runtime/session
			// ownership. An explicit 0 denotes a system or delegated runtime with
			// no human caller and must not fall back to the transcript owner.
			$calling_user_id = isset( $options['calling_user_id'] )
				? max( 0, (int) $options['calling_user_id'] )
				: $user_id;

			$resolver       = new ToolPolicyResolver();
			$client_context = $options['client_context'] ?? array();
			$all_tools      = $resolver->resolve(
				array(
					'modes'          => $modes,
					'agent_id'       => $agent_id,
					'agent_slug'     => $agent_slug,
					'user_id'        => $user_id,
					'calling_user_id' => $calling_user_id,
					'interactive'    => true,
					'client_context' => is_array( $client_context ) ? $client_context : array(),
					'tool_policy'    => is_array( $options['tool_policy'] ?? null ) ? $options['tool_policy'] : null,
					'allow_only'     => is_array( $options['allow_only'] ?? null ) ? $options['allow_only'] : array(),
				)
			);

			$loop_context = array(
				'session_id'      => $session_id,
				'user_id'         => $user_id,
				'calling_user_id' => $calling_user_id,
				'agent_id'        => $agent_id,
				'agent_modes'     => $modes,
			);
			if ( '' !== $agent_slug ) {
				$loop_context['agent_slug'] = $agent_slug;
			}
			if ( $selected_pipeline_id ) {
				$loop_context['selected_pipeline_id'] = $selected_pipeline_id;
			}
			if ( ! empty( $client_context ) ) {
				$loop_context['client_context'] = $client_context;
			}
			if ( is_array( $options['cross_site_handoff'] ?? null ) && ! empty( $options['cross_site_handoff'] ) ) {
				$loop_context['cross_site_handoff'] = $options['cross_site_handoff'];
			}
			if ( is_array( $options['completion_assertions'] ?? null ) ) {
				$loop_context['completion_assertions'] = $options['completion_assertions'];
			}
			if ( isset( $options['event_sink'] ) ) {
				$loop_context['event_sink'] = $options['event_sink'];
			}
			if ( null !== $interrupt_source ) {
				$loop_context['interrupt_source'] = $interrupt_source;
			}

			$loop_result = datamachine_run_conversation(
				$messages,
				$all_tools,
				$provider,
				$model,
				$modes,
				$loop_context,
				$max_turns,
				$single_turn
			);

			if ( isset( $loop_result['error'] ) ) {
				$chat_db->update_session(
					$session_id,
					$messages,
					array(
						'status'        => 'error',
						'error_message' => $loop_result['error'],
						'last_activity' => current_time( 'mysql', true ),
						'message_count' => count( $messages ),
					),
					$provider,
					$model
				);

				do_action(
					'datamachine_log',
					'error',
					'AI loop returned error',
					array(
						'session_id' => $session_id,
						'error'      => $loop_result['error'],
						'mode'       => $mode,
					)
				);

				return new WP_Error(
					'wp_ai_client_request_failed',
					$loop_result['error'],
					array( 'status' => 500 )
				);
			}

			$loop_metadata = datamachine_conversation_metadata( $loop_result );

			return array(
				'messages'      => $loop_result['messages'],
				'final_content' => $loop_result['final_content'],
				'turn_count'    => $loop_result['turn_count'] ?? 1,
				'usage'         => $loop_result['usage'] ?? array(),
				'metadata'      => array(
					'datamachine' => $loop_metadata,
				),
			);
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'error',
				'AI loop failed with exception',
				array(
					'session_id' => $session_id,
					'error'      => $e->getMessage(),
					'mode'       => $mode,
				)
			);

			$chat_db->update_session(
				$session_id,
				$messages,
				array(
					'status'        => 'error',
					'error_message' => $e->getMessage(),
					'last_activity' => current_time( 'mysql', true ),
					'message_count' => count( $messages ),
				),
				$provider,
				$model
			);

			return new WP_Error(
				'chat_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}
