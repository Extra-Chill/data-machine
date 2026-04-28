<?php
/**
 * OAuth 2.0 Handler
 *
 * Centralized OAuth 2.0 flow implementation for all OAuth2 providers.
 * Supports three flow types:
 *
 * 1. Authorization Code (default) — server apps with a client_secret.
 * 2. Authorization Code + PKCE — modern public clients, no secret needed.
 * 3. Implicit (legacy) — token returned in URL fragment, no secret needed.
 *
 * @package DataMachine
 * @subpackage Core\OAuth
 * @since 0.2.0
 */

namespace DataMachine\Core\OAuth;

use DataMachine\Core\HttpClient;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class OAuth2Handler {

	use OAuthRedirects;

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/**
	 * Maximum serialized payload size in bytes.
	 *
	 * Keeps transients sane — payloads larger than this are rejected at
	 * create_state() time rather than silently bloating the options table.
	 */
	const MAX_PAYLOAD_SIZE = 4096;

	// -------------------------------------------------------------------------
	// State Management
	// -------------------------------------------------------------------------

	/**
	 * Create OAuth state nonce and store in transient.
	 *
	 * The state parameter is the canonical place in OAuth 2.0 to carry
	 * caller-defined context through the authorization dance. The payload
	 * array is stored alongside the CSRF nonce and returned to the caller
	 * on successful verify_state().
	 *
	 * @since 0.2.0
	 * @since 0.88.0 Added optional $payload parameter.
	 *
	 * @param string $provider_key Provider identifier (e.g., 'reddit', 'facebook').
	 * @param array  $payload      Optional caller-defined context to propagate through the OAuth flow.
	 *                             Must serialize to <= 4 KB. Opaque to the OAuth provider.
	 * @return string Generated state nonce value.
	 *
	 * @throws \InvalidArgumentException When serialized payload exceeds MAX_PAYLOAD_SIZE.
	 */
	public function create_state( string $provider_key, array $payload = array() ): string {
		// Validate payload size.
		if ( ! empty( $payload ) ) {
			$serialized_size = strlen( maybe_serialize( $payload ) );
			if ( $serialized_size > self::MAX_PAYLOAD_SIZE ) {
				throw new \InvalidArgumentException(
					sprintf(
						'OAuth state payload exceeds maximum size (%d bytes > %d bytes).',
						$serialized_size,
						self::MAX_PAYLOAD_SIZE
					)
				);
			}
		}

		$nonce  = bin2hex( random_bytes( 32 ) );
		$record = array(
			'nonce'      => $nonce,
			'payload'    => $payload,
			'created_at' => time(),
		);

		set_transient( "datamachine_{$provider_key}_oauth_state", $record, 15 * MINUTE_IN_SECONDS );

		do_action(
			'datamachine_log',
			'debug',
			'OAuth2: Created state nonce',
			array(
				'provider'     => $provider_key,
				'state_length' => strlen( $nonce ),
				'has_payload'  => ! empty( $payload ),
			)
		);

		return $nonce;
	}

	/**
	 * Verify OAuth state nonce and return the associated payload.
	 *
	 * Returns the caller-defined payload array on success, or false on failure.
	 * The transient is consumed (deleted) on successful verification.
	 *
	 * Backward-compatible: legacy plain-string transients (from in-flight
	 * authorizations during the deploy window) are handled gracefully —
	 * they verify correctly and return an empty payload array.
	 *
	 * IMPORTANT: The return type changed from `bool` to `array|false`.
	 * Callers MUST use `false !== $oauth2->verify_state(...)` instead of
	 * the previous `if ( $oauth2->verify_state(...) )` pattern, because
	 * an empty array `[]` is falsy in PHP boolean context.
	 *
	 * @since 0.2.0
	 * @since 0.88.0 Return type changed from bool to array|false. Returns payload on success.
	 *
	 * @param string $provider_key Provider identifier.
	 * @param string $state        State nonce value to verify.
	 * @return array|false Payload array on success (may be empty), false on failure.
	 */
	public function verify_state( string $provider_key, string $state ) {
		if ( empty( $state ) ) {
			$this->log_state_verification( $provider_key, false );
			return false;
		}

		$record = get_transient( "datamachine_{$provider_key}_oauth_state" );

		if ( false === $record ) {
			$this->log_state_verification( $provider_key, false );
			return false;
		}

		// Backward compat: legacy plain-string state (pre-payload era).
		if ( is_string( $record ) ) {
			if ( hash_equals( $record, $state ) ) {
				delete_transient( "datamachine_{$provider_key}_oauth_state" );
				$this->log_state_verification( $provider_key, true );
				return array();
			}
			$this->log_state_verification( $provider_key, false );
			return false;
		}

		// New structured record format.
		if ( ! is_array( $record ) || empty( $record['nonce'] ) ) {
			$this->log_state_verification( $provider_key, false );
			return false;
		}

		if ( ! hash_equals( $record['nonce'], $state ) ) {
			$this->log_state_verification( $provider_key, false );
			return false;
		}

		delete_transient( "datamachine_{$provider_key}_oauth_state" );
		$this->log_state_verification( $provider_key, true );

		return $record['payload'] ?? array();
	}

	/**
	 * Alias for verify_state() — reads cleaner at call sites that want the payload.
	 *
	 * @since 0.88.0
	 *
	 * @param string $provider_key Provider identifier.
	 * @param string $state        State nonce value to verify.
	 * @return array|false Payload array on success, false on failure.
	 */
	public function get_state_payload( string $provider_key, string $state ) {
		return $this->verify_state( $provider_key, $state );
	}

	/**
	 * Log state verification result.
	 *
	 * @param string $provider_key Provider identifier.
	 * @param bool   $is_valid     Whether the state was valid.
	 */
	private function log_state_verification( string $provider_key, bool $is_valid ): void {
		do_action(
			'datamachine_log',
			$is_valid ? 'debug' : 'error',
			'OAuth2: State verification',
			array(
				'provider' => $provider_key,
				'valid'    => $is_valid,
			)
		);
	}

	// -------------------------------------------------------------------------
	// PKCE (Proof Key for Code Exchange)
	// -------------------------------------------------------------------------

	/**
	 * Generate and store a PKCE code_verifier/code_challenge pair.
	 *
	 * The code_verifier is stored in a transient and included in the token
	 * exchange request. The code_challenge is sent with the authorization URL.
	 *
	 * Uses S256 method (SHA-256 hash of the verifier, base64url-encoded).
	 *
	 * @since 0.66.0
	 * @param string $provider_key Provider identifier.
	 * @return array{verifier: string, challenge: string, method: string} PKCE parameters.
	 */
	public function create_pkce( string $provider_key ): array {
		// Generate a random 32-byte verifier (43-128 chars when base64url-encoded per RFC 7636).
		$verifier = $this->base64url_encode( random_bytes( 32 ) );

		// S256: SHA-256 hash of the verifier, base64url-encoded.
		$challenge = $this->base64url_encode( hash( 'sha256', $verifier, true ) );

		// Store verifier for token exchange (15 minutes, same as state).
		set_transient( "datamachine_{$provider_key}_pkce_verifier", $verifier, 15 * MINUTE_IN_SECONDS );

		do_action(
			'datamachine_log',
			'debug',
			'OAuth2: Created PKCE challenge',
			array(
				'provider' => $provider_key,
				'method'   => 'S256',
			)
		);

		return array(
			'verifier'  => $verifier,
			'challenge' => $challenge,
			'method'    => 'S256',
		);
	}

	/**
	 * Retrieve and consume the stored PKCE code_verifier.
	 *
	 * The verifier is deleted after retrieval (one-time use).
	 *
	 * @since 0.66.0
	 * @param string $provider_key Provider identifier.
	 * @return string|null Code verifier, or null if not found/expired.
	 */
	public function get_pkce_verifier( string $provider_key ): ?string {
		$verifier = get_transient( "datamachine_{$provider_key}_pkce_verifier" );

		if ( false === $verifier ) {
			return null;
		}

		delete_transient( "datamachine_{$provider_key}_pkce_verifier" );
		return $verifier;
	}

	/**
	 * Base64url-encode a string (RFC 4648 §5, no padding).
	 *
	 * @since 0.66.0
	 * @param string $data Raw binary data.
	 * @return string Base64url-encoded string.
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	// -------------------------------------------------------------------------
	// Authorization URL
	// -------------------------------------------------------------------------

	/**
	 * Build authorization URL with parameters.
	 *
	 * @param string $auth_url Base authorization URL.
	 * @param array  $params Query parameters for authorization.
	 * @return string Complete authorization URL.
	 */
	public function get_authorization_url( string $auth_url, array $params ): string {
		$url = add_query_arg( $params, $auth_url );

		do_action(
			'datamachine_log',
			'debug',
			'OAuth2: Built authorization URL',
			array(
				'auth_url'    => $auth_url,
				'param_count' => count( $params ),
			)
		);

		return $url;
	}

	// -------------------------------------------------------------------------
	// Authorization Code Flow (with optional PKCE)
	// -------------------------------------------------------------------------

	/**
	 * Handle OAuth2 authorization code callback flow.
	 *
	 * Verifies state, exchanges authorization code for access token, retrieves account details,
	 * stores account data, and redirects with success/error messages.
	 *
	 * When PKCE is enabled, the stored code_verifier is automatically included
	 * in the token exchange parameters.
	 *
	 * The recovered state payload is passed to the storage callback as the second
	 * argument, allowing providers to access caller-defined context that was
	 * propagated through the OAuth dance via create_state().
	 *
	 * @since 0.2.0
	 * @since 0.88.0 Storage callback now receives payload as second argument.
	 *
	 * @param string        $provider_key Provider identifier.
	 * @param string        $token_url Token exchange endpoint URL.
	 * @param array         $token_params Parameters for token exchange.
	 * @param callable      $account_details_fn Callback to retrieve account details from token data.
	 *                                          Signature: function(array $token_data): array|WP_Error
	 * @param callable|null $token_transform_fn Optional function to transform token data (for two-stage exchanges like Meta long-lived tokens).
	 *                                          Signature: function(array $token_data): array|WP_Error
	 * @param callable|null $storage_fn Optional callback to store account data.
	 *                                  Signature: function(array $account_data, array $state_payload): bool
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function handle_callback(
		string $provider_key,
		string $token_url,
		array $token_params,
		callable $account_details_fn,
		?callable $token_transform_fn = null,
		?callable $storage_fn = null
	) {
		// Sanitize input - nonce verification handled via OAuth state parameter
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		// Handle OAuth errors
		if ( $error ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: Provider returned error',
				array(
					'provider' => $provider_key,
					'error'    => $error,
				)
			);

			$this->redirect_with_error( $provider_key, 'denied' );
			return new \WP_Error( 'oauth_denied', __( 'OAuth authorization denied.', 'data-machine' ) );
		}

		// Verify state and recover payload.
		$state_payload = $this->verify_state( $provider_key, $state );

		if ( false === $state_payload ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: State verification failed',
				array(
					'provider' => $provider_key,
				)
			);

			$this->redirect_with_error( $provider_key, 'invalid_state' );
			return new \WP_Error( 'invalid_state', __( 'Invalid OAuth state.', 'data-machine' ) );
		}

		// Include PKCE code_verifier in token exchange if one was stored.
		$verifier = $this->get_pkce_verifier( $provider_key );
		if ( null !== $verifier ) {
			$token_params['code_verifier'] = $verifier;

			do_action(
				'datamachine_log',
				'debug',
				'OAuth2: Including PKCE code_verifier in token exchange',
				array( 'provider' => $provider_key )
			);
		}

		// Exchange authorization code for access token
		$token_data = $this->exchange_token( $token_url, $token_params );

		if ( is_wp_error( $token_data ) ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: Token exchange failed',
				array(
					'provider' => $provider_key,
					'error'    => $token_data->get_error_message(),
				)
			);

			$this->redirect_with_error( $provider_key, 'token_exchange_failed' );
			return $token_data;
		}

		// Optional two-stage token transformation (e.g., Meta long-lived token exchange)
		if ( $token_transform_fn ) {
			$token_data = call_user_func( $token_transform_fn, $token_data );

			if ( is_wp_error( $token_data ) ) {
				do_action(
					'datamachine_log',
					'error',
					'OAuth2: Token transformation failed',
					array(
						'provider' => $provider_key,
						'error'    => $token_data->get_error_message(),
					)
				);

				$this->redirect_with_error( $provider_key, 'token_transform_failed' );
				return $token_data;
			}
		}

		// Get account details using provider-specific callback
		$account_data = call_user_func( $account_details_fn, $token_data );

		if ( is_wp_error( $account_data ) ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: Failed to retrieve account details',
				array(
					'provider' => $provider_key,
					'error'    => $account_data->get_error_message(),
				)
			);

			$this->redirect_with_error( $provider_key, 'account_fetch_failed' );
			return $account_data;
		}

		// Store account data — pass recovered state payload as second argument
		// so providers can access caller-defined context without touching OAuth2Handler directly.
		$stored = false;
		if ( $storage_fn ) {
			$stored = call_user_func( $storage_fn, $account_data, $state_payload );
		} else {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: No storage callback provided',
				array(
					'provider' => $provider_key,
				)
			);
		}

		if ( ! $stored ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: Failed to store account data',
				array(
					'provider' => $provider_key,
				)
			);

			$this->redirect_with_error( $provider_key, 'storage_failed' );
			return new \WP_Error( 'storage_failed', __( 'Failed to store account data.', 'data-machine' ) );
		}

		do_action(
			'datamachine_log',
			'info',
			'OAuth2: Authentication successful',
			array(
				'provider'   => $provider_key,
				'account_id' => $account_data['id'] ?? 'unknown',
			)
		);

		// Redirect to success
		$this->redirect_with_success( $provider_key );
		return true;
	}

	// -------------------------------------------------------------------------
	// Implicit Flow
	// -------------------------------------------------------------------------

	/**
	 * Render a callback page for OAuth2 implicit flow.
	 *
	 * In the implicit flow, the provider redirects to the callback URL with
	 * the access_token in the URL fragment (#access_token=...&token_type=bearer).
	 * Fragments never reach the server, so this method renders a minimal HTML
	 * page with JavaScript that:
	 *
	 * 1. Extracts token data from window.location.hash
	 * 2. POSTs it to the same callback URL with a nonce for verification
	 * 3. The server-side handle_implicit_callback() processes the token
	 *
	 * Called by the provider's handle_oauth_callback() when the request has
	 * no query parameters (first hit from the redirect with only a fragment).
	 *
	 * @since 0.66.0
	 * @param string $provider_key Provider identifier.
	 * @param string $callback_url The callback URL to POST the token to.
	 * @return void Outputs HTML and exits.
	 */
	public function render_implicit_callback_page( string $provider_key, string $callback_url ): void {
		$nonce = wp_create_nonce( "datamachine_implicit_{$provider_key}" );

		// Minimal HTML page — extracts fragment params and POSTs to server.
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><title>Authenticating…</title></head><body>';
		echo '<p>Completing authentication…</p>';
		echo '<script>';
		echo 'var hash = window.location.hash.substring(1);';
		echo 'if (!hash) { document.body.innerHTML = "<p>Authentication failed: no token received.</p>"; }';
		echo 'else {';
		echo '  var params = new URLSearchParams(hash);';
		echo '  var token = params.get("access_token");';
		echo '  if (!token) { document.body.innerHTML = "<p>Authentication failed: no access token in response.</p>"; }';
		echo '  else {';
		echo '    var data = new URLSearchParams();';
		echo '    data.append("datamachine_implicit_flow", "1");';
		echo '    data.append("_wpnonce", ' . wp_json_encode( $nonce ) . ');';
		echo '    data.append("provider", ' . wp_json_encode( $provider_key ) . ');';
		// Forward all fragment params (access_token, token_type, expires_in, site_id, etc.)
		echo '    params.forEach(function(v, k) { data.append(k, v); });';
		echo '    fetch(' . wp_json_encode( $callback_url ) . ', {';
		echo '      method: "POST",';
		echo '      headers: { "Content-Type": "application/x-www-form-urlencoded" },';
		echo '      credentials: "same-origin",';
		echo '      body: data.toString()';
		echo '    }).then(function(r) { return r.json(); })';
		echo '    .then(function(result) {';
		echo '      if (result.success) {';
		echo '        if (window.opener) {';
		echo '          window.opener.postMessage({ type: "oauth_callback", success: true, account: result.data || {} }, window.location.origin);';
		echo '          window.close();';
		echo '        } else {';
		echo '          window.location.href = result.redirect || ' . wp_json_encode( admin_url( 'admin.php?page=datamachine-settings&auth_success=1&provider=' . $provider_key ) ) . ';';
		echo '        }';
		echo '      } else {';
		echo '        document.body.innerHTML = "<p>Authentication failed: " + (result.error || "unknown error") + "</p>";';
		echo '      }';
		echo '    }).catch(function(err) {';
		echo '      document.body.innerHTML = "<p>Authentication failed: " + err.message + "</p>";';
		echo '    });';
		echo '  }';
		echo '}';
		echo '</script></body></html>';
		exit;
	}

	/**
	 * Handle the server-side POST from the implicit flow callback page.
	 *
	 * Verifies the nonce, extracts token data from the POST body, calls
	 * the account details callback, stores the result, and returns a JSON
	 * response for the JS callback page to handle.
	 *
	 * @since 0.66.0
	 * @param string        $provider_key       Provider identifier.
	 * @param callable      $account_details_fn Callback to retrieve account details from token data.
	 *                                          Receives array with 'access_token', 'token_type',
	 *                                          'expires_in', and any other fragment params.
	 *                                          Signature: function(array $token_data): array|WP_Error
	 * @param callable|null $storage_fn         Optional callback to store account data.
	 *                                          Signature: function(array $account_data): bool
	 * @return void Outputs JSON and exits.
	 */
	public function handle_implicit_callback(
		string $provider_key,
		callable $account_details_fn,
		?callable $storage_fn = null
	): void {
		header( 'Content-Type: application/json; charset=utf-8' );

		// Verify nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified manually below.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, "datamachine_implicit_{$provider_key}" ) ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: Implicit flow nonce verification failed',
				array( 'provider' => $provider_key )
			);

			echo wp_json_encode( array(
				'success' => false,
				'error'   => 'Invalid nonce.',
			) );
			exit;
		}

		// Build token data from POST params.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$access_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';

		if ( empty( $access_token ) ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: Implicit flow missing access_token',
				array( 'provider' => $provider_key )
			);

			echo wp_json_encode( array(
				'success' => false,
				'error'   => 'No access token received.',
			) );
			exit;
		}

		// Collect all token-related POST params.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$token_data = array(
			'access_token' => $access_token,
			'token_type'   => isset( $_POST['token_type'] ) ? sanitize_text_field( wp_unslash( $_POST['token_type'] ) ) : 'bearer',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		if ( isset( $_POST['expires_in'] ) ) {
			$token_data['expires_in'] = absint( $_POST['expires_in'] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		if ( isset( $_POST['site_id'] ) ) {
			$token_data['site_id'] = sanitize_text_field( wp_unslash( $_POST['site_id'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		if ( isset( $_POST['scope'] ) ) {
			$token_data['scope'] = sanitize_text_field( wp_unslash( $_POST['scope'] ) );
		}

		do_action(
			'datamachine_log',
			'debug',
			'OAuth2: Implicit flow token received',
			array(
				'provider'   => $provider_key,
				'token_type' => $token_data['token_type'],
				'expires_in' => $token_data['expires_in'] ?? 'unknown',
			)
		);

		// Get account details using provider-specific callback.
		$account_data = call_user_func( $account_details_fn, $token_data );

		if ( is_wp_error( $account_data ) ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: Implicit flow account details failed',
				array(
					'provider' => $provider_key,
					'error'    => $account_data->get_error_message(),
				)
			);

			echo wp_json_encode( array(
				'success' => false,
				'error'   => $account_data->get_error_message(),
			) );
			exit;
		}

		// Store account data.
		$stored = false;
		if ( $storage_fn ) {
			$stored = call_user_func( $storage_fn, $account_data );
		}

		if ( ! $stored ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: Implicit flow storage failed',
				array( 'provider' => $provider_key )
			);

			echo wp_json_encode( array(
				'success' => false,
				'error'   => 'Failed to store account data.',
			) );
			exit;
		}

		do_action(
			'datamachine_log',
			'info',
			'OAuth2: Implicit flow authentication successful',
			array(
				'provider'   => $provider_key,
				'account_id' => $account_data['id'] ?? 'unknown',
			)
		);

		$redirect_url = add_query_arg(
			array(
				'page'         => 'datamachine-settings',
				'auth_success' => '1',
				'provider'     => $provider_key,
			),
			admin_url( 'admin.php' )
		);

		echo wp_json_encode( array(
			'success'  => true,
			'data'     => $account_data,
			'redirect' => $redirect_url,
		) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Token Exchange
	// -------------------------------------------------------------------------

	/**
	 * Exchange authorization code for access token.
	 *
	 * @param string $token_url Token exchange endpoint URL.
	 * @param array  $params Token exchange parameters.
	 * @return array|\WP_Error Token data on success, WP_Error on failure.
	 */
	private function exchange_token( string $token_url, array $params ) {
		// Extract custom headers if provided (e.g., Reddit requires Basic Auth)
		$custom_headers = array();
		if ( isset( $params['headers'] ) && is_array( $params['headers'] ) ) {
			$custom_headers = $params['headers'];
			unset( $params['headers'] );
		}

		// Merge default headers with custom headers (custom takes precedence)
		$headers = array_merge(
			array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			$custom_headers
		);

		$result = HttpClient::post(
			$token_url,
			array(
				'body'    => $params,
				'headers' => $headers,
				'context' => 'OAuth2 Token Exchange',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'http_error', $result['error'] );
		}

		$token_data = json_decode( $result['data'], true );

		if ( ! $token_data || ! isset( $token_data['access_token'] ) ) {
			return new \WP_Error(
				'invalid_token_response',
				__( 'Invalid token response.', 'data-machine' ),
				array( 'response' => $result['data'] )
			);
		}

		return $token_data;
	}

}
