<?php
/**
 * Email IMAP Authentication Provider
 *
 * Stores IMAP credentials (host, port, user, app password) using
 * Data Machine's auth data storage. Not OAuth — just encrypted
 * credential management for IMAP connections.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\Email
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Email;

use DataMachine\Core\OAuth\BaseAuthProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmailAuth extends BaseAuthProvider {

	public function __construct() {
		parent::__construct( 'email_imap' );
	}

	/**
	 * Get configuration fields for IMAP setup.
	 *
	 * @return array Configuration field definitions.
	 */
	public function get_config_fields(): array {
		return array(
			'imap_host'       => array(
				'type'        => 'text',
				'label'       => __( 'IMAP Host', 'data-machine' ),
				'placeholder' => 'imap.gmail.com',
				'description' => __( 'IMAP server hostname.', 'data-machine' ),
				'required'    => true,
			),
			'imap_port'       => array(
				'type'        => 'number',
				'label'       => __( 'IMAP Port', 'data-machine' ),
				'default'     => 993,
				'description' => __( 'IMAP server port. Usually 993 for SSL.', 'data-machine' ),
			),
			'imap_encryption' => array(
				'type'        => 'select',
				'label'       => __( 'Encryption', 'data-machine' ),
				'options'     => array(
					'ssl'  => 'SSL',
					'tls'  => 'TLS',
					'none' => __( 'None', 'data-machine' ),
				),
				'default'     => 'ssl',
				'description' => __( 'Connection encryption method.', 'data-machine' ),
			),
			'imap_user'       => array(
				'type'        => 'text',
				'label'       => __( 'Username', 'data-machine' ),
				'placeholder' => 'your-email@gmail.com',
				'description' => __( 'Your email address (used as IMAP username).', 'data-machine' ),
				'required'    => true,
			),
			'imap_password'   => array(
				'type'        => 'password',
				'label'       => __( 'App Password', 'data-machine' ),
				'description' => __( 'An app-specific password (not your account password). Generate one in your email provider\'s security settings.', 'data-machine' ),
				'required'    => true,
			),
		);
	}

	/**
	 * Check if IMAP credentials are configured.
	 *
	 * @return bool True if authenticated (credentials saved).
	 */
	public function is_authenticated(): bool {
		$config = $this->get_config();
		return ! empty( $config['imap_host'] )
			&& ! empty( $config['imap_user'] )
			&& ! empty( $config['imap_password'] );
	}

	/**
	 * Convert inline IMAP credentials to the install-local default ref.
	 *
	 * @param array  $handler_config Handler config being exported.
	 * @param string $handler_slug Handler slug.
	 * @param array  $context Export context.
	 * @return string|null Auth ref or null when config carries no IMAP credential shape.
	 */
	public function get_auth_ref_for_config( array $handler_config, string $handler_slug = '', array $context = array() ): ?string {
		unset( $handler_slug, $context );

		foreach ( array( 'imap_host', 'imap_user', 'imap_password' ) as $field ) {
			if ( ! empty( $handler_config[ $field ] ) ) {
				return 'email_imap:default';
			}
		}

		return null;
	}

	/**
	 * Resolve the default IMAP auth ref to local credentials.
	 *
	 * @param string $account Auth ref account/id segment.
	 * @param string $handler_slug Handler slug requesting credentials.
	 * @param array  $context Import/runtime context.
	 * @return array|\WP_Error Local IMAP config or failure.
	 */
	public function resolve_auth_ref( string $account, string $handler_slug = '', array $context = array() ): array|\WP_Error {
		unset( $handler_slug, $context );

		if ( 'default' !== $account ) {
			return new \WP_Error( 'auth_ref_unresolved', __( 'Email auth only supports the default local connection.', 'data-machine' ) );
		}

		if ( ! $this->is_authenticated() ) {
			return new \WP_Error( 'auth_ref_unresolved', __( 'Email IMAP credentials are not configured on this install.', 'data-machine' ) );
		}

		return $this->get_config();
	}

	/**
	 * Get IMAP host.
	 *
	 * @return string IMAP hostname.
	 */
	public function getHost(): string {
		$config = $this->get_config();
		return $config['imap_host'] ?? '';
	}

	/**
	 * Get IMAP port.
	 *
	 * @return int IMAP port number.
	 */
	public function getPort(): int {
		$config = $this->get_config();
		return (int) ( $config['imap_port'] ?? 993 );
	}

	/**
	 * Get IMAP encryption type.
	 *
	 * @return string Encryption type (ssl, tls, none).
	 */
	public function getEncryption(): string {
		$config = $this->get_config();
		return $config['imap_encryption'] ?? 'ssl';
	}

	/**
	 * Get IMAP username.
	 *
	 * @return string IMAP username.
	 */
	public function getUser(): string {
		$config = $this->get_config();
		return $config['imap_user'] ?? '';
	}

	/**
	 * Get IMAP password.
	 *
	 * @return string IMAP app password.
	 */
	public function getPassword(): string {
		$config = $this->get_config();
		return $config['imap_password'] ?? '';
	}

	/**
	 * Get account details for display.
	 *
	 * @return array|null Account display details.
	 */
	public function get_account_details(): ?array {
		if ( ! $this->is_authenticated() ) {
			return null;
		}

		$config = $this->get_config();
		return array(
			'email'      => $config['imap_user'] ?? '',
			'host'       => $config['imap_host'] ?? '',
			'port'       => $config['imap_port'] ?? 993,
			'encryption' => $config['imap_encryption'] ?? 'ssl',
		);
	}
}
