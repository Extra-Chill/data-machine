<?php
/**
 * REST API Agent Ping Endpoint
 *
 * Provides confirmation callback endpoint for agent ping responses.
 * Agents call this endpoint to report completion status.
 *
 * @package DataMachine\Api
 * @since 0.22.0
 */

namespace DataMachine\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Agent Ping API Handler
 *
 * Provides REST endpoint for agent ping confirmation callbacks.
 */
class AgentPing {

	/**
	 * Register the API endpoint.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register REST API routes
	 *
	 * @since 0.22.0
	 */
	public static function register_routes() {
		// Agent ping confirmation callback
		register_rest_route(
			'datamachine/v1',
			'/agent-ping/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_confirm' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'callback_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Unique callback ID from the original ping', 'data-machine' ),
					),
					'status'      => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'success', 'failed', 'timeout' ), true );
						},
						'description'       => __( 'Agent execution status: success, failed, or timeout', 'data-machine' ),
					),
					'message_preview' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'description'       => __( 'Preview of agent response/message', 'data-machine' ),
					),
					'error_message' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'description'       => __( 'Error message if status is failed', 'data-machine' ),
					),
				),
			)
		);

		// Get callback status (for polling)
		register_rest_route(
			'datamachine/v1',
			'/agent-ping/callback/(?P<callback_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_callback_status' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'callback_id' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'Callback ID to check status for', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Validate bearer token from webhook
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	public static function check_permission( WP_REST_Request $request ) {
		$auth_header = $request->get_header( 'authorization' );

		// Get expected token from settings
		$expected_token = get_option( 'datamachine_agent_ping_callback_token', '' );

		// If no token configured, only allow from localhost
		if ( empty( $expected_token ) ) {
			$real_ip = $request->get_header( 'x-real-ip' ) ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
			if ( in_array( $real_ip, array( '127.0.0.1', '::1', 'localhost' ), true ) ) {
				return true;
			}
			return new \WP_Error(
				'rest_forbidden',
				__( 'No callback token configured. Only localhost requests allowed.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		// Validate bearer token
		if ( ! $auth_header || ! str_starts_with( $auth_header, 'Bearer ' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Missing or invalid authorization header.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		$token = substr( $auth_header, 7 ); // Remove "Bearer "

		if ( ! hash_equals( $expected_token, $token ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Invalid callback token.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle agent ping confirmation
	 *
	 * Called by the webhook when agent completes processing.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_confirm( WP_REST_Request $request ) {
		$callback_id     = $request->get_param( 'callback_id' );
		$status          = $request->get_param( 'status' );
		$message_preview = $request->get_param( 'message_preview' ) ?? '';
		$error_message   = $request->get_param( 'error_message' ) ?? '';

		// Get stored callback data
		$callback_data = get_option( "datamachine_agent_ping_callback_{$callback_id}" );

		if ( ! $callback_data ) {
			return new \WP_Error(
				'callback_not_found',
				__( 'Callback ID not found or expired.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		// Check if already processed
		if ( ! empty( $callback_data['processed_at'] ) ) {
			return rest_ensure_response(
				array(
					'success'   => true,
					'message'   => __( 'Callback already processed.', 'data-machine' ),
					'status'    => $callback_data['status'],
					'processed' => true,
				)
			);
		}

		// Update callback data
		$callback_data['status']          = $status;
		$callback_data['message_preview'] = $message_preview;
		$callback_data['error_message']   = $error_message;
		$callback_data['processed_at']    = current_time( 'mysql' );

		update_option( "datamachine_agent_ping_callback_{$callback_id}", $callback_data );

		// Trigger action for job processing
		do_action(
			'datamachine_agent_ping_confirmed',
			$callback_data['job_id'],
			$callback_data['flow_step_id'],
			$status,
			$callback_data
		);

		// Log confirmation
		do_action(
			'datamachine_log',
			'info',
			'Agent ping confirmed',
			array(
				'job_id'        => $callback_data['job_id'],
				'callback_id'   => $callback_id,
				'status'        => $status,
				'error_message' => $error_message,
			)
		);

		return rest_ensure_response(
			array(
				'success'      => true,
				'message'      => __( 'Confirmation received.', 'data-machine' ),
				'callback_id'  => $callback_id,
				'job_id'       => $callback_data['job_id'],
				'flow_step_id' => $callback_data['flow_step_id'],
			)
		);
	}

	/**
	 * Get callback status
	 *
	 * Allows polling for callback status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_get_callback_status( WP_REST_Request $request ) {
		$callback_id   = $request->get_param( 'callback_id' );
		$callback_data = get_option( "datamachine_agent_ping_callback_{$callback_id}" );

		if ( ! $callback_data ) {
			return new \WP_Error(
				'callback_not_found',
				__( 'Callback ID not found or expired.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'callback_id'     => $callback_id,				'job_id'           => $callback_data['job_id'] ?? null,
				'flow_step_id'     => $callback_data['flow_step_id'] ?? null,
				'status'           => $callback_data['status'] ?? 'pending',
				'message_preview'  => $callback_data['message_preview'] ?? '',
				'error_message'    => $callback_data['error_message'] ?? '',
				'processed_at'     => $callback_data['processed_at'] ?? null,
				'created_at'       => $callback_data['created_at'] ?? null,
			)
		);
	}
}
