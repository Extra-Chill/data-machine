<?php
/**
 * LinkedIn Update Ability
 *
 * Abilities API primitive for updating LinkedIn posts.
 * Uses the Posts API PARTIAL_UPDATE method.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\LinkedIn
 * @since      0.5.0
 */

namespace DataMachineSocials\Abilities\LinkedIn;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\LinkedIn\LinkedInAuth;

defined( 'ABSPATH' ) || exit;

class LinkedInUpdateAbility {

	private static bool $registered = false;

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
				'datamachine/linkedin-update',
				array(
					'label'               => __( 'Update LinkedIn Post', 'data-machine-socials' ),
					'description'         => __( 'Update the commentary of an existing LinkedIn post', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id', 'commentary' ),
						'properties' => array(
							'post_id'    => array(
								'type'        => 'string',
								'description' => __( 'Post URN to update (e.g., urn:li:share:12345)', 'data-machine-socials' ),
							),
							'commentary' => array(
								'type'        => 'string',
								'description' => __( 'New commentary text for the post', 'data-machine-socials' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'post_id' => array( 'type' => 'string' ),
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
		return PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		$post_id    = $input['post_id'] ?? '';
		$commentary = $input['commentary'] ?? '';

		if ( empty( $post_id ) ) {
			return new \WP_Error( 'missing_param', 'post_id is required', array( 'status' => 400 ) );
		}

		if ( empty( $commentary ) ) {
			return new \WP_Error( 'missing_param', 'commentary is required', array( 'status' => 400 ) );
		}

		$provider = $this->getAuthProvider();
		if ( ! $provider ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn auth provider not available', array( 'status' => 401 ) );
		}

		if ( ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn not authenticated', array( 'status' => 401 ) );
		}

		$encoded_id = rawurlencode( $post_id );
		$url        = LinkedInAuth::API_BASE . "/rest/posts/{$encoded_id}";

		$payload = array(
			'patch' => array(
				'$set' => array(
					'commentary' => $commentary,
				),
			),
		);

		$result = $provider->api_request(
			'POST',
			$url,
			array(
				'headers' => array( 'X-RestLi-Method' => 'PARTIAL_UPDATE' ),
				'body'    => wp_json_encode( $payload ),
				'context' => 'LinkedIn Update Post',
			)
		);

		// LinkedIn returns 204 on successful update.
		if ( $result['success'] ) {
			return array(
				'success' => true,
				'post_id' => $post_id,
			);
		}

		return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to update LinkedIn post', array( 'status' => 500 ) );
	}

	private function getAuthProvider(): ?LinkedInAuth {
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'linkedin' );

		if ( $provider instanceof LinkedInAuth ) {
			return $provider;
		}

		return null;
	}
}
