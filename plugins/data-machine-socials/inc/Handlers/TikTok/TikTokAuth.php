<?php
/**
 * Handles TikTok OAuth 2.0 authentication via the Login Kit web flow.
 *
 * TikTok's token model differs from the Meta providers in three ways:
 *   1. Access tokens are short-lived (24h), backed by a refresh token.
 *   2. Refresh tokens live up to 365 days and ROTATE on every refresh — the
 *      response may return a different refresh_token, which must be persisted.
 *   3. The refresh grant sends the refresh_token (not the access_token).
 *
 * Uses OAuth2Handler for the centralized authorize/callback flow. A small
 * refresh override preserves TikTok's rotating refresh token with a 1-hour
 * access-token refresh buffer.
 *
 * Official docs:
 *   Login Kit Web         — https://developers.tiktok.com/doc/login-kit-web
 *   Token Management      — https://developers.tiktok.com/doc/oauth-user-access-token-management
 *
 * @package DataMachineSocials
 * @subpackage Handlers\TikTok
 * @since 0.17.0
 */

namespace DataMachineSocials\Handlers\TikTok;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TikTokAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	const AUTH_URL    = 'https://www.tiktok.com/v2/auth/authorize/';
	const TOKEN_URL   = 'https://open.tiktokapis.com/v2/oauth/token/';
	const REVOKE_URL  = 'https://open.tiktokapis.com/v2/oauth/revoke/';
	const API_BASE    = 'https://open.tiktokapis.com';
	const USER_INFO   = 'https://open.tiktokapis.com/v2/user/info/';
	const SCOPES      = 'user.info.basic,video.list,video.publish';

	public function __construct() {
		parent::__construct( 'tiktok' );
	}

	/**
	 * Get configuration fields required for TikTok authentication.
	 *
	 * TikTok developer apps issue a "Client Key" and "Client Secret" — not the
	 * app_id/app_secret pair used by the Meta providers.
	 *
	 * @return array Configuration field definitions.
	 */
	public function get_config_fields(): array {
		return array(
			'client_key' => array(
				'label'       => __( 'Client Key', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your TikTok application Client Key from developers.tiktok.com', 'data-machine-socials' ),
			),
			'client_secret' => array(
				'label'       => __( 'Client Secret', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your TikTok application Client Secret from developers.tiktok.com', 'data-machine-socials' ),
			),
		);
	}

	/**
	 * Check if TikTok authentication is properly configured.
	 *
	 * @return bool True if client_key + client_secret are configured.
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['client_key'] ) && ! empty( $config['client_secret'] );
	}

	/**
	 * Treat a still-valid refresh token as authenticated so callers can recover
	 * after TikTok's 24-hour access token expires.
	 *
	 * @return bool Whether the account can make or refresh authenticated calls.
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		if ( ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return false;
		}

		$access_expires_at = (int) ( $account['token_expires_at'] ?? 0 );
		if ( 0 === $access_expires_at || time() <= $access_expires_at ) {
			return true;
		}

		$refresh_expires_at = (int) ( $account['refresh_token_expires_at'] ?? 0 );
		return ! empty( $account['refresh_token'] ) && ( 0 === $refresh_expires_at || time() <= $refresh_expires_at );
	}

	/**
	 * TikTok access tokens expire after 24 hours. Override the default 7-day
	 * refresh buffer down to 1 hour so tokens only refresh in their final hour,
	 * not on every single use.
	 *
	 * @return int Buffer in seconds before token expiry to trigger refresh.
	 */
	protected function get_refresh_buffer_seconds(): int {
		return HOUR_IN_SECONDS;
	}

	/**
	 * Get a valid TikTok token while preserving a rotated refresh token.
	 *
	 * BaseOAuth2Provider only persists access_token and expires_at after calling
	 * do_refresh_token(). TikTok rotates refresh tokens, so using the base
	 * implementation would overwrite the rotated token saved by the refresh
	 * method with the stale account snapshot. Keep this small override local to
	 * TikTok and persist the current account after the refresh completes.
	 *
	 * @return string|null Valid access token, or null when unavailable.
	 */
	public function get_valid_access_token(): ?string {
		$account = $this->get_account();
		if ( ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return null;
		}

		$expires_at = (int) ( $account['token_expires_at'] ?? 0 );
		if ( 0 === $expires_at || ( $expires_at - time() ) >= $this->get_refresh_buffer_seconds() ) {
			return $account['access_token'];
		}

		$refreshed = $this->do_refresh_token( $account['access_token'] );
		if ( is_wp_error( $refreshed ) || empty( $refreshed['access_token'] ) ) {
			return time() < $expires_at ? $account['access_token'] : null;
		}

		// do_refresh_token() already saved a possibly rotated refresh token.
		$account                     = $this->get_account();
		$account['access_token']     = $refreshed['access_token'];
		$account['token_expires_at'] = $refreshed['expires_at'];
		$account['last_refreshed_at'] = time();
		$this->save_account( $account );
		$this->schedule_proactive_refresh();

		return $refreshed['access_token'];
	}

	/**
	 * Perform TikTok-specific token refresh using the refresh_token grant.
	 *
	 * TikTok refresh tokens ROTATE — the response may contain a different
	 * refresh_token than the one sent in. We persist the new one so the
	 * 365-day refresh chain stays intact.
	 *
	 * The BaseOAuth2Provider passes the current access_token as $current_token,
	 * but TikTok's refresh grant needs the stored refresh_token. We pull it
	 * from the stored account data.
	 *
	 * @param string $current_token The current access token (unused by TikTok).
	 * @return array|\WP_Error|null Token data on success, WP_Error on failure.
	 */
	protected function do_refresh_token( string $current_token ): array|\WP_Error|null {
		$account       = $this->get_account();
		$refresh_token = $account['refresh_token'] ?? '';

		if ( empty( $refresh_token ) ) {
			return new \WP_Error( 'tiktok_refresh_no_token', 'No refresh token stored. Re-authorize TikTok.' );
		}

		$config = $this->get_config();

		$result = HttpClient::post(
			self::TOKEN_URL,
			array(
				'context' => 'TikTok OAuth Refresh',
				'body'    => array(
					'client_key'    => $config['client_key'] ?? '',
					'client_secret' => $config['client_secret'] ?? '',
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				),
				'timeout' => 30,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'tiktok_refresh_http_error', $result['error'] );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || empty( $data['access_token'] ) ) {
			$error_message = $data['error_description'] ?? $data['message'] ?? 'Failed to refresh TikTok access token.';
			return new \WP_Error( 'tiktok_refresh_api_error', $error_message, $data );
		}

		$expires_in = $data['expires_in'] ?? 86400;
		$expires_at = time() + intval( $expires_in );

		// Persist the (possibly rotated) refresh token immediately so the
		// next refresh cycle uses the correct token.
		$rotated_refresh_token = $data['refresh_token'] ?? $refresh_token;
		if ( '' !== $rotated_refresh_token ) {
			$this->update_account_field( 'refresh_token', $rotated_refresh_token );

			$refresh_expires_in = $data['refresh_expires_in'] ?? 31536000;
			$this->update_account_field( 'refresh_token_expires_at', time() + intval( $refresh_expires_in ) );
		}

		return array(
			'access_token' => $data['access_token'],
			'expires_at'   => $expires_at,
		);
	}

	/**
	 * Get stored TikTok open_id.
	 *
	 * @return string|null Open ID or null.
	 */
	public function get_user_id(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['open_id'] ) ) {
			return null;
		}
		return $account['open_id'];
	}

	/**
	 * Get the stored refresh_token expiry timestamp.
	 *
	 * @return int|null Unix timestamp or null.
	 */
	public function get_refresh_token_expires_at(): ?int {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['refresh_token_expires_at'] ) ) {
			return null;
		}
		return (int) $account['refresh_token_expires_at'];
	}

	/**
	 * Get authorization URL for TikTok OAuth (Login Kit web flow).
	 *
	 * @return string Authorization URL.
	 */
	public function get_authorization_url(): string {
		$state  = $this->oauth2->create_state( 'tiktok' );
		$config = $this->get_config();

		$params = array(
			'client_key'    => $config['client_key'] ?? '',
			'scope'         => self::SCOPES,
			'response_type' => 'code',
			'redirect_uri'  => $this->get_callback_url(),
			'state'         => $state,
		);

		return $this->oauth2->get_authorization_url( self::AUTH_URL, $params );
	}

	/**
	 * Handle OAuth callback from TikTok.
	 *
	 * TikTok's token exchange is single-stage (no two-stage long-lived exchange
	 * like Meta). The response includes access_token, refresh_token, open_id,
	 * scope, and expiry fields.
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$config = $this->get_config();

		$this->oauth2->handle_callback(
			'tiktok',
			self::TOKEN_URL,
			array(
				'client_key'    => $config['client_key'] ?? '',
				'client_secret' => $config['client_secret'] ?? '',
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $this->get_callback_url(),
			),
			function ( $token_data ) {
				if ( empty( $token_data['access_token'] ) ) {
					return new \WP_Error( 'tiktok_no_access_token', 'TikTok did not return an access token.' );
				}

				$expires_in  = $token_data['expires_in'] ?? 86400;
				$expires_at  = time() + intval( $expires_in );
				$refresh_expires_in = $token_data['refresh_expires_in'] ?? 31536000;

				return array(
					'access_token'              => $token_data['access_token'],
					'refresh_token'             => $token_data['refresh_token'] ?? '',
					'open_id'                   => $token_data['open_id'] ?? '',
					'scope'                     => $token_data['scope'] ?? self::SCOPES,
					'token_type'                => $token_data['token_type'] ?? 'Bearer',
					'authenticated_at'          => time(),
					'token_expires_at'          => $expires_at,
					'refresh_token_expires_at'  => time() + intval( $refresh_expires_in ),
				);
			},
			null,
			function ( $account_data ) {
				$saved = $this->save_account( $account_data );
				$this->schedule_proactive_refresh();
				return $saved;
			}
		);
	}

	/**
	 * Remove stored TikTok account and revoke the access token.
	 *
	 * @return bool Success status.
	 */
	public function remove_account(): bool {
		$account = $this->get_account();
		$token   = $account['access_token'] ?? '';
		$config  = $this->get_config();

		if ( $token && ! empty( $config['client_key'] ) ) {
			$result = HttpClient::post(
				self::REVOKE_URL,
				array(
					'context' => 'TikTok Authentication',
					'body'    => array(
						'client_key'    => $config['client_key'],
						'client_secret' => $config['client_secret'] ?? '',
						'token'         => $token,
					),
					'timeout' => 30,
				)
			);

			if ( ! $result['success'] ) {
				do_action(
					'datamachine_log',
					'error',
					'TikTok token revocation failed during account deletion',
					array( 'error' => $result['error'] ?? 'Unknown error' )
				);
			}
		}

		return $this->clear_account();
	}

	/**
	 * Get stored TikTok account details for display.
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
	 * Update a single field on the stored account without clobbering the rest.
	 *
	 * Used by do_refresh_token() to persist rotated refresh tokens.
	 *
	 * @param string $key   Account data key.
	 * @param mixed  $value Value to set.
	 * @return void
	 */
	protected function update_account_field( string $key, $value ): void {
		$account   = $this->get_account();
		if ( ! is_array( $account ) ) {
			$account = array();
		}
		$account[ $key ] = $value;
		$this->save_account( $account );
	}
}
