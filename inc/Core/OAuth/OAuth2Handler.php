<?php
/**
 * OAuth 2.0 Handler
 *
 * Centralized OAuth 2.0 flow implementation for all OAuth2 providers.
 * Eliminates code duplication across Reddit, Facebook, Threads, and Google Sheets handlers.
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

	/**
	 * Handle OAuth2 callback flow.
	 *
	 * Verifies state, exchanges authorization code for access token, retrieves account details,
	 * stores account data, and redirects with success/error messages.
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
