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
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class FacebookDeleteAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const GRAPH_API_URL = FacebookAuth::GRAPH_API_URL;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/facebook-delete',
				array(
					'label'               => __( 'Delete Facebook Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete posts from your Facebook Page', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
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
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Facebook auth provider not available', array( 'status' => 401 ) );
		}

		$access_token = $auth->get_page_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'Facebook page access token unavailable', array( 'status' => 401 ) );
		}

		if ( empty( $input['post_id'] ) ) {
			return new \WP_Error( 'missing_param', 'post_id is required', array( 'status' => 400 ) );
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

		$normalized = $this->normalizeJsonResponse( $response );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$status_code = $normalized['status_code'];
		$body        = $normalized['data'];

		if ( 200 === $status_code || ( isset( $body['success'] ) && $body['success'] ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'post_id' => $input['post_id'],
					'deleted' => true,
				),
			);
		}

		return $this->apiError( $body['error']['message'] ?? 'Failed to delete post' );
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
