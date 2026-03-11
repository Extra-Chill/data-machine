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

defined( 'ABSPATH' ) || exit;

class BlueskyDeleteAbility {

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
				'datamachine/bluesky-delete',
				array(
					'label'               => __( 'Delete Bluesky Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete your Bluesky posts', 'data-machine-socials' ),
					'category'            => 'datamachine',
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
				'error'   => 'Bluesky auth provider not available',
			);
		}

		$session = $auth->get_session();
		if ( empty( $session['accessJwt'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Bluesky session not available',
			);
		}

		if ( empty( $input['post_uri'] ) ) {
			return array(
				'success' => false,
				'error'   => 'post_uri is required',
			);
		}

		$did = $session['did'];

		// Parse rkey from AT URI: at://did:plc:xxx/app.bsky.feed.post/rkey
		$post_uri  = $input['post_uri'];
		$uri_parts = explode( '/', $post_uri );
		$rkey      = end( $uri_parts );

		if ( empty( $rkey ) ) {
			return array(
				'success' => false,
				'error'   => 'Could not extract rkey from post URI: ' . $post_uri,
			);
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
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
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
		return array(
			'success' => false,
			'error'   => $body['error'] ?? 'Failed to delete post',
		);
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
