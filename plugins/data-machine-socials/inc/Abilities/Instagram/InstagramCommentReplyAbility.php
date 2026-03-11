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
use DataMachineSocials\Handlers\Instagram\InstagramAuth;

defined( 'ABSPATH' ) || exit;

class InstagramCommentReplyAbility {

	private static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.facebook.com/v23.0';

	const MAX_REPLY_LENGTH = 1000;

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
				'datamachine/instagram-comment-reply',
				array(
					'label'               => __( 'Reply to Instagram Comment', 'data-machine-socials' ),
					'description'         => __( 'Reply to a specific Instagram comment', 'data-machine-socials' ),
					'category'            => 'datamachine',
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
				'error'   => 'Instagram auth provider not available',
			);
		}

		$access_token = $auth->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Instagram access token unavailable (expired or refresh failed)',
			);
		}

		$comment_id = sanitize_text_field( $input['comment_id'] ?? '' );
		$message    = trim( sanitize_textarea_field( $input['message'] ?? '' ) );

		if ( '' === $comment_id ) {
			return array(
				'success' => false,
				'error'   => 'comment_id is required',
			);
		}

		if ( '' === $message ) {
			return array(
				'success' => false,
				'error'   => 'message is required',
			);
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

	private function replyToComment( string $access_token, string $comment_id, string $message ): array {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $comment_id ) . '/replies';

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

		if ( 200 !== $status_code || isset( $body['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $body['error']['message'] ?? 'Failed to reply to Instagram comment',
			);
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
