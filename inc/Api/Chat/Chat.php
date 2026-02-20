<?php
/**
 * Chat REST API Endpoint
 *
 * Conversational AI endpoint for building and executing Data Machine workflows
 * through natural language interaction.
 *
 * @package DataMachine\Api\Chat
 * @since 0.2.0
 */

namespace DataMachine\Api\Chat;

use DataMachine\Core\Database\Chat\Chat as ChatDatabase;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\ConversationManager;
use DataMachine\Engine\AI\AIConversationLoop;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\AgentType;
use DataMachine\Engine\AI\AgentContext;
use WP_REST_Response;
use WP_REST_Server;
use WP_REST_Request;
use WP_Error;

require_once __DIR__ . '/ChatPipelinesDirective.php';
require_once __DIR__ . '/ChatAgentDirective.php';

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat API Handler
 */
class Chat {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register chat endpoints
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_chat' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'message'              => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'User message', 'data-machine' ),
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'session_id'           => array(
						'type'              => 'string',
						'required'          => false,
						'description'       => __( 'Optional session ID for conversation continuity', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'provider'             => array(
						'type'              => 'string',
						'required'          => false,
						'validate_callback' => function ( $param ) {
							if ( empty( $param ) ) {
								return true;
							}
							$providers = apply_filters( 'chubes_ai_providers', array() );
							return isset( $providers[ $param ] );
						},
						'description'       => __( 'AI provider (optional, uses default if not provided)', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'model'                => array(
						'type'              => 'string',
						'required'          => false,
						'description'       => __( 'Model identifier (optional, uses default if not provided)', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'selected_pipeline_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'description'       => __( 'Currently selected pipeline ID for context', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/chat/continue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_continue' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'Session ID to continue', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/chat/(?P<session_id>[a-f0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_session' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'Session ID', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/chat/(?P<session_id>[a-f0-9-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_session' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'Session ID', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/chat/ping',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_ping' ),
				'permission_callback' => array( self::class, 'verify_ping_token' ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'Message for the chat agent', 'data-machine' ),
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'prompt'  => array(
						'type'              => 'string',
						'required'          => false,
						'description'       => __( 'Optional system-level instructions for this ping', 'data-machine' ),
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'context' => array(
						'type'        => 'object',
						'required'    => false,
						'description' => __( 'Optional pipeline context (flow_id, pipeline_id, job_id, etc.)', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/chat/sessions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_sessions' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'limit'      => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'description'       => __( 'Maximum sessions to return', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
					'offset'     => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'description'       => __( 'Pagination offset', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
					'agent_type' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => \DataMachine\Engine\AI\AgentType::CHAT,
						'description'       => __( 'Agent type filter (chat, pipeline, system)', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return \DataMachine\Engine\AI\AgentType::isValid( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * Verify bearer token for chat ping endpoint.
	 *
	 * @since 0.24.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function verify_ping_token( WP_REST_Request $request ) {
		$secret = PluginSettings::get( 'chat_ping_secret', '' );

		if ( empty( $secret ) ) {
			return new WP_Error(
				'ping_not_configured',
				__( 'Chat ping secret not configured.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$auth_header = $request->get_header( 'Authorization' );

		if ( empty( $auth_header ) ) {
			return new WP_Error(
				'missing_authorization',
				__( 'Authorization header required.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		// Accept "Bearer <token>" format.
		$token = $auth_header;
		if ( str_starts_with( $auth_header, 'Bearer ' ) ) {
			$token = substr( $auth_header, 7 );
		}

		if ( ! hash_equals( $secret, $token ) ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid authorization token.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle incoming chat ping from webhook.
	 *
	 * Creates a new chat session, runs the full AI conversation loop
	 * (multi-turn), and returns the final response. Authentication is
	 * via bearer token, not WordPress cookies.
	 *
	 * @since 0.24.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function handle_ping( WP_REST_Request $request ) {
		$message = sanitize_textarea_field( wp_unslash( $request->get_param( 'message' ) ) );
		$prompt  = sanitize_textarea_field( wp_unslash( $request->get_param( 'prompt' ) ?? '' ) );
		$context = $request->get_param( 'context' ) ?? array();

		$agent_config = PluginSettings::getAgentModel( 'chat' );
		$provider     = $agent_config['provider'];
		$model        = $agent_config['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			return new WP_Error(
				'provider_required',
				__( 'Default AI provider and model must be configured.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		// Build the message with optional context.
		$full_message = $message;
		if ( ! empty( $prompt ) ) {
			$full_message = $prompt . "\n\n" . $message;
		}
		if ( ! empty( $context ) ) {
			$context_str   = wp_json_encode( $context, JSON_PRETTY_PRINT );
			$full_message .= "\n\n**Pipeline Context:**\n```json\n" . $context_str . "\n```";
		}

		// Use admin user (ID 1) for session ownership since this is a system-level request.
		$admin_users = get_users( array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		) );
		$user_id     = ! empty( $admin_users ) ? $admin_users[0]->ID : 1;

		$chat_db    = new ChatDatabase();
		$session_id = $chat_db->create_session(
			$user_id,
			array(
				'started_at'    => current_time( 'mysql', true ),
				'message_count' => 0,
				'source'        => 'ping',
			),
			AgentType::CHAT
		);

		if ( empty( $session_id ) ) {
			return new WP_Error(
				'session_creation_failed',
				__( 'Failed to create chat session.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		$messages   = array();
		$messages[] = ConversationManager::buildConversationMessage( 'user', $full_message, array( 'type' => 'text' ) );

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
			array( 'agent_type' => AgentType::CHAT )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update session to completed with ping source.
		$chat_db->update_session(
			$session_id,
			$result['messages'],
			array(
				'status'        => 'completed',
				'last_activity' => current_time( 'mysql', true ),
				'message_count' => count( $result['messages'] ),
				'source'        => 'ping',
			),
			$provider,
			$model
		);

		// Generate title.
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/generate-session-title' ) : null;
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
				'agent_type' => AgentType::CHAT,
			)
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'session_id' => $session_id,
					'response'   => $result['final_content'],
					'turns'      => $result['turn_count'],
					'completed'  => true,
				),
			)
		);
	}

	/**
	 * List all chat sessions for current user
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function list_sessions( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$limit      = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );
		$offset     = max( 0, (int) $request->get_param( 'offset' ) );
		$agent_type = $request->get_param( 'agent_type' );

		$chat_db  = new ChatDatabase();
		$sessions = $chat_db->get_user_sessions( $user_id, $limit, $offset, $agent_type );
		$total    = $chat_db->get_user_session_count( $user_id, $agent_type );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'sessions'   => $sessions,
					'total'      => $total,
					'limit'      => $limit,
					'offset'     => $offset,
					'agent_type' => $agent_type,
				),
			)
		);
	}

	/**
	 * Delete a chat session
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function delete_session( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$user_id    = get_current_user_id();

		$chat_db = new ChatDatabase();
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

		$deleted = $chat_db->delete_session( $session_id );

		if ( ! $deleted ) {
			return new WP_Error(
				'session_delete_failed',
				__( 'Failed to delete session', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'session_id' => $session_id,
					'deleted'    => true,
				),
			)
		);
	}

	/**
	 * Get existing chat session
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function get_session( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$user_id    = get_current_user_id();

		$chat_db = new ChatDatabase();
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

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'session_id'   => $session['session_id'],
					'conversation' => $session['messages'],
					'metadata'     => $session['metadata'],
				),
			)
		);
	}

	/**
	 * Handle chat request
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function handle_chat( WP_REST_Request $request ) {
		$request_id = $request->get_header( 'X-Request-ID' );
		if ( $request_id ) {
			$request_id      = sanitize_text_field( $request_id );
			$cache_key       = 'datamachine_chat_request_' . $request_id;
			$cached_response = get_transient( $cache_key );
			if ( false !== $cached_response ) {
				return rest_ensure_response( $cached_response );
			}
		}

		$message = sanitize_textarea_field( wp_unslash( $request->get_param( 'message' ) ) );

		// Get provider and model with defaults.
		$provider = $request->get_param( 'provider' );
		$model    = $request->get_param( 'model' );

		if ( empty( $provider ) || empty( $model ) ) {
			$agent_config = PluginSettings::getAgentModel( 'chat' );
			if ( empty( $provider ) ) {
				$provider = $agent_config['provider'];
			}
			if ( empty( $model ) ) {
				$model = $agent_config['model'];
			}
		}

		$provider = sanitize_text_field( $provider );
		$model    = sanitize_text_field( $model );

		$session_id           = $request->get_param( 'session_id' );
		$selected_pipeline_id = (int) $request->get_param( 'selected_pipeline_id' );
		$user_id              = get_current_user_id();
		$max_turns            = PluginSettings::get( 'max_turns', 12 );

		// Validate that we have provider and model.
		if ( empty( $provider ) ) {
			return new WP_Error(
				'provider_required',
				__( 'AI provider is required. Please set a default provider in Data Machine settings or provide one in the request.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $model ) ) {
			return new WP_Error(
				'model_required',
				__( 'AI model is required. Please set a default model in Data Machine settings or provide one in the request.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$chat_db = new ChatDatabase();

		if ( $session_id ) {
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

			$messages = $session['messages'];
		} else {
			// Check for recent pending session to prevent duplicates from timeout retries.
			// This handles the case where Cloudflare times out but PHP continues executing,
			// creating orphaned sessions. On retry, we reuse the pending session.
			$pending_session = $chat_db->get_recent_pending_session( $user_id, 600, AgentType::CHAT );

			if ( $pending_session ) {
				$session_id = $pending_session['session_id'];
				$messages   = $pending_session['messages'];

				do_action(
					'datamachine_log',
					'info',
					'Chat: Reusing pending session (deduplication)',
					array(
						'session_id'          => $session_id,
						'user_id'             => $user_id,
						'original_created_at' => $pending_session['created_at'],
						'agent_type'          => AgentType::CHAT,
					)
				);
			} else {
				$session_id = $chat_db->create_session(
					$user_id,
					array(
						'started_at'    => current_time( 'mysql', true ),
						'message_count' => 0,
					),
					AgentType::CHAT
				);

				if ( empty( $session_id ) ) {
					return new WP_Error(
						'session_creation_failed',
						__( 'Failed to create chat session', 'data-machine' ),
						array( 'status' => 500 )
					);
				}

				$messages = array();
			}
		}

		$messages[] = ConversationManager::buildConversationMessage( 'user', $message, array( 'type' => 'text' ) );

		// Persist user message immediately so it survives navigation away from page.
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

		// Set request_id → session_id transient BEFORE AI loop to prevent duplicate
		// sessions when retries arrive during processing (transient timing fix).
		if ( $request_id ) {
			$cache_key = 'datamachine_chat_request_' . $request_id;
			set_transient( $cache_key, array(
				'session_id' => $session_id,
				'pending'    => true,
			), 60 );
		}

		$result = self::executeConversationTurn(
			$session_id,
			$messages,
			$provider,
			$model,
			array(
				'single_turn'          => true,
				'max_turns'            => $max_turns,
				'selected_pipeline_id' => $selected_pipeline_id ? $selected_pipeline_id : null,
				'agent_type'           => AgentType::CHAT,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$is_completed = $result['completed'];

		$metadata = array(
			'status'            => $is_completed ? 'completed' : 'processing',
			'last_activity'     => current_time( 'mysql', true ),
			'message_count'     => count( $result['messages'] ),
			'current_turn'      => $result['turn_count'],
			'has_pending_tools' => ! $is_completed,
		);

		// Store selected pipeline for continuation.
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

		// After successful session update, trigger title generation for new sessions.
		if ( $update_success ) {
			$session = $chat_db->get_session( $session_id );
			if ( $session && empty( $session['title'] ) ) {
				$ability = wp_get_ability( 'datamachine/generate-session-title' );
				if ( $ability ) {
					$ability->execute( array( 'session_id' => $session_id ) );
				}
			}
		}

		$response_data = array(
			'session_id'   => $session_id,
			'response'     => $result['final_content'],
			'tool_calls'   => $result['last_tool_calls'],
			'conversation' => $result['messages'],
			'metadata'     => $metadata,
			'completed'    => $is_completed,
			'max_turns'    => $max_turns,
			'turn_number'  => $result['turn_count'],
		);

		if ( isset( $result['warning'] ) ) {
			$response_data['warning'] = $result['warning'];
		}

		if ( isset( $result['max_turns_reached'] ) && $result['max_turns_reached'] ) {
			$response_data['max_turns_reached'] = true;
		}

		$response = array(
			'success' => true,
			'data'    => $response_data,
		);

		if ( $request_id ) {
			$cache_key = 'datamachine_chat_request_' . $request_id;
			set_transient( $cache_key, $response, 60 );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Handle chat continue request (turn-by-turn execution)
	 *
	 * Loads an existing session with pending tool calls and executes one more turn.
	 * Client polls this endpoint until completed === true.
	 *
	 * @since 0.12.0
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response data or error
	 */
	public static function handle_continue( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$user_id    = get_current_user_id();
		$max_turns  = PluginSettings::get( 'max_turns', 12 );

		$chat_db = new ChatDatabase();
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

		// Check if session is already completed.
		if ( isset( $metadata['status'] ) && 'completed' === $metadata['status'] && empty( $metadata['has_pending_tools'] ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'session_id'    => $session_id,
						'new_messages'  => array(),
						'final_content' => '',
						'tool_calls'    => array(),
						'completed'     => true,
						'turn_number'   => $metadata['current_turn'] ?? 0,
						'max_turns'     => $max_turns,
					),
				)
			);
		}

		$messages             = $session['messages'] ?? array();
		$chat_defaults        = PluginSettings::getAgentModel( 'chat' );
		$provider             = $session['provider'] ?? $chat_defaults['provider'];
		$model                = $session['model'] ?? $chat_defaults['model'];
		$message_count_before = count( $messages );
		$selected_pipeline_id = $metadata['selected_pipeline_id'] ?? null;

		$result = self::executeConversationTurn(
			$session_id,
			$messages,
			$provider,
			$model,
			array(
				'single_turn'          => true,
				'max_turns'            => $max_turns,
				'selected_pipeline_id' => $selected_pipeline_id,
				'agent_type'           => AgentType::CHAT,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract new messages (added during this turn).
		$new_messages      = array_slice( $result['messages'], $message_count_before );
		$is_completed      = $result['completed'];
		$current_turn      = ( $metadata['current_turn'] ?? 0 ) + $result['turn_count'];
		$max_turns_reached = $result['max_turns_reached'] ?? ( $current_turn >= $max_turns );

		// Update session with new state.
		$updated_metadata = array(
			'status'            => $is_completed ? 'completed' : 'processing',
			'last_activity'     => current_time( 'mysql', true ),
			'message_count'     => count( $result['messages'] ),
			'current_turn'      => $current_turn,
			'has_pending_tools' => ! $is_completed,
		);

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

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'session_id'        => $session_id,
					'new_messages'      => $new_messages,
					'final_content'     => $result['final_content'],
					'tool_calls'        => $result['last_tool_calls'],
					'completed'         => $is_completed,
					'turn_number'       => $current_turn,
					'max_turns'         => $max_turns,
					'max_turns_reached' => $max_turns_reached,
				),
			)
		);
	}

	/**
	 * Execute a single conversation turn with the AI loop.
	 *
	 * Encapsulates AgentContext management, tool loading, AIConversationLoop
	 * execution, error handling, and session error updates. Used by handle_chat,
	 * handle_continue, and handle_ping to eliminate duplication.
	 *
	 * @since 0.26.0
	 *
	 * @param string $session_id Session ID.
	 * @param array  $messages   Current conversation messages.
	 * @param string $provider   AI provider identifier.
	 * @param string $model      AI model identifier.
	 * @param array  $options    Optional settings {
	 *     @type bool   $single_turn          Whether to run single turn (default false).
	 *     @type int    $max_turns             Maximum turns allowed (default 12).
	 *     @type int    $selected_pipeline_id  Currently selected pipeline ID.
	 *     @type string $agent_type            Agent type for context (default AgentType::CHAT).
	 * }
	 * @return array|WP_Error Result array with messages, final_content, completed, turn_count,
	 *                        last_tool_calls, and optional warning/max_turns_reached keys.
	 *                        WP_Error on failure.
	 */
	private static function executeConversationTurn(
		string $session_id,
		array $messages,
		string $provider,
		string $model,
		array $options = array()
	): array|\WP_Error {
		$single_turn          = $options['single_turn'] ?? false;
		$max_turns            = $options['max_turns'] ?? PluginSettings::get( 'max_turns', 12 );
		$selected_pipeline_id = $options['selected_pipeline_id'] ?? null;
		$agent_type           = $options['agent_type'] ?? AgentType::CHAT;

		$chat_db = new ChatDatabase();

		AgentContext::set( $agent_type );

		try {
			$tool_manager = new ToolManager();
			$all_tools    = $tool_manager->getAvailableToolsForChat();

			$loop_context = array( 'session_id' => $session_id );
			if ( $selected_pipeline_id ) {
				$loop_context['selected_pipeline_id'] = $selected_pipeline_id;
			}

			$loop        = new AIConversationLoop();
			$loop_result = $loop->execute(
				$messages,
				$all_tools,
				$provider,
				$model,
				$agent_type,
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
						'agent_type' => $agent_type,
					)
				);

				return new WP_Error(
					'chubes_ai_request_failed',
					$loop_result['error'],
					array( 'status' => 500 )
				);
			}

			return array(
				'messages'          => $loop_result['messages'],
				'final_content'     => $loop_result['final_content'],
				'completed'         => $loop_result['completed'] ?? false,
				'turn_count'        => $loop_result['turn_count'] ?? 1,
				'last_tool_calls'   => $loop_result['last_tool_calls'] ?? array(),
				'warning'           => $loop_result['warning'] ?? null,
				'max_turns_reached' => $loop_result['max_turns_reached'] ?? false,
			);
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'error',
				'AI loop failed with exception',
				array(
					'session_id' => $session_id,
					'error'      => $e->getMessage(),
					'agent_type' => $agent_type,
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
		} finally {
			AgentContext::clear();
		}
	}
}
