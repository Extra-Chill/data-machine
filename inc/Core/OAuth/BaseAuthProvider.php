<?php
/**
 * Base Authentication Provider
 *
 * Abstract base class for all authentication providers.
 * Centralizes option storage, retrieval, and common configuration logic.
 *
 * Auth data is stored via get_site_option()/update_site_option() so credentials
 * are shared across all subsites on a multisite network. On single-site installs,
 * these behave identically to get_option()/update_option().
 *
 * @package DataMachine
 * @subpackage Core\OAuth
 * @since 0.2.6
 */

namespace DataMachine\Core\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseAuthProvider {

	/**
	 * @var string Provider slug (e.g., 'twitter', 'facebook')
	 */
	protected $provider_slug;

	/**
	 * Constructor
	 *
	 * @param string $provider_slug Provider identifier
	 */
	public function __construct( string $provider_slug ) {
		$this->provider_slug = $provider_slug;
	}

	/**
	 * Get configuration fields (Abstract)
	 *
	 * @return array Configuration field definitions
	 */
	abstract public function get_config_fields(): array;

	/**
	 * Check if authenticated (Abstract)
	 *
	 * @return bool True if authenticated
	 */
	abstract public function is_authenticated(): bool;

	/**
	 * Check if provider is properly configured
	 *
	 * @return bool True if configured
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config );
	}

	/**
	 * Get the callback URL for this provider.
	 *
	 * @since 0.67.0
	 *
	 * @return string Callback URL
	 */
	public function get_callback_url(): string {
		$url = site_url( "/datamachine-auth/{$this->provider_slug}/" );

		/**
		 * Filters the OAuth callback URL for an auth provider.
		 *
		 * Allows plugins to customize the callback URL to match their
		 * OAuth client's registered redirect URI.
		 *
		 * @since 0.67.0
		 *
		 * @param string $url           The default callback URL.
		 * @param string $provider_slug The provider slug (e.g. 'wpcom', 'twitter').
		 */
		return apply_filters( 'datamachine_oauth_callback_url', $url, $this->provider_slug );
	}

	/**
	 * Get OAuth account data directly from options.
	 *
	 * @return array Account data or empty array
	 */
	public function get_account(): array {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		return $all_auth_data[ $this->provider_slug ]['account'] ?? array();
	}

	/**
	 * Get OAuth configuration keys directly from options.
	 *
	 * @return array Configuration data or empty array
	 */
	public function get_config(): array {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		return $all_auth_data[ $this->provider_slug ]['config'] ?? array();
	}

	/**
	 * Store OAuth account data directly in options.
	 *
	 * @param array $data Account data to store
	 * @return bool True on success
	 */
	public function save_account( array $data ): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( ! isset( $all_auth_data[ $this->provider_slug ] ) ) {
			$all_auth_data[ $this->provider_slug ] = array();
		}
		$all_auth_data[ $this->provider_slug ]['account'] = $data;
		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	/**
	 * Store OAuth configuration keys directly in options.
	 *
	 * @param array $data Configuration data to store
	 * @return bool True on success
	 */
	public function save_config( array $data ): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( ! isset( $all_auth_data[ $this->provider_slug ] ) ) {
			$all_auth_data[ $this->provider_slug ] = array();
		}
		$all_auth_data[ $this->provider_slug ]['config'] = $data;
		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	/**
	 * Clear OAuth account data from options.
	 *
	 * @return bool True on success
	 */
	public function clear_account(): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( isset( $all_auth_data[ $this->provider_slug ]['account'] ) ) {
			unset( $all_auth_data[ $this->provider_slug ]['account'] );
			return update_site_option( 'datamachine_auth_data', $all_auth_data );
		}
		return true;
	}

	/**
	 * Get the authenticated username for this provider.
	 *
	 * All providers should store username under the canonical 'username'
	 * key in account data. Override only if the provider stores it
	 * elsewhere (e.g. in config rather than account).
	 *
	 * @since 0.2.7
	 * @return string|null Username or null if not available
	 */
	public function get_username(): ?string {
		$account = $this->get_account();
		return ! empty( $account['username'] ) ? $account['username'] : null;
	}

	/**
	 * Get account details for display (Optional)
	 *
	 * @return array|null Account details or null
	 */
	public function get_account_details(): ?array {
		return $this->get_account();
	}
}
