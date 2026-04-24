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

use DataMachine\Abilities\PermissionHelper;
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
	 * Default maximum raw body size (1 MB).
	 *
	 * @var int
	 */
	const DEFAULT_MAX_BODY_BYTES = 1048576;

	/**
	 * Handle inbound webhook trigger.
	 *
	 * Authentication flow (Bearer, default):
	 * 1. Extract Bearer token from Authorization header
	 * 2. Load flow by ID
	 * 3. Verify webhook_enabled in scheduling_config
	 * 4. Constant-time token comparison via hash_equals()
	 * 5. Delegate to execute-workflow ability
	 *
	 * Authentication flow (HMAC-SHA256, opt-in via `webhook_auth_mode`):
	 * 1. Load flow by ID
	 * 2. Verify webhook_enabled and a non-empty secret in scheduling_config
	 * 3. Enforce optional max body size (413 on overflow)
	 * 4. Verify signature header via WebhookSignatureVerifier::verify_hmac_sha256()
	 * 5. Delegate to execute-workflow ability
	 *
	 * Returns generic 401 for all auth failures to prevent information leakage.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_trigger( \WP_REST_Request $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		// Load flow first so auth-mode branching can read scheduling_config.
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

		if ( ! $webhook_enabled ) {
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

		$auth_mode = self::resolve_auth_mode( $scheduling_config );

		if ( 'hmac_sha256' === $auth_mode ) {
			$auth_error = self::authenticate_hmac( $flow_id, $scheduling_config, $request );
		} else {
			$auth_error = self::authenticate_bearer( $flow_id, $scheduling_config, $request );
		}

		if ( $auth_error instanceof \WP_Error ) {
			return $auth_error;
		}

		// Check rate limit before executing.
		$rate_limit_error = self::check_rate_limit( $flow_id, $scheduling_config, $request );
		if ( $rate_limit_error ) {
			return $rate_limit_error;
		}

		// Auth passed — execute the flow via the Abilities API.
		// Use run_as_authenticated() so the ability's permission callback
		// recognizes this as a pre-authenticated context (Bearer token validated above).
		// See https://github.com/Extra-Chill/data-machine/issues/346
		$ability = wp_get_ability( 'datamachine/run-flow' );

		if ( ! $ability ) {
			do_action(
				'datamachine_log',
				'error',
				'Webhook trigger: run-flow ability not registered',
				array( 'flow_id' => $flow_id )
			);

			return new \WP_Error(
				'ability_not_found',
				__( 'Run flow ability not available.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		// Build webhook payload from request body.
		$webhook_body = $request->get_json_params();
		if ( empty( $webhook_body ) ) {
			$webhook_body = $request->get_body_params();
		}
		if ( empty( $webhook_body ) ) {
			// Fall back to decoding the raw body — HMAC flows often arrive with
			// non-standard content types that skip WP's automatic parsing.
			$raw = $request->get_body();
			if ( ! empty( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$webhook_body = $decoded;
				}
			}
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
					'auth_mode'   => $auth_mode,
				),
			),
		);

		$result = PermissionHelper::run_as_authenticated(
			function () use ( $ability, $input ) {
				return $ability->execute( $input );
			}
		);

		// WP_Ability::execute() returns WP_Error on permission/validation failure.
		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Webhook trigger: Ability execution error',
				array(
					'flow_id' => $flow_id,
					'error'   => $result->get_error_message(),
				)
			);

			return new \WP_Error(
				'execution_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

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
	 * Default rate limit: max requests per window.
	 *
	 * @var int
	 */
	const DEFAULT_RATE_LIMIT_MAX = 60;

	/**
	 * Default rate limit window in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_RATE_LIMIT_WINDOW = 60;

	/**
	 * Resolve the effective webhook auth mode for a flow.
	 *
	 * Missing / unrecognized values default to `bearer` so existing flows
	 * behave identically to before HMAC support was added.
	 *
	 * @param array $scheduling_config Flow scheduling config.
	 * @return string Either 'bearer' or 'hmac_sha256'.
	 */
	private static function resolve_auth_mode( array $scheduling_config ): string {
		$mode = $scheduling_config['webhook_auth_mode'] ?? 'bearer';
		if ( 'hmac_sha256' === $mode ) {
			return 'hmac_sha256';
		}
		return 'bearer';
	}

	/**
	 * Authenticate a webhook request using a per-flow Bearer token.
	 *
	 * @param int              $flow_id          Flow ID.
	 * @param array            $scheduling_config Flow scheduling config.
	 * @param \WP_REST_Request $request          REST request object.
	 * @return \WP_Error|null WP_Error on failure, null on success.
	 */
	private static function authenticate_bearer( int $flow_id, array $scheduling_config, \WP_REST_Request $request ): ?\WP_Error {
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

		$stored_token = $scheduling_config['webhook_token'] ?? '';

		if ( empty( $stored_token ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: Bearer token not configured for flow',
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

		return null;
	}

	/**
	 * Authenticate a webhook request using HMAC-SHA256 signatures.
	 *
	 * Verifies the raw request body (`$request->get_body()`) against a
	 * provider-specified signature header, using the shared secret stored
	 * in `scheduling_config.webhook_secret`.
	 *
	 * @param int              $flow_id          Flow ID.
	 * @param array            $scheduling_config Flow scheduling config.
	 * @param \WP_REST_Request $request          REST request object.
	 * @return \WP_Error|null WP_Error on failure, null on success.
	 */
	private static function authenticate_hmac( int $flow_id, array $scheduling_config, \WP_REST_Request $request ): ?\WP_Error {
		$secret = $scheduling_config['webhook_secret'] ?? '';
		if ( empty( $secret ) || ! is_string( $secret ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: HMAC secret not configured for flow',
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

		$signature_header = $scheduling_config['webhook_signature_header'] ?? 'X-Hub-Signature-256';
		$signature_format = $scheduling_config['webhook_signature_format'] ?? WebhookSignatureVerifier::FORMAT_PREFIXED_HEX;

		$provided = $request->get_header( $signature_header );
		if ( empty( $provided ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: Missing HMAC signature header',
				array(
					'flow_id'          => $flow_id,
					'remote_ip'        => self::get_remote_ip( $request ),
					'signature_header' => $signature_header,
				)
			);

			return new \WP_Error(
				'unauthorized',
				'Invalid or missing authorization.',
				array( 'status' => 401 )
			);
		}

		$raw_body = $request->get_body();

		// Enforce an optional max body size before running HMAC — unauthenticated
		// clients can otherwise force the server to hash arbitrarily large payloads.
		$max_body_bytes = (int) ( $scheduling_config['webhook_max_body_bytes'] ?? self::DEFAULT_MAX_BODY_BYTES );
		if ( $max_body_bytes > 0 && strlen( $raw_body ) > $max_body_bytes ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: Payload exceeds max_body_bytes',
				array(
					'flow_id'   => $flow_id,
					'remote_ip' => self::get_remote_ip( $request ),
					'size'      => strlen( $raw_body ),
					'limit'     => $max_body_bytes,
				)
			);

			return new \WP_Error(
				'payload_too_large',
				'Payload too large.',
				array( 'status' => 413 )
			);
		}

		$valid = WebhookSignatureVerifier::verify_hmac_sha256(
			$raw_body,
			$provided,
			$secret,
			$signature_format
		);

		if ( ! $valid ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: HMAC signature mismatch',
				array(
					'flow_id'          => $flow_id,
					'remote_ip'        => self::get_remote_ip( $request ),
					'signature_header' => $signature_header,
					'signature_format' => $signature_format,
				)
			);

			return new \WP_Error(
				'unauthorized',
				'Invalid or missing authorization.',
				array( 'status' => 401 )
			);
		}

		return null;
	}

	/**
	 * Check rate limit for a webhook trigger request.
	 *
	 * Uses WordPress transients as a simple fixed-window counter.
	 * Each flow gets its own counter that resets after the window expires.
	 *
	 * @param int              $flow_id          Flow ID.
	 * @param array            $scheduling_config Flow scheduling config.
	 * @param \WP_REST_Request $request          REST request for logging.
	 * @return \WP_Error|null Error if rate limited, null if allowed.
	 */
	private static function check_rate_limit( int $flow_id, array $scheduling_config, \WP_REST_Request $request ): ?\WP_Error {
		$rate_config = $scheduling_config['webhook_rate_limit'] ?? array();
		$max         = (int) ( $rate_config['max'] ?? self::DEFAULT_RATE_LIMIT_MAX );
		$window      = (int) ( $rate_config['window'] ?? self::DEFAULT_RATE_LIMIT_WINDOW );

		// Rate limiting disabled if max is 0.
		if ( $max <= 0 ) {
			return null;
		}

		$transient_key = 'dm_webhook_rate_' . $flow_id;
		$current_count = (int) get_transient( $transient_key );

		if ( $current_count >= $max ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: Rate limit exceeded',
				array(
					'flow_id'   => $flow_id,
					'remote_ip' => self::get_remote_ip( $request ),
					'limit'     => $max,
					'window'    => $window,
				)
			);

			$response = new \WP_Error(
				'rate_limit_exceeded',
				sprintf(
					'Rate limit exceeded. Maximum %d requests per %d seconds.',
					$max,
					$window
				),
				array( 'status' => 429 )
			);

			// Add Retry-After header hint.
			add_filter(
				'rest_post_dispatch',
				function ( $result ) use ( $window ) {
					if ( $result instanceof \WP_REST_Response ) {
						$result->header( 'Retry-After', (string) $window );
					}
					return $result;
				}
			);

			return $response;
		}

		// Increment counter (set with TTL on first request in window).
		if ( 0 === $current_count ) {
			set_transient( $transient_key, 1, $window );
		} else {
			set_transient( $transient_key, $current_count + 1, $window );
		}

		return null;
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
			'x-shopify-topic',
			'x-shopify-hmac-sha256',
			'stripe-signature',
			'linear-signature',
			'x-slack-signature',
			'x-slack-request-timestamp',
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
