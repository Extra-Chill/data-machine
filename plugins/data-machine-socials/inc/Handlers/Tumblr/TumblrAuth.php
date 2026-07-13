<?php
/**
 * Tumblr OAuth 2.0 Authentication Provider.
 *
 * Implements the Tumblr v2 OAuth2 Authorization Code flow with automatic
 * token refresh via BaseOAuth2Provider. Tumblr access tokens are short-lived
 * (the documented example TTL is ~42 minutes) and refresh tokens are returned
 * only when the `offline_access` scope is requested and rotate on each refresh.
 *
 * Refresh is handled automatically via:
 * - On-demand: get_valid_access_token() checks expiry with a 5-minute buffer.
 * - Proactive: WP-Cron fires at (expires_at - buffer) to keep tokens fresh.
 *
 * Tumblr's token exchange is unusual in that the client credentials are passed
 * in the request body (not via HTTP Basic auth), matching the official docs'
 * curl examples.
 *
 * @package DataMachineSocials\Handlers\Tumblr
 * @since 0.17.0
 */

namespace DataMachineSocials\Handlers\Tumblr;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tumblr Auth Provider
 *
 * Manages Tumblr API v2 OAuth2 authentication with auto-refresh.
 */
class TumblrAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	const AUTH_URL  = 'https://www.tumblr.com/oauth2/authorize';
	const TOKEN_URL = 'https://api.tumblr.com/v2/oauth2/token';
	const SCOPES    = 'basic write offline_access';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'tumblr' );
	}

	/**
	 * Get configuration fields required for Tumblr authentication.
	 *
	 * @return array Field definitions for the settings UI.
	 */
	public function get_config_fields(): array {
		return array(
			'client_id'     => array(
				'label'       => __( 'OAuth Consumer Key', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Tumblr OAuth Consumer Key from www.tumblr.com/oauth/apps', 'data-machine-socials' ),
			),
			'client_secret' => array(
				'label'       => __( 'OAuth Consumer Secret', 'data-machine-socials' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Your Tumblr OAuth Consumer Secret (Secret Key) from www.tumblr.com/oauth/apps', 'data-machine-socials' ),
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
	 * Check if Tumblr is authenticated with a valid, non-expired token.
	 *
	 * Requires both an access_token and a refresh_token (so we can auto-refresh
	 * the short-lived access tokens Tumblr issues).
	 *
	 * @return bool True if authenticated and not expired.
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return false;
		}

		// Without a refresh token we can't auto-refresh Tumblr's short-lived tokens.
		if ( empty( $account['refresh_token'] ) ) {
			return false;
		}

		return parent::is_authenticated();
	}

	/**
	 * Get the Tumblr OAuth2 authorization URL.
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
				'Tumblr OAuth: OAuth Consumer Key not configured',
				array(
					'handler'   => 'tumblr',
					'operation' => 'get_authorization_url',
				)
			);
			return '';
		}

		$state  = $this->oauth2->create_state( 'tumblr' );
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
	 * Handle the OAuth2 callback from Tumblr.
	 *
	 * Exchanges the authorization code for access + refresh tokens, saves them,
	 * and schedules proactive refresh via WP-Cron. Tumblr takes client
	 * credentials in the body (not Basic auth).
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$config = $this->get_config();

		$client_id     = $config['client_id'] ?? '';
		$client_secret = $config['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			do_action( 'datamachine_log', 'error', 'Tumblr OAuth: Missing app credentials for token exchange' );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'datamachine-settings',
						'auth_error' => 'missing_config',
						'provider'   => 'tumblr',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$token_params = array(
			'grant_type'    => 'authorization_code',
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'redirect_uri'  => $this->get_callback_url(),
		);

		$this->oauth2->handle_callback(
			'tumblr',
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
	 * Perform Tumblr-specific token refresh.
	 *
	 * Tumblr access tokens are short-lived (~minutes). Refresh requires the
	 * client credentials and stored refresh token in the body; a new
	 * refresh_token is returned and must be stored (rotating refresh tokens).
	 *
	 * @since 0.17.0
	 * @param string $current_token The current access token (unused — Tumblr refresh uses refresh_token).
	 * @return array|\WP_Error|null Token data on success, WP_Error on failure.
	 */
	protected function do_refresh_token( string $current_token ): array|\WP_Error|null {
		$account = $this->get_account();

		if ( empty( $account['refresh_token'] ) ) {
			return new \WP_Error( 'tumblr_refresh_no_token', 'Tumblr: No refresh token stored — re-authorization required' );
		}

		$config        = $this->get_config();
		$client_id     = $config['client_id'] ?? '';
		$client_secret = $config['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new \WP_Error( 'tumblr_refresh_missing_config', 'Tumblr: Missing app credentials for token refresh' );
		}

		$result = HttpClient::post(
			self::TOKEN_URL,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $account['refresh_token'],
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
				),
				'context' => 'Tumblr OAuth Token Refresh',
			)
		);

		if ( ! $result['success'] || 200 !== ( $result['status_code'] ?? 0 ) ) {
			return new \WP_Error(
				'tumblr_refresh_api_error',
				'Tumblr: Token refresh request failed',
				array(
					'status_code' => $result['status_code'] ?? 'unknown',
					'error'       => $result['error'] ?? 'unknown',
				)
			);
		}

		$data = json_decode( $result['data'], true );

		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error(
				'tumblr_refresh_no_access_token',
				'Tumblr: No access token in refresh response',
				array( 'response' => $result['data'] )
			);
		}

		$expires_in = intval( $data['expires_in'] ?? 3600 );

		// Tumblr rotates refresh tokens — store the new one if provided.
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
	 * Tumblr access tokens are short-lived, so refresh 5 minutes early.
	 *
	 * @return int Buffer in seconds.
	 */
	protected function get_refresh_buffer_seconds(): int {
		return 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * Build structured account data from Tumblr token response.
	 *
	 * @param array $token_data Raw token response from Tumblr API.
	 * @return array Structured account data for storage.
	 */
	private function build_account_data( array $token_data ): array {
		$access_token  = $token_data['access_token'];
		$refresh_token = $token_data['refresh_token'] ?? null;
		$expires_in    = intval( $token_data['expires_in'] ?? 3600 );

		return array(
			'access_token'      => $access_token,
			'refresh_token'     => $refresh_token,
			'token_expires_at'  => time() + $expires_in,
			'scope'             => $token_data['scope'] ?? self::SCOPES,
			'last_refreshed_at' => time(),
		);
	}

	/**
	 * Get stored Tumblr account details for display.
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
	 * Remove Tumblr account credentials.
	 *
	 * @return bool True on success.
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
