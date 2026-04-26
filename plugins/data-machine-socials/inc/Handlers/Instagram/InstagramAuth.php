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

	const AUTH_URL      = 'https://www.facebook.com/v18.0/dialog/oauth';
	const TOKEN_URL     = 'https://graph.facebook.com/v18.0/oauth/access_token';
	const GRAPH_API_URL = 'https://graph.instagram.com';
	const FB_API_URL    = 'https://graph.facebook.com/v18.0';
	const SCOPES        = 'instagram_basic,instagram_content_publish,instagram_manage_messages,instagram_manage_comments,pages_read_engagement';

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

	// get_username() is inherited from BaseAuthProvider.
	// Instagram already stores username under the canonical 'username' key.

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
				$access_token = $short_lived_token_data['access_token'];

				// Facebook Login doesn't return user_id in token response.
				// Fetch Instagram Business Account ID from Facebook Graph API.
				// resolve_instagram_account_from_facebook_token() returns both id and
				// username in one call, so we capture both up front and avoid a second
				// round trip below.
				$resolved_username = '';
				$user_id           = $short_lived_token_data['user_id'] ?? null;
				if ( empty( $user_id ) ) {
					$resolved = $this->resolve_instagram_account_from_facebook_token( $access_token );
					if ( is_wp_error( $resolved ) ) {
						return new \WP_Error( 'instagram_missing_user_id', $resolved->get_error_message() );
					}
					$user_id           = $resolved['id'];
					$resolved_username = $resolved['username'] ?? '';
				}

				if ( empty( $user_id ) ) {
					return new \WP_Error( 'instagram_missing_user_id', 'Could not determine Instagram user ID.' );
				}

				// Try Facebook token extension (fb_exchange_token) for Facebook Login tokens.
				// Falls back to Instagram ig_exchange_token for backward compatibility.
				$long_lived = $this->exchange_for_long_lived_token_fb( $access_token, $config );
				if ( is_wp_error( $long_lived ) ) {
					// Fallback: try Instagram endpoint (legacy Basic Display tokens).
					$long_lived = $this->exchange_for_long_lived_token( $access_token, $user_id, $config );
				}

				if ( is_wp_error( $long_lived ) ) {
					return $long_lived;
				}

				$long_lived['user_id'] = $user_id;

				// Username preference: use the value already resolved from /me/accounts
				// when present (Facebook Login path). Only hit the username-only endpoint
				// as a fallback for legacy Instagram Basic Display tokens.
				$username = $resolved_username;
				if ( '' === $username ) {
					$looked_up = $this->get_username_from_token( $long_lived['access_token'], $user_id );
					if ( is_wp_error( $looked_up ) ) {
						do_action(
							'datamachine_log',
							'warning',
							'Instagram OAuth: username lookup failed, storing empty value. ' . $looked_up->get_error_message(),
							array( 'user_id' => $user_id )
						);
						$username = '';
					} else {
						$username = $looked_up;
					}
				}
				$long_lived['username'] = $username;

				return $long_lived;
			},
			null, // No token transform needed after exchange
			function ( $account_data ) {
				$saved = $this->save_account( $account_data );
				$this->schedule_proactive_refresh();
				return $saved;
			}
		);
	}

	/**
	 * Resolve the Instagram Business Account from a Facebook User Access Token.
	 *
	 * Queries the user's Facebook Pages and extracts the first connected
	 * Instagram Business Account, returning both id and username so callers
	 * can avoid a separate username lookup.
	 *
	 * @param string $access_token Facebook User Access Token.
	 * @return array{id: string, username: string}|\WP_Error Account data or error.
	 */
	private function resolve_instagram_account_from_facebook_token( string $access_token ): array|\WP_Error {
		$url = self::FB_API_URL . '/me/accounts?fields=instagram_business_account{id,username}&access_token=' . $access_token;

		$result = HttpClient::get( $url, array( 'context' => 'Instagram OAuth - Resolve IG Account' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'instagram_fb_resolve_failed', 'Failed to resolve Instagram account from Facebook token: ' . $result['error'] );
		}

		$body      = $result['data'];
		$data      = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Failed to resolve Instagram account.';
			return new \WP_Error( 'instagram_fb_resolve_error', $error_message, $data );
		}

		if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new \WP_Error( 'instagram_no_pages', 'No Facebook Pages found. Link your Instagram Business account to a Facebook Page.' );
		}

		foreach ( $data['data'] as $page ) {
			if ( ! empty( $page['instagram_business_account']['id'] ) ) {
				return array(
					'id'       => (string) $page['instagram_business_account']['id'],
					'username' => (string) ( $page['instagram_business_account']['username'] ?? '' ),
				);
			}
		}

		return new \WP_Error( 'instagram_no_linked_account', 'No Instagram Business Account linked to any Facebook Page.' );
	}

	/**
	 * Exchange short-lived Facebook token for long-lived token.
	 *
	 * Uses Facebook's fb_exchange_token grant for tokens obtained via Facebook Login.
	 *
	 * @param string $short_lived_token Short-lived Facebook access token.
	 * @param array  $config OAuth configuration.
	 * @return array|\WP_Error Token data or error.
	 */
	private function exchange_for_long_lived_token_fb( string $short_lived_token, array $config ): array|\WP_Error {
		do_action( 'datamachine_log', 'debug', 'Instagram OAuth: Exchanging short-lived Facebook token for long-lived token' );

		$params = array(
			'grant_type'    => 'fb_exchange_token',
			'client_id'     => $config['app_id'] ?? '',
			'client_secret' => $config['app_secret'] ?? '',
			'fb_exchange_token' => $short_lived_token,
		);
		$url = self::FB_API_URL . '/oauth/access_token?' . http_build_query( $params );

		$result = HttpClient::get( $url, array( 'context' => 'Instagram OAuth' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'instagram_fb_exchange_request_failed', $result['error'] );
		}

		$body      = $result['data'];
		$data      = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || empty( $data['access_token'] ) ) {
			$error_message = $data['error']['message'] ?? $data['error_description'] ?? 'Failed to exchange Facebook token.';
			return new \WP_Error( 'instagram_fb_exchange_failed', $error_message, $data );
		}

		$expires_in = $data['expires_in'] ?? 3600 * 24 * 60;
		$expires_at = time() + intval( $expires_in );

		return array(
			'access_token'     => $data['access_token'],
			'token_expires_at' => $expires_at,
		);
	}

	/**
	 * Exchange short-lived token for long-lived token (Instagram-specific, legacy).
	 *
	 * Uses Instagram's ig_exchange_token grant for Basic Display tokens.
	 *
	 * @param string $short_lived_token Short-lived access token.
	 * @param string $user_id Instagram user ID.
	 * @param array  $config OAuth configuration.
	 * @return array|\WP_Error Token data or error.
	 */
	private function exchange_for_long_lived_token( string $short_lived_token, string $user_id, array $config ): array|\WP_Error {
		do_action( 'datamachine_log', 'debug', 'Instagram OAuth: Exchanging short-lived token for long-lived token' );

		$params = array(
			'grant_type'    => 'ig_exchange_token',
			'client_secret' => $config['app_secret'] ?? '',
			'access_token'  => $short_lived_token,
		);
		$url = self::GRAPH_API_URL . '/access_token?' . http_build_query( $params );

		$result = HttpClient::get( $url, array( 'context' => 'Instagram OAuth' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'instagram_oauth_exchange_request_failed', $result['error'] );
		}

		$body      = $result['data'];
		$data      = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || empty( $data['access_token'] ) ) {
			$error_message = $data['error']['message'] ?? $data['error_description'] ?? 'Failed to retrieve long-lived access token from Instagram.';
			return new \WP_Error( 'instagram_oauth_exchange_failed', $error_message, $data );
		}

		$long_lived_token = $data['access_token'];
		$expires_in       = $data['expires_in'] ?? 3600 * 24 * 60;
		$expires_at       = time() + intval( $expires_in );

		return array(
			'access_token'     => $long_lived_token,
			'user_id'          => $user_id,
			'token_expires_at' => $expires_at,
		);
	}

	/**
	 * Get the Instagram username for a given user ID.
	 *
	 * Used as the fallback path when an OAuth flow does not surface the
	 * username up front (e.g. legacy Instagram Basic Display tokens, or
	 * out-of-band backfills). Hits the Facebook Graph API because all
	 * tokens issued by this provider — including IG Basic Display — are
	 * accepted there, while graph.instagram.com rejects FB-flavored tokens
	 * with "Cannot parse access token".
	 *
	 * @param string $access_token Access token (FB-flavored or IG-flavored).
	 * @param string $user_id      Instagram Business Account ID.
	 * @return string|\WP_Error Username or error.
	 */
	private function get_username_from_token( string $access_token, string $user_id ): string|\WP_Error {
		$url = self::FB_API_URL . "/{$user_id}?fields=username&access_token={$access_token}";

		$result = HttpClient::get(
			$url,
			array(
				'context' => 'Instagram Authentication',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'instagram_username_fetch_failed', $result['error'] );
		}

		$body      = $result['data'];
		$data      = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Failed to fetch Instagram username.';
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
