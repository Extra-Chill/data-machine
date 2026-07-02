<?php
/**
 * Generic HTTP Basic authentication provider.
 *
 * @package DataMachine\Core\OAuth
 */

namespace DataMachine\Core\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Stores one named HTTP Basic credential as an encrypted Data Machine auth ref.
 */
final class HttpBasicAuthProvider extends BaseAuthProvider {

	public const PROVIDER_SLUG = 'http_basic';

	public function __construct() {
		parent::__construct( self::PROVIDER_SLUG );
	}

	/**
	 * Get configuration fields for CLI/UI credential entry.
	 */
	public function get_config_fields(): array {
		return array(
			'account'   => array(
				'label'       => __( 'Account', 'data-machine' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Local auth ref account name, for example logstash.', 'data-machine' ),
			),
			'username'  => array(
				'label'    => __( 'Username', 'data-machine' ),
				'type'     => 'text',
				'required' => true,
			),
			'password'  => array(
				'label'    => __( 'Password', 'data-machine' ),
				'type'     => 'password',
				'required' => true,
			),
			'proxy_url' => array(
				'label'       => __( 'Proxy URL', 'data-machine' ),
				'type'        => 'url',
				'required'    => false,
				'description' => __( 'Optional per-request proxy URL.', 'data-machine' ),
			),
		);
	}

	/**
	 * Whether a usable credential is stored.
	 */
	public function is_authenticated(): bool {
		$config = $this->get_config();
		return ! empty( $config['account'] ) && ! empty( $config['username'] ) && ! empty( $config['password'] );
	}

	/**
	 * Resolve http_basic:<account> into HttpClient auth/proxy options.
	 */
	public function resolve_auth_ref( string $account, string $handler_slug = '', array $context = array() ): array|\WP_Error {
		unset( $handler_slug, $context );

		$config = $this->get_config();
		if ( (string) ( $config['account'] ?? '' ) !== $account || empty( $config['username'] ) || empty( $config['password'] ) ) {
			return new \WP_Error(
				'auth_ref_unresolved',
				sprintf(
					/* translators: %s: auth ref account. */
					__( 'No HTTP Basic credential is configured for auth ref "%s".', 'data-machine' ),
					$account
				)
			);
		}

		$resolved = array(
			'auth' => array(
				'type'     => 'basic',
				'username' => (string) $config['username'],
				'password' => (string) $config['password'],
			),
		);

		if ( ! empty( $config['proxy_url'] ) ) {
			$resolved['proxy_url'] = (string) $config['proxy_url'];
		}

		return $resolved;
	}
}
