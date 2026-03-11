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
use DataMachineSocials\Handlers\Pinterest\PinterestAuth;

defined( 'ABSPATH' ) || exit;

class PinterestUpdateAbility {

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
				'datamachine/pinterest-update',
				array(
					'label'               => __( 'Update Pinterest Pins', 'data-machine-socials' ),
					'description'         => __( 'Delete pins from Pinterest boards', 'data-machine-socials' ),
					'category'            => 'datamachine',
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
		$action = $input['action'] ?? '';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest auth provider not available',
			);
		}

		$access_token = $auth->get_valid_access_token();

		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings',
			);
		}

		if ( empty( $input['pin_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'pin_id is required',
			);
		}

		$pin_id = $input['pin_id'];

		switch ( $action ) {
			case 'delete':
				return $this->deletePin( $access_token, $pin_id );

			default:
				return array(
					'success' => false,
					'error'   => "Unknown action: {$action}. Use delete.",
				);
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

	private function deletePin( string $access_token, string $pin_id ): array {
		$url = self::API_URL . '/pins/' . rawurlencode( $pin_id );

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

		if ( 204 === $status_code || 200 === $status_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'pin_id'  => $pin_id,
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
}
