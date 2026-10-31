<?php
/**
 * Instagram Message Send Ability
 *
 * Abilities API primitive for sending Instagram direct messages.
 * Uses the Facebook Graph Messaging API with the Page access token stored
 * on the account, since the user token cannot call
 * /{page_id}/messages.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Instagram
 * @since      0.21.0
 */

namespace DataMachineSocials\Abilities\Instagram;

use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use DataMachineSocials\PublishAuthorization;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class InstagramMessageSendAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.facebook.com/' . FacebookAuth::GRAPH_API_VERSION;

	const MAX_MESSAGE_LENGTH = 1000;

	/**
	 * Meta error code / subcode pair returned when the 24-hour messaging
	 * window for a conversation has closed.
	 */
	const WINDOW_ERROR_CODE    = 10;
	const WINDOW_ERROR_SUBCODE = 2018278;

	const MESSAGING_TYPES = array( 'RESPONSE', 'UPDATE', 'MESSAGE_TAG' );

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/instagram-message-send',
				array(
					'label'               => __( 'Send Instagram Direct Message', 'data-machine-socials' ),
					'description'         => __( 'Send an Instagram direct message reply to a user. Only allowed inside the 24-hour messaging window.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'recipient_id'   => array(
								'type'        => 'string',
								'description' => __( 'Instagram-scoped user ID (IGSID) of the message recipient', 'data-machine-socials' ),
							),
							'message'        => array(
								'type'        => 'string',
								'maxLength'   => self::MAX_MESSAGE_LENGTH,
								'description' => __( 'Text of the direct message to send', 'data-machine-socials' ),
							),
							'messaging_type' => array(
								'type'        => 'string',
								'enum'        => self::MESSAGING_TYPES,
								'default'     => 'RESPONSE',
								'description' => __( 'Messaging type (RESPONSE for replies inside the 24-hour window)', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'recipient_id', 'message' ),
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
		return PublishAuthorization::can_publish();
	}

	public function execute( array $input ): array|\WP_Error {
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Instagram auth provider not available', array( 'status' => 401 ) );
		}

		$page = $auth->get_page_context();
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$recipient_id = sanitize_text_field( (string) ( $input['recipient_id'] ?? '' ) );
		$message      = trim( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ) );

		if ( '' === $recipient_id ) {
			return new \WP_Error( 'missing_param', 'recipient_id is required', array( 'status' => 400 ) );
		}

		if ( '' === $message ) {
			return new \WP_Error( 'missing_param', 'message is required', array( 'status' => 400 ) );
		}

		if ( mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
			$message = mb_substr( $message, 0, self::MAX_MESSAGE_LENGTH );
		}

		$messaging_type = strtoupper( sanitize_text_field( (string) ( $input['messaging_type'] ?? 'RESPONSE' ) ) );
		if ( ! in_array( $messaging_type, self::MESSAGING_TYPES, true ) ) {
			$messaging_type = 'RESPONSE';
		}

		return $this->sendMessage( $page['id'], $page['access_token'], $recipient_id, $message, $messaging_type );
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


	private function sendMessage( string $page_id, string $page_token, string $recipient_id, string $message, string $messaging_type ): array|\WP_Error {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $page_id ) . '/messages';

		$result = HttpClient::post(
			$url,
			array(
				'context' => 'Instagram Message Send',
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'recipient'      => array( 'id' => $recipient_id ),
						'message'        => array( 'text' => $message ),
						'messaging_type' => $messaging_type,
						'access_token'   => $page_token,
					)
				),
			)
		);

		$body = isset( $result['data'] ) && is_string( $result['data'] ) ? json_decode( $result['data'], true ) : null;

		if ( empty( $result['success'] ) || isset( $body['error'] ) ) {
			return $this->sendError( $result, $body );
		}

		return array(
			'success' => true,
			'data'    => array(
				'recipient_id'   => $body['recipient_id'] ?? $recipient_id,
				'message_id'     => $body['message_id'] ?? '',
				'messaging_type' => $messaging_type,
				'text'           => $message,
			),
		);
	}

	/**
	 * Map a failed send to the standard error surface.
	 *
	 * The 24-hour messaging window violation (Meta error 10, subcode
	 * 2018278) gets its own human-readable WP_Error instead of a raw
	 * Graph error.
	 *
	 * @param array     $result HttpClient result.
	 * @param array|null $body  Decoded Graph response body, when present.
	 * @return \WP_Error
	 */
	private function sendError( array $result, ?array $body ): \WP_Error {
		$graph_error = is_array( $body ) ? ( $body['error'] ?? null ) : null;

		if ( is_array( $graph_error )
			&& (int) ( $graph_error['code'] ?? 0 ) === self::WINDOW_ERROR_CODE
			&& (int) ( $graph_error['error_subcode'] ?? 0 ) === self::WINDOW_ERROR_SUBCODE
		) {
			return new \WP_Error(
				'instagram_message_window_closed',
				__( 'Instagram message window closed: the 24-hour reply window for this conversation has expired, so the message was not sent.', 'data-machine-socials' ),
				array( 'status' => 400 )
			);
		}

		return new \WP_Error(
			'api_error',
			( is_array( $graph_error ) ? ( $graph_error['message'] ?? null ) : null ) ?? ( $result['error'] ?? 'Failed to send Instagram message' ),
			array( 'status' => 500 )
		);
	}
}
