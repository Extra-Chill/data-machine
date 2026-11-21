<?php
/**
 * Normalized external channel message value object.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable normalized message shape shared by direct channels and bridges.
 */
final class WP_Agent_External_Message {

	private const ROOM_KINDS = array( 'dm', 'group', 'channel' );

	public readonly string $text;
	public readonly string $connector_id;
	public readonly string $external_provider;
	public readonly ?string $external_conversation_id;
	public readonly ?string $external_message_id;
	public readonly ?string $sender_id;
	public readonly bool $from_self;
	public readonly ?string $room_kind;
	/** @var array<int, mixed> */
	public readonly array $attachments;
	/** @var array<string, mixed> */
	public readonly array $raw;

	/**
	 * @param string              $text                     User-visible message text.
	 * @param string              $connector_id             Connector or channel instance id.
	 * @param string              $external_provider        External network/provider id.
	 * @param string|null         $external_conversation_id Opaque external conversation id.
	 * @param string|null         $external_message_id      Opaque external message id.
	 * @param string|null         $sender_id                Opaque sender id.
	 * @param bool                $from_self                Whether this message came from the bot/client itself.
	 * @param string|null         $room_kind                dm, group, channel, or null.
	 * @param array<int, mixed>   $attachments              Runtime-defined attachment payloads.
	 * @param array<string,mixed> $raw                      Original payload or safe subset for diagnostics.
	 */
	public function __construct(
		string $text,
		string $connector_id,
		string $external_provider,
		?string $external_conversation_id = null,
		?string $external_message_id = null,
		?string $sender_id = null,
		bool $from_self = false,
		?string $room_kind = null,
		array $attachments = array(),
		array $raw = array()
	) {
		$this->text                     = trim( $text );
		$this->connector_id             = self::normalize_required_slug( $connector_id, 'connector_id' );
		$this->external_provider        = self::normalize_required_slug( $external_provider, 'external_provider' );
		$this->external_conversation_id = self::normalize_optional_string( $external_conversation_id );
		$this->external_message_id      = self::normalize_optional_string( $external_message_id );
		$this->sender_id                = self::normalize_optional_string( $sender_id );
		$this->from_self                = $from_self;
		$this->room_kind                = self::normalize_room_kind( $room_kind );
		$this->attachments              = array_values( $attachments );
		$this->raw                      = $raw;
	}

	/**
	 * Build a normalized message from an array payload.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			self::string_value( $data['text'] ?? '' ),
			self::string_value( $data['connector_id'] ?? '' ),
			self::string_value( $data['external_provider'] ?? '' ),
			self::nullable_string_value( $data['external_conversation_id'] ?? null ),
			self::nullable_string_value( $data['external_message_id'] ?? null ),
			self::nullable_string_value( $data['sender_id'] ?? null ),
			(bool) ( $data['from_self'] ?? false ),
			self::nullable_string_value( $data['room_kind'] ?? null ),
			isset( $data['attachments'] ) && is_array( $data['attachments'] ) ? array_values( $data['attachments'] ) : array(),
			isset( $data['raw'] ) && is_array( $data['raw'] ) ? self::string_keyed_array( $data['raw'] ) : array()
		);
	}

	/**
	 * Return the canonical array shape.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'text'                     => $this->text,
			'connector_id'             => $this->connector_id,
			'external_provider'        => $this->external_provider,
			'external_conversation_id' => $this->external_conversation_id,
			'external_message_id'      => $this->external_message_id,
			'sender_id'                => $this->sender_id,
			'from_self'                => $this->from_self,
			'room_kind'                => $this->room_kind,
			'attachments'              => $this->attachments,
			'raw'                      => $this->raw,
		);
	}

	/**
	 * Return the chat ability client_context subset.
	 *
	 * @param string $source channel, bridge, rest, or block.
	 * @return array<string,mixed>
	 */
	public function client_context( string $source = 'channel' ): array {
		return array(
			'source'                   => $source,
			'connector_id'             => $this->connector_id,
			'client_name'              => $this->connector_id,
			'external_provider'        => $this->external_provider,
			'external_conversation_id' => $this->external_conversation_id,
			'external_message_id'      => $this->external_message_id,
			'sender_id'                => $this->sender_id,
			'room_kind'                => $this->room_kind,
		);
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) || $value instanceof \Stringable ? (string) $value : '';
	}

	private static function nullable_string_value( mixed $value ): ?string {
		return null === $value ? null : self::string_value( $value );
	}

	/**
	 * @param array<mixed> $data
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $data ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}

	private static function normalize_required_slug( string $value, string $field ): string {
		$value = trim( strtolower( str_replace( '_', '-', $value ) ) );
		if ( '' === $value || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $value ) ) {
			if ( 'connector_id' === $field ) {
				throw new InvalidArgumentException( 'connector_id must be a non-empty slug.' );
			}
			throw new InvalidArgumentException( 'external_provider must be a non-empty slug.' );
		}
		return $value;
	}

	private static function normalize_optional_string( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$value = trim( $value );
		return '' === $value ? null : $value;
	}

	private static function normalize_room_kind( ?string $room_kind ): ?string {
		$room_kind = self::normalize_optional_string( $room_kind );
		if ( null === $room_kind ) {
			return null;
		}
		if ( ! in_array( $room_kind, self::ROOM_KINDS, true ) ) {
			throw new InvalidArgumentException( 'room_kind must be dm, group, channel, or null.' );
		}
		return $room_kind;
	}
}
