<?php
/**
 * Handles Reddit OAuth 2.0 Authorization Code Grant flow.
 *
 * Migrated from data-machine core to data-machine-socials.
 * Modernized to use BaseOAuth2Provider::do_refresh_token() lifecycle
 * instead of the legacy refresh_token() override.
 *
 * Reddit tokens expire in 1 hour, so the refresh buffer is set to 5 minutes.
 * Reddit requires Basic Auth (client_id:client_secret) for token exchange
 * and refresh requests.
 *
 * @package    DataMachineSocials
 * @subpackage Handlers\Reddit
 * @since      0.3.0
 */

namespace DataMachineSocials\Handlers\Reddit;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RedditAuth extends \DataMachine\Core\OAuth\BaseOAuth2Provider {

	public function __construct() {
		parent::__construct( 'reddit' );
	}

	/**
	 * Get configuration fields required for Reddit authentication
	 *
	 * @return array Configuration field definitions
	 */
	public function get_config_fields(): array {
		return array(
			'client_id'          => array(
				'label'       => __( 'Client ID', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Reddit application Client ID from reddit.com/prefs/apps', 'data-machine-socials' ),
			),
			'client_secret'      => array(
				'label'       => __( 'Client Secret', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Reddit application Client Secret from reddit.com/prefs/apps', 'data-machine-socials' ),
			),
			'developer_username' => array(
				'label'       => __( 'Developer Username', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Reddit username that is registered in the Reddit app configuration', 'data-machine-socials' ),
			),
		);
	}

	/**
	 * Get the authorization URL for Reddit OAuth
	 *
	 * @return string Authorization URL
	 */
	public function get_authorization_url(): string {
		$config    = $this->get_config();
		$client_id = $config['client_id'] ?? '';

		if ( empty( $client_id ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Reddit OAuth Error: Client ID not configured.',
				array(
					'handler'   => 'reddit',
					'operation' => 'get_authorization_url',
				)
			);
			return '';
		}

		// Create state via OAuth2Handler
		$state = $this->oauth2->create_state( 'reddit' );

		// Build authorization URL with Reddit-specific parameters
		$params = array(
			'client_id'     => $client_id,
			'response_type' => 'code',
			'state'         => $state,
			'redirect_uri'  => $this->get_callback_url(),
			'duration'      => 'permanent', // Reddit-specific: request refresh token
			'scope'         => 'identity read', // Reddit-specific scopes
		);

		return $this->oauth2->get_authorization_url( 'https://www.reddit.com/api/v1/authorize', $params );
	}

	/**
	 * Handle OAuth callback from Reddit
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter provides CSRF protection via OAuth2Handler
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		// Get configuration
		$config             = $this->get_config();
		$client_id          = $config['client_id'] ?? '';
		$client_secret      = $config['client_secret'] ?? '';
		$developer_username = $config['developer_username'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $developer_username ) ) {
			do_action( 'datamachine_log', 'error', 'Reddit OAuth Error: Missing configuration' );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'datamachine-settings',
						'auth_error' => 'missing_config',
						'provider'   => 'reddit',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Prepare token exchange parameters (Reddit-specific)
		$token_params = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $this->get_callback_url(),
		);

		// Reddit requires Basic Auth for token exchange
		$token_params['headers'] = array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
			'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
			'User-Agent'    => 'php:DataMachineWPPlugin:v' . DATAMACHINE_VERSION . ' (by /u/' . $developer_username . ')',
			'Content-Type'  => 'application/x-www-form-urlencoded',
		);

		// Use OAuth2Handler for token exchange and callback handling
		$this->oauth2->handle_callback(
			'reddit',
			'https://www.reddit.com/api/v1/access_token',
			$token_params,
			function ( $token_data ) use ( $developer_username ) {
				// Reddit-specific: Get user identity
				return $this->get_reddit_user_identity( $token_data, $developer_username );
			},
			null,
			function ( $account_data ) {
				$this->save_account( $account_data );
				$this->schedule_proactive_refresh();
			}
		);
	}

	/**
	 * Get Reddit user identity (Reddit-specific logic)
	 *
	 * @param array  $token_data Token data from Reddit
	 * @param string $developer_username Developer username for User-Agent
	 * @return array Account data
	 */
	private function get_reddit_user_identity( array $token_data, string $developer_username ): array {
		$access_token     = $token_data['access_token'];
		$refresh_token    = $token_data['refresh_token'] ?? null;
		$expires_in       = $token_data['expires_in'] ?? 3600;
		$scope_granted    = $token_data['scope'] ?? '';
		$token_expires_at = time() + intval( $expires_in );

		// Get user identity from Reddit API
		$identity_url    = 'https://oauth.reddit.com/api/v1/me';
		$identity_result = HttpClient::get(
			$identity_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'User-Agent'    => 'php:DataMachineWPPlugin:v' . DATAMACHINE_VERSION . ' (by /u/' . $developer_username . ')',
				),
				'context' => 'Reddit Authentication',
			)
		);

		$identity_username = null;
		if ( $identity_result['success'] && 200 === $identity_result['status_code'] ) {
			$identity_data     = json_decode( $identity_result['data'], true );
			$identity_username = $identity_data['name'] ?? null;

			if ( empty( $identity_username ) ) {
				do_action( 'datamachine_log', 'warning', 'Reddit OAuth Warning: Could not get username from /api/v1/me' );
			}
		} else {
			do_action( 'datamachine_log', 'warning', 'Reddit OAuth Warning: Failed to get user identity after token exchange' );
		}

		// Return account data for storage
		return array(
			'username'          => $identity_username,
			'access_token'      => $access_token,
			'refresh_token'     => $refresh_token,
			'token_expires_at'  => $token_expires_at,
			'scope'             => $scope_granted,
			'last_refreshed_at' => time(),
		);
	}

	/**
	 * Perform Reddit-specific token refresh.
	 *
	 * Reddit access tokens expire in 1 hour. Refresh requires Basic Auth with
	 * client_id:client_secret, and the refresh_token from the original authorization.
	 * Reddit may return a new refresh_token, which must be stored.
	 *
	 * @since 0.3.0
	 * @param string $current_token The current access token (not used for Reddit refresh — uses refresh_token instead).
	 * @return array|\WP_Error|null Token data on success, WP_Error on failure.
	 */
	protected function do_refresh_token( string $current_token ): array|\WP_Error|null {
		$account = $this->get_account();
		if ( empty( $account['refresh_token'] ) ) {
			return new \WP_Error( 'reddit_refresh_no_token', 'Reddit: No refresh token available' );
		}

		$config             = $this->get_config();
		$client_id          = $config['client_id'] ?? '';
		$client_secret      = $config['client_secret'] ?? '';
		$developer_username = $config['developer_username'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $developer_username ) ) {
			return new \WP_Error( 'reddit_refresh_missing_config', 'Reddit: Missing OAuth configuration for refresh' );
		}

		$token_url = 'https://www.reddit.com/api/v1/access_token';
		$result    = HttpClient::post(
			$token_url,
			array(
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'User-Agent'    => 'php:DataMachineWPPlugin:v' . DATAMACHINE_VERSION . ' (by /u/' . $developer_username . ')',
				),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $account['refresh_token'],
				),
				'context' => 'Reddit OAuth',
			)
		);

		if ( ! $result['success'] || 200 !== $result['status_code'] ) {
			return new \WP_Error(
				'reddit_refresh_api_error',
				'Reddit: Token refresh request failed',
				array(
					'status_code' => $result['status_code'] ?? 'unknown',
					'error'       => $result['error'] ?? 'unknown',
				)
			);
		}

		$data = json_decode( $result['data'], true );
		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'reddit_refresh_no_access_token', 'Reddit: No access token in refresh response' );
		}

		$expires_in = intval( $data['expires_in'] ?? 3600 );

		// If Reddit returned a new refresh_token, update it in the stored account.
		if ( ! empty( $data['refresh_token'] ) && $data['refresh_token'] !== $account['refresh_token'] ) {
			$account['refresh_token'] = $data['refresh_token'];
			$account['scope']         = $data['scope'] ?? $account['scope'] ?? '';
			$this->save_account( $account );
		}

		return array(
			'access_token' => $data['access_token'],
			'expires_at'   => time() + $expires_in,
		);
	}

	/**
	 * Get the number of seconds before token expiry to trigger a refresh.
	 *
	 * Reddit tokens expire in 1 hour — use a 5-minute buffer.
	 *
	 * @since 0.3.0
	 * @return int Buffer in seconds.
	 */
	protected function get_refresh_buffer_seconds(): int {
		return 300;
	}

	/**
	 * Check if admin has valid Reddit authentication.
	 *
	 * Reddit requires both an access_token and a refresh_token.
	 * Also checks expiry via parent.
	 *
	 * @return bool True if authenticated
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['refresh_token'] ) ) {
			return false;
		}

		return parent::is_authenticated();
	}

	/**
	 * Get Reddit account details
	 *
	 * @return array|null Account details or null if not authenticated
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return null;
		}

		return $account;
	}

	/**
	 * Remove stored Reddit account details
	 *
	 * @return bool Success status
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
