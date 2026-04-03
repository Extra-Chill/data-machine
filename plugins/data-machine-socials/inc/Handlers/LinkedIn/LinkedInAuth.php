<?php
/**
 * LinkedIn OAuth 2.0 authentication handler.
 *
 * Uses OAuth2Handler for centralized OAuth flow with LinkedIn's standard
 * authorization code flow. Supports token refresh via refresh_token grant.
 *
 * @package DataMachineSocials
 * @subpackage Handlers\LinkedIn
 * @since 0.5.0
 */

namespace DataMachineSocials\Handlers\LinkedIn;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LinkedInAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	public const AUTH_URL     = 'https://www.linkedin.com/oauth/v2/authorization';
	public const TOKEN_URL    = 'https://www.linkedin.com/oauth/v2/accessToken';
	public const API_BASE     = 'https://api.linkedin.com';
	public const API_VERSION  = '202603';
	public const SCOPES       = 'openid profile email w_member_social';
	public const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';

	public function __construct() {
		parent::__construct( 'linkedin' );
	}

	/**
	 * Get configuration fields required for LinkedIn authentication.
	 *
	 * @return array Configuration field definitions.
	 */
	public function get_config_fields(): array {
		return array(
			'client_id'     => array(
				'label'       => __( 'Client ID', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your LinkedIn application Client ID from linkedin.com/developers', 'data-machine-socials' ),
			),
			'client_secret' => array(
				'label'       => __( 'Client Secret', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your LinkedIn application Client Secret from linkedin.com/developers', 'data-machine-socials' ),
			),
		);
	}

	/**
	 * Check if LinkedIn authentication is properly configured.
	 *
	 * @return bool True if OAuth credentials are configured.
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['client_id'] ) && ! empty( $config['client_secret'] );
	}

	/**
	 * Perform LinkedIn-specific token refresh.
	 *
	 * LinkedIn supports standard refresh_token grant for programmatic refresh.
	 * Access tokens last 60 days; refresh tokens have a longer lifespan.
	 *
	 * @since 0.5.0
	 * @param string $current_token The current access token to refresh.
	 * @return array|\WP_Error|null Token data on success, WP_Error on failure.
	 */
	protected function do_refresh_token( string $current_token ): array|\WP_Error|null {
		$account = $this->get_account();
		if ( empty( $account['refresh_token'] ) ) {
			// No refresh token available — cannot refresh programmatically.
			return null;
		}

		$config = $this->get_config();
		if ( empty( $config['client_id'] ) || empty( $config['client_secret'] ) ) {
			return new \WP_Error( 'linkedin_refresh_missing_config', 'LinkedIn OAuth configuration is incomplete.' );
		}

		$result = HttpClient::post(
			self::TOKEN_URL,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $account['refresh_token'],
					'client_id'     => $config['client_id'],
					'client_secret' => $config['client_secret'],
				),
				'context' => 'LinkedIn OAuth Token Refresh',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'linkedin_refresh_http_error', $result['error'] );
		}

		$data = json_decode( $result['data'], true );

		if ( empty( $data['access_token'] ) ) {
			$error_message = $data['error_description'] ?? $data['error'] ?? 'Failed to refresh LinkedIn access token.';
			return new \WP_Error( 'linkedin_refresh_api_error', $error_message, $data );
		}

		$expires_in = $data['expires_in'] ?? 5184000; // Default 60 days.
		$expires_at = time() + intval( $expires_in );

		// Update refresh token if a new one was issued.
		if ( ! empty( $data['refresh_token'] ) ) {
			$account['refresh_token']            = $data['refresh_token'];
			$account['refresh_token_expires_at'] = ! empty( $data['refresh_token_expires_in'] )
				? time() + intval( $data['refresh_token_expires_in'] )
				: $account['refresh_token_expires_at'] ?? null;
		}

		$account['access_token']      = $data['access_token'];
		$account['token_expires_at']  = $expires_at;
		$account['last_refreshed_at'] = time();
		$this->save_account( $account );

		return array(
			'access_token' => $data['access_token'],
			'expires_at'   => $expires_at,
		);
	}

	/**
	 * Get authorization URL for LinkedIn OAuth.
	 *
	 * @return string Authorization URL.
	 */
	public function get_authorization_url(): string {
		$state = $this->oauth2->create_state( 'linkedin' );

		$config = $this->get_config();
		$params = array(
			'response_type' => 'code',
			'client_id'     => $config['client_id'] ?? '',
			'redirect_uri'  => $this->get_callback_url(),
			'scope'         => self::SCOPES,
			'state'         => $state,
		);

		return $this->oauth2->get_authorization_url( self::AUTH_URL, $params );
	}

	/**
	 * Handle OAuth callback from LinkedIn.
	 */
	public function handle_oauth_callback() {
		$config = $this->get_config();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$linkedin_code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		$this->oauth2->handle_callback(
			'linkedin',
			self::TOKEN_URL,
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $linkedin_code,
				'client_id'     => $config['client_id'] ?? '',
				'client_secret' => $config['client_secret'] ?? '',
				'redirect_uri'  => $this->get_callback_url(),
			),
			function ( $token_data ) {
				$access_token = $token_data['access_token'];
				$expires_at   = $token_data['expires_at'] ?? ( time() + 5184000 );

				// Fetch user profile via OpenID Connect userinfo endpoint.
				$profile = $this->get_user_profile( $access_token );
				if ( is_wp_error( $profile ) ) {
					return $profile;
				}

				$account_data = array(
					'access_token'     => $access_token,
					'token_type'       => 'bearer',
					'token_expires_at' => $expires_at,
					'person_id'        => $profile['sub'] ?? '',
					'username'         => $profile['name'] ?? null,
					'email'            => $profile['email'] ?? '',
					'picture'          => $profile['picture'] ?? '',
					'authenticated_at' => time(),
				);

				// Store refresh token if provided.
				if ( ! empty( $token_data['refresh_token'] ) ) {
					$account_data['refresh_token'] = $token_data['refresh_token'];
					if ( ! empty( $token_data['refresh_token_expires_in'] ) ) {
						$account_data['refresh_token_expires_at'] = time() + intval( $token_data['refresh_token_expires_in'] );
					}
				}

				return $account_data;
			},
			null, // No two-stage token exchange needed for LinkedIn.
			function ( $account_data ) {
				$this->save_account( $account_data );
				$this->schedule_proactive_refresh();
			}
		);
	}

	/**
	 * Get user profile from LinkedIn OpenID Connect userinfo endpoint.
	 *
	 * @param string $access_token Access token.
	 * @return array|\WP_Error Profile data or error.
	 */
	private function get_user_profile( string $access_token ): array|\WP_Error {
		$result = HttpClient::get(
			self::USERINFO_URL,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'LinkedIn Authentication',
			)
		);

		if ( ! $result['success'] ) {
			do_action(
				'datamachine_log',
				'error',
				'LinkedIn OAuth Error: Profile fetch failed',
				array( 'error' => $result['error'] )
			);
			return new \WP_Error( 'linkedin_profile_fetch_failed', $result['error'] );
		}

		$data = json_decode( $result['data'], true );

		if ( empty( $data['sub'] ) ) {
			do_action(
				'datamachine_log',
				'error',
				'LinkedIn OAuth Error: Profile response missing sub (person ID)',
				array( 'response' => $result['data'] )
			);
			return new \WP_Error(
				'linkedin_profile_id_missing',
				__( 'Profile ID missing in response from LinkedIn.', 'data-machine-socials' )
			);
		}

		do_action( 'datamachine_log', 'debug', 'LinkedIn OAuth: Profile fetched successfully', array( 'sub' => $data['sub'] ) );
		return $data;
	}

	/**
	 * Get the authenticated member's person URN.
	 *
	 * @return string|null Person URN (e.g., urn:li:person:abc123) or null.
	 */
	public function get_person_urn(): ?string {
		$account = $this->get_account();
		if ( empty( $account['person_id'] ) ) {
			return null;
		}
		return 'urn:li:person:' . $account['person_id'];
	}

	/**
	 * Make an authenticated LinkedIn API request with required headers.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Full API URL.
	 * @param array  $options Additional options for HttpClient.
	 * @return array HttpClient response.
	 */
	public function api_request( string $method, string $url, array $options = array() ): array {
		$access_token = $this->get_valid_access_token();
		if ( null === $access_token ) {
			return array(
				'success' => false,
				'error'   => 'LinkedIn access token is not available or expired.',
			);
		}

		$default_headers = array(
			'Authorization'              => 'Bearer ' . $access_token,
			'Linkedin-Version'           => self::API_VERSION,
			'X-Restli-Protocol-Version'  => '2.0.0',
			'Content-Type'               => 'application/json',
		);

		$options['headers'] = array_merge( $default_headers, $options['headers'] ?? array() );
		$options['context'] = $options['context'] ?? 'LinkedIn API';

		return HttpClient::request( $method, $url, $options );
	}

	/**
	 * Get stored LinkedIn account details.
	 *
	 * @return array|null Account details or null.
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) ) {
			return null;
		}
		return $account;
	}

	/**
	 * Remove stored LinkedIn account details.
	 *
	 * @return bool Success status.
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
