<?php
/**
 * Handles Instagram OAuth 2.0 authentication for the Instagram publish handler.
 *
 * Uses OAuth2Handler for centralized OAuth flow with Instagram-specific two-stage token exchange.
 * Ported from post-to-instagram plugin to data-machine-socials.
 *
 * @package DataMachineSocials
 * @subpackage Handlers\Instagram
 * @since 0.2.0
 */

namespace DataMachineSocials\Handlers\Instagram;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InstagramAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	const AUTH_URL      = 'https://www.instagram.com/oauth/authorize';
	const TOKEN_URL     = 'https://api.instagram.com/oauth/access_token';
	const GRAPH_API_URL = 'https://graph.instagram.com';
	const SCOPES        = 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_messages,instagram_business_manage_comments';

	public function __construct() {
		parent::__construct( 'instagram' );
	}

	/**
	 * Get configuration fields required for Instagram authentication
	 *
	 * @return array Configuration field definitions
	 */
	public function get_config_fields(): array {
		return array(
			'app_id'     => array(
				'label'       => __( 'App ID', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Instagram application App ID from developers.facebook.com', 'data-machine-socials' ),
			),
			'app_secret' => array(
				'label'       => __( 'App Secret', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Instagram application App Secret from developers.facebook.com', 'data-machine-socials' ),
			),
		);
	}

	/**
	 * Check if Instagram authentication is properly configured
	 *
	 * @return bool True if OAuth credentials are configured
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['app_id'] ) && ! empty( $config['app_secret'] );
	}

	/**
	 * Perform Instagram-specific token refresh.
	 *
	 * Instagram long-lived tokens are refreshed via the ig_refresh_token grant.
	 * Delegates to BaseOAuth2Provider::get_valid_access_token() for on-demand
	 * refresh with 7-day buffer (inherited default).
	 *
	 * @since 0.3.0
	 * @param string $current_token The current access token to refresh.
	 * @return array|\WP_Error|null Token data on success, WP_Error on failure.
	 */
	protected function do_refresh_token( string $current_token ): array|\WP_Error|null {
		$url    = self::GRAPH_API_URL . '/refresh_access_token';
		$params = array(
			'grant_type'   => 'ig_refresh_token',
			'access_token' => $current_token,
		);

		$result = HttpClient::get( $url . '?' . http_build_query( $params ), array( 'context' => 'Instagram OAuth' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'instagram_refresh_http_error', $result['error'] );
		}

		$body      = $result['data'];
		$data      = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || empty( $data['access_token'] ) ) {
			$error_message = $data['error']['message'] ?? $data['error_description'] ?? 'Failed to refresh Instagram access token.';
			return new \WP_Error( 'instagram_refresh_api_error', $error_message, $data );
		}

		$expires_in = $data['expires_in'] ?? 3600 * 24 * 60;
		$expires_at = time() + intval( $expires_in );

		return array(
			'access_token' => $data['access_token'],
			'expires_at'   => $expires_at,
		);
	}

	/**
	 * Get stored Instagram User ID
	 *
	 * @return string|null User ID or null
	 */
	public function get_user_id(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['user_id'] ) ) {
			return null;
		}
		return $account['user_id'];
	}

	/**
	 * Get stored Instagram username
	 *
	 * @return string|null Username or null
	 */
	public function get_username(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['username'] ) ) {
			return null;
		}
		return $account['username'];
	}

	/**
	 * Get authorization URL for Instagram OAuth
	 *
	 * @return string Authorization URL
	 */
	public function get_authorization_url(): string {
		$state = $this->oauth2->create_state( 'instagram' );

		$config = $this->get_config();
		$params = array(
			'client_id'            => $config['app_id'] ?? '',
			'redirect_uri'         => $this->get_callback_url(),
			'scope'                => self::SCOPES,
			'response_type'        => 'code',
			'state'                => $state,
			'enable_fb_login'      => '0',
			'force_authentication' => '1',
		);

		return $this->oauth2->get_authorization_url( self::AUTH_URL, $params );
	}

	/**
	 * Handle OAuth callback from Instagram
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$config = $this->get_config();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		$this->oauth2->handle_callback(
			'instagram',
			self::TOKEN_URL,
			array(
				'client_id'     => $config['app_id'] ?? '',
				'client_secret' => $config['app_secret'] ?? '',
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $this->get_callback_url(),
				'code'          => $code,
			),
			function ( $short_lived_token_data ) use ( $config ) {
				// Instagram: Exchange short-lived for long-lived token
				return $this->exchange_for_long_lived_token(
					$short_lived_token_data['access_token'],
					$short_lived_token_data['user_id'],
					$config
				);
			},
			null, // No token transform needed after exchange
			function ( $account_data ) {
				$this->save_account( $account_data );
				$this->schedule_proactive_refresh();
			}
		);
	}

	/**
	 * Exchange short-lived token for long-lived token (Instagram-specific)
	 *
	 * @param string $short_lived_token Short-lived access token
	 * @param string $user_id Instagram user ID
	 * @param array $config OAuth configuration
	 * @return array|\WP_Error Token data ['access_token' => ..., 'user_id' => ..., 'expires_at' => ..., 'username' => ...] or error
	 */
	private function exchange_for_long_lived_token( string $short_lived_token, string $user_id, array $config ): array|\WP_Error {
		do_action( 'datamachine_log', 'debug', 'Instagram OAuth: Exchanging short-lived token for long-lived token' );

		$params = array(
			'grant_type'    => 'ig_exchange_token',
			'client_secret' => $config['app_secret'] ?? '',
			'access_token'  => $short_lived_token,
		);
		$url    = self::GRAPH_API_URL . '/access_token?' . http_build_query( $params );

		$result = HttpClient::get( $url, array( 'context' => 'Instagram OAuth' ) );

		if ( ! $result['success'] ) {
			do_action( 'datamachine_log', 'error', 'Instagram OAuth Error: Long-lived token exchange request failed', array( 'error' => $result['error'] ) );
			return new \WP_Error( 'instagram_oauth_exchange_request_failed', __( 'HTTP error during long-lived token exchange with Instagram.', 'data-machine-socials' ), $result['error'] );
		}

		$body      = $result['data'];
		$data      = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || empty( $data['access_token'] ) ) {
			$error_message = $data['error']['message'] ?? $data['error_description'] ?? 'Failed to retrieve long-lived access token from Instagram.';
			do_action(
				'datamachine_log',
				'error',
				'Instagram OAuth Error: Long-lived token exchange failed',
				array(
					'http_code' => $http_code,
					'response'  => $body,
				)
			);
			return new \WP_Error( 'instagram_oauth_exchange_failed', $error_message, $data );
		}

		$long_lived_token = $data['access_token'];
		$expires_in       = $data['expires_in'] ?? 3600 * 24 * 60;
		$expires_at       = time() + intval( $expires_in );

		// Fetch username
		$username = $this->get_username_from_token( $long_lived_token, $user_id );
		if ( is_wp_error( $username ) ) {
			$username = '';
		}

		do_action( 'datamachine_log', 'debug', 'Instagram OAuth: Successfully exchanged for long-lived token', array( 'user_id' => $user_id ) );

		return array(
			'access_token'     => $long_lived_token,
			'user_id'          => $user_id,
			'username'         => $username,
			'token_expires_at' => $expires_at,
		);
	}

	/**
	 * Get username from Instagram Graph API
	 *
	 * @param string $access_token Access token
	 * @param string $user_id User ID
	 * @return string|\WP_Error Username or error
	 */
	private function get_username_from_token( string $access_token, string $user_id ): string|\WP_Error {
		$url = self::GRAPH_API_URL . "/{$user_id}?fields=username&access_token={$access_token}";

		$result = HttpClient::get(
			$url,
			array(
				'context' => 'Instagram Authentication',
			)
		);

		if ( ! $result['success'] ) {
			do_action( 'datamachine_log', 'error', 'Instagram OAuth Error: Username fetch request failed', array( 'error' => $result['error'] ) );
			return new \WP_Error( 'instagram_username_fetch_failed', $result['error'] );
		}

		$body      = $result['data'];
		$data      = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Failed to fetch Instagram username.';
			do_action(
				'datamachine_log',
				'error',
				'Instagram OAuth Error: Username fetch failed',
				array(
					'http_code' => $http_code,
					'response'  => $body,
				)
			);
			return new \WP_Error( 'instagram_username_fetch_failed', $error_message, $data );
		}

		return $data['username'] ?? '';
	}

	/**
	 * Get stored Instagram account details
	 *
	 * @return array|null Account details or null
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) ) {
			return null;
		}
		return $account;
	}

	/**
	 * Remove stored Instagram account details
	 *
	 * @return bool Success status
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
