<?php
/**
 * Instagram Comment Reply Ability
 *
 * Abilities API primitive for replying to Instagram comments.
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

class InstagramCommentReplyAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.facebook.com/' . FacebookAuth::GRAPH_API_VERSION;

	const MAX_REPLY_LENGTH = 1000;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/instagram-comment-reply',
				array(
					'label'               => __( 'Reply to Instagram Comment', 'data-machine-socials' ),
					'description'         => __( 'Reply to a specific Instagram comment', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'comment_id' => array(
								'type'        => 'string',
								'description' => __( 'Instagram comment ID to reply to', 'data-machine-socials' ),
							),
							'message'    => array(
								'type'        => 'string',
								'maxLength'   => self::MAX_REPLY_LENGTH,
								'description' => __( 'Reply text', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'comment_id', 'message' ),
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
			return new \WP_Error( 'missing_auth', 'Instagram access token unavailable (expired or refresh failed)', array( 'status' => 401 ) );
		}

		$comment_id = sanitize_text_field( $input['comment_id'] ?? '' );
		$message    = trim( sanitize_textarea_field( $input['message'] ?? '' ) );

		if ( '' === $comment_id ) {
			return new \WP_Error( 'missing_param', 'comment_id is required', array( 'status' => 400 ) );
		}

		if ( '' === $message ) {
			return new \WP_Error( 'missing_param', 'message is required', array( 'status' => 400 ) );
		}

		if ( mb_strlen( $message ) > self::MAX_REPLY_LENGTH ) {
			$message = mb_substr( $message, 0, self::MAX_REPLY_LENGTH );
		}

		return $this->replyToComment( $access_token, $comment_id, $message );
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

	private function replyToComment( string $access_token, string $comment_id, string $message ): array|\WP_Error {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $comment_id ) . '/replies';

		$result = HttpClient::post(
			$url,
			array(
				'context' => 'Instagram Comment Reply',
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
					'message'      => $message,
				),
			)
		);

		$body = ! empty( $result['success'] ) ? json_decode( $result['data'], true ) : null;

		if ( empty( $result['success'] ) || isset( $body['error'] ) ) {
			return new \WP_Error( 'api_error', $body['error']['message'] ?? ( $result['error'] ?? 'Failed to reply to Instagram comment' ), array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'data'    => array(
				'reply_id'   => $body['id'] ?? '',
				'comment_id' => $comment_id,
				'message'    => $message,
			),
		);
	}
}
