<?php
/**
 * Tumblr Delete Ability
 *
 * Abilities API primitive for deleting a Tumblr post via the v2 API.
 * Tumblr deletes are POST requests to /post/delete (not an HTTP DELETE method).
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Tumblr
 * @since      0.17.0
 */

namespace DataMachineSocials\Abilities\Tumblr;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Tumblr\TumblrAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class TumblrDeleteAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const API_URL = 'https://api.tumblr.com/v2';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/tumblr-delete',
				array(
					'label'               => __( 'Delete Tumblr Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete a post from a Tumblr blog', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'blog_identifier', 'post_id' ),
						'properties' => array(
							'blog_identifier' => array(
								'type'        => 'string',
								'description' => __( 'Target Tumblr blog identifier', 'data-machine-socials' ),
							),
							'post_id'         => array(
								'type'        => 'string',
								'description' => __( 'Tumblr post ID to delete', 'data-machine-socials' ),
							),
						),
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
			return new \WP_Error( 'missing_auth', 'Tumblr auth provider not available', array( 'status' => 401 ) );
		}

		$token = $auth->get_valid_access_token();
		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Tumblr access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings', array( 'status' => 401 ) );
		}

		$blog_identifier = sanitize_text_field( (string) ( $input['blog_identifier'] ?? '' ) );
		$post_id         = sanitize_text_field( (string) ( $input['post_id'] ?? '' ) );

		if ( '' === $blog_identifier || '' === $post_id ) {
			return new \WP_Error( 'missing_param', 'blog_identifier and post_id are required', array( 'status' => 400 ) );
		}

		$url = self::API_URL . '/blog/' . rawurlencode( $blog_identifier ) . '/post/delete';

		$result = HttpClient::post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'id' => (int) $post_id ) ),
				'timeout' => 30,
				'context' => 'Tumblr Delete',
			)
		);

		if ( ! empty( $result['success'] ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'post_id' => $post_id,
					'deleted' => true,
				),
			);
		}

		return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to delete Tumblr post', array( 'status' => 500 ) );
	}

	private function getAuthProvider(): ?TumblrAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'tumblr' );

		if ( $provider instanceof TumblrAuth ) {
			return $provider;
		}

		return null;
	}
}
