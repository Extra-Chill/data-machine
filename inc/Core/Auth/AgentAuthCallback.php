<?php
/**
 * Agent Auth Callback Handler
 *
 * Receives bearer tokens from external Data Machine authorize flows.
 * When another DM instance redirects back with ?token=datamachine_...,
 * this endpoint captures the token and stores it securely.
 *
 * Flow (from this site's perspective — we are the RECEIVING side):
 * 1. Our agent initiates auth on remote site (e.g., extrachill.com)
 * 2. Human approves on remote site
 * 3. Remote site redirects to: our-site.com/wp-json/datamachine/v1/agent/auth/callback?token=X&agent_slug=Y
 * 4. This handler stores the token and shows a success page
 *
 * Storage: tokens are stored in a network option keyed by remote site + agent slug.
 * Format: datamachine_external_tokens = { "extrachill.com/sarai": { token, agent_slug, agent_id, received_at } }
 *
 * @package DataMachine\Core\Auth
 * @since 0.56.0
 */

namespace DataMachine\Core\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentAuthCallback {

	/**
	 * Option key for storing received external tokens.
	 */
	const OPTION_KEY = 'datamachine_external_tokens';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the callback route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'datamachine/v1',
			'/agent/auth/callback',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'      => array(
						'required' => false,
						'type'     => 'string',
					),
					'agent_slug' => array(
						'required' => false,
						'type'     => 'string',
					),
					'agent_id'   => array(
						'required' => false,
						'type'     => 'integer',
					),
					'error'      => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		// Retrieve stored token for a remote site + agent (authenticated).
		register_rest_route(
			'datamachine/v1',
			'/agent/auth/tokens',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_external_tokens' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Get a specific external token by key (authenticated).
		register_rest_route(
			'datamachine/v1',
			'/agent/auth/tokens/(?P<key>[a-zA-Z0-9._\-/]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_external_token' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'key' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Handle the OAuth callback — store token or show error.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return void Renders HTML result page.
	 */
	public function handle_callback( \WP_REST_Request $request ) {
		$error = sanitize_text_field( $request->get_param( 'error' ) );

		if ( ! empty( $error ) ) {
			$this->render_result_page( false, $this->get_error_message( $error ) );
			exit;
		}

		$token      = sanitize_text_field( $request->get_param( 'token' ) );
		$agent_slug = sanitize_text_field( $request->get_param( 'agent_slug' ) );
		$agent_id   = (int) $request->get_param( 'agent_id' );

		if ( empty( $token ) ) {
			$this->render_result_page( false, 'No token received in callback.' );
			exit;
		}

		if ( empty( $agent_slug ) ) {
			$this->render_result_page( false, 'No agent_slug received in callback.' );
			exit;
		}

		// Detect the remote site from the Referer header or token prefix.
		$remote_site = $this->detect_remote_site( $request );

		// Store the token.
		$storage_key = $this->store_token( $remote_site, $agent_slug, $token, $agent_id );

		do_action(
			'datamachine_log',
			'info',
			'External agent token received via callback',
			array(
				'remote_site' => $remote_site,
				'agent_slug'  => $agent_slug,
				'agent_id'    => $agent_id,
				'storage_key' => $storage_key,
			)
		);

		$this->render_result_page(
			true,
			sprintf(
				'Token received for agent <strong>%s</strong> from <strong>%s</strong>. Stored as <code>%s</code>.',
				esc_html( $agent_slug ),
				esc_html( $remote_site ),
				esc_html( $storage_key )
			)
		);
		exit;
	}

	/**
	 * List all stored external tokens (metadata only — never expose raw tokens via REST).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function list_external_tokens( \WP_REST_Request $request ): \WP_REST_Response {
		$tokens = get_option( self::OPTION_KEY, array() );
		$result = array();

		foreach ( $tokens as $key => $data ) {
			$result[] = array(
				'key'         => $key,
				'remote_site' => $data['remote_site'] ?? '',
				'agent_slug'  => $data['agent_slug'] ?? '',
				'agent_id'    => $data['agent_id'] ?? 0,
				'received_at' => $data['received_at'] ?? '',
				'has_token'   => ! empty( $data['token'] ),
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get a specific external token by storage key.
	 *
	 * Returns the actual token value — admin-only.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_external_token( \WP_REST_Request $request ): \WP_REST_Response {
		$key    = sanitize_text_field( $request->get_param( 'key' ) );
		$tokens = get_option( self::OPTION_KEY, array() );

		if ( ! isset( $tokens[ $key ] ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'Token not found for key: ' . $key ),
				404
			);
		}

		return rest_ensure_response( $tokens[ $key ] );
	}

	/**
	 * Store an external token.
	 *
	 * @param string $remote_site Remote site domain.
	 * @param string $agent_slug  Agent slug on the remote site.
	 * @param string $token       Raw bearer token.
	 * @param int    $agent_id    Agent ID on the remote site.
	 * @return string Storage key.
	 */
	private function store_token( string $remote_site, string $agent_slug, string $token, int $agent_id ): string {
		$key    = $remote_site . '/' . $agent_slug;
		$tokens = get_option( self::OPTION_KEY, array() );

		$tokens[ $key ] = array(
			'remote_site' => $remote_site,
			'agent_slug'  => $agent_slug,
			'agent_id'    => $agent_id,
			'token'       => $token,
			'received_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		update_option( self::OPTION_KEY, $tokens, false );

		return $key;
	}

	/**
	 * Detect the remote site from the request context.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return string Remote site domain.
	 */
	private function detect_remote_site( \WP_REST_Request $request ): string {
		// Try Referer header first.
		$referer = $request->get_header( 'referer' );
		if ( ! empty( $referer ) ) {
			$parsed = wp_parse_url( $referer );
			if ( ! empty( $parsed['host'] ) ) {
				return $parsed['host'];
			}
		}

		// Fall back to Origin header.
		$origin = $request->get_header( 'origin' );
		if ( ! empty( $origin ) ) {
			$parsed = wp_parse_url( $origin );
			if ( ! empty( $parsed['host'] ) ) {
				return $parsed['host'];
			}
		}

		return 'unknown';
	}

	/**
	 * Get human-readable error message.
	 *
	 * @param string $error Error code.
	 * @return string
	 */
	private function get_error_message( string $error ): string {
		$messages = array(
			'access_denied'         => 'Authorization was denied by the user.',
			'not_authenticated'     => 'User was not logged in on the remote site.',
			'agent_not_found'       => 'The requested agent was not found on the remote site.',
			'token_creation_failed' => 'The remote site failed to create a token.',
			'invalid_nonce'         => 'Security validation failed. Please try again.',
		);

		return $messages[ $error ] ?? sprintf( 'Authorization failed: %s', esc_html( $error ) );
	}

	/**
	 * Render a simple result page.
	 *
	 * @param bool   $success Whether the operation succeeded.
	 * @param string $message HTML message to display.
	 */
	private function render_result_page( bool $success, string $message ): void {
		$site_name = get_bloginfo( 'name' );
		$icon      = $success ? '&#10003;' : '&#10007;';
		$color     = $success ? '#00a32a' : '#d63638';
		$title     = $success ? 'Authorization Complete' : 'Authorization Failed';
		$bg_color  = $success ? '#edfaef' : '#fcf0f1';

		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>' . esc_html( $title ) . ' &mdash; ' . esc_html( $site_name ) . '</title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f0f0f1;
			color: #1d2327;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 2rem;
		}
		.result-container {
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 1px 3px rgba(0,0,0,0.1);
			max-width: 480px;
			width: 100%;
			padding: 2rem;
			text-align: center;
		}
		.result-icon {
			width: 48px;
			height: 48px;
			border-radius: 50%;
			background: ' . $bg_color . ';
			color: ' . $color . ';
			font-size: 1.5rem;
			line-height: 48px;
			margin: 0 auto 1rem;
		}
		h1 {
			font-size: 1.25rem;
			font-weight: 600;
			margin-bottom: 0.75rem;
		}
		.message {
			font-size: 0.9375rem;
			color: #50575e;
			line-height: 1.5;
		}
		.message code {
			background: #f6f7f7;
			padding: 0.125rem 0.375rem;
			border-radius: 3px;
			font-size: 0.8125rem;
		}
		.close-hint {
			margin-top: 1.5rem;
			font-size: 0.8125rem;
			color: #a7aaad;
		}
	</style>
</head>
<body>
	<div class="result-container">
		<div class="result-icon">' . $icon . '</div>
		<h1>' . esc_html( $title ) . '</h1>
		<p class="message">' . $message . '</p>
		<p class="close-hint">You can close this window.</p>
	</div>
</body>
</html>';
	}

	/**
	 * Get a stored external token programmatically.
	 *
	 * @param string $remote_site Remote site domain (e.g., "extrachill.com").
	 * @param string $agent_slug  Agent slug on the remote site.
	 * @return string|null Raw token or null if not stored.
	 */
	public static function get_token( string $remote_site, string $agent_slug ): ?string {
		$key    = $remote_site . '/' . $agent_slug;
		$tokens = get_option( self::OPTION_KEY, array() );

		if ( isset( $tokens[ $key ] ) && ! empty( $tokens[ $key ]['token'] ) ) {
			return $tokens[ $key ]['token'];
		}

		return null;
	}
}
