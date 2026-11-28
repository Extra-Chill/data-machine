<?php
/**
 * Base OAuth 1.0a Provider Class
 *
 * Abstract base class for OAuth 1.0a providers.
 * Standardizes configuration, authentication checks, and callback handling.
 *
 * @package DataMachine
 * @subpackage Core\OAuth
 * @since 0.2.6
 */

namespace DataMachine\Core\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseOAuth1Provider extends BaseAuthProvider {

	/**
	 * @var OAuth1Handler OAuth1 handler instance
	 */
	protected $oauth1;

	/**
	 * Constructor
	 *
	 * @param string $provider_slug Provider identifier
	 */
	public function __construct( string $provider_slug ) {
		parent::__construct( $provider_slug );
		$this->oauth1 = new OAuth1Handler();
	}

	/**
	 * Check if provider is properly configured
	 *
	 * @return bool True if configured
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['api_key'] ) && ! empty( $config['api_secret'] );
	}

	/**
	 * Check if authenticated
	 *
	 * @return bool True if authenticated
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		return ! empty( $account ) &&
				is_array( $account ) &&
				! empty( $account['access_token'] ) &&
				! empty( $account['access_token_secret'] );
	}

	/**
	 * Get authorization URL (Abstract)
	 *
	 * @return string Authorization URL
	 */
	abstract public function get_authorization_url(): string;

	/**
	 * Handle OAuth callback (Abstract)
	 */
	abstract public function handle_oauth_callback();
}
