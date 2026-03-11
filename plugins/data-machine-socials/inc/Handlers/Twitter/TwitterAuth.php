<?php
/**
 * Twitter OAuth 1.0a authentication handler.
 *
 * Refactored to use centralized OAuth1Handler for standardized OAuth 1.0a flow.
 * Maintains Twitter-specific connection management via TwitterOAuth library.
 *
 * @package DataMachineSocials\Handlers\Twitter
 * @since 0.1.0
 */

namespace DataMachineSocials\Handlers\Twitter;

use Abraham\TwitterOAuth\TwitterOAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TwitterAuth extends \DataMachine\Core\OAuth\BaseOAuth1Provider {

	public function __construct() {
		parent::__construct( 'twitter' );
	}

	/**
	 * Check if Twitter authentication is valid.
	 *
	 * @return bool True if authenticated
	 */
	public function is_authenticated(): bool {
		return parent::is_authenticated();
	}

	/**
	 * Get Twitter configuration field definitions.
	 *
	 * @return array Configuration fields
	 */
	public function get_config_fields(): array {
		return array(
			'api_key'    => array(
				'label'       => __( 'API Key', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Twitter application API key from developer.twitter.com', 'data-machine-socials' ),
			),
			'api_secret' => array(
				'label'       => __( 'API Secret', 'data-machine-socials' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your Twitter application API secret from developer.twitter.com', 'data-machine-socials' ),
			),
		);
	}

	/**
	 * Check if Twitter API credentials are configured.
	 *
	 * @return bool True if configured
	 */
	public function is_configured(): bool {
		return parent::is_configured();
	}

	/**
	 * Get authenticated TwitterOAuth connection.
	 *
	 * @return TwitterOAuth|\WP_Error Connection or error
	 */
	public function get_connection() {
		$credentials = $this->get_account();
		if ( empty( $credentials ) || empty( $credentials['access_token'] ) || empty( $credentials['access_token_secret'] ) ) {
			do_action( 'datamachine_log', 'error', 'Missing Twitter credentials in options.' );
			return new \WP_Error( 'twitter_missing_credentials', __( 'Twitter credentials not found. Please authenticate.', 'data-machine-socials' ) );
		}

		$access_token        = $credentials['access_token'];
		$access_token_secret = $credentials['access_token_secret'];

		$config          = $this->get_config();
		$consumer_key    = $config['api_key'] ?? '';
		$consumer_secret = $config['api_secret'] ?? '';

		if ( empty( $consumer_key ) || empty( $consumer_secret ) ) {
			do_action( 'datamachine_log', 'error', 'Missing Twitter API key/secret in site options.' );
			return new \WP_Error( 'twitter_missing_app_keys', __( 'Twitter application keys are not configured in plugin settings.', 'data-machine-socials' ) );
		}

		try {
			$connection = new TwitterOAuth( $consumer_key, $consumer_secret, $access_token, $access_token_secret );
			return $connection;
		} catch ( \Exception $e ) {
			do_action( 'datamachine_log', 'error', 'Exception creating TwitterOAuth connection: ' . $e->getMessage() );
			return new \WP_Error( 'twitter_connection_exception', __( 'Could not establish connection to Twitter.', 'data-machine-socials' ) );
		}
	}

	/**
	 * Get Twitter OAuth authorization URL.
	 *
	 * @return string Authorization URL
	 */
	public function get_authorization_url(): string {
		$config     = $this->get_config();
		$api_key    = $config['api_key'] ?? '';
		$api_secret = $config['api_secret'] ?? '';

		if ( empty( $api_key ) || empty( $api_secret ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Twitter OAuth Error: API Key/Secret not configured.',
				array(
					'handler'   => 'twitter',
					'operation' => 'get_authorization_url',
				)
			);
			return '';
		}

		$callback_url = $this->get_callback_url();

		// Get request token via OAuth1Handler
		$request_token = $this->oauth1->get_request_token(
			'https://api.twitter.com/oauth/request_token',
			$api_key,
			$api_secret,
			$callback_url,
			'twitter'
		);

		if ( is_wp_error( $request_token ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Twitter OAuth Error: ' . $request_token->get_error_message(),
				array(
					'handler'   => 'twitter',
					'operation' => 'get_authorization_url',
				)
			);
			return '';
		}

		// Build authorization URL via OAuth1Handler
		return $this->oauth1->get_authorization_url(
			'https://api.twitter.com/oauth/authenticate',
			$request_token['oauth_token'],
			'twitter'
		);
	}

	/**
	 * Handle Twitter OAuth callback after user authorization.
	 */
	public function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( add_query_arg( 'auth_error', 'twitter_permission_denied', admin_url( 'admin.php?page=datamachine-settings' ) ) );
			exit;
		}

		$config     = $this->get_config();
		$api_key    = $config['api_key'] ?? '';
		$api_secret = $config['api_secret'] ?? '';

		if ( empty( $api_key ) || empty( $api_secret ) ) {
			do_action( 'datamachine_log', 'error', 'Twitter OAuth Error: API Key/Secret missing during callback.' );
			wp_safe_redirect( add_query_arg( 'auth_error', 'twitter_missing_app_keys', admin_url( 'admin.php?page=datamachine-settings' ) ) );
			exit;
		}

		// Use OAuth1Handler for complete callback flow
		$this->oauth1->handle_callback(
			'twitter',
			'https://api.twitter.com/oauth/access_token',
			$api_key,
			$api_secret,
			function ( $access_token_data ) {
				// Build account data from Twitter response
				return array(
					'access_token'        => $access_token_data['oauth_token'],
					'access_token_secret' => $access_token_data['oauth_token_secret'],
					'user_id'             => $access_token_data['user_id'] ?? null,
					'screen_name'         => $access_token_data['screen_name'] ?? null,
					'last_verified_at'    => time(),
				);
			},
			array( $this, 'save_account' )
		);
	}

	/**
	 * Get stored Twitter account details.
	 *
	 * @return array|null Account details or null
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) || empty( $account['access_token_secret'] ) ) {
			return null;
		}
		return $account;
	}

	/**
	 * Remove stored Twitter account details.
	 *
	 * @return bool Success status
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
