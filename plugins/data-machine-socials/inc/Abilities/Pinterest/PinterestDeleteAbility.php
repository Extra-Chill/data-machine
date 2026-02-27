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
use DataMachineSocials\Handlers\Pinterest\PinterestAuth;

defined( 'ABSPATH' ) || exit;

class PinterestDeleteAbility {

	private static bool $registered = false;

	const API_URL = 'https://api.pinterest.com/v5';

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/pinterest-delete',
				array(
					'label'               => __( 'Delete Pinterest Pins', 'data-machine-socials' ),
					'description'         => __( 'Delete pins from your Pinterest boards', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'pin_id' => array(
								'type'        => 'string',
								'description' => __( 'Pinterest pin ID to delete', 'data-machine-socials' ),
							),
						),
						'required' => array( 'pin_id' ),
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	public function execute( array $input ): array {
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest auth provider not available',
			);
		}

		$config = $auth->get_config();
		$access_token = $config['access_token'] ?? '';

		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest access token not configured',
			);
		}

		if ( empty( $input['pin_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'pin_id is required',
			);
		}

		$url = self::API_URL . '/pins/' . rawurlencode( $input['pin_id'] );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code === 204 || $status_code === 200 ) {
			return array(
				'success' => true,
				'data'    => array(
					'pin_id'  => $input['pin_id'],
					'deleted' => true,
				),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'success' => false,
			'error'   => $body['message'] ?? 'Failed to delete pin',
		);
	}

	private function getAuthProvider(): ?PinterestAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'pinterest' );

		if ( ! $provider instanceof PinterestAuth ) {
			return null;
		}

		return $provider;
	}
}
