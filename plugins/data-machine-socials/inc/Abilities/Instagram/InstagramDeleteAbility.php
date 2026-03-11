<?php
/**
 * Instagram Delete Ability
 *
 * Abilities API primitive for deleting Instagram media.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Instagram
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Instagram;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;

defined( 'ABSPATH' ) || exit;

class InstagramDeleteAbility {

	private static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.instagram.com';

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
				'datamachine/instagram-delete',
				array(
					'label'               => __( 'Delete Instagram Media', 'data-machine-socials' ),
					'description'         => __( 'Delete Instagram posts', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'media_id' => array(
								'type'        => 'string',
								'description' => __( 'Instagram media ID to delete', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'media_id' ),
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
				'error'   => 'Instagram auth provider not available',
			);
		}

		$access_token = $auth->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Instagram access token unavailable',
			);
		}

		if ( empty( $input['media_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'media_id is required',
			);
		}

		$media_id = $input['media_id'];

		return $this->deleteMedia( $access_token);
	}

	private function getAuthProvider(): ?InstagramAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth     = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'instagram' );

		if ( ! $provider instanceof InstagramAuth ) {
			return null;
		}

		return $provider;
	}

	private function deleteMedia( string $access_token): array {
		$access_token;
		// Note: Instagram API doesn't have a direct delete endpoint for all media types.
		// This is a limitation - recommend archiving instead.
		return array(
			'success' => false,
			'error'   => 'Delete not supported for this media type via API. Consider archiving instead.',
		);
	}
}
