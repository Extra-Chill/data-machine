<?php
/**
 * Facebook Update Ability
 *
 * Abilities API primitive for updating Facebook Page posts.
 * Supports editing post message, hiding/unhiding posts, and deleting posts.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;

defined( 'ABSPATH' ) || exit;

class FacebookUpdateAbility {

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
				'datamachine/facebook-update',
				array(
					'label'               => __( 'Update Facebook Page Posts', 'data-machine-socials' ),
					'description'         => __( 'Edit post message, hide/unhide posts, or delete posts from a Facebook Page', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'  => array(
								'type'        => 'string',
								'enum'        => array( 'edit', 'hide', 'unhide', 'delete' ),
								'description' => __( 'Action: edit (update message), hide, unhide, delete', 'data-machine-socials' ),
							),
							'post_id' => array(
								'type'        => 'string',
								'description' => __( 'Facebook post ID', 'data-machine-socials' ),
							),
							'message' => array(
								'type'        => 'string',
								'description' => __( 'New post message (for edit action)', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'action', 'post_id' ),
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

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute the update ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public function execute( array $input ): array {
		$action = $input['action'] ?? '';

		// Get auth provider.
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

		$post_id = $input['post_id'];

		switch ( $action ) {
			case 'edit':
				if ( empty( $input['message'] ) ) {
					return array(
						'success' => false,
						'error'   => 'message is required for edit action',
					);
				}
				return $this->editPost( $access_token, $post_id, $input['message'] );

			case 'hide':
				return $this->hidePost( $access_token, $post_id, true );

			case 'unhide':
				return $this->hidePost( $access_token, $post_id, false );

			case 'delete':
				return $this->deletePost( $access_token, $post_id );

			default:
				return array(
					'success' => false,
					'error'   => "Unknown action: {$action}. Use edit, hide, unhide, or delete.",
				);
		}
	}

	/**
	 * Get auth provider.
	 *
	 * @return FacebookAuth|null
	 */
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

	/**
	 * Edit a post's message.
	 *
	 * @param string $access_token Page access token.
	 * @param string $post_id      Post ID.
	 * @param string $message      New message.
	 * @return array Result.
	 */
	private function editPost( string $access_token, string $post_id, string $message ): array {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $post_id );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
					'message'      => $message,
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

		if ( 200 !== $status_code ) {
			return array(
				'success' => false,
				'error'   => $body['error']['message'] ?? 'Failed to edit post',
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'post_id' => $body['id'] ?? $post_id,
				'message' => $message,
			),
		);
	}

	/**
	 * Hide or unhide a post.
	 *
	 * @param string $access_token Page access token.
	 * @param string $post_id      Post ID.
	 * @param bool   $hide         Whether to hide (true) or unhide (false).
	 * @return array Result.
	 */
	private function hidePost( string $access_token, string $post_id, bool $hide ): array {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $post_id );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
					'is_hidden'    => $hide ? 'true' : 'false',
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

		if ( 200 !== $status_code ) {
			return array(
				'success' => false,
				'error'   => $body['error']['message'] ?? 'Failed to update post visibility',
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'post_id' => $post_id,
				'hidden'  => $hide,
			),
		);
	}

	/**
	 * Delete a post.
	 *
	 * @param string $access_token Page access token.
	 * @param string $post_id      Post ID.
	 * @return array Result.
	 */
	private function deletePost( string $access_token, string $post_id ): array {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $post_id );

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
					'post_id' => $post_id,
					'deleted' => true,
				),
			);
		}

		return array(
			'success' => false,
			'error'   => $body['error']['message'] ?? 'Failed to delete post',
		);
	}
}
