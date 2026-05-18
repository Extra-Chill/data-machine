<?php
/**
 * Handles Google OAuth 2.0 authentication.
 *
 * One Google credential shared across every Google handler in this plugin
 * (Sheets, Drive, future Calendar, etc.). Each handler family declares its
 * required scopes via the `datamachine_google_oauth_scopes` filter and the
 * provider unions them at consent time.
 *
 * Uses the centralized OAuth2Handler for the standard authorization-code
 * flow and keeps the Google-specific bits (offline access type, forced
 * consent prompt, refresh-token grant) here.
 *
 * @package DataMachineBusiness
 * @subpackage OAuth\Providers
 * @since 0.1.0
 */

namespace DataMachineBusiness\OAuth\Providers;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	/**
	 * Default base scope. Handler families add their own scopes via the
	 * `datamachine_google_oauth_scopes` filter and get_scopes() unions them.
	 *
	 * Defaulting to the Sheets scope keeps the legacy single-handler install
	 * working unchanged when no Drive/other handlers are loaded.
	 */
	const SCOPES = 'https://www.googleapis.com/auth/spreadsheets';

	public function __construct() {
		parent::__construct( 'google' );
	}

	/**
	 * Get the full space-separated scope string for the authorization request.
	 *
	 * Unions the base scope with anything contributed by sibling Google
	 * handlers via the `datamachine_google_oauth_scopes` filter. Handlers
	 * should add scopes like:
	 *
	 *     add_filter( 'datamachine_google_oauth_scopes', function( $scopes ) {
	 *         $scopes[] = 'https://www.googleapis.com/auth/drive.readonly';
	 *         return $scopes;
	 *     } );
	 *
	 * Existing tokens issued before a new scope was added will not have
	 * that scope. Callers must check granted scopes (account['scope']) and
	 * surface a re-consent error when a required scope is missing — do not
	 * silently fall back.
	 *
	 * @since 0.3.0
	 * @return string Space-separated scope string.
	 */
	public function get_scopes(): string {
		$scopes = array_filter( array_map( 'trim', explode( ' ', self::SCOPES ) ) );

		/**
		 * Filters the OAuth scopes requested for the unified Google provider.
		 *
		 * Sibling Google handlers (Sheets, Drive, future Calendar, etc.) add
		 * their required scopes here. The provider unions and de-duplicates
		 * them at consent time.
		 *
		 * @since 0.3.0
		 * @param string[] $scopes Array of scope URLs.
		 */
		$scopes = apply_filters( 'datamachine_google_oauth_scopes', $scopes );

		// Normalize: trim, drop empties, de-duplicate, preserve order.
		$normalized = array();
		foreach ( (array) $scopes as $scope ) {
			$scope = is_string( $scope ) ? trim( $scope ) : '';
			if ( '' === $scope ) {
				continue;
			}
			if ( ! in_array( $scope, $normalized, true ) ) {
				$normalized[] = $scope;
			}
		}

		return implode( ' ', $normalized );
	}

	/**
	 * Check whether the granted token covers a specific scope.
	 *
	 * Use this from handlers before making a scoped API call so you can
	 * surface a clear re-consent error instead of letting the upstream
	 * API return a vague 403.
	 *
	 * @since 0.3.0
	 * @param string $required_scope Scope URL to check for.
	 * @return bool True when the granted scope string includes the scope.
	 */
	public function has_scope( string $required_scope ): bool {
		$account = $this->get_account();
		if ( empty( $account['scope'] ) || ! is_string( $account['scope'] ) ) {
			return false;
		}

		$granted = array_filter( array_map( 'trim', explode( ' ', $account['scope'] ) ) );
		return in_array( $required_scope, $granted, true );
	}

	/**
	 * Check if admin has valid Google authentication.
	 *
	 * @return bool True if authenticated
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		return ! empty( $account ) &&
			is_array( $account ) &&
			! empty( $account['access_token'] ) &&
			! empty( $account['refresh_token'] );
	}

	/**
	 * Get configuration fields required for Google OAuth.
	 *
	 * @return array Configuration field definitions
	 */
	public function get_config_fields(): array {
		return array(
			'client_id' => array(
				'label' => __( 'Client ID', 'data-machine-business' ),
				'type' => 'text',
				'required' => true,
				'description' => __( 'Your Google application Client ID from console.cloud.google.com', 'data-machine-business' ),
			),
			'client_secret' => array(
				'label' => __( 'Client Secret', 'data-machine-business' ),
				'type' => 'text',
				'required' => true,
				'description' => __( 'Your Google application Client Secret from console.cloud.google.com', 'data-machine-business' ),
			),
		);
	}

	/**
	 * Check if Google OAuth is properly configured.
	 *
	 * @return bool True if OAuth credentials are configured
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['client_id'] ) && ! empty( $config['client_secret'] );
	}

	/**
	 * Get a valid Google access token, refreshing if expired.
	 *
	 * @return string|\WP_Error Access token or error
	 */
	public function get_service() {
		do_action( 'datamachine_log', 'debug', 'Attempting to get authenticated Google access token.', array() );

		$credentials = $this->get_account();
		if ( empty( $credentials ) || empty( $credentials['access_token'] ) || empty( $credentials['refresh_token'] ) ) {
			do_action( 'datamachine_log', 'error', 'Missing Google credentials in options.', array() );
			return new \WP_Error( 'google_missing_credentials', __( 'Google credentials not found. Please authenticate.', 'data-machine-business' ) );
		}

		$access_token = $credentials['access_token'];
		$refresh_token = $credentials['refresh_token'];

		// Check if access token needs refreshing.
		$expires_at = $credentials['expires_at'] ?? 0;
		if ( time() >= $expires_at - 300 ) { // Refresh 5 minutes before expiry
			do_action( 'datamachine_log', 'debug', 'Google access token expired, attempting refresh.', array() );

			$refreshed_token = $this->refresh_access_token( $refresh_token );
			if ( is_wp_error( $refreshed_token ) ) {
				return $refreshed_token;
			}

			return $refreshed_token;
		}

		do_action( 'datamachine_log', 'debug', 'Successfully retrieved valid Google access token.', array() );
		return $access_token;
	}

	/**
	 * Refresh expired access token via the Google refresh_token grant.
	 *
	 * @param string $refresh_token Refresh token
	 * @return string|\WP_Error New access token or error
	 */
	private function refresh_access_token( string $refresh_token ) {
		$config = $this->get_config();
		$client_id = $config['client_id'] ?? '';
		$client_secret = $config['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			do_action( 'datamachine_log', 'error', 'Missing Google OAuth client credentials.', array() );
			return new \WP_Error( 'google_missing_oauth_config', __( 'Google OAuth configuration is incomplete.', 'data-machine-business' ) );
		}

		$result = HttpClient::post(
			'https://oauth2.googleapis.com/token',
			array(
				'body' => array(
					'client_id' => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type' => 'refresh_token',
				),
				'context' => 'Google OAuth',
			)
		);

		if ( ! $result['success'] ) {
			do_action(
				'datamachine_log',
				'error',
				'Google token refresh request failed.',
				array(
					'error' => $result['error'],
				)
			);
			return new \WP_Error( 'google_refresh_failed', __( 'Failed to refresh Google access token.', 'data-machine-business' ) );
		}

		$response_code = $result['status_code'];
		$response_body = $result['data'];

		if ( 200 !== $response_code ) {
			do_action(
				'datamachine_log',
				'error',
				'Google token refresh failed.',
				array(
					'response_code' => $response_code,
					'response_body' => $response_body,
				)
			);
			return new \WP_Error( 'google_refresh_error', __( 'Google token refresh failed. Please re-authenticate.', 'data-machine-business' ) );
		}

		$token_data = json_decode( $response_body, true );
		if ( empty( $token_data['access_token'] ) ) {
			do_action( 'datamachine_log', 'error', 'Invalid token refresh response from Google.', array() );
			return new \WP_Error( 'google_invalid_refresh_response', __( 'Invalid response from Google during token refresh.', 'data-machine-business' ) );
		}

		// Update stored credentials
		$this->update_credentials( $token_data['access_token'], $refresh_token, $token_data['expires_in'] ?? 3600 );

		do_action( 'datamachine_log', 'debug', 'Successfully refreshed Google access token.', array() );
		return $token_data['access_token'];
	}

	/**
	 * Update credentials with new tokens.
	 *
	 * @param string $access_token New access token
	 * @param string $refresh_token Refresh token
	 * @param int $expires_in Token expiry time in seconds
	 */
	private function update_credentials( string $access_token, string $refresh_token, int $expires_in ) {
		$account_data = array(
			'access_token' => $access_token,
			'refresh_token' => $refresh_token,
			'expires_at' => time() + $expires_in,
			'last_refreshed_at' => time(),
		);

		$this->save_account( $account_data );
	}

	/**
	 * Get authorization URL for Google OAuth.
	 *
	 * @return string Authorization URL
	 */
	public function get_authorization_url(): string {
		$config = $this->get_config();
		$client_id = $config['client_id'] ?? '';

		if ( empty( $client_id ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Google OAuth Error: Client ID not configured.',
				array(
					'provider' => 'google',
					'operation' => 'get_authorization_url',
				)
			);
			return '';
		}

		// Create state via OAuth2Handler
		$state = $this->oauth2->create_state( 'google' );

		// Build authorization URL with Google-specific parameters
		$params = array(
			'client_id' => $client_id,
			'redirect_uri' => $this->get_callback_url(),
			'scope' => $this->get_scopes(),
			'response_type' => 'code',
			'access_type' => 'offline', // Google-specific: request refresh token
			'prompt' => 'consent', // Google-specific: force consent to ensure refresh token
			'state' => $state,
		);

		return $this->oauth2->get_authorization_url( 'https://accounts.google.com/o/oauth2/v2/auth', $params );
	}

	/**
	 * Handle OAuth callback from Google
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		// Get configuration
		$config = $this->get_config();
		$client_id = $config['client_id'] ?? '';
		$client_secret = $config['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			do_action( 'datamachine_log', 'error', 'Google OAuth Error: Missing configuration', array() );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'datamachine-settings',
						'auth_error' => 'missing_config',
						'provider' => 'google',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Prepare token exchange parameters (Google-specific)
		$token_params = array(
			'client_id' => $client_id,
			'client_secret' => $client_secret,
			'code' => $code,
			'grant_type' => 'authorization_code',
			'redirect_uri' => $this->get_callback_url(),
		);

		// Use OAuth2Handler for token exchange and callback handling
		$this->oauth2->handle_callback(
			'google',
			'https://oauth2.googleapis.com/token',
			$token_params,
			function ( $token_data ) {
				return array(
					'access_token' => $token_data['access_token'],
					'refresh_token' => $token_data['refresh_token'] ?? null,
					'expires_at' => time() + ( $token_data['expires_in'] ?? 3600 ),
					'scope' => $token_data['scope'] ?? $this->get_scopes(),
					'last_verified_at' => time(),
				);
			},
			null,
			array( $this, 'save_account' )
		);
	}

	/**
	 * Get stored Google account details.
	 *
	 * @return array|null Account details or null
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) || empty( $account['refresh_token'] ) ) {
			return null;
		}
		return $account;
	}

	/**
	 * Remove stored Google account details.
	 *
	 * @return bool Success status
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
