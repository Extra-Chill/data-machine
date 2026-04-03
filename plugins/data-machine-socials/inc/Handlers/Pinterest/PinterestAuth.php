<?php
/**
 * Pinterest OAuth 2.0 Authentication Provider.
 *
 * Implements the full Pinterest OAuth2 Authorization Code flow with automatic
 * token refresh via BaseOAuth2Provider. Pinterest access tokens expire in 30 days
 * (production) — refresh tokens are valid for 1 year and rotated on use.
 *
 * Refresh is handled automatically via:
 * - On-demand: get_valid_access_token() checks expiry with a 7-day buffer.
 * - Proactive: WP-Cron fires at (expires_at - buffer) to keep tokens fresh.
 *
 * @package DataMachineSocials\Handlers\Pinterest
 * @since 0.3.0
 */

namespace DataMachineSocials\Handlers\Pinterest;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pinterest Auth Provider
 *
 * Manages Pinterest API v5 OAuth2 authentication with auto-refresh.
 */
class PinterestAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	const AUTH_URL  = 'https://www.pinterest.com/oauth/';
	const TOKEN_URL = 'https://api.pinterest.com/v5/oauth/token';
	const SCOPES    = 'boards:read,pins:read,pins:write,user_accounts:read';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'pinterest' );
	}

	/**
	 * Get configuration fields required for Pinterest authentication.
	 *
	 * @return array Field definitions for the settings UI.
	 */
	public function get_config_fields(): array {
		return array(
			'client_id'     => array(
				'label'       => __( 'App ID', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Pinterest app ID from developers.pinterest.com', 'data-machine-socials' ),
			),
			'client_secret' => array(
				'label'       => __( 'App Secret', 'data-machine-socials' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Your Pinterest app secret from developers.pinterest.com', 'data-machine-socials' ),
			),
		);
	}

	/**
	 * Check if OAuth credentials are configured.
	 *
	 * @return bool True if client_id and client_secret are set.
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['client_id'] ) && ! empty( $config['client_secret'] );
	}

	/**
	 * Check if Pinterest is authenticated with a valid, non-expired token.
	 *
	 * Requires both an access_token and a refresh_token. Expiry is checked
	 * by the parent's is_authenticated() implementation.
	 *
	 * @return bool True if authenticated and not expired.
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return false;
		}

		// Require a refresh_token — without it we can't auto-refresh.
		if ( empty( $account['refresh_token'] ) ) {
			return false;
		}

		return parent::is_authenticated();
	}

	/**
	 * Get the Pinterest OAuth2 authorization URL.
	 *
	 * @return string Authorization URL, or empty string if not configured.
	 */
	public function get_authorization_url(): string {
		$config    = $this->get_config();
		$client_id = $config['client_id'] ?? '';

		if ( empty( $client_id ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Pinterest OAuth: App ID not configured',
				array(
					'handler'   => 'pinterest',
					'operation' => 'get_authorization_url',
				)
			);
			return '';
		}

		$state  = $this->oauth2->create_state( 'pinterest' );
		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => $this->get_callback_url(),
			'response_type' => 'code',
			'scope'         => self::SCOPES,
			'state'         => $state,
		);

		return $this->oauth2->get_authorization_url( self::AUTH_URL, $params );
	}

	/**
	 * Handle the OAuth2 callback from Pinterest.
	 *
	 * Exchanges the authorization code for access + refresh tokens,
	 * saves them, and schedules proactive refresh via WP-Cron.
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$config = $this->get_config();

		$client_id     = $config['client_id'] ?? '';
		$client_secret = $config['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			do_action( 'datamachine_log', 'error', 'Pinterest OAuth: Missing app credentials for token exchange' );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'datamachine-settings',
						'auth_error' => 'missing_config',
						'provider'   => 'pinterest',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Pinterest requires Basic Auth for token exchange (same as Reddit).
		$token_params = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $this->get_callback_url(),
			'headers'      => array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
			),
		);

		$this->oauth2->handle_callback(
			'pinterest',
			self::TOKEN_URL,
			$token_params,
			function ( $token_data ) {
				return $this->build_account_data( $token_data );
			},
			null,
			function ( $account_data ) {
				$this->save_account( $account_data );
				$this->schedule_proactive_refresh();
			}
		);
	}

	/**
	 * Perform Pinterest-specific token refresh.
	 *
	 * Pinterest access tokens expire in 30 days. Refresh requires Basic Auth
	 * with client_id:client_secret and the stored refresh_token. Pinterest
	 * rotates the refresh_token on each use — the new one must be stored.
	 *
	 * @since 0.3.0
	 * @param string $current_token The current access token (not used — Pinterest refresh uses refresh_token).
	 * @return array|\WP_Error|null Token data on success, WP_Error on failure.
	 */
	protected function do_refresh_token( string $current_token ): array|\WP_Error|null {
		$account = $this->get_account();

		if ( empty( $account['refresh_token'] ) ) {
			return new \WP_Error( 'pinterest_refresh_no_token', 'Pinterest: No refresh token stored — re-authorization required' );
		}

		$config        = $this->get_config();
		$client_id     = $config['client_id'] ?? '';
		$client_secret = $config['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new \WP_Error( 'pinterest_refresh_missing_config', 'Pinterest: Missing app credentials for token refresh' );
		}

		$result = HttpClient::post(
			self::TOKEN_URL,
			array(
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $account['refresh_token'],
					'scope'         => self::SCOPES,
				),
				'context' => 'Pinterest OAuth Token Refresh',
			)
		);

		if ( ! $result['success'] || 200 !== ( $result['status_code'] ?? 0 ) ) {
			return new \WP_Error(
				'pinterest_refresh_api_error',
				'Pinterest: Token refresh request failed',
				array(
					'status_code' => $result['status_code'] ?? 'unknown',
					'error'       => $result['error'] ?? 'unknown',
				)
			);
		}

		$data = json_decode( $result['data'], true );

		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error(
				'pinterest_refresh_no_access_token',
				'Pinterest: No access token in refresh response',
				array( 'response' => $result['data'] )
			);
		}

		$expires_in = intval( $data['expires_in'] ?? 2592000 ); // Default: 30 days.

		// Pinterest rotates refresh tokens — store the new one if provided.
		if ( ! empty( $data['refresh_token'] ) && $data['refresh_token'] !== $account['refresh_token'] ) {
			$account['refresh_token'] = $data['refresh_token'];
			$this->save_account( $account );
		}

		return array(
			'access_token' => $data['access_token'],
			'expires_at'   => time() + $expires_in,
		);
	}

	/**
	 * Get seconds before expiry to trigger refresh.
	 *
	 * Pinterest access tokens last ~30 days — refresh 7 days early (default).
	 *
	 * @return int Buffer in seconds.
	 */
	protected function get_refresh_buffer_seconds(): int {
		return 7 * DAY_IN_SECONDS;
	}

	/**
	 * Build structured account data from Pinterest token response.
	 *
	 * @param array $token_data Raw token response from Pinterest API.
	 * @return array Structured account data for storage.
	 */
	private function build_account_data( array $token_data ): array {
		$access_token  = $token_data['access_token'];
		$refresh_token = $token_data['refresh_token'] ?? null;
		$expires_in    = intval( $token_data['expires_in'] ?? 2592000 );

		return array(
			'access_token'      => $access_token,
			'refresh_token'     => $refresh_token,
			'token_expires_at'  => time() + $expires_in,
			'scope'             => $token_data['scope'] ?? self::SCOPES,
			'last_refreshed_at' => time(),
		);
	}

	/**
	 * Get stored Pinterest account details for display.
	 *
	 * @return array|null Account details or null if not authenticated.
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || empty( $account['access_token'] ) ) {
			return null;
		}
		return $account;
	}

	/**
	 * Remove Pinterest account credentials.
	 *
	 * @return bool True on success.
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
