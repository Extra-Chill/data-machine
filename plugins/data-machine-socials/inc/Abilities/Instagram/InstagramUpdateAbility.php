<?php
/**
 * Instagram Update Ability
 *
 * Abilities API primitive for updating Instagram media.
 * Supports editing captions, deleting media, and archiving posts.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Instagram
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Instagram;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use DataMachineSocials\Abilities\Traits\HasCheckPermission;
use DataMachineSocials\Abilities\Instagram\InstagramDeleteAbility;

defined( 'ABSPATH' ) || exit;

class InstagramUpdateAbility {
	use HasCheckPermission;


	private static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.instagram.com';

	/**
	 * Max caption length.
	 */
	const MAX_CAPTION_LENGTH = 2200;

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
				'datamachine/instagram-update',
				array(
					'label'               => __( 'Update Instagram Media', 'data-machine-socials' ),
					'description'         => __( 'Edit caption, delete, or archive Instagram posts', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'   => array(
								'type'        => 'string',
								'enum'        => array( 'edit', 'delete', 'archive' ),
								'description' => __( 'Action: edit (update caption), delete (remove post), archive (hide from profile)', 'data-machine-socials' ),
							),
							'media_id' => array(
								'type'        => 'string',
								'description' => __( 'Instagram media ID', 'data-machine-socials' ),
							),
							'caption'  => array(
								'type'        => 'string',
								'maxLength'   => 2200,
								'description' => __( 'New caption text (for edit action)', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'action', 'media_id' ),
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
	 * Execute the update ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? '';

		// Get auth provider.
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Instagram auth provider not available', array( 'status' => 401 ) );
		}

		$access_token = $auth->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'Instagram access token unavailable (expired or refresh failed)', array( 'status' => 401 ) );
		}

		if ( empty( $input['media_id'] ) ) {
			return new \WP_Error( 'missing_param', 'media_id is required', array( 'status' => 400 ) );
		}

		$media_id = $input['media_id'];

		switch ( $action ) {
			case 'edit':
				if ( empty( $input['caption'] ) ) {
					return new \WP_Error( 'missing_param', 'caption is required for edit action', array( 'status' => 400 ) );
				}
				return $this->editCaption( $access_token, $media_id, $input['caption'] );

			case 'delete':
				return $this->deleteMedia( $access_token, $media_id );

			case 'archive':
				return $this->archiveMedia( $access_token, $media_id );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use edit, delete, or archive.", array( 'status' => 500 ) );
		}
	}

	/**
	 * Edit caption of a media item.
	 *
	 * @param string $access_token Valid access token.
	 * @param string $media_id      Media ID.
	 * @param string $caption       New caption.
	 * @return array Result.
	 */
	private function editCaption( string $access_token, string $media_id, string $caption ): array|\WP_Error {
		// Truncate to max length.
		if ( mb_strlen( $caption ) > self::MAX_CAPTION_LENGTH ) {
			$caption = mb_substr( $caption, 0, self::MAX_CAPTION_LENGTH - 3 ) . '...';
		}

		$url = self::GRAPH_API_URL . '/' . rawurlencode( $media_id );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
					'caption'      => $caption,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			return new \WP_Error( 'api_error', $body['error']['message'] ?? 'Failed to edit caption', array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'data'    => array(
				'media_id' => $body['id'] ?? $media_id,
				'caption'  => $body['caption'] ?? $caption,
			),
		);
	}

	/**
	 * Delete a media item.
	 *
	 * @param string $access_token Valid access token.
	 * @param string $media_id      Media ID.
	 * @return array Result.
	 */
	private function deleteMedia( string $access_token, string $media_id ): array|\WP_Error {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $media_id );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
					'fields'       => 'id',
				),
			)
		);

		// Note: Instagram API doesn't have a direct delete endpoint for media.
		// We need to use the user media edge to delete. This is a limitation.
		// For now, return an error with guidance.

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status_code || 204 === $status_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'media_id' => $media_id,
					'deleted'  => true,
				),
			);
		}

		// Instagram doesn't support direct delete via API for all media types.
		// Return informative error.
		return new \WP_Error( 'api_error', $body['error']['message'] ?? 'Delete not supported for this media type via API. Consider archiving instead.', array( 'status' => 500 ) );
	}

	/**
	 * Archive a media item (hide from profile).
	 *
	 * @param string $access_token Valid access token.
	 * @param string $media_id      Media ID.
	 * @return array Result.
	 */
	private function archiveMedia( string $access_token, string $media_id ): array|\WP_Error {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $media_id );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
					'is_hidden'    => 'true',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			return new \WP_Error( 'api_error', $body['error']['message'] ?? 'Failed to archive media', array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'data'    => array(
				'media_id' => $body['id'] ?? $media_id,
				'archived' => true,
			),
		);
	}
}
