<?php
/**
 * Pinterest Delete Ability
 *
 * Abilities API primitive for deleting Pinterest pins.
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

class PinterestDeleteAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const API_URL = 'https://api.pinterest.com/v5';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/pinterest-delete',
				array(
					'label'               => __( 'Delete Pinterest Pins', 'data-machine-socials' ),
					'description'         => __( 'Delete pins from your Pinterest boards', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'pin_id' => array(
								'type'        => 'string',
								'description' => __( 'Pinterest pin ID to delete', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'pin_id' ),
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

		$url = self::API_URL . '/pins/' . rawurlencode( $input['pin_id'] );

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
					'pin_id'  => $input['pin_id'],
					'deleted' => true,
				),
			);
		}

		return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to delete pin', array( 'status' => 500 ) );
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
}
