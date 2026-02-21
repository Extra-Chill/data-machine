<?php
/**
* Handles Facebook OAuth 2.0 authentication for the Facebook publish handler.
*
* Uses OAuth2Handler for centralized OAuth flow with Meta-specific two-stage token exchange.
* Preserves Facebook-specific logic: page credentials, dual token system, permissions.
*
* @package DataMachineSocials
* @subpackage Handlers\Facebook
* @since 0.2.0
*/

namespace DataMachineSocials\Handlers\Facebook;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacebookAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	public const GRAPH_API_VERSION = 'v23.0';
	public const AUTH_URL = 'https://www.facebook.com/' . self::GRAPH_API_VERSION . '/dialog/oauth';
	public const TOKEN_URL = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION . '/oauth/access_token';
	public const SCOPES = 'email,public_profile,pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,business_management';
	public const GRAPH_API_URL = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION;

	public function __construct() {
		parent::__construct( 'facebook' );
	}

	/**
	* Get configuration fields required for Facebook authentication
	*
	* @return array Configuration field definitions
	*/
	public function get_config_fields(): array {
		return array(
			'app_id' => array(
				'label' => __( 'App ID', 'data-machine-socials' ),
				'type' => 'text',
				'required' => true,
				'description' => __( 'Your Facebook application App ID from developers.facebook.com', 'data-machine-socials' ),
			),
			'app_secret' => array(
				'label' => __( 'App Secret', 'data-machine-socials' ),
				'type' => 'text',
				'required' => true,
				'description' => __( 'Your Facebook application App Secret from developers.facebook.com', 'data-machine-socials' ),
			),
		);
	}

	/**
	* Check if Facebook authentication is properly configured
	*
	* @return bool True if OAuth credentials are configured
	*/
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['app_id'] ) && ! empty( $config['app_secret'] );
	}

	/**
	* Check if admin has valid Facebook authentication
	*
	* @return bool True if authenticated
	*/
	public function is_authenticated(): bool {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) ) {
			return false;
		}

		if ( empty( $account['user_access_token'] ) || empty( $account['page_access_token'] ) ) {
			return false;
		}

		if ( isset( $account['token_expires_at'] ) && time() > $account['token_expires_at'] ) {
			return false;
		}

		return true;
	}

	/**
	* Get stored Page access token (Facebook-specific)
	*
	* @return string|null Page access token or null
	*/
	public function get_page_access_token(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['page_access_token'] ) ) {
			return null;
		}

		if ( isset( $account['token_expires_at'] ) && time() > $account['token_expires_at'] ) {
			return null;
		}

		return $account['page_access_token'];
	}

	/**
	* Get stored User access token (Facebook-specific)
	*
	* @return string|null User access token or null
	*/
	public function get_user_access_token(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['user_access_token'] ) ) {
			return null;
		}

		if ( isset( $account['token_expires_at'] ) && time() > $account['token_expires_at'] ) {
			return null;
		}

		return $account['user_access_token'];
	}

	/**
	* Get stored Page ID (Facebook-specific)
	*
	* @return string|null Page ID or null
	*/
	public function get_page_id(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['page_id'] ) ) {
			return null;
		}
		return $account['page_id'];
	}

	/**
	* Get authorization URL for Facebook OAuth
	*
	* @return string Authorization URL
	*/
	public function get_authorization_url(): string {
		$state = $this->oauth2->create_state( 'facebook' );

		$config = $this->get_config();
		$params = array(
			'client_id' => $config['app_id'] ?? '',
			'redirect_uri' => $this->get_callback_url(),
			'scope' => self::SCOPES,
			'response_type' => 'code',
			'state' => $state,
		);

		return $this->oauth2->get_authorization_url( self::AUTH_URL, $params );
	}

	/**
	* Handle OAuth callback from Facebook
	*/
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$config = $this->get_config();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$facebook_code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		$this->oauth2->handle_callback(
			'facebook',
			self::TOKEN_URL,
			array(
				'client_id' => $config['app_id'] ?? '',
				'client_secret' => $config['app_secret'] ?? '',
				'redirect_uri' => $this->get_callback_url(),
				'code' => $facebook_code,
			),
			function ( $long_lived_token_data ) {
				// Build account data from long-lived token
				$access_token = $long_lived_token_data['access_token'];
				$token_expires_at = $long_lived_token_data['expires_at'];

				// Fetch Page credentials
				$page_credentials = $this->get_page_credentials( $access_token );
				if ( is_wp_error( $page_credentials ) ) {
					return $page_credentials;
				}

				// Fetch user profile
				$profile_info = $this->get_user_profile( $access_token );
				$user_profile_id = 'Unknown';
				$user_profile_name = 'Unknown';

				if ( ! is_wp_error( $profile_info ) ) {
					$user_profile_id = $profile_info['id'] ?? 'ErrorFetchingId';
					$user_profile_name = $profile_info['name'] ?? 'ErrorFetchingName';
				}

				return array(
					'user_access_token' => $access_token,
					'page_access_token' => $page_credentials['access_token'],
					'token_type' => 'bearer',
					'user_id' => $user_profile_id,
					'user_name' => $user_profile_name,
					'page_id' => $page_credentials['id'],
					'page_name' => $page_credentials['name'],
					'authenticated_at' => time(),
					'token_expires_at' => $token_expires_at,
				);
			},
			function ( $short_lived_token_data ) use ( $config ) {
				// Two-stage: Exchange short-lived token for long-lived token
				return $this->exchange_for_long_lived_token(
					$short_lived_token_data['access_token'],
					$config
				);
			},
			array( $this, 'save_account' )
		);
	}

	/**
	* Exchange short-lived token for long-lived token (Facebook-specific)
	*
	* @param string $short_lived_token Short-lived access token
	* @param array $config OAuth configuration
	* @return array|\WP_Error Token data ['access_token' => ..., 'expires_at' => ...] or error
	*/
	private function exchange_for_long_lived_token( string $short_lived_token, array $config ): array|\WP_Error {
		do_action( 'datamachine_log', 'debug', 'Exchanging Facebook short-lived token for long-lived token' );

		$params = array(
			'grant_type' => 'fb_exchange_token',
			'client_id' => $config['app_id'] ?? '',
			'client_secret' => $config['app_secret'] ?? '',
			'fb_exchange_token' => $short_lived_token,
		);
		$url = self::TOKEN_URL . '?' . http_build_query( $params );

		$result = HttpClient::get( $url, array( 'context' => 'Facebook OAuth' ) );

		if ( ! $result['success'] ) {
			do_action( 'datamachine_log', 'error', 'Facebook OAuth Error: Long-lived token request failed', array( 'error' => $result['error'] ) );
			return new \WP_Error( 'facebook_oauth_long_token_request_failed', __( 'HTTP error during long-lived token exchange with Facebook.', 'data-machine-socials' ), $result['error'] );
		}

		$body = $result['data'];
		$data = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || empty( $data['access_token'] ) ) {
			$error_message = $data['error']['message'] ?? $data['error_description'] ?? 'Failed to retrieve long-lived access token from Facebook.';
			do_action(
				'datamachine_log',
				'error',
				'Facebook OAuth Error: Long-lived token exchange failed',
				array(
					'http_code' => $http_code,
					'response' => $body,
				)
			);
			return new \WP_Error( 'facebook_oauth_long_token_exchange_failed', $error_message, $data );
		}

		$expires_in = $data['expires_in'] ?? 3600 * 24 * 60;
		$expires_at = time() + intval( $expires_in );

		do_action( 'datamachine_log', 'debug', 'Successfully obtained Facebook long-lived token' );

		return array(
			'access_token' => $data['access_token'],
			'expires_at' => $expires_at,
		);
	}

	/**
	* Get page credentials using user access token (Facebook-specific)
	*
	* @param string $user_access_token User access token
	* @return array|\WP_Error Page credentials or error
	*/
	private function get_page_credentials( string $user_access_token ): array|\WP_Error {
		do_action( 'datamachine_log', 'debug', 'Fetching Facebook page credentials' );

		$url = self::GRAPH_API_URL . '/me/accounts?fields=id,name,access_token';

		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $user_access_token ),
				'context' => 'Facebook Authentication',
			)
		);

		if ( ! $result['success'] ) {
			do_action( 'datamachine_log', 'error', 'Facebook Page Fetch Error: Request failed', array( 'error' => $result['error'] ) );
			return new \WP_Error( 'facebook_page_request_failed', __( 'HTTP error while fetching Facebook pages.', 'data-machine-socials' ), $result['error'] );
		}

		$body = $result['data'];
		$data = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || ! isset( $data['data'] ) ) {
			$error_message = $data['error']['message'] ?? 'Failed to retrieve pages from Facebook.';
			do_action(
				'datamachine_log',
				'error',
				'Facebook Page Fetch Error: API error',
				array(
					'http_code' => $http_code,
					'response' => $body,
				)
			);
			return new \WP_Error( 'facebook_page_api_error', $error_message, $data );
		}

		if ( empty( $data['data'] ) ) {
			do_action( 'datamachine_log', 'error', 'Facebook Page Fetch Error: No pages found for this user' );
			return new \WP_Error( 'facebook_no_pages_found', __( 'No Facebook pages were found for this account.', 'data-machine-socials' ) );
		}

		$first_page = $data['data'][0];

		if ( empty( $first_page['id'] ) || empty( $first_page['access_token'] ) || empty( $first_page['name'] ) ) {
			do_action( 'datamachine_log', 'error', 'Facebook Page Fetch Error: Incomplete data for the first page', array( 'page_data' => $first_page ) );
			return new \WP_Error( 'facebook_incomplete_page_data', __( 'Required information (ID, Access Token, Name) was missing for the Facebook page.', 'data-machine-socials' ) );
		}

		do_action( 'datamachine_log', 'debug', 'Successfully fetched credentials for Facebook page', array( 'page_id' => $first_page['id'] ) );

		return array(
			'id' => $first_page['id'],
			'name' => $first_page['name'],
			'access_token' => $first_page['access_token'],
		);
	}

	/**
	* Get user profile from Facebook Graph API
	*
	* @param string $access_token Access token
	* @return array|\WP_Error Profile data or error
	*/
	private function get_user_profile( string $access_token ): array|\WP_Error {
		$url = self::GRAPH_API_URL . '/me?fields=id,name';

		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'Facebook Authentication',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'facebook_profile_fetch_failed', $result['error'] );
		}

		$body = $result['data'];
		$data = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Failed to fetch Facebook profile.';
			return new \WP_Error( 'facebook_profile_fetch_failed', $error_message, $data );
		}

		return $data;
	}

	/**
	* Check if account has comment permissions (Facebook-specific)
	*
	* @return bool True if comment permissions available
	*/
	public function has_comment_permission(): bool {
		$user_access_token = $this->get_user_access_token();
		if ( ! $user_access_token ) {
			do_action( 'datamachine_log', 'error', 'Facebook: No user access token available for permission check' );
			return false;
		}

		$permissions_url = self::GRAPH_API_URL . '/me/permissions?access_token=' . $user_access_token;

		$result = HttpClient::get( $permissions_url, array( 'context' => 'Facebook Comment Permission Check' ) );

		if ( ! $result['success'] ) {
			do_action( 'datamachine_log', 'error', 'Facebook: Failed to check comment permissions', array( 'error' => $result['error'] ) );
			return false;
		}

		$data = json_decode( $result['data'], true );

		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			do_action( 'datamachine_log', 'error', 'Facebook: Invalid permission response format', array( 'response_data' => $data ) );
			return false;
		}

		foreach ( $data['data'] as $permission ) {
			if ( isset( $permission['permission'] ) &&
				'pages_manage_engagement' === $permission['permission'] &&
				'granted' === $permission['status'] ) {
				return true;
			}
		}

		do_action( 'datamachine_log', 'error', 'Facebook: pages_manage_engagement permission not granted' );
		return false;
	}

	/**
	* Get stored Facebook account details
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
	* Remove stored Facebook account details
	*
	* @return bool Success status
	*/
	public function remove_account(): bool {
		$account = $this->get_account();
		$token = null;

		if ( ! empty( $account ) && is_array( $account ) && ! empty( $account['user_access_token'] ) ) {
			$token = $account['user_access_token'];
		}

		if ( $token ) {
			$url = self::GRAPH_API_URL . '/me/permissions';
			$result = HttpClient::delete(
				$url,
				array(
					'body' => array( 'access_token' => $token ),
					'context' => 'Facebook Authentication',
				)
			);

			if ( ! $result['success'] ) {
				do_action( 'datamachine_log', 'warning', 'Facebook deauthorization failed (non-critical)', array( 'error' => $result['error'] ) );
			} else {
				do_action( 'datamachine_log', 'debug', 'Facebook deauthorization successful' );
			}
		}

		return $this->clear_account();
	}
}
