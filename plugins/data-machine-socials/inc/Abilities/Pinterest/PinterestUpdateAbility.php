<?php
/**
 * Pinterest Update Ability
 *
 * Abilities API primitive for updating Pinterest content.
 * Supports deleting pins.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Pinterest
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Pinterest;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Pinterest\PinterestAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class PinterestUpdateAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const API_URL = 'https://api.pinterest.com/v5';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/pinterest-update',
				array(
					'label'               => __( 'Update Pinterest Pins', 'data-machine-socials' ),
					'description'         => __( 'Delete pins from Pinterest boards', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action' => array(
								'type'        => 'string',
								'enum'        => array( 'delete' ),
								'description' => __( 'Action: delete (remove pin)', 'data-machine-socials' ),
							),
							'pin_id' => array(
								'type'        => 'string',
								'description' => __( 'Pinterest pin ID', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'action', 'pin_id' ),
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
		$action = $input['action'] ?? '';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Pinterest auth provider not available', array( 'status' => 401 ) );
		}

		$access_token = $auth->get_valid_access_token();

		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'Pinterest access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings', array( 'status' => 401 ) );
		}

		if ( empty( $input['pin_id'] ) ) {
			return new \WP_Error( 'missing_param', 'pin_id is required', array( 'status' => 400 ) );
		}

		$pin_id = $input['pin_id'];

		switch ( $action ) {
			case 'delete':
				return $this->deletePin( $access_token, $pin_id );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use delete.", array( 'status' => 500 ) );
		}
	}

	private function getAuthProvider(): ?PinterestAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth     = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'pinterest' );

		if ( ! $provider instanceof PinterestAuth ) {
			return null;
		}

		return $provider;
	}

	private function deletePin( string $access_token, string $pin_id ): array|\WP_Error {
		$url = self::API_URL . '/pins/' . rawurlencode( $pin_id );

		$result = HttpClient::delete(
			$url,
			array(
				'context' => 'Pinterest Delete',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( ! empty( $result['success'] ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'pin_id'  => $pin_id,
					'deleted' => true,
				),
			);
		}

		return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to delete pin', array( 'status' => 500 ) );
	}
}
