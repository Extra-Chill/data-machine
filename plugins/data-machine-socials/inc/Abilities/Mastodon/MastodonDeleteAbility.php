<?php
/**
 * Mastodon Delete Ability
 *
 * Abilities API primitive for deleting Mastodon statuses.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Mastodon
 * @since      0.17.0
 */

namespace DataMachineSocials\Abilities\Mastodon;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Mastodon\MastodonAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class MastodonDeleteAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/mastodon-delete',
				array(
					'label'               => __( 'Delete Mastodon Statuses', 'data-machine-socials' ),
					'description'         => __( 'Delete your own Mastodon statuses', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'status_id' ),
						'properties' => array(
							'status_id' => array(
								'type'        => 'string',
								'description' => __( 'ID of the status to delete', 'data-machine-socials' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		if ( empty( $input['status_id'] ) ) {
			return new \WP_Error( 'missing_param', 'status_id is required', array( 'status' => 400 ) );
		}

		$provider = $this->getAuthProvider();
		if ( ! $provider ) {
			return new \WP_Error( 'missing_auth', 'Mastodon auth provider not available', array( 'status' => 401 ) );
		}

		$instance = $provider->get_instance();
		$token    = $provider->get_access_token();

		if ( empty( $instance ) || empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Mastodon not configured', array( 'status' => 401 ) );
		}

		$status_id = $input['status_id'];
		$url       = $instance . '/api/v1/statuses/' . rawurlencode( $status_id );

		$result = HttpClient::delete(
			$url,
			array(
				'context' => 'Mastodon Delete',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 20,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'status_id' => $status_id,
					'deleted'   => true,
				),
			);
		}

		$error_msg = $result['error'] ?? 'Failed to delete status';

		if ( ! empty( $result['data'] ) ) {
			$body = json_decode( $result['data'], true );
			if ( is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) ) {
				$error_msg = $body['error'];
			}
		}

		return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
	}

	private function getAuthProvider(): ?MastodonAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth     = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'mastodon' );

		if ( ! $provider instanceof MastodonAuth ) {
			return null;
		}

		return $provider;
	}
}
