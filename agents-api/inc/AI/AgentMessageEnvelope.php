<?php
/**
 * JSON-friendly agent message envelope contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes agent messages into the canonical typed envelope.
 */
class AgentMessageEnvelope {

	public const SCHEMA  = 'agents-api.message';
	public const VERSION = 1;

	public const TYPE_TEXT              = 'text';
	public const TYPE_TOOL_CALL         = 'tool_call';
	public const TYPE_TOOL_RESULT       = 'tool_result';
	public const TYPE_INPUT_REQUIRED    = 'input_required';
	public const TYPE_APPROVAL_REQUIRED = 'approval_required';
	public const TYPE_FINAL_RESULT      = 'final_result';
	public const TYPE_ERROR             = 'error';
	public const TYPE_DELTA             = 'delta';
	public const TYPE_MULTIMODAL_PART   = 'multimodal_part';

	private const SUPPORTED_TYPES = array(
		self::TYPE_TEXT,
		self::TYPE_TOOL_CALL,
		self::TYPE_TOOL_RESULT,
		self::TYPE_INPUT_REQUIRED,
		self::TYPE_APPROVAL_REQUIRED,
		self::TYPE_FINAL_RESULT,
		self::TYPE_ERROR,
		self::TYPE_DELTA,
		self::TYPE_MULTIMODAL_PART,
	);

	/**
	 * Return the supported envelope type names.
	 *
	 * @return array<int, string>
	 */
	public static function supported_types(): array {
		return self::SUPPORTED_TYPES;
	}

	/**
	 * Build a canonical text envelope.
	 *
	 * @param string       $role     Message role.
	 * @param string|array $content  Message content.
	 * @param array        $metadata Extension metadata.
	 * @return array<string, mixed>
	 */
	public static function text( string $role, $content, array $metadata = array() ): array {
		return self::buildEnvelope( $role, $content, self::inferContentType( $content, $metadata ), array(), $metadata, array() );
	}

	/**
	 * Build a canonical tool-call envelope.
	 *
	 * @param string $content    Human-readable tool-call content.
	 * @param string $tool_name  Tool identifier.
	 * @param array  $parameters Tool parameters.
	 * @param int    $turn       Conversation turn.
	 * @param array  $metadata   Extension metadata.
	 * @return array<string, mixed>
	 */
	public static function toolCall( string $content, string $tool_name, array $parameters, int $turn, array $metadata = array() ): array {
		return self::buildEnvelope(
			'assistant',
			$content,
			self::TYPE_TOOL_CALL,
			array(
				'tool_name'  => $tool_name,
				'parameters' => $parameters,
				'turn'       => $turn,
			),
			$metadata,
			array()
		);
	}

	/**
	 * Build a canonical tool-result envelope.
	 *
	 * @param string $content  Human-readable tool-result content.
	 * @param string $tool_name Tool identifier.
	 * @param array  $payload  Type-specific result payload.
	 * @param array  $metadata Extension metadata.
	 * @return array<string, mixed>
	 */
	public static function toolResult( string $content, string $tool_name, array $payload, array $metadata = array() ): array {
		$payload['tool_name'] = $tool_name;

		return self::buildEnvelope( 'user', $content, self::TYPE_TOOL_RESULT, $payload, $metadata, array() );
	}

