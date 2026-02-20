<?php
/**
 * OAuth 1.0a Handler
 *
 * Centralized OAuth 1.0a flow implementation for Twitter and future OAuth1 providers.
 * Eliminates code duplication in three-legged OAuth flow with temporary token management.
 *
 * @package DataMachine
 * @subpackage Core\OAuth
 * @since 0.2.0
 */

namespace DataMachine\Core\OAuth;

use Abraham\TwitterOAuth\TwitterOAuth;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class OAuth1Handler {

	const TEMP_TOKEN_SECRET_PREFIX = 'datamachine_oauth1_temp_secret_';

	/**
	 * Get OAuth request token (step 1 of 3-legged OAuth).
	 *
	 * @param string $request_token_url Request token endpoint URL.
	 * @param string $consumer_key OAuth consumer key.
	 * @param string $consumer_secret OAuth consumer secret.
	 * @param string $callback_url OAuth callback URL.
	 * @param string $provider_key Provider identifier (for logging).
	 * @return array|\WP_Error Request token data or error.
	 */
	public function get_request_token(
		string $request_token_url,
		string $consumer_key,
		string $consumer_secret,
		string $callback_url,
		string $provider_key = 'oauth1'
	) {
		try {
			// Initialize OAuth connection without tokens
			$connection = new TwitterOAuth( $consumer_key, $consumer_secret );

			// Get request token
			$request_token = $connection->oauth( $request_token_url, array( 'oauth_callback' => $callback_url ) );

			// Check for API errors
			if ( $connection->getLastHttpCode() != 200 ||
				! isset( $request_token['oauth_token'] ) ||
				! isset( $request_token['oauth_token_secret'] ) ) {
				do_action(
					'datamachine_log',
					'error',
					'OAuth1: Failed to get request token',
					array(
						'provider'  => $provider_key,
						'http_code' => $connection->getLastHttpCode(),
						'response'  => $connection->getLastBody(),
					)
				);

				return new \WP_Error(
					'request_token_failed',
					__( 'Failed to get OAuth request token.', 'data-machine' ),
					array( 'http_code' => $connection->getLastHttpCode() )
				);
			}

			// Store temporary token secret for callback verification
			$this->store_temp_token_secret(
				$provider_key,
				$request_token['oauth_token'],
				$request_token['oauth_token_secret']
			);

			do_action(
				'datamachine_log',
				'debug',
				'OAuth1: Request token obtained',
				array(
					'provider'    => $provider_key,
					'oauth_token' => substr( $request_token['oauth_token'], 0, 10 ) . '...',
				)
			);

			return $request_token;
		} catch ( \Exception $e ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth1: Exception getting request token',
				array(
					'provider' => $provider_key,
					'error'    => $e->getMessage(),
				)
			);

				return new \WP_Error(
					'request_token_exception',
					sprintf(
						/* translators: %s: OAuth exception message */
						__( 'OAuth exception: %s', 'data-machine' ),
						$e->getMessage()
					)
				);
		}
	}

	/**
	 * Build authorization URL (step 2 of 3-legged OAuth).
	 *
	 * @param string $authorize_url Authorization endpoint URL.
	 * @param string $oauth_token Request token from step 1.
	 * @param string $provider_key Provider identifier (for logging).
	 * @return string Authorization URL.
	 */
	public function get_authorization_url(
		string $authorize_url,
		string $oauth_token,
		string $provider_key = 'oauth1'
	): string {
		$url = add_query_arg( array( 'oauth_token' => $oauth_token ), $authorize_url );

		do_action(
			'datamachine_log',
			'debug',
			'OAuth1: Built authorization URL',
			array(
				'provider'    => $provider_key,
				'oauth_token' => substr( $oauth_token, 0, 10 ) . '...',
			)
		);

		return $url;
	}

	/**
	 * Handle OAuth1 callback and exchange for access token (step 3 of 3-legged OAuth).
	 *
	 * @param string        $provider_key Provider identifier.
	 * @param string        $access_token_url Access token endpoint URL.
	 * @param string        $consumer_key OAuth consumer key.
	 * @param string        $consumer_secret OAuth consumer secret.
	 * @param callable      $account_details_fn Callback to build account data from access token response.
	 *                                          Signature: function(array $access_token_data): array
	 * @param callable|null $storage_fn Callback to store account data.
	 *                                  Signature: function(array $account_data): bool
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function handle_callback(
		string $provider_key,
		string $access_token_url,
		string $consumer_key,
		string $consumer_secret,
		callable $account_details_fn,
		?callable $storage_fn = null
	) {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'datamachine_oauth1_callback' ) ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth1: Callback nonce verification failed',
				array(
					'provider' => $provider_key,
				)
			);
			$this->redirect_with_error( $provider_key, 'invalid_nonce' );
			return new \WP_Error( 'invalid_nonce', __( 'Invalid OAuth callback nonce.', 'data-machine' ) );
		}

		// Sanitize input after nonce verification
		$denied         = isset( $_GET['denied'] ) ? sanitize_text_field( wp_unslash( $_GET['denied'] ) ) : '';
		$oauth_token    = isset( $_GET['oauth_token'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_token'] ) ) : '';
		$oauth_verifier = isset( $_GET['oauth_verifier'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_verifier'] ) ) : '';

		// Handle user denial
		if ( ! empty( $denied ) ) {
			$this->delete_temp_token_secret( $provider_key, $denied );

			do_action(
				'datamachine_log',
				'warning',
				'OAuth1: User denied access',
				array(
					'provider'     => $provider_key,
					'denied_token' => substr( $denied, 0, 10 ) . '...',
				)
			);

			$this->redirect_with_error( $provider_key, 'access_denied' );
			return new \WP_Error( 'access_denied', __( 'OAuth access denied.', 'data-machine' ) );
		}

		// Validate required parameters
		if ( empty( $oauth_token ) || empty( $oauth_verifier ) ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth1: Missing callback parameters',
				array(
					'provider'     => $provider_key,
					'has_token'    => ! empty( $oauth_token ),
					'has_verifier' => ! empty( $oauth_verifier ),
				)
			);

			$this->redirect_with_error( $provider_key, 'missing_parameters' );
			return new \WP_Error( 'missing_parameters', __( 'Missing OAuth callback parameters.', 'data-machine' ) );
		}

		// Retrieve and validate temporary secret
		$oauth_token_secret = $this->get_temp_token_secret( $provider_key, $oauth_token );

		if ( empty( $oauth_token_secret ) ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth1: Token secret missing or expired',
				array(
					'provider'    => $provider_key,
					'oauth_token' => substr( $oauth_token, 0, 10 ) . '...',
				)
			);

			$this->redirect_with_error( $provider_key, 'token_secret_expired' );
			return new \WP_Error( 'token_secret_expired', __( 'OAuth token secret expired.', 'data-machine' ) );
		}

		// Clean up temporary secret immediately
		$this->delete_temp_token_secret( $provider_key, $oauth_token );

		// Exchange for access token
		try {
			// Initialize with request token
			$connection = new TwitterOAuth( $consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret );

			// Exchange tokens
			$access_token_data = $connection->oauth( $access_token_url, array( 'oauth_verifier' => $oauth_verifier ) );

			// Validate token exchange
			if ( $connection->getLastHttpCode() != 200 ||
				! isset( $access_token_data['oauth_token'] ) ||
				! isset( $access_token_data['oauth_token_secret'] ) ) {
				do_action(
					'datamachine_log',
					'error',
					'OAuth1: Failed to get access token',
					array(
						'provider'  => $provider_key,
						'http_code' => $connection->getLastHttpCode(),
						'response'  => $connection->getLastBody(),
					)
				);

				$this->redirect_with_error( $provider_key, 'access_token_failed' );
				return new \WP_Error( 'access_token_failed', __( 'Failed to get access token.', 'data-machine' ) );
			}

			// Build account data using provider-specific callback
			$account_data = call_user_func( $account_details_fn, $access_token_data );

			// Store account data
			$stored = false;
			if ( $storage_fn ) {
				$stored = call_user_func( $storage_fn, $account_data );
			} else {
				do_action(
					'datamachine_log',
					'error',
					'OAuth1: No storage callback provided',
					array(
						'provider' => $provider_key,
					)
				);
			}

			if ( ! $stored ) {
				do_action(
					'datamachine_log',
					'error',
					'OAuth1: Failed to store account data',
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
				'OAuth1: Authentication successful',
				array(
					'provider' => $provider_key,
					'user_id'  => $account_data['user_id'] ?? 'unknown',
				)
			);

			// Redirect to success
			$this->redirect_with_success( $provider_key );
			return true;
		} catch ( \Exception $e ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth1: Exception during callback',
				array(
					'provider' => $provider_key,
					'error'    => $e->getMessage(),
				)
			);

			$this->redirect_with_error( $provider_key, 'callback_exception' );
			return new \WP_Error( 'callback_exception', __( 'OAuth callback exception.', 'data-machine' ) );
		}
	}

	/**
	 * Store temporary token secret in transient.
	 *
	 * @param string $provider_key Provider identifier.
	 * @param string $oauth_token Request token.
	 * @param string $oauth_token_secret Token secret to store.
	 * @return void
	 */
	private function store_temp_token_secret( string $provider_key, string $oauth_token, string $oauth_token_secret ): void {
		$transient_key = self::TEMP_TOKEN_SECRET_PREFIX . $provider_key . '_' . $oauth_token;
		set_transient( $transient_key, $oauth_token_secret, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Retrieve temporary token secret from transient.
	 *
	 * @param string $provider_key Provider identifier.
	 * @param string $oauth_token Request token.
	 * @return string|null Token secret or null if not found.
	 */
	private function get_temp_token_secret( string $provider_key, string $oauth_token ): ?string {
		$transient_key = self::TEMP_TOKEN_SECRET_PREFIX . $provider_key . '_' . $oauth_token;
		$secret        = get_transient( $transient_key );
		return $secret ? $secret : null;
	}

	/**
	 * Delete temporary token secret from transient.
	 *
	 * @param string $provider_key Provider identifier.
	 * @param string $oauth_token Request token.
	 * @return void
	 */
	private function delete_temp_token_secret( string $provider_key, string $oauth_token ): void {
		$transient_key = self::TEMP_TOKEN_SECRET_PREFIX . $provider_key . '_' . $oauth_token;
		delete_transient( $transient_key );
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
