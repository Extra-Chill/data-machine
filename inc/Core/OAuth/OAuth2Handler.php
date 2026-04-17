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

	// -------------------------------------------------------------------------
	// State Management
	// -------------------------------------------------------------------------

	/**
	 * Create OAuth state nonce and store in transient.
	 *
	 * @param string $provider_key Provider identifier (e.g., 'reddit', 'facebook').
	 * @return string Generated state value.
	 */
	public function create_state( string $provider_key ): string {
		$state = bin2hex( random_bytes( 32 ) );
		set_transient( "datamachine_{$provider_key}_oauth_state", $state, 15 * MINUTE_IN_SECONDS );

		do_action(
			'datamachine_log',
			'debug',
			'OAuth2: Created state nonce',
			array(
				'provider'     => $provider_key,
				'state_length' => strlen( $state ),
			)
		);

		return $state;
	}

	/**
	 * Verify OAuth state nonce.
	 *
	 * @param string $provider_key Provider identifier.
	 * @param string $state State value to verify.
	 * @return bool True if state is valid.
	 */
	public function verify_state( string $provider_key, string $state ): bool {
		$stored_state = get_transient( "datamachine_{$provider_key}_oauth_state" );
		$is_valid     = ! empty( $state ) && false !== $stored_state && hash_equals( $stored_state, $state );

		if ( $is_valid ) {
			delete_transient( "datamachine_{$provider_key}_oauth_state" );
		}

		do_action(
			'datamachine_log',
			$is_valid ? 'debug' : 'error',
			'OAuth2: State verification',
			array(
				'provider' => $provider_key,
				'valid'    => $is_valid,
			)
		);

		return $is_valid;
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
	 * @param string        $provider_key Provider identifier.
	 * @param string        $token_url Token exchange endpoint URL.
	 * @param array         $token_params Parameters for token exchange.
	 * @param callable      $account_details_fn Callback to retrieve account details from token data.
	 *                                          Signature: function(array $token_data): array|WP_Error
	 * @param callable|null $token_transform_fn Optional function to transform token data (for two-stage exchanges like Meta long-lived tokens).
	 *                                          Signature: function(array $token_data): array|WP_Error
	 * @param callable|null $storage_fn Optional callback to store account data.
	 *                                  Signature: function(array $account_data): bool
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

		// Verify state
		if ( ! $this->verify_state( $provider_key, $state ) ) {
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

		// Store account data
		$stored = false;
		if ( $storage_fn ) {
			$stored = call_user_func( $storage_fn, $account_data );
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

	// -------------------------------------------------------------------------
	// Redirects
	// -------------------------------------------------------------------------

	/**
	 * Redirect to admin with error message.
	 *
	 * @param string $provider_key Provider identifier.
	 * @param string $error_code Error code.
	 * @return void
	 */
	private function redirect_with_error( string $provider_key, string $error_code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'datamachine-settings',
					'auth_error' => $error_code,
					'provider'   => $provider_key,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect to admin with success message.
	 *
	 * @param string $provider_key Provider identifier.
	 * @return void
	 */
	private function redirect_with_success( string $provider_key ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'datamachine-settings',
					'auth_success' => '1',
					'provider'     => $provider_key,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
