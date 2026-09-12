<?php
/**
 * Facebook Comment Reply Ability
 *
 * Abilities API primitive for replying to Facebook Page comments.
 * Uses the page_access_token for page-level engagement operations.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 * @since      0.20.3
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class FacebookCommentReplyAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const MAX_REPLY_LENGTH = 8000;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/facebook-comment-reply',
				array(
					'label'               => __( 'Reply to Facebook Comment', 'data-machine-socials' ),
					'description'         => __( 'Reply to a specific Facebook Page comment', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'comment_id' => array(
								'type'        => 'string',
								'description' => __( 'Facebook comment ID to reply to', 'data-machine-socials' ),
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
			return new \WP_Error( 'missing_auth', 'Facebook auth provider not available', array( 'status' => 401 ) );
		}

		// Facebook uses page_access_token for page operations.
		$page_token = $auth->get_page_access_token();
		if ( empty( $page_token ) ) {
			return new \WP_Error( 'missing_auth', 'Facebook page access token unavailable (expired or refresh failed)', array( 'status' => 401 ) );
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

		return $this->replyToComment( $page_token, $comment_id, $message );
	}

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

	private function replyToComment( string $page_token, string $comment_id, string $message ): array|\WP_Error {
		$url = FacebookAuth::GRAPH_API_URL . '/' . rawurlencode( $comment_id ) . '/comments';

		$result = HttpClient::post(
			$url,
			array(
				'context' => 'Facebook Comment Reply',
				'timeout' => 30,
				'body'    => array(
					'access_token' => $page_token,
					'message'      => $message,
				),
			)
		);

		$body      = ! empty( $result['success'] ) ? json_decode( $result['data'], true ) : null;
		$http_code = $result['status_code'] ?? 0;

		if ( empty( $result['success'] ) || 200 !== $http_code || isset( $body['error'] ) ) {
			return new \WP_Error( 'api_error', $body['error']['message'] ?? ( $result['error'] ?? 'Failed to reply to Facebook comment' ), array( 'status' => 500 ) );
		}

		$reply_id = $body['id'] ?? '';

		return array(
			'success' => true,
			'data'    => array(
				'id'         => $reply_id,
				'reply_id'   => $reply_id,
				'comment_id' => $comment_id,
				'message'    => $message,
			),
		);
	}
}
