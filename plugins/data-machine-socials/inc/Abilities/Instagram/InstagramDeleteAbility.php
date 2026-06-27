<?php
/**
 * Instagram Delete Ability
 *
 * Abilities API primitive for deleting Instagram media.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Instagram
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Instagram;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class InstagramDeleteAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	/**
	 * Uses Facebook Graph API because our OAuth flow issues FB-flavored tokens.
	 * graph.instagram.com rejects these with "Cannot parse access token" (code 190).
	 *
	 * @see InstagramPublishAbility::GRAPH_API_URL for full rationale.
	 */
	const GRAPH_API_URL = 'https://graph.facebook.com/' . FacebookAuth::GRAPH_API_VERSION;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/instagram-delete',
				array(
					'label'               => __( 'Delete Instagram Media', 'data-machine-socials' ),
					'description'         => __( 'Delete Instagram posts', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'media_id' => array(
								'type'        => 'string',
								'description' => __( 'Instagram media ID to delete', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'media_id' ),
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
			return new \WP_Error( 'missing_auth', 'Instagram auth provider not available', array( 'status' => 401 ) );
		}

		$access_token = $auth->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'Instagram access token unavailable', array( 'status' => 401 ) );
		}

		if ( empty( $input['media_id'] ) ) {
			return new \WP_Error( 'missing_param', 'media_id is required', array( 'status' => 400 ) );
		}

		return $this->deleteMedia( $access_token, $input['media_id'] );
	}

	private function getAuthProvider(): ?InstagramAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth     = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'instagram' );

		if ( ! $provider instanceof InstagramAuth ) {
			return null;
		}

		return $provider;
	}

	/**
	 * Delete a media item via the Instagram Graph API.
	 *
	 * Note: The Instagram API may not support deletion for all media types.
	 * If deletion fails, consider archiving instead (datamachine/instagram-update with action: archive).
	 *
	 * @param string $access_token Valid access token.
	 * @param string $media_id     Media ID to delete.
	 * @return array Result.
	 */
	private function deleteMedia( string $access_token, string $media_id ): array|\WP_Error {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $media_id );

		$result = HttpClient::delete(
			$url,
			array(
				'context' => 'Instagram Delete',
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
				),
			)
		);

		if ( ! empty( $result['success'] ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'media_id' => $media_id,
					'deleted'  => true,
				),
			);
		}

		return $this->apiError( $result['error'] ?? 'Delete failed. The Instagram API may not support deletion for this media type. Consider archiving instead.' );
	}
}
