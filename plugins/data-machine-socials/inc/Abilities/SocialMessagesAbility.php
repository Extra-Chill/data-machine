<?php
/**
 * Generic account-level social direct messages ability.
 *
 * @package DataMachineSocials
 */

namespace DataMachineSocials\Abilities;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Read conversations and messages and send replies without requiring callers
 * to enumerate platform abilities.
 */
class SocialMessagesAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	/**
	 * Provider map of read and send ability slugs. Facebook can be added
	 * later with the same shape.
	 */
	private const PROVIDERS = array(
		'instagram' => array(
			'read' => 'datamachine/instagram-read',
			'send' => 'datamachine/instagram-message-send',
		),
	);

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/social-messages',
				array(
					'label'               => __( 'Read and Send Social Direct Messages', 'data-machine-socials' ),
					'description'         => __( 'Read conversations and messages and send replies through a normalized provider-independent contract.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'          => array(
								'type'    => 'string',
								'enum'    => array( 'conversations', 'messages', 'send' ),
								'default' => 'conversations',
							),
							'provider'        => array(
								'type'        => 'string',
								'description' => __( 'Provider slug.', 'data-machine-socials' ),
							),
							'conversation_id' => array(
								'type'        => 'string',
								'description' => __( 'Conversation ID (required for the messages action).', 'data-machine-socials' ),
							),
							'recipient_id'    => array(
								'type'        => 'string',
								'description' => __( 'Recipient platform-scoped user ID (required for the send action).', 'data-machine-socials' ),
							),
							'message'         => array(
								'type'        => 'string',
								'description' => __( 'Message text (required for the send action).', 'data-machine-socials' ),
							),
							'messaging_type'  => array(
								'type'        => 'string',
								'description' => __( 'Optional messaging type for the send action (provider-specific).', 'data-machine-socials' ),
							),
							'limit'           => array(
								'type'        => 'integer',
								'default'     => 25,
								'description' => __( 'Maximum conversations to return.', 'data-machine-socials' ),
							),
							'user_id'         => array(
								'type'        => 'string',
								'description' => __( 'Optional platform-scoped user ID to fetch a single conversation thread.', 'data-machine-socials' ),
							),
							'after'           => array(
								'type'        => 'string',
								'description' => __( 'Provider cursor for the next conversations page.', 'data-machine-socials' ),
							),
						),
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

	/**
	 * Return the stable account-level result envelope.
	 *
	 * Provider reads and sends are deliberately performed through existing
	 * platform abilities; this layer owns only aggregation and normalization.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute( array $input ): array {
		$provider = sanitize_key( $input['provider'] ?? '' );

		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return $this->failure( $provider, 'unsupported', 'Direct messages are not supported for this provider.' );
		}

		switch ( $input['action'] ?? 'conversations' ) {
			case 'conversations':
				return $this->listConversations( $provider, $input );
			case 'messages':
				return $this->listMessages( $provider, $input );
			case 'send':
				return $this->sendMessage( $provider, $input );
			default:
				return $this->failure( $provider, 'invalid_action', 'Unknown action. Use conversations, messages, or send.' );
		}
	}

	private function listConversations( string $provider, array $input ): array {
		$base = array(
			'provider'      => $provider,
			'conversations' => array(),
			'count'         => 0,
			'partial'       => false,
			'status'        => 'ok',
			'next_cursor'   => null,
		);

		$ability = $this->getProviderAbility( $provider, 'read' );
		if ( ! $ability ) {
			return $this->failure( $provider, 'provider_error', 'The provider read ability is not available.', $base );
		}

		$payload = array(
			'action' => 'conversations',
			'limit'  => min( max( absint( $input['limit'] ?? 25 ), 1 ), 100 ),
		);
		if ( ! empty( $input['user_id'] ) ) {
			$payload['user_id'] = sanitize_text_field( (string) $input['user_id'] );
		}
		if ( ! empty( $input['after'] ) ) {
			$payload['after'] = sanitize_text_field( (string) $input['after'] );
		}

		$result = $ability->execute( $payload );
		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$base['status'] = 'provider_error';
			$base['error']  = $this->errorMessage( $result, 'Provider conversations read failed.' );
			return array(
				'success' => false,
				'data'    => $base,
				'error'   => $base['error'],
			);
		}

		foreach ( $result['data']['conversations'] ?? array() as $conversation ) {
			if ( is_array( $conversation ) ) {
				$base['conversations'][] = $this->normalizeConversation( $conversation, $provider );
			}
		}

		$base['count']       = count( $base['conversations'] );
		$base['next_cursor'] = $result['data']['cursors']['after'] ?? null;

		return array(
			'success' => true,
			'data'    => $base,
		);
	}

	private function listMessages( string $provider, array $input ): array {
		$conversation_id = sanitize_text_field( (string) ( $input['conversation_id'] ?? '' ) );
		if ( '' === $conversation_id ) {
			return $this->failure( $provider, 'invalid_input', 'conversation_id is required for the messages action.' );
		}

		$base = array(
			'provider'        => $provider,
			'conversation_id' => $conversation_id,
			'messages'        => array(),
			'count'           => 0,
			'status'          => 'ok',
		);

		$ability = $this->getProviderAbility( $provider, 'read' );
		if ( ! $ability ) {
			return $this->failure( $provider, 'provider_error', 'The provider read ability is not available.', $base );
		}

		$result = $ability->execute( array(
			'action'          => 'messages',
			'conversation_id' => $conversation_id,
		) );
		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$base['status'] = 'provider_error';
			$base['error']  = $this->errorMessage( $result, 'Provider messages read failed.' );
			return array(
				'success' => false,
				'data'    => $base,
				'error'   => $base['error'],
			);
		}

		foreach ( $result['data']['messages'] ?? array() as $message ) {
			if ( is_array( $message ) ) {
				$base['messages'][] = $this->normalizeMessage( $message, $provider, $conversation_id );
			}
		}

		$base['count'] = count( $base['messages'] );

		return array(
			'success' => true,
			'data'    => $base,
		);
	}

	private function sendMessage( string $provider, array $input ): array {
		$recipient_id = sanitize_text_field( (string) ( $input['recipient_id'] ?? '' ) );
		$message      = trim( (string) ( $input['message'] ?? '' ) );

		if ( '' === $recipient_id || '' === $message ) {
			return $this->failure( $provider, 'invalid_input', 'recipient_id and message are required for the send action.' );
		}

		$base = array(
			'provider'     => $provider,
			'recipient_id' => $recipient_id,
			'status'       => 'sent',
		);

		$ability = $this->getProviderAbility( $provider, 'send' );
		if ( ! $ability ) {
			return $this->failure( $provider, 'unsupported', 'Sending messages is not supported for this provider.' );
		}

		$payload = array(
			'recipient_id' => $recipient_id,
			'message'      => $message,
		);
		if ( ! empty( $input['messaging_type'] ) ) {
			$payload['messaging_type'] = sanitize_text_field( (string) $input['messaging_type'] );
		}

		$result = $ability->execute( $payload );
		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$base['status'] = 'provider_error';
			$base['error']  = $this->errorMessage( $result, 'Provider message send failed.' );
			return array(
				'success' => false,
				'data'    => $base,
				'error'   => $base['error'],
			);
		}

		$base['result'] = $result['data'] ?? array();

		return array(
			'success' => true,
			'data'    => $base,
		);
	}

	/**
	 * Normalize a provider conversation into the generic shape.
	 *
	 * Shape: { id, platform, participant: {id, username}, updated_time,
	 *          unread_count, raw }
	 *
	 * Accepts both platform-normalized and raw provider items so future
	 * providers can plug in without changing this contract.
	 */
	private function normalizeConversation( array $conversation, string $provider ): array {
		$participant = $conversation['participant'] ?? null;
		if ( ! is_array( $participant ) ) {
			$participants = $conversation['participants']['data'] ?? array();
			$first        = ( is_array( $participants ) && ! empty( $participants ) ) ? reset( $participants ) : array();
			$participant  = array(
				'id'       => (string) ( $first['id'] ?? '' ),
				'username' => (string) ( $first['username'] ?? '' ),
			);
		} else {
			$participant = array(
				'id'       => (string) ( $participant['id'] ?? '' ),
				'username' => (string) ( $participant['username'] ?? '' ),
			);
		}

		return array(
			'id'           => (string) ( $conversation['id'] ?? '' ),
			'platform'     => $provider,
			'participant'  => $participant,
			'updated_time' => (string) ( $conversation['updated_time'] ?? '' ),
			'unread_count' => (int) ( $conversation['unread_count'] ?? 0 ),
			'raw'          => $conversation['raw'] ?? $conversation,
		);
	}

	/**
	 * Normalize a provider message into the generic shape.
	 *
	 * Shape: { id, platform, conversation_id, from: {id, username},
	 *          is_echo, text, attachments[], created_time, raw }
	 *
	 * Accepts both platform-normalized and raw provider items so future
	 * providers can plug in without changing this contract.
	 */
	private function normalizeMessage( array $message, string $provider, string $conversation_id ): array {
		$from = is_array( $message['from'] ?? null ) ? $message['from'] : array();

		$attachments = $message['attachments'] ?? array();
		if ( isset( $attachments['data'] ) && is_array( $attachments['data'] ) ) {
			$attachments = $attachments['data'];
		}
		if ( ! is_array( $attachments ) ) {
			$attachments = array();
		}

		return array(
			'id'              => (string) ( $message['id'] ?? '' ),
			'platform'        => $provider,
			'conversation_id' => (string) ( $message['conversation_id'] ?? $conversation_id ),
			'from'            => array(
				'id'       => (string) ( $from['id'] ?? '' ),
				'username' => (string) ( $from['username'] ?? $from['name'] ?? '' ),
			),
			'is_echo'         => (bool) ( $message['is_echo'] ?? false ),
			'text'            => (string) ( $message['text'] ?? $message['message'] ?? '' ),
			'attachments'     => array_values( $attachments ),
			'created_time'    => (string) ( $message['created_time'] ?? '' ),
			'raw'             => $message['raw'] ?? $message,
		);
	}

	/** Resolve a provider read/send ability slug. Overridable for contract tests. */
	protected function getProviderAbility( string $provider, string $kind = 'read' ) {
		$slug = self::PROVIDERS[ $provider ][ $kind ] ?? null;
		if ( ! $slug ) {
			return null;
		}

		return wp_get_ability( $slug );
	}

	private function failure( string $provider, string $status, string $error, array $base = array() ): array {
		$data = array_merge(
			array(
				'provider' => $provider,
				'status'   => $status,
			),
			$base,
			array( 'error' => $error )
		);

		return array(
			'success' => false,
			'data'    => $data,
			'error'   => $error,
		);
	}
}
