<?php
/**
 * Tumblr Update Ability
 *
 * Abilities API primitive for editing an existing Tumblr NPF post via PUT.
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

class TumblrUpdateAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const API_URL = 'https://api.tumblr.com/v2';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/tumblr-update',
				array(
					'label'               => __( 'Update Tumblr Posts', 'data-machine-socials' ),
					'description'         => __( 'Edit an existing Tumblr post (Neue Post Format). Posts can only be edited in NPF if originally created in NPF.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'blog_identifier', 'post_id', 'content' ),
						'properties' => array(
							'blog_identifier' => array(
								'type'        => 'string',
								'description' => __( 'Target Tumblr blog identifier', 'data-machine-socials' ),
							),
							'post_id'         => array(
								'type'        => 'string',
								'description' => __( 'Tumblr post ID to edit', 'data-machine-socials' ),
							),
							'content'         => array(
								'type'        => 'array',
								'description' => __( 'Replacement array of NPF content blocks', 'data-machine-socials' ),
							),
							'tags'            => array(
								'type'        => 'string',
								'description' => __( 'Optional comma-separated tags', 'data-machine-socials' ),
							),
							'state'           => array(
								'type'        => 'string',
								'enum'        => array( 'published', 'queue', 'draft' ),
								'description' => __( 'Optional new post state', 'data-machine-socials' ),
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
		$content         = is_array( $input['content'] ?? null ) ? $input['content'] : array();

		if ( '' === $blog_identifier || '' === $post_id ) {
			return new \WP_Error( 'missing_param', 'blog_identifier and post_id are required', array( 'status' => 400 ) );
		}
		if ( empty( $content ) ) {
			return new \WP_Error( 'missing_param', 'content is required', array( 'status' => 400 ) );
		}

		$body = array( 'content' => $content );
		if ( ! empty( $input['tags'] ) ) {
			$body['tags'] = sanitize_text_field( (string) $input['tags'] );
		}
		if ( in_array( $input['state'] ?? '', array( 'published', 'queue', 'draft' ), true ) ) {
			$body['state'] = $input['state'];
		}

		$url = self::API_URL . '/blog/' . rawurlencode( $blog_identifier ) . '/posts/' . rawurlencode( $post_id );

		$result = HttpClient::request( 'PUT', $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
			'context' => 'Tumblr Post Edit',
		) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to edit Tumblr post', array( 'status' => 500 ) );
		}

		$decoded = json_decode( $result['data'], true );
		$id      = is_array( $decoded ) ? ( $decoded['response']['id'] ?? $post_id ) : $post_id;

		return array(
			'success' => true,
			'data'    => array(
				'post_id' => (string) $id,
				'updated' => true,
			),
		);
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
