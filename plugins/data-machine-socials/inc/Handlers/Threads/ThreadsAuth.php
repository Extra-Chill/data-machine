<?php
/**
* Handles Threads OAuth 2.0 authentication for the Threads publish handler.
*
* Uses OAuth2Handler for centralized OAuth flow with Meta-specific two-stage token exchange.
* Preserves Threads-specific logic: token refresh, automatic refresh.
*
* @package DataMachineSocials
* @subpackage Handlers\Threads
* @since 0.2.0
*/

namespace DataMachineSocials\Handlers\Threads;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ThreadsAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	const AUTH_URL = 'https://graph.facebook.com/oauth/authorize';
	const TOKEN_URL = 'https://graph.threads.net/oauth/access_token';
	const REFRESH_URL = 'https://graph.threads.net/refresh_access_token';
	const SCOPES = 'threads_basic,threads_content_publish';

	public function __construct() {
		parent::__construct( 'threads' );
	}

	/**
	* Get configuration fields required for Threads authentication
	*
	* @return array Configuration field definitions
	*/
	public function get_config_fields(): array {
		return array(
			'app_id' => array(
				'label' => __( 'App ID', 'data-machine-socials' ),
				'type' => 'text',
				'required' => true,
				'description' => __( 'Your Threads application App ID from developers.facebook.com', 'data-machine-socials' ),
			),
			'app_secret' => array(
				'label' => __( 'App Secret', 'data-machine-socials' ),
				'type' => 'text',
				'required' => true,
				'description' => __( 'Your Threads application App Secret from developers.facebook.com', 'data-machine-socials' ),
			),
		);
	}

	/**
	* Check if Threads authentication is properly configured
	*
	* @return bool True if OAuth credentials are configured
	*/
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['app_id'] ) && ! empty( $config['app_secret'] );
	}

	/**
	* Check if admin has valid Threads authentication
	*
	* @return bool True if authenticated
	*/
	public function is_authenticated(): bool {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) ) {
			return false;
		}

		if ( empty( $account['access_token'] ) ) {
			return false;
		}

		if ( isset( $account['token_expires_at'] ) && time() > $account['token_expires_at'] ) {
			return false;
		}

		return true;
	}

	/**
	* Get access token with automatic refresh (Threads-specific)
	*
	* @return string|null Access token or null
	*/
	public function get_access_token(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return null;
		}

		// Check if token needs refresh (expires within the next 7 days)
		$needs_refresh = false;
		if ( isset( $account['token_expires_at'] ) ) {
			$expiry_timestamp = intval( $account['token_expires_at'] );
			$seven_days_in_seconds = 7 * 24 * 60 * 60;
			if ( time() > $expiry_timestamp ) {
				$needs_refresh = true;
			} elseif ( ( $expiry_timestamp - time() ) < $seven_days_in_seconds ) {
				$needs_refresh = true;
			}
		}

		$current_token = $account['access_token'];

		if ( $needs_refresh ) {
			$refreshed_data = $this->refresh_access_token( $current_token );
			if ( ! is_wp_error( $refreshed_data ) ) {
				$account['access_token'] = $refreshed_data['access_token'];
				$account['token_expires_at'] = $refreshed_data['expires_at'];
				$this->save_account( $account );
				return $refreshed_data['access_token'];
			} else {
				if ( isset( $account['token_expires_at'] ) && time() > intval( $account['token_expires_at'] ) ) {
					return null;
				}
				return $current_token;
			}
		}

		return $current_token;
	}

	/**
	* Get stored Page ID (Threads-specific)
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
	* Get authorization URL for Threads OAuth
	*
	* @return string Authorization URL
	*/
	public function get_authorization_url(): string {
		$state = $this->oauth2->create_state( 'threads' );

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
	* Handle OAuth callback from Threads
	*/
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$config = $this->get_config();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$threads_code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		$this->oauth2->handle_callback(
			'threads',
			self::TOKEN_URL,
			array(
				'client_id' => $config['app_id'] ?? '',
				'client_secret' => $config['app_secret'] ?? '',
				'grant_type' => 'authorization_code',
				'redirect_uri' => $this->get_callback_url(),
				'code' => $threads_code,
			),
			function ( $long_lived_token_data ) {
				// Build account data from long-lived token
				$access_token = $long_lived_token_data['access_token'];
				$token_expires_at = $long_lived_token_data['expires_at'];

				// Fetch posting entity info
				$posting_entity_info = $this->get_user_profile( $access_token );
				if ( is_wp_error( $posting_entity_info ) || empty( $posting_entity_info['id'] ) ) {
					return is_wp_error( $posting_entity_info ) ? $posting_entity_info : new \WP_Error(
						'threads_oauth_me_id_missing',
						__( 'Could not retrieve the necessary profile ID using the access token.', 'data-machine-socials' )
					);
				}

				return array(
					'access_token' => $access_token,
					'token_type' => 'bearer',
					'page_id' => $posting_entity_info['id'],
					'page_name' => $posting_entity_info['name'] ?? 'Unknown Page/User',
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
	* Exchange short-lived token for long-lived token (Threads-specific)
	*
	* @param string $short_lived_token Short-lived access token
	* @param array $config OAuth configuration
	* @return array|\WP_Error Token data ['access_token' => ..., 'expires_at' => ...] or error
	*/
	private function exchange_for_long_lived_token( string $short_lived_token, array $config ): array|\WP_Error {
		do_action( 'datamachine_log', 'debug', 'Threads OAuth: Exchanging short-lived token for long-lived token' );

		$params = array(
			'grant_type' => 'th_exchange_token',
			'client_secret' => $config['app_secret'] ?? '',
			'access_token' => $short_lived_token,
		);
		$url = 'https://graph.threads.net/access_token?' . http_build_query( $params );

		$result = HttpClient::get( $url, array( 'context' => 'Threads OAuth' ) );

		if ( ! $result['success'] ) {
			do_action( 'datamachine_log', 'error', 'Threads OAuth Error: Long-lived token exchange request failed', array( 'error' => $result['error'] ) );
			return new \WP_Error( 'threads_oauth_exchange_request_failed', __( 'HTTP error during long-lived token exchange with Threads.', 'data-machine-socials' ), $result['error'] );
		}

		$body = $result['data'];
		$data = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || empty( $data['access_token'] ) ) {
			$error_message = $data['error']['message'] ?? $data['error_description'] ?? 'Failed to retrieve long-lived access token from Threads.';
			do_action(
				'datamachine_log',
				'error',
				'Threads OAuth Error: Long-lived token exchange failed',
				array(
					'http_code' => $http_code,
					'response' => $body,
				)
			);
			return new \WP_Error( 'threads_oauth_exchange_failed', $error_message, $data );
		}

		$expires_in = $data['expires_in'] ?? 3600 * 24 * 60;
		$expires_at = time() + intval( $expires_in );

		do_action( 'datamachine_log', 'debug', 'Threads OAuth: Successfully exchanged for long-lived token' );

		return array(
			'access_token' => $data['access_token'],
			'expires_at' => $expires_at,
		);
	}

	/**
	* Get user profile from Facebook Graph API
	*
	* @param string $access_token Access token
	* @return array|\WP_Error Profile data or error
	*/
	private function get_user_profile( string $access_token ): array|\WP_Error {
		$url = 'https://graph.facebook.com/v19.0/me?fields=id,name';

		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'Threads Authentication',
			)
		);

		if ( ! $result['success'] ) {
			do_action( 'datamachine_log', 'error', 'Threads OAuth Error: Profile fetch request failed', array( 'error' => $result['error'] ) );
			return new \WP_Error( 'threads_profile_fetch_failed', $result['error'] );
		}

		$body = $result['data'];
		$data = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Failed to fetch Threads profile.';
			do_action(
				'datamachine_log',
				'error',
				'Threads OAuth Error: Profile fetch failed',
				array(
					'http_code' => $http_code,
					'response' => $body,
				)
			);
			return new \WP_Error( 'threads_profile_fetch_failed', $error_message, $data );
		}

		if ( empty( $data['id'] ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Threads OAuth Error: Profile fetch response missing ID',
				array(
					'http_code' => $http_code,
					'response' => $body,
				)
			);
			return new \WP_Error( 'threads_profile_id_missing', __( 'Profile ID missing in response from Threads.', 'data-machine-socials' ), $data );
		}

		do_action( 'datamachine_log', 'debug', 'Threads OAuth: Profile fetched successfully', array( 'profile_id' => $data['id'] ) );
		return $data;
	}

	/**
	* Refresh long-lived Threads access token (Threads-specific)
	*
	* @param string $access_token Current long-lived token
	* @return array|\WP_Error ['access_token' => ..., 'expires_at' => ...] or error
	*/
	private function refresh_access_token( string $access_token ): array|\WP_Error {
		$params = array(
			'grant_type' => 'th_refresh_token',
			'access_token' => $access_token,
		);
		$url = self::REFRESH_URL . '?' . http_build_query( $params );

		$result = HttpClient::get( $url, array( 'context' => 'Threads OAuth' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'threads_refresh_http_error', $result['error'] );
		}

		$body = $result['data'];
		$data = json_decode( $body, true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || empty( $data['access_token'] ) ) {
			$error_message = $data['error']['message'] ?? $data['error_description'] ?? 'Failed to refresh Threads access token.';
			return new \WP_Error( 'threads_refresh_api_error', $error_message, $data );
		}

		$expires_in = $data['expires_in'] ?? 3600 * 24 * 60;
		$expires_at = time() + intval( $expires_in );

		return array(
			'access_token' => $data['access_token'],
			'expires_at' => $expires_at,
		);
	}

	/**
	* Get stored Threads account details
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
	* Remove stored Threads account details
	*
	* @return bool Success status
	*/
	public function remove_account(): bool {
		$account = $this->get_account();
		$token = null;

		if ( ! empty( $account ) && is_array( $account ) && ! empty( $account['access_token'] ) ) {
			$token = $account['access_token'];
		}

		if ( $token ) {
			$url = 'https://graph.facebook.com/v19.0/me/permissions';
			$result = HttpClient::delete(
				$url,
				array(
					'body' => array( 'access_token' => $token ),
					'context' => 'Threads Authentication',
				)
			);

			if ( ! $result['success'] ) {
				do_action(
					'datamachine_log',
					'error',
					'Threads token revocation failed during account deletion',
					array(
						'error' => $result['error'] ?? 'Unknown error',
					)
				);
			}
		}

		return $this->clear_account();
	}
}
