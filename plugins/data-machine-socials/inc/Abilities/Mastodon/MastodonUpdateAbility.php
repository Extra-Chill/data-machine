<?php
/**
 * Mastodon Update Ability
 *
 * Abilities API primitive for engagement actions on Mastodon: favourite,
 * unfavourite, reblog (boost), unreblog, bookmark, and unbookmark.
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

class MastodonUpdateAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/mastodon-update',
				array(
					'label'               => __( 'Engage on Mastodon', 'data-machine-socials' ),
					'description'         => __( 'Favourite, boost (reblog), or bookmark Mastodon statuses', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action', 'status_id' ),
						'properties' => array(
							'action'    => array(
								'type'        => 'string',
								'enum'        => array( 'favourite', 'unfavourite', 'reblog', 'unreblog', 'bookmark', 'unbookmark' ),
								'description' => __( 'Engagement action', 'data-machine-socials' ),
							),
							'status_id' => array(
								'type'        => 'string',
								'description' => __( 'ID of the status to act on', 'data-machine-socials' ),
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
		$action    = $input['action'] ?? '';
		$status_id = $input['status_id'] ?? '';

		if ( empty( $action ) ) {
			return new \WP_Error( 'missing_param', 'action is required', array( 'status' => 400 ) );
		}

		if ( empty( $status_id ) ) {
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

		// Map action to API endpoint path.
		$endpoint_map = array(
			'favourite'   => 'favourite',
			'unfavourite' => 'unfavourite',
			'reblog'      => 'reblog',
			'unreblog'    => 'unreblog',
			'bookmark'    => 'bookmark',
			'unbookmark'  => 'unbookmark',
		);

		if ( ! isset( $endpoint_map[ $action ] ) ) {
			return new \WP_Error( 'api_error', "Unknown action: {$action}", array( 'status' => 500 ) );
		}

		$path = $endpoint_map[ $action ];
		$url  = $instance . '/api/v1/statuses/' . rawurlencode( $status_id ) . '/' . $path;
		$verb = ucfirst( $action );

		$result = HttpClient::post(
			$url,
			array(
				'context' => "Mastodon {$verb}",
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 20,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$data = json_decode( $result['data'], true );

			return array(
				'success' => true,
				'data'    => array(
					'status_id' => $status_id,
					'action'    => $action,
					'status'    => is_array( $data ) ? $data : array(),
				),
			);
		}

		$error_msg = $result['error'] ?? "Failed to {$action}";

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
