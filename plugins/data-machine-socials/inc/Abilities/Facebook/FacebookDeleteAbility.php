<?php
/**
 * Facebook Delete Ability
 *
 * Abilities API primitive for deleting Facebook Page posts.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;

defined( 'ABSPATH' ) || exit;

class FacebookDeleteAbility {

	private static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.facebook.com/v23.0';

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
				'datamachine/facebook-delete',
				array(
					'label'               => __( 'Delete Facebook Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete posts from your Facebook Page', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_id' => array(
								'type'        => 'string',
								'description' => __( 'Facebook post ID to delete', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'post_id' ),
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
				'error'   => 'Facebook auth provider not available',
			);
		}

		$access_token = $auth->get_page_access_token();
		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Facebook page access token unavailable',
			);
		}

		if ( empty( $input['post_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'post_id is required',
			);
		}

		$url = self::GRAPH_API_URL . '/' . rawurlencode( $input['post_id'] );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
					'method'       => 'DELETE',
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

		if ( 200 === $status_code || ( isset( $body['success'] ) && $body['success'] ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'post_id' => $input['post_id'],
					'deleted' => true,
				),
			);
		}

		return array(
			'success' => false,
			'error'   => $body['error']['message'] ?? 'Failed to delete post',
		);
	}

	private function getAuthProvider(): ?FacebookAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth     = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'facebook' );

		if ( ! $provider instanceof FacebookAuth ) {
			return null;
		}

		return $provider;
	}
}
