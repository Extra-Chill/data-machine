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
	 * Flow:
	 * 1. Load the flow by id.
	 * 2. Silently migrate legacy v1 HMAC fields into the canonical v2 shape.
	 * 3. Route to the `authenticate_bearer` or `authenticate_via_verifier`
	 *    path based on `webhook_auth_mode`.
	 * 4. Enforce rate limiting.
	 * 5. Delegate to the `datamachine/run-flow` ability.
	 *
	 * Returns a generic 401 (or 413 for oversized HMAC payloads) for all auth
	 * failures to prevent information leakage. The real failure reason is
	 * logged server-side for the flow owner's diagnostics.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_trigger( \WP_REST_Request $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

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

		// Silently upgrade legacy v1 HMAC fields into the canonical v2 shape.
		// This happens once per flow, the first time any v1 flow is hit.
		$migration = WebhookAuthResolver::migrate_legacy( $scheduling_config );
		if ( $migration['migrated'] ) {
			$scheduling_config = $migration['config'];
			$db_flows->update_flow( $flow_id, array( 'scheduling_config' => $scheduling_config ) );
		}

		if ( empty( $scheduling_config['webhook_enabled'] ) ) {
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

		$resolved  = WebhookAuthResolver::resolve( $scheduling_config );
		$auth_mode = $resolved['mode'];

		if ( 'bearer' === $auth_mode ) {
			$auth_error = self::authenticate_bearer( $flow_id, $scheduling_config, $request );
		} else {
			$auth_error = self::authenticate_via_verifier( $flow_id, $resolved, $request );
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
	 * Authenticate a webhook request via the template verifier.
	 *
	 * Works for any mode other than `bearer`. Returns a generic 401
	 * (or 413 for oversized payloads) so callers can't distinguish
	 * failure modes from the outside. The structured reason is logged
	 * server-side for the flow owner's diagnostics.
	 *
	 * @param int              $flow_id
	 * @param array            $resolved  Output of WebhookAuthResolver::resolve().
	 * @param \WP_REST_Request $request
	 * @return \WP_Error|null WP_Error on failure, null on success.
	 */
	private static function authenticate_via_verifier( int $flow_id, array $resolved, \WP_REST_Request $request ): ?\WP_Error {
		$verifier_config = $resolved['verifier'] ?? null;
		if ( ! is_array( $verifier_config ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Webhook trigger: missing or malformed verifier config',
				array(
					'flow_id'   => $flow_id,
					'remote_ip' => self::get_remote_ip( $request ),
					'mode'      => $resolved['mode'] ?? 'unknown',
				)
			);
			return new \WP_Error(
				'unauthorized',
				'Invalid or missing authorization.',
				array( 'status' => 401 )
			);
		}

		$raw_body     = $request->get_body();
		$headers      = self::collect_headers( $request );
		$query_params = (array) $request->get_query_params();
		$post_params  = (array) $request->get_body_params();
		$url          = self::build_request_url( $request );

		$result = WebhookVerifier::verify(
			$raw_body,
			$headers,
			$query_params,
			$post_params,
			$url,
			$verifier_config
		);

		do_action(
			'datamachine_log',
			$result->ok ? 'info' : 'warning',
			'Webhook trigger: verification ' . $result->reason,
			array(
				'flow_id'      => $flow_id,
				'remote_ip'    => self::get_remote_ip( $request ),
				'mode'         => $resolved['mode'] ?? 'hmac',
				'reason'       => $result->reason,
				'secret_id'    => $result->secret_id,
				'timestamp'    => $result->timestamp,
				'skew_seconds' => $result->skew_seconds,
				'detail'       => $result->detail,
			)
		);

		if ( $result->ok ) {
			return null;
		}

		if ( WebhookVerificationResult::PAYLOAD_TOO_LARGE === $result->reason ) {
			return new \WP_Error(
				'payload_too_large',
				'Payload too large.',
				array( 'status' => 413 )
			);
		}

		return new \WP_Error(
			'unauthorized',
			'Invalid or missing authorization.',
			array( 'status' => 401 )
		);
	}

	/**
	 * Collect all request headers into a lower-case-keyed assoc array.
	 *
	 * @param \WP_REST_Request $request
	 * @return array<string,string>
	 */
	private static function collect_headers( \WP_REST_Request $request ): array {
		$out = array();
		foreach ( (array) $request->get_headers() as $name => $values ) {
			$value              = is_array( $values ) ? implode( ',', array_map( 'strval', $values ) ) : (string) $values;
			$normalised         = strtolower( str_replace( '_', '-', (string) $name ) );
			$out[ $normalised ] = $value;
		}
		return $out;
	}

	/**
	 * Reconstruct the full request URL.
	 *
	 * @param \WP_REST_Request $request
	 * @return string
	 */
	private static function build_request_url( \WP_REST_Request $request ): string {
		$route = ltrim( $request->get_route(), '/' );
		$url   = rest_url( $route );
		$query = $request->get_query_params();
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}
		return $url;
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
	 * Safe subset of request headers for logging.
	 *
	 * Pattern-based deny-list — we log everything EXCEPT headers whose name
	 * matches a sensitive pattern (auth / cookies / anything that looks like
	 * a secret or signature). No provider-specific allow-list; works for
	 * every current and future webhook source by construction.
	 *
	 * @param \WP_REST_Request $request
	 * @return array Filtered headers, lower-case-keyed.
	 */
	private static function get_safe_headers( \WP_REST_Request $request ): array {
		$deny_exact   = array( 'authorization', 'cookie', 'proxy-authorization' );
		$deny_pattern = '/(?:secret|token|sig|hmac|signature|auth|password|bearer|api[-_]?key)/i';

		$headers = array();
		foreach ( (array) $request->get_headers() as $name => $values ) {
			$key = strtolower( str_replace( '_', '-', (string) $name ) );
			if ( in_array( $key, $deny_exact, true ) ) {
				continue;
			}
			if ( preg_match( $deny_pattern, $key ) ) {
				continue;
			}
			$value           = is_array( $values ) ? implode( ',', array_map( 'strval', $values ) ) : (string) $values;
			$headers[ $key ] = $value;
		}

		return $headers;
	}
}
