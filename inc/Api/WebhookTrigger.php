<?php
/**
 * Webhook Trigger REST Endpoint
 *
 * Public endpoint for triggering flows via inbound HTTP requests.
 * Authenticated via per-flow Bearer tokens, not WordPress capabilities.
 *
 * Complementary to the existing /execute endpoint (admin-only) and
 * WebhookGate step (mid-pipeline pause/resume). This endpoint starts
 * new flow executions from external services.
 *
 * @package DataMachine\Api
 * @since 0.30.0
 * @see https://github.com/Extra-Chill/data-machine/issues/342
 */

namespace DataMachine\Api;

use DataMachine\Abilities\Job\ExecuteWorkflowAbility;
use DataMachine\Core\Database\Flows\Flows;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook Trigger REST API handler.
 *
 * Registers and handles the public /trigger/{flow_id} endpoint
 * for inbound webhook-driven flow execution.
 */
class WebhookTrigger {

	/**
	 * Initialize REST API hooks.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register webhook trigger REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/trigger/(?P<flow_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_trigger' ),
				'permission_callback' => '__return_true', // Auth via Bearer token, not WP capabilities.
				'args'                => array(
					'flow_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
						'sanitize_callback' => function ( $value ) {
							return (int) $value;
						},
					),
				),
			)
		);
	}

	/**
	 * Handle inbound webhook trigger.
	 *
	 * Authentication flow:
	 * 1. Extract Bearer token from Authorization header
	 * 2. Load flow by ID
	 * 3. Verify webhook_enabled in scheduling_config
	 * 4. Constant-time token comparison via hash_equals()
	 * 5. Delegate to execute-workflow ability
	 *
	 * Returns generic 401 for all auth failures to prevent information leakage.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_trigger( \WP_REST_Request $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		// Extract Bearer token from Authorization header.
		$auth_header = $request->get_header( 'authorization' );
		$token       = self::extract_bearer_token( $auth_header );

		if ( ! $token ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: Missing or malformed Authorization header',
				array(
					'flow_id'   => $flow_id,
					'remote_ip' => self::get_remote_ip( $request ),
				)
			);

			return new \WP_Error(
				'unauthorized',
				'Invalid or missing authorization.',
				array( 'status' => 401 )
			);
		}

		// Validate token format before database lookup.
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: Invalid token format',
				array(
					'flow_id'   => $flow_id,
					'remote_ip' => self::get_remote_ip( $request ),
				)
			);

			return new \WP_Error(
				'unauthorized',
				'Invalid or missing authorization.',
				array( 'status' => 401 )
			);
		}

		// Load flow and validate webhook configuration.
		$db_flows = new Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: Flow not found',
				array(
					'flow_id'   => $flow_id,
					'remote_ip' => self::get_remote_ip( $request ),
				)
			);

			// Generic 401 — don't reveal whether the flow exists.
			return new \WP_Error(
				'unauthorized',
				'Invalid or missing authorization.',
				array( 'status' => 401 )
			);
		}

		$scheduling_config = $flow['scheduling_config'] ?? array();
		$webhook_enabled   = ! empty( $scheduling_config['webhook_enabled'] );
		$stored_token      = $scheduling_config['webhook_token'] ?? '';

		if ( ! $webhook_enabled || empty( $stored_token ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: Webhook not enabled for flow',
				array(
					'flow_id'   => $flow_id,
					'remote_ip' => self::get_remote_ip( $request ),
				)
			);

			return new \WP_Error(
				'unauthorized',
				'Invalid or missing authorization.',
				array( 'status' => 401 )
			);
		}

		// Constant-time token comparison to prevent timing attacks.
		if ( ! hash_equals( $stored_token, $token ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: Token mismatch',
				array(
					'flow_id'   => $flow_id,
					'remote_ip' => self::get_remote_ip( $request ),
				)
			);

			return new \WP_Error(
				'unauthorized',
				'Invalid or missing authorization.',
				array( 'status' => 401 )
			);
		}

		// Auth passed — execute the flow.
		// Instantiate directly to bypass the Abilities API permission check.
		// The webhook trigger has already authenticated via Bearer token.
		$ability = new ExecuteWorkflowAbility();

		// Build webhook payload from request body.
		$webhook_body = $request->get_json_params();
		if ( empty( $webhook_body ) ) {
			$webhook_body = $request->get_body_params();
		}
		if ( empty( $webhook_body ) ) {
			$webhook_body = array();
		}

		// Execute the flow with webhook metadata in initial_data.
		$input = array(
			'flow_id'      => $flow_id,
			'initial_data' => array(
				'webhook_trigger' => array(
					'payload'     => $webhook_body,
					'received_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'remote_ip'   => self::get_remote_ip( $request ),
					'headers'     => self::get_safe_headers( $request ),
				),
			),
		);

		$result = $ability->execute( $input );

		if ( ! ( $result['success'] ?? false ) ) {
			$error = $result['error'] ?? __( 'Flow execution failed', 'data-machine' );

			do_action(
				'datamachine_log',
				'error',
				'Webhook trigger: Flow execution failed',
				array(
					'flow_id' => $flow_id,
					'error'   => $error,
				)
			);

			return new \WP_Error(
				'execution_failed',
				$error,
				array( 'status' => 500 )
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Webhook trigger: Flow executed successfully',
			array(
				'flow_id'      => $flow_id,
				'flow_name'    => $result['flow_name'] ?? '',
				'job_id'       => $result['job_id'] ?? null,
				'remote_ip'    => self::get_remote_ip( $request ),
				'payload_keys' => array_keys( $webhook_body ),
			)
		);

		return rest_ensure_response(
			array(
				'success'   => true,
				'flow_id'   => $flow_id,
				'flow_name' => $result['flow_name'] ?? '',
				'job_id'    => $result['job_id'] ?? null,
				'message'   => $result['message'] ?? __( 'Flow triggered successfully', 'data-machine' ),
			)
		);
	}

	/**
	 * Extract Bearer token from Authorization header.
	 *
	 * @param string|null $auth_header Authorization header value.
	 * @return string|null Token string or null if not found.
	 */
	private static function extract_bearer_token( ?string $auth_header ): ?string {
		if ( empty( $auth_header ) ) {
			return null;
		}

		if ( 0 !== strpos( $auth_header, 'Bearer ' ) ) {
			return null;
		}

		$token = substr( $auth_header, 7 );

		return ! empty( $token ) ? trim( $token ) : null;
	}

	/**
	 * Get remote IP address from request.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return string Remote IP address.
	 */
	private static function get_remote_ip( \WP_REST_Request $request ): string {
		$forwarded = $request->get_header( 'x-forwarded-for' );
		if ( $forwarded ) {
			// Take the first IP if multiple are chained.
			$ips = explode( ',', $forwarded );
			return trim( $ips[0] );
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}

	/**
	 * Get safe subset of request headers for logging.
	 *
	 * Excludes the Authorization header to avoid logging tokens.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return array Filtered headers.
	 */
	private static function get_safe_headers( \WP_REST_Request $request ): array {
		$safe_keys = array(
			'content-type',
			'user-agent',
			'x-github-event',
			'x-github-delivery',
			'x-hub-signature-256',
			'x-webhook-id',
			'x-request-id',
		);

		$headers = array();
		foreach ( $safe_keys as $key ) {
			$value = $request->get_header( $key );
			if ( $value ) {
				$headers[ $key ] = $value;
			}
		}

		return $headers;
	}
}
