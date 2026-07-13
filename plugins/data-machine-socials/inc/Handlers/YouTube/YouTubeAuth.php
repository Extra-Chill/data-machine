<?php
/**
 * Handles Google OAuth 2.0 authentication for the YouTube publish handler.
 *
 * YouTube authenticates as a Google OAuth2 user (service accounts are not
 * supported — they return NoLinkedYouTubeAccount). This provider issues
 * short-lived 1-hour access tokens with a refresh_token grant, following the
 * BaseOAuth2Provider::do_refresh_token() lifecycle (same shape as RedditAuth).
 *
 * Scopes requested cover video upload, comment engagement, and channel
 * management:
 *   - youtube.upload   (videos.insert resumable upload)
 *   - youtube.force-ssl (comments.insert, commentThreads.insert)
 *   - youtube          (channels.list, search.list, playlists)
 *
 * @package DataMachineSocials
 * @subpackage Handlers\YouTube
 * @since 0.17.0
 */

namespace DataMachineSocials\Handlers\YouTube;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YouTubeAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_BASE  = 'https://www.googleapis.com/youtube/v3';

	/**
	 * OAuth scopes. youtube.upload covers resumable uploads, youtube.force-ssl
	 * covers comment creation/replies, youtube covers search and channel reads.
	 */
	const SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.force-ssl https://www.googleapis.com/auth/youtube';

	public function __construct() {
		parent::__construct( 'youtube' );
	}

	/**
	 * Get configuration fields required for YouTube authentication.
	 *
	 * @return array Configuration field definitions.
	 */
	public function get_config_fields(): array {
		return array(
			'client_id'     => array(
				'label'       => __( 'Client ID', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Google OAuth Client ID from console.cloud.google.com (YouTube Data API v3 enabled).', 'data-machine-socials' ),
			),
			'client_secret' => array(
				'label'       => __( 'Client Secret', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Google OAuth Client Secret from console.cloud.google.com.', 'data-machine-socials' ),
			),
		);
	}

	/**
	 * Check if YouTube OAuth is properly configured.
	 *
	 * @return bool True if OAuth credentials are configured.
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['client_id'] ) && ! empty( $config['client_secret'] );
	}

	/**
	 * Get authorization URL for Google OAuth.
	 *
	 * @return string Authorization URL.
	 */
	public function get_authorization_url(): string {
		$config    = $this->get_config();
		$client_id = $config['client_id'] ?? '';

		if ( empty( $client_id ) ) {
			return '';
		}

		$state  = $this->oauth2->create_state( 'youtube' );
		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => $this->get_callback_url(),
			'scope'         => self::SCOPES,
			'response_type' => 'code',
			'access_type'   => 'offline',  // Google-specific: request a refresh token.
			'prompt'        => 'consent',  // Google-specific: force consent so refresh_token is always issued.
			'state'         => $state,
		);

		return $this->oauth2->get_authorization_url( self::AUTH_URL, $params );
	}

	/**
	 * Handle the OAuth callback from Google.
	 *
	 * Exchanges the authorization code for tokens, then fetches the
	 * authenticated channel ID and title via channels.list (mine=true) so the
	 * stored account carries the YouTube channel identity.
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		$config        = $this->get_config();
		$client_id     = $config['client_id'] ?? '';
		$client_secret = $config['client_secret'] ?? '';

		$token_params = array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'code'          => $code,
			'grant_type'    => 'authorization_code',
			'redirect_uri'  => $this->get_callback_url(),
		);

		$this->oauth2->handle_callback(
			'youtube',
			self::TOKEN_URL,
			$token_params,
			function ( array $token_data ): array|\WP_Error {
				$access_token      = $token_data['access_token'];
				$refresh_token     = $token_data['refresh_token'] ?? null;
				$token_expires_at  = time() + intval( $token_data['expires_in'] ?? 3600 );

				// Resolve the authenticated YouTube channel identity up front.
				$channel = self::fetch_channel_identity( $access_token );
				if ( is_wp_error( $channel ) ) {
					return $channel;
				}

				return array(
					'access_token'     => $access_token,
					'refresh_token'    => $refresh_token,
					'token_expires_at' => $token_expires_at,
					'scope'            => $token_data['scope'] ?? self::SCOPES,
					'channel_id'       => $channel['id'],
					'username'         => $channel['title'],
					'authenticated_at' => time(),
				);
			},
			null,
			function ( array $account_data ) {
				$this->save_account( $account_data );
				$this->schedule_proactive_refresh();
			}
		);
	}

	/**
	 * Fetch the authenticated channel identity via channels.list (mine=true).
	 *
	 * @param string $access_token A valid Google access token.
	 * @return array{0: string, 1: string}|\WP_Error [id, title] or error.
	 */
	private static function fetch_channel_identity( string $access_token ): array|\WP_Error {
		$url = self::API_BASE . '/channels?part=snippet&mine=true';

		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'YouTube OAuth',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'youtube_channel_fetch_failed', 'Failed to fetch YouTube channel: ' . ( $result['error'] ?? 'unknown error' ) );
		}

		$data = json_decode( $result['data'], true );
		if ( empty( $data['items'][0]['id'] ) ) {
			return new \WP_Error( 'youtube_no_channel', 'No YouTube channel found for this Google account. Create a channel at youtube.com first.' );
		}

		return array(
			'id'    => (string) $data['items'][0]['id'],
			'title' => (string) ( $data['items'][0]['snippet']['title'] ?? '' ),
		);
	}

	/**
	 * Perform the Google-specific token refresh.
	 *
	 * Google 1-hour access tokens are refreshed via the refresh_token grant.
	 * Google does NOT echo a new refresh_token on refresh, so the stored
	 * refresh_token is preserved by the caller (BaseOAuth2Provider merges the
	 * refreshed access_token into the existing account array).
	 *
	 * @param string $current_token The current (expired/near-expiry) access token.
	 * @return array|\WP_Error Token data on success.
	 */
	protected function do_refresh_token( string $current_token ): array|\WP_Error {
		$account = $this->get_account();
		if ( empty( $account['refresh_token'] ) ) {
			return new \WP_Error( 'youtube_refresh_no_token', 'YouTube: No refresh token available. Re-authenticate in Data Machine > Settings > Auth.' );
		}

		$config        = $this->get_config();
		$client_id     = $config['client_id'] ?? '';
		$client_secret = $config['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new \WP_Error( 'youtube_refresh_missing_config', 'YouTube: Missing OAuth configuration for refresh.' );
		}

		$result = HttpClient::post(
			self::TOKEN_URL,
			array(
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $account['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
				'context' => 'YouTube OAuth',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'youtube_refresh_http_error', 'YouTube token refresh request failed: ' . ( $result['error'] ?? 'unknown error' ) );
		}

		$data = json_decode( $result['data'], true );
		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'youtube_refresh_no_access_token', 'YouTube: No access token in refresh response.' );
		}

		return array(
			'access_token' => $data['access_token'],
			'expires_at'   => time() + intval( $data['expires_in'] ?? 3600 ),
		);
	}

	/**
	 * Google access tokens expire in 1 hour — use a 5-minute refresh buffer.
	 *
	 * @return int Buffer in seconds.
	 */
	protected function get_refresh_buffer_seconds(): int {
		return 300;
	}

	/**
	 * YouTube requires both an access_token and a refresh_token.
	 *
	 * @return bool True if authenticated.
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['refresh_token'] ) ) {
			return false;
		}

		return parent::is_authenticated();
	}

	/**
	 * Get the stored YouTube channel ID.
	 *
	 * @return string|null Channel ID or null.
	 */
	public function get_channel_id(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['channel_id'] ) ) {
			return null;
		}
		return (string) $account['channel_id'];
	}

	/**
	 * Get stored YouTube account details.
	 *
	 * @return array|null Account details or null.
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return null;
		}
		return $account;
	}

	/**
	 * Remove stored YouTube account details.
	 *
	 * @return bool Success status.
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
