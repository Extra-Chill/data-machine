<?php
/**
 * Bluesky Delete Ability
 *
 * Abilities API primitive for deleting Bluesky posts.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Bluesky
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Bluesky;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Bluesky\BlueskyAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class BlueskyDeleteAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/bluesky-delete',
				array(
					'label'               => __( 'Delete Bluesky Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete your Bluesky posts', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_uri' => array(
								'type'        => 'string',
								'description' => __( 'Bluesky post URI (at://...)', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'post_uri' ),
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
			return new \WP_Error( 'missing_auth', 'Bluesky auth provider not available', array( 'status' => 401 ) );
		}

		$session = $auth->get_session();
		if ( empty( $session['accessJwt'] ) ) {
			return new \WP_Error( 'missing_auth', 'Bluesky session not available', array( 'status' => 401 ) );
		}

		if ( empty( $input['post_uri'] ) ) {
			return new \WP_Error( 'missing_param', 'post_uri is required', array( 'status' => 400 ) );
		}

		$did = $session['did'];

		// Parse rkey from AT URI: at://did:plc:xxx/app.bsky.feed.post/rkey
		$post_uri  = $input['post_uri'];
		$uri_parts = explode( '/', $post_uri );
		$rkey      = end( $uri_parts );

		if ( empty( $rkey ) ) {
			return new \WP_Error( 'api_error', 'Could not extract rkey from post URI: ' . $post_uri, array( 'status' => 500 ) );
		}

		$response = wp_remote_post(
			'https://bsky.social/xrpc/com.atproto.repo.deleteRecord',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $session['accessJwt'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'repo'       => $did,
					'collection' => 'app.bsky.feed.post',
					'rkey'       => $rkey,
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $status_code || 204 === $status_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'post_uri' => $input['post_uri'],
					'deleted'  => true,
				),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return new \WP_Error( 'api_error', $body['error'] ?? 'Failed to delete post', array( 'status' => 500 ) );
	}

	private function getAuthProvider(): ?BlueskyAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth     = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'bluesky' );

		if ( ! $provider instanceof BlueskyAuth ) {
			return null;
		}

		return $provider;
	}
}
