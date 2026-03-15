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

		return $this->deleteMedia( $access_token, $input['media_id'] );
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

	/**
	 * Delete a media item via the Instagram Graph API.
	 *
	 * Note: The Instagram API may not support deletion for all media types.
	 * If deletion fails, consider archiving instead (datamachine/instagram-update with action: archive).
	 *
	 * @param string $access_token Valid access token.
	 * @param string $media_id     Media ID to delete.
	 * @return array Result.
	 */
	private function deleteMedia( string $access_token, string $media_id ): array {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $media_id );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
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
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status_code || 204 === $status_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'media_id' => $media_id,
					'deleted'  => true,
				),
			);
		}

		return array(
			'success' => false,
			'error'   => $body['error']['message'] ?? 'Delete failed. The Instagram API may not support deletion for this media type. Consider archiving instead.',
		);
	}
}
