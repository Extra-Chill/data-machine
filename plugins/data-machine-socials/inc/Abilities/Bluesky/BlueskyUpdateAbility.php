<?php
/**
 * Bluesky Update Ability
 *
 * Abilities API primitive for updating Bluesky posts.
 * Supports deleting posts and liking/unliking posts.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Bluesky
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Bluesky;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Bluesky\BlueskyAuth;

defined( 'ABSPATH' ) || exit;

class BlueskyUpdateAbility {

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
				'datamachine/bluesky-update',
				array(
					'label'               => __( 'Update Bluesky Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete posts or like/unlike posts on Bluesky', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'  => array(
								'type'        => 'string',
								'enum'        => array( 'delete', 'like', 'unlike' ),
								'description' => __( 'Action: delete, like, unlike', 'data-machine-socials' ),
							),
							'post_uri' => array(
								'type'        => 'string',
								'description' => __( 'Bluesky post URI (at://...)', 'data-machine-socials' ),
							),
						),
						'required' => array( 'action', 'post_uri' ),
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
		$action = $input['action'] ?? '';

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

		$post_uri = $input['post_uri'];

		switch ( $action ) {
			case 'delete':
				return $this->deletePost( $session, $post_uri );

			case 'like':
				return $this->likePost( $session, $post_uri );

			case 'unlike':
				return $this->unlikePost( $session, $post_uri );

			default:
				return array(
					'success' => false,
					'error'   => "Unknown action: {$action}. Use delete, like, or unlike.",
				);
		}
	}

	private function getAuthProvider(): ?BlueskyAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'bluesky' );

		if ( ! $provider instanceof BlueskyAuth ) {
			return null;
		}

		return $provider;
	}

	private function deletePost( array $session, string $post_uri ): array {
		$pds_url = $session['pds_url'];
		$did      = $session['did'];

		// Extract the record key from URI: at://did/rkey
		$parts = parse_url( $post_uri );
		$path  = ltrim( $parts['path'], '/' );
		$rkey  = basename( $path );

		$url    = $pds_url . '/xrpc/app.bsky.feed.post';
		$params = array( 'repo' => $did, 'collection' => 'app.bsky.feed.post', 'rkey' => $rkey );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $session['accessJwt'],
					'Content-Type'   => 'application/json',
				),
				'body'    => json_encode( $params ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code === 200 || $status_code === 204 ) {
			return array(
				'success' => true,
				'data'    => array(
					'post_uri' => $post_uri,
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

	private function likePost( array $session, string $post_uri ): array {
		$pds_url = $session['pds_url'];
		$did      = $session['did'];

		$url = $pds_url . '/xrpc/app.bsky.feed.like';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $session['accessJwt'],
					'Content-Type'   => 'application/json',
				),
				'body'    => json_encode( array(
					'repo'     => $did,
					'collection' => 'app.bsky.feed.like',
					'record'   => array(
						'subject' => array( 'uri' => $post_uri, 'cid' => '' ),
						'createdAt' => gmdate( 'c' ),
					),
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
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code === 200 ) {
			return array(
				'success' => true,
				'data'    => array(
					'post_uri' => $post_uri,
					'liked'    => true,
					'uri'      => $body['uri'] ?? '',
				),
			);
		}

		return array(
			'success' => false,
			'error'   => $body['error'] ?? 'Failed to like post',
		);
	}

	private function unlikePost( array $session, string $post_uri ): array {
		$pds_url = $session['pds_url'];
		$did      = $session['did'];

		// Unlike requires the like URI which we don't have stored.
		// This is a limitation - user would need to query likes first.
		return array(
			'success' => false,
			'error'   => 'Unlike requires the like URI. Query likes first to get the like record to delete.',
		);
	}
}
