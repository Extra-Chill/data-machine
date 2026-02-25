<?php
/**
 * Chat REST API Controller
 *
 * Thin REST controller for chat endpoints. Handles route registration,
 * request parsing, response formatting, and idempotency. Business logic
 * is delegated to ChatOrchestrator and Chat Session abilities.
 *
 * @package DataMachine\Api\Chat
 * @since 0.2.0
 * @since 0.31.0 Refactored to thin controller; orchestration moved to ChatOrchestrator,
 *               session CRUD moved to Chat abilities.
 */

namespace DataMachine\Api\Chat;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\AgentType;
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
 * Chat API Controller
 */
class Chat {

	/**
	 * Register REST API routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register chat endpoints.
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
						'default'           => AgentType::CHAT,
						'description'       => __( 'Agent type filter (chat, pipeline, system)', 'data-machine' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return AgentType::isValid( $param );
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

		$result = ChatOrchestrator::processPing( $full_message, $provider, $model );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * List all chat sessions for current user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response data.
	 */
	public static function list_sessions( WP_REST_Request $request ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/list-chat-sessions' ) : null;

		if ( $ability ) {
			$result = $ability->execute(
				array(
					'user_id'    => get_current_user_id(),
					'limit'      => (int) $request->get_param( 'limit' ),
					'offset'     => (int) $request->get_param( 'offset' ),
					'agent_type' => $request->get_param( 'agent_type' ),
				)
			);

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $result,
				)
			);
		}

		// Fallback: direct DB access (should not happen when abilities are loaded).
		$user_id    = get_current_user_id();
		$limit      = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );
		$offset     = max( 0, (int) $request->get_param( 'offset' ) );
		$agent_type = $request->get_param( 'agent_type' );

		$chat_db  = new \DataMachine\Core\Database\Chat\Chat();
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
	 * Delete a chat session.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function delete_session( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$user_id    = get_current_user_id();

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/delete-chat-session' ) : null;

		if ( $ability ) {
			$result = $ability->execute(
				array(
					'session_id' => $session_id,
					'user_id'    => $user_id,
				)
			);

			if ( empty( $result['success'] ) ) {
				$error_code = $result['error'] ?? 'delete_failed';
				$status     = 'session_not_found' === $error_code ? 404 : ( 'session_access_denied' === $error_code ? 403 : 500 );

				return new WP_Error( $error_code, $result['error'] ?? 'Delete failed', array( 'status' => $status ) );
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $result,
				)
			);
		}

		// Fallback: direct DB access.
		$chat_db = new \DataMachine\Core\Database\Chat\Chat();
		$session = $chat_db->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error( 'session_not_found', __( 'Session not found', 'data-machine' ), array( 'status' => 404 ) );
		}

		if ( (int) $session['user_id'] !== $user_id ) {
			return new WP_Error( 'session_access_denied', __( 'Access denied to this session', 'data-machine' ), array( 'status' => 403 ) );
		}

		$deleted = $chat_db->delete_session( $session_id );

		if ( ! $deleted ) {
			return new WP_Error( 'session_delete_failed', __( 'Failed to delete session', 'data-machine' ), array( 'status' => 500 ) );
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
	 * Get existing chat session.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function get_session( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$user_id    = get_current_user_id();

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/get-chat-session' ) : null;

		if ( $ability ) {
			$result = $ability->execute(
				array(
					'session_id' => $session_id,
					'user_id'    => $user_id,
				)
			);

			if ( empty( $result['success'] ) ) {
				$error_code = $result['error'] ?? 'get_failed';
				$status     = 'session_not_found' === $error_code ? 404 : ( 'session_access_denied' === $error_code ? 403 : 500 );

				return new WP_Error( $error_code, $result['error'] ?? 'Get failed', array( 'status' => $status ) );
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $result,
				)
			);
		}

		// Fallback: direct DB access.
		$chat_db = new \DataMachine\Core\Database\Chat\Chat();
		$session = $chat_db->get_session( $session_id );

		if ( ! $session ) {
			return new WP_Error( 'session_not_found', __( 'Session not found', 'data-machine' ), array( 'status' => 404 ) );
		}

		if ( (int) $session['user_id'] !== $user_id ) {
			return new WP_Error( 'session_access_denied', __( 'Access denied to this session', 'data-machine' ), array( 'status' => 403 ) );
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
	 * Handle chat request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function handle_chat( WP_REST_Request $request ) {
		// --- Idempotency check ---
		$request_id = $request->get_header( 'X-Request-ID' );
		if ( $request_id ) {
			$request_id      = sanitize_text_field( $request_id );
			$cache_key       = 'datamachine_chat_request_' . $request_id;
			$cached_response = get_transient( $cache_key );
			if ( false !== $cached_response ) {
				return rest_ensure_response( $cached_response );
			}
		}

		// --- Extract and resolve params ---
		$message = sanitize_textarea_field( wp_unslash( $request->get_param( 'message' ) ) );

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

		// --- Delegate to orchestrator ---
		$result = ChatOrchestrator::processChat(
			$message,
			$provider,
			$model,
			get_current_user_id(),
			array(
				'session_id'           => $request->get_param( 'session_id' ),
				'selected_pipeline_id' => (int) $request->get_param( 'selected_pipeline_id' ),
				'request_id'           => $request_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// --- Format and cache response ---
		$response = array(
			'success' => true,
			'data'    => $result,
		);

		if ( $request_id ) {
			$cache_key = 'datamachine_chat_request_' . $request_id;
			set_transient( $cache_key, $response, 60 );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Handle chat continue request (turn-by-turn execution).
	 *
	 * @since 0.12.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response data or error.
	 */
	public static function handle_continue( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );

		$result = ChatOrchestrator::processContinue( $session_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}
}
