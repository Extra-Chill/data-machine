<?php
/**
 * Pinterest authentication provider using bearer token.
 *
 * Simple credential-based auth with a Pinterest API v5 access token.
 * Token is stored in WordPress options via BaseAuthProvider.
 *
 * @package DataMachine\Core\Steps\Publish\Handlers\Pinterest
 * @since 0.3.0
 */

namespace DataMachine\Core\Steps\Publish\Handlers\Pinterest;

use DataMachine\Core\OAuth\BaseAuthProvider;
use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pinterest Auth Provider
 *
 * Manages Pinterest API bearer token authentication.
 * Verifies token validity by calling the user account endpoint.
 */
class PinterestAuth extends BaseAuthProvider {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'pinterest' );
	}

	/**
	 * Check if Pinterest is authenticated.
	 *
	 * @return bool True if access token is configured.
	 */
	public function is_authenticated(): bool {
		$config = $this->get_config();
		return ! empty( $config['access_token'] );
	}

	/**
	 * Get configuration fields for the settings UI.
	 *
	 * @return array Field definitions for Pinterest auth.
	 */
	public function get_config_fields(): array {
		return array(
			'access_token' => array(
				'label'       => __( 'Access Token', 'data-machine' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Pinterest API access token from developers.pinterest.com', 'data-machine' ),
			),
		);
	}

	/**
	 * Get account details by verifying token with Pinterest API.
	 *
	 * @return array|null Account details or null on failure.
	 */
	public function get_account_details(): ?array {
		$config = $this->get_config();
		$token  = $config['access_token'] ?? '';

		if ( empty( $token ) ) {
			return null;
		}

		$result = HttpClient::get(
			'https://api.pinterest.com/v5/user_account',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'context' => 'Pinterest Account Verification',
			)
		);

		if ( ! $result['success'] ) {
			return null;
		}

		$data = json_decode( $result['data'] ?? '', true );

		if ( empty( $data['username'] ) ) {
			return null;
		}

		return array(
			'username'   => $data['username'],
			'configured' => true,
		);
	}

	/**
	 * Remove Pinterest account credentials.
	 *
	 * @return bool True on success.
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
