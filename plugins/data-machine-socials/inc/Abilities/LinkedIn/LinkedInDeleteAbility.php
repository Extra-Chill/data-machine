<?php
/**
 * LinkedIn Delete Ability
 *
 * Abilities API primitive for deleting LinkedIn posts.
 * Deletions are idempotent (previously deleted posts return 204).
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\LinkedIn
 * @since      0.5.0
 */

namespace DataMachineSocials\Abilities\LinkedIn;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\LinkedIn\LinkedInAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class LinkedInDeleteAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/linkedin-delete',
				array(
					'label'               => __( 'Delete LinkedIn Post', 'data-machine-socials' ),
					'description'         => __( 'Delete a post from LinkedIn', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id' ),
						'properties' => array(
							'post_id' => array(
								'type'        => 'string',
								'description' => __( 'Post URN to delete (e.g., urn:li:share:12345)', 'data-machine-socials' ),
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
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		$post_id = $input['post_id'] ?? '';

		if ( empty( $post_id ) ) {
			return new \WP_Error( 'missing_param', 'post_id is required', array( 'status' => 400 ) );
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

		$result = $provider->api_request(
			'DELETE',
			$url,
			array(
				'headers' => array( 'X-RestLi-Method' => 'DELETE' ),
				'context' => 'LinkedIn Delete Post',
			)
		);

		// LinkedIn returns 204 on successful delete (idempotent).
		if ( $result['success'] ) {
			return array(
				'success' => true,
				'post_id' => $post_id,
			);
		}

		return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to delete LinkedIn post', array( 'status' => 500 ) );
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
