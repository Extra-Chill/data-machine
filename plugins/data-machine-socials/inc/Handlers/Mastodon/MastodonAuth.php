<?php
/**
 * Handles Mastodon (Fediverse) authentication for the publish handler.
 *
 * Instance-agnostic OAuth2 bearer-token authentication. The operator provides
 * their own instance base URL and a user OAuth access token (obtained via the
 * instance's Development settings or a standard OAuth2 authorization flow).
 * The access token is stored in config and encrypted at rest by
 * BaseAuthProvider::encrypt_fields() (access_token is in ENCRYPTED_FIELDS).
 *
 * @package DataMachineSocials
 * @subpackage Handlers\Mastodon
 * @since 0.17.0
 */

namespace DataMachineSocials\Handlers\Mastodon;

use DataMachine\Core\OAuth\BaseAuthProvider;
use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class MastodonAuth extends BaseAuthProvider {

	/**
	 * Default character limit for statuses.
	 *
	 * Mastodon ships with 500 but this is instance-configurable. We use this as
	 * a sensible default for UI hints and validation; the actual limit is
	 * queried at runtime from the instance configuration when available.
	 */
	public const DEFAULT_CHAR_LIMIT = 500;

	public function __construct() {
		parent::__construct( 'mastodon' );
	}

	public function is_authenticated(): bool {
		$config = $this->get_config();
		return ! empty( $config ) &&
			! empty( $config['instance'] ) &&
			! empty( $config['access_token'] );
	}

	/**
	 * Get the configured instance base URL (normalized, no trailing slash).
	 *
	 * @return string|null Instance URL or null if not configured.
	 */
	public function get_instance(): ?string {
		$config   = $this->get_config();
		$instance = $config['instance'] ?? '';

		if ( empty( $instance ) ) {
			return null;
		}

		return $this->normalize_instance( $instance );
	}

	/**
	 * Get the configured access token (decrypted).
	 *
	 * @return string|null Token or null if not configured.
	 */
	public function get_access_token(): ?string {
		$config = $this->get_config();

		return ! empty( $config['access_token'] ) ? $config['access_token'] : null;
	}

	public function get_config_fields(): array {
		return array(
			'instance'     => array(
				'label'       => __( 'Instance URL', 'data-machine-socials' ),
				'type'        => 'url',
				'required'    => true,
				'description' => __( 'Your Mastodon / Fediverse instance base URL (e.g. https://mastodon.social, https://mas.to, https://your-instance.example). Any software implementing the Mastodon API works.', 'data-machine-socials' ),
			),
			'access_token' => array(
				'label'       => __( 'Access Token', 'data-machine-socials' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Create an application in your instance under Settings → Development → New application, then copy the access token. Required scopes: read write.', 'data-machine-socials' ),
			),
		);
	}

	/**
	 * Get the authenticated account details by verifying the token.
	 *
	 * Calls GET /api/v1/accounts/verify_credentials to confirm the token is
	 * valid and return the account profile. The result is cached per-request
	 * via a static to avoid repeated verification calls.
	 *
	 * @return array|null Account details or null if not authenticated/verifiable.
	 */
	public function get_account_details(): ?array {
		$instance = $this->get_instance();
		$token    = $this->get_access_token();

		if ( empty( $instance ) || empty( $token ) ) {
			return null;
		}

		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$result = HttpClient::get(
			$instance . '/api/v1/accounts/verify_credentials',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'context' => 'Mastodon Verify Credentials',
				'timeout' => 15,
			)
		);

		if ( empty( $result['success'] ) ) {
			$cached = null;
			return null;
		}

		$data = json_decode( $result['data'], true );

		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			$cached = null;
			return null;
		}

		$cached = array(
			'id'           => $data['id'] ?? '',
			'username'     => $data['username'] ?? '',
			'acct'         => $data['acct'] ?? '',
			'display_name' => $data['display_name'] ?? '',
			'url'          => $data['url'] ?? '',
			'configured'   => true,
			'instance'     => $instance,
		);

		return $cached;
	}

	/**
	 * Get the account ID from the verified credentials.
	 *
	 * @return string|null Account ID or null.
	 */
	public function get_account_id(): ?string {
		$details = $this->get_account_details();
		return $details['id'] ?? null;
	}

	public function get_username(): ?string {
		$details = $this->get_account_details();
		return $details['acct'] ?? null;
	}

	/**
	 * Register a new application on the target instance.
	 *
	 * Helper for the CLI bootstrap. Calls POST /api/v1/apps to obtain a
	 * client_id and client_secret. The operator then completes the OAuth flow
	 * out of band to obtain the access token.
	 *
	 * @param string $instance   Instance base URL.
	 * @param string $client_name Application name to register.
	 * @param string $redirect_uris Comma-separated redirect URIs.
	 * @param string $scopes      OAuth scopes (space-separated).
	 * @return array|\WP_Error App registration data or error.
	 */
	public static function register_app(
		string $instance,
		string $client_name = 'Data Machine Socials',
		string $redirect_uris = 'urn:ietf:wg:oauth:2.0:oob',
		string $scopes = 'read write'
	) {
		$instance = self::normalize_instance( $instance );

		$body = array(
			'client_name'   => $client_name,
			'redirect_uris' => $redirect_uris,
			'scopes'        => $scopes,
			'website'       => home_url(),
		);

		$result = HttpClient::post(
			$instance . '/api/v1/apps',
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $body ),
				'context' => 'Mastodon App Registration',
				'timeout' => 15,
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'mastodon_register_failed',
				'App registration failed: ' . ( $result['error'] ?? 'unknown error' )
			);
		}

		$data = json_decode( $result['data'], true );

		if ( empty( $data['client_id'] ) ) {
			return new \WP_Error(
				'mastodon_register_incomplete',
				'App registration succeeded but response was incomplete.'
			);
		}

		return $data;
	}

	/**
	 * Build the OAuth authorize URL for the manual authorization step.
	 *
	 * @param string $instance    Instance base URL.
	 * @param string $client_id   Client ID from register_app().
	 * @param string $scopes      OAuth scopes.
	 * @return string Authorize URL.
	 */
	public static function build_authorize_url(
		string $instance,
		string $client_id,
		string $scopes = 'read write'
	): string {
		$instance = self::normalize_instance( $instance );
		$params   = array(
			'client_id'     => $client_id,
			'redirect_uri'  => 'urn:ietf:wg:oauth:2.0:oob',
			'response_type' => 'code',
			'scope'         => $scopes,
		);

		return $instance . '/oauth/authorize?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for an access token.
	 *
	 * @param string $instance      Instance base URL.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $code          Authorization code from the redirect.
	 * @param string $redirect_uri  Redirect URI (must match the authorize step).
	 * @return array|\WP_Error Token data or error.
	 */
	public static function exchange_code(
		string $instance,
		string $client_id,
		string $client_secret,
		string $code,
		string $redirect_uri = 'urn:ietf:wg:oauth:2.0:oob'
	) {
		$instance = self::normalize_instance( $instance );

		$body = array(
			'grant_type'    => 'authorization_code',
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'redirect_uri'  => $redirect_uri,
			'code'          => $code,
			'scope'         => 'read write',
		);

		$result = HttpClient::post(
			$instance . '/oauth/token',
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $body ),
				'context' => 'Mastodon OAuth Token Exchange',
				'timeout' => 15,
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'mastodon_token_exchange_failed',
				'Token exchange failed: ' . ( $result['error'] ?? 'unknown error' )
			);
		}

		$data = json_decode( $result['data'], true );

		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error(
				'mastodon_token_exchange_incomplete',
				'Token exchange succeeded but no access_token was returned.'
			);
		}

		return $data;
	}

	public function remove_account(): bool {
		return $this->clear_account();
	}

	/**
	 * Normalize an instance URL: ensure https scheme, no trailing slash.
	 *
	 * @param string $instance Raw instance input.
	 * @return string Normalized instance URL.
	 */
	public static function normalize_instance( string $instance ): string {
		$instance = trim( $instance );

		if ( ! preg_match( '#^https?://#i', $instance ) ) {
			$instance = 'https://' . $instance;
		}

		return rtrim( $instance, '/' );
	}
}