	/**
	 * Normalize a legacy message or typed envelope to the canonical envelope.
	 *
	 * @param array $message Message array.
	 * @return array<string, mixed> Normalized envelope.
	 * @throws \InvalidArgumentException When the message is invalid.
	 */
	public static function normalize( array $message ): array {
		$envelope = self::isEnvelope( $message )
			? self::normalizeEnvelope( $message )
			: self::fromLegacyMessage( $message );

		if ( false === self::jsonEncode( $envelope ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_message_envelope: envelope must be JSON serializable' );
		}

		return $envelope;
	}

	/**
	 * Normalize a list of messages to envelopes.
	 *
	 * @param array $messages Message arrays.
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalize_many( array $messages ): array {
		$normalized = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				throw new \InvalidArgumentException( 'invalid_ai_message_envelope: message must be an array' );
			}
			$normalized[] = self::normalize( $message );
		}
		return $normalized;
	}

	/**
	 * Project an envelope to a provider request message shape.
	 *
	 * @param array $message Typed envelope or legacy message.
	 * @return array<string, mixed> Provider-facing message.
	 */
	public static function to_provider_message( array $message ): array {
		$envelope = self::normalize( $message );
		$metadata = $envelope['metadata'];

		if ( self::TYPE_TEXT !== $envelope['type'] || ! empty( $envelope['payload'] ) || ! empty( $metadata ) ) {
			$metadata['type'] = $envelope['type'];
		}
		foreach ( $envelope['payload'] as $key => $value ) {
			if ( ! array_key_exists( $key, $metadata ) ) {
				$metadata[ $key ] = $value;
			}
		}

		$provider_message = array(
			'role'    => $envelope['role'],
			'content' => $envelope['content'],
		);

		if ( ! empty( $metadata ) ) {
			$provider_message['metadata'] = $metadata;
		}

		return $provider_message;
	}

	/**
	 * Project envelopes to a provider request message shape.
	 *
	 * @param array $messages Typed envelopes or legacy messages.
	 * @return array<int, array<string, mixed>> Provider-facing messages.
	 */
	public static function to_provider_messages( array $messages ): array {
		$provider_messages = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				throw new \InvalidArgumentException( 'invalid_ai_message_envelope: message must be an array' );
			}
			$provider_messages[] = self::to_provider_message( $message );
		}
		return $provider_messages;
	}

	/**
	 * Extract the canonical type from a message.
	 *
	 * @param array $message Typed envelope or legacy message.
	 * @return string Message type.
	 */
	public static function type( array $message ): string {
		$envelope = self::normalize( $message );
		return $envelope['type'];
	}

	/**
	 * Detect whether an array already uses the envelope shape.
	 *
	 * @param array $message Message array.
	 * @return bool
	 */
	private static function isEnvelope( array $message ): bool {
		return isset( $message['version'], $message['type'] )
			&& ( isset( $message['schema'] ) || array_key_exists( 'payload', $message ) || array_key_exists( 'data', $message ) );
	}

	/**
	 * Normalize an already-typed envelope.
	 *
	 * @param array $message Raw envelope.
	 * @return array<string, mixed> Canonical envelope.
	 */
	private static function normalizeEnvelope( array $message ): array {
		if ( self::VERSION !== (int) $message['version'] ) {
			throw new \InvalidArgumentException( 'invalid_ai_message_envelope: unsupported version' );
		}

		$type    = self::normalizeType( $message['type'] ?? self::TYPE_TEXT );
		$payload = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();

		if ( empty( $payload ) && is_array( $message['data'] ?? null ) ) {
			$payload = $message['data'];
		}

		return self::buildEnvelope(
			$message['role'] ?? self::roleForType( $type ),
			$message['content'] ?? '',
			$type,
			$payload,
			is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array(),
			$message
		);
	}

	/**
	 * Normalize the legacy role/content/metadata message shape.
	 *
	 * @param array $message Legacy message.
	 * @return array<string, mixed> Canonical envelope.
	 */
	private static function fromLegacyMessage( array $message ): array {
		$metadata = is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array();
		$type     = self::inferType( $message, $metadata );

		return self::buildEnvelope(
			$message['role'] ?? self::roleForType( $type ),
			$message['content'] ?? '',
			$type,
			self::payloadFromLegacyMetadata( $type, $metadata ),
			$metadata,
			$message
		);
	}

	/**
	 * Build a canonical envelope with common optional fields preserved.
	 *
	 * @param mixed  $role     Raw role.
	 * @param mixed  $content  Raw content.
	 * @param string $type     Envelope type.
	 * @param array  $payload  Type-specific payload.
	 * @param array  $metadata Extension metadata.
	 * @param array  $source   Source message.
	 * @return array<string, mixed> Canonical envelope.
	 */
	private static function buildEnvelope( $role, $content, string $type, array $payload, array $metadata, array $source ): array {
		if ( ! is_string( $role ) || '' === $role ) {
			throw new \InvalidArgumentException( 'invalid_ai_message_envelope: role must be a non-empty string' );
		}

		if ( ! is_string( $content ) && ! is_array( $content ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_message_envelope: content must be a string or array' );
		}

		$envelope = array(
			'schema'   => self::SCHEMA,
			'version'  => self::VERSION,
			'type'     => self::normalizeType( $type ),
			'role'     => $role,
			'content'  => $content,
			'payload'  => $payload,
			'metadata' => $metadata,
		);

		foreach ( array( 'id', 'created_at', 'updated_at' ) as $field ) {
			if ( isset( $source[ $field ] ) && is_string( $source[ $field ] ) && '' !== $source[ $field ] ) {
				$envelope[ $field ] = $source[ $field ];
			}
		}

		return $envelope;
	}

	/**
	 * Infer the typed envelope event from legacy message fields.
	 *
	 * @param array $message  Legacy message.
	 * @param array $metadata Legacy metadata.
	 * @return string Envelope type.
	 */
	private static function inferType( array $message, array $metadata ): string {
		$metadata_type = is_string( $metadata['type'] ?? null ) ? $metadata['type'] : '';
		if ( in_array( $metadata_type, self::SUPPORTED_TYPES, true ) ) {
			return $metadata_type;
		}

		if ( 'multimodal' === $metadata_type || is_array( $message['content'] ?? null ) ) {
			return self::TYPE_MULTIMODAL_PART;
		}

		if ( isset( $metadata['error'] ) ) {
			return self::TYPE_ERROR;
		}

		return self::TYPE_TEXT;
	}

	/**
	 * Infer the type for newly built messages.
	 *
	 * @param mixed $content  Message content.
	 * @param array $metadata Message metadata.
	 * @return string Envelope type.
	 */
	private static function inferContentType( $content, array $metadata ): string {
		return self::inferType( array( 'content' => $content ), $metadata );
	}

	/**
	 * Pull common legacy metadata fields into type-specific envelope payload.
	 *
	 * @param string $type     Envelope type.
	 * @param array  $metadata Legacy metadata.
	 * @return array<string, mixed> Type-specific payload.
	 */
	private static function payloadFromLegacyMetadata( string $type, array $metadata ): array {
		$payload = array();

		if ( self::TYPE_TOOL_CALL === $type ) {
			foreach ( array( 'tool_name', 'parameters', 'turn' ) as $key ) {
				if ( array_key_exists( $key, $metadata ) ) {
					$payload[ $key ] = $metadata[ $key ];
				}
			}
		}

		if ( self::TYPE_TOOL_RESULT === $type ) {
			foreach ( array( 'tool_name', 'success', 'turn', 'tool_data', 'media', 'error' ) as $key ) {
				if ( array_key_exists( $key, $metadata ) ) {
					$payload[ $key ] = $metadata[ $key ];
				}
			}
		}

		return $payload;
	}

	/**
	 * Normalize and validate a message type.
	 *
	 * @param mixed $type Raw type.
	 * @return string Supported type.
	 */
	private static function normalizeType( $type ): string {
		if ( ! is_string( $type ) || ! in_array( $type, self::SUPPORTED_TYPES, true ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_message_envelope: unsupported type' );
		}
		return $type;
	}

	/**
	 * Encode data for serializability checks with a pure-PHP fallback for smokes.
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false Encoded JSON or false on failure.
	 */
	private static function jsonEncode( $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
		return json_encode( $data );
	}

	/**
	 * Pick a default role for future typed envelopes that omit one.
	 *
	 * @param string $type Envelope type.
	 * @return string Role.
	 */
	private static function roleForType( string $type ): string {
		if ( in_array( $type, array( self::TYPE_TOOL_RESULT, self::TYPE_INPUT_REQUIRED, self::TYPE_APPROVAL_REQUIRED ), true ) ) {
			return 'tool';
		}

		return 'assistant';
	}
}
