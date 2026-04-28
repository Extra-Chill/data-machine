<?php
/**
 * JSON-friendly AI message envelope contract.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes Data Machine AI messages into a stable typed envelope.
 *
 * The persisted chat/session shape remains `role/content/metadata`; this class
 * gives adapters a versioned JSON object to target without coupling Data
 * Machine core to any host-specific DTO.
 */
class MessageEnvelope {

	public const SCHEMA  = 'datamachine.ai.message';
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
	 * Normalize a legacy message or typed envelope to the canonical envelope.
	 *
	 * @param array $message Message array.
	 * @return array Normalized envelope.
	 * @throws \InvalidArgumentException When the message is invalid.
	 */
	public static function normalize( array $message ): array {
		$envelope = self::is_envelope( $message )
			? self::normalize_envelope( $message )
			: self::from_legacy_message( $message );

		if ( false === self::json_encode( $envelope ) ) {
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
	 * Convert an envelope back to the current persisted Data Machine shape.
	 *
	 * @param array $envelope Typed envelope or legacy message.
	 * @return array Legacy message with role/content/metadata.
	 */
	public static function to_legacy_message( array $envelope ): array {
		$source_is_envelope  = self::is_envelope( $envelope );
		$source_had_metadata = array_key_exists( 'metadata', $envelope );
		$source_metadata     = is_array( $envelope['metadata'] ?? null ) ? $envelope['metadata'] : array();
		$envelope            = self::normalize( $envelope );

		$metadata = $envelope['metadata'];
		if ( $source_is_envelope || self::TYPE_TEXT !== $envelope['type'] || array_key_exists( 'type', $source_metadata ) ) {
			$metadata['type'] = $envelope['type'];
		}

		foreach ( $envelope['data'] as $key => $value ) {
			if ( ! array_key_exists( $key, $metadata ) ) {
				$metadata[ $key ] = $value;
			}
		}

		$message = array(
			'role'    => $envelope['role'],
			'content' => $envelope['content'],
		);

		if ( ! empty( $metadata ) || $source_is_envelope || $source_had_metadata ) {
			$message['metadata'] = $metadata;
		}

		foreach ( array( 'id', 'created_at', 'updated_at' ) as $field ) {
			if ( isset( $envelope[ $field ] ) && is_string( $envelope[ $field ] ) && '' !== $envelope[ $field ] ) {
				$message[ $field ] = $envelope[ $field ];
			}
		}

		return $message;
	}

	/**
	 * Convert a message list back to the current persisted shape.
	 *
	 * @param array $messages Message arrays or envelopes.
	 * @return array<int, array<string, mixed>>
	 */
	public static function to_legacy_messages( array $messages ): array {
		$legacy_messages = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				throw new \InvalidArgumentException( 'invalid_ai_message_envelope: message must be an array' );
			}
			$legacy_messages[] = self::to_legacy_message( $message );
		}
		return $legacy_messages;
	}

	/**
	 * Detect whether an array already uses the envelope shape.
	 *
	 * @param array $message Message array.
	 * @return bool
	 */
	private static function is_envelope( array $message ): bool {
		return isset( $message['version'], $message['type'] )
			&& ( isset( $message['schema'] ) || array_key_exists( 'data', $message ) );
	}

	/**
	 * Normalize an already-typed envelope.
	 *
	 * @param array $message Raw envelope.
	 * @return array Canonical envelope.
	 */
	private static function normalize_envelope( array $message ): array {
		if ( self::VERSION !== (int) $message['version'] ) {
			throw new \InvalidArgumentException( 'invalid_ai_message_envelope: unsupported version' );
		}

		$type = self::normalize_type( $message['type'] ?? self::TYPE_TEXT );

		return self::build_envelope(
			$message['role'] ?? self::role_for_type( $type ),
			$message['content'] ?? '',
			$type,
			is_array( $message['data'] ?? null ) ? $message['data'] : array(),
			is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array(),
			$message
		);
	}

	/**
	 * Normalize the legacy role/content/metadata message shape.
	 *
	 * @param array $message Legacy message.
	 * @return array Canonical envelope.
	 */
	private static function from_legacy_message( array $message ): array {
		$metadata = is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array();
		$type     = self::infer_type( $message, $metadata );

		return self::build_envelope(
			$message['role'] ?? self::role_for_type( $type ),
			$message['content'] ?? '',
			$type,
			self::data_from_legacy_metadata( $type, $metadata ),
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
	 * @param array  $data     Type-specific payload.
	 * @param array  $metadata Extension metadata.
	 * @param array  $source   Source message.
	 * @return array Canonical envelope.
	 */
	private static function build_envelope( $role, $content, string $type, array $data, array $metadata, array $source ): array {
		if ( ! is_string( $role ) || '' === $role ) {
			throw new \InvalidArgumentException( 'invalid_ai_message_envelope: role must be a non-empty string' );
		}

		if ( ! is_string( $content ) && ! is_array( $content ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_message_envelope: content must be a string or array' );
		}

		$envelope = array(
			'schema'   => self::SCHEMA,
			'version'  => self::VERSION,
			'type'     => self::normalize_type( $type ),
			'role'     => $role,
			'content'  => $content,
			'data'     => $data,
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
	private static function infer_type( array $message, array $metadata ): string {
		if ( isset( $metadata['type'] ) && is_string( $metadata['type'] ) && in_array( $metadata['type'], self::SUPPORTED_TYPES, true ) ) {
			return $metadata['type'];
		}

		if ( isset( $metadata['error'] ) ) {
			return self::TYPE_ERROR;
		}

		if ( is_array( $message['content'] ?? null ) ) {
			return self::TYPE_MULTIMODAL_PART;
		}

		return self::TYPE_TEXT;
	}

	/**
	 * Pull common legacy metadata fields into type-specific envelope data.
	 *
	 * @param string $type     Envelope type.
	 * @param array  $metadata Legacy metadata.
	 * @return array Type-specific data.
	 */
	private static function data_from_legacy_metadata( string $type, array $metadata ): array {
		$data = array();

		if ( self::TYPE_TOOL_CALL === $type ) {
			foreach ( array( 'tool_name', 'parameters', 'turn' ) as $key ) {
				if ( array_key_exists( $key, $metadata ) ) {
					$data[ $key ] = $metadata[ $key ];
				}
			}
		}

		if ( self::TYPE_TOOL_RESULT === $type ) {
			foreach ( array( 'tool_name', 'success', 'turn', 'tool_data', 'media', 'error' ) as $key ) {
				if ( array_key_exists( $key, $metadata ) ) {
					$data[ $key ] = $metadata[ $key ];
				}
			}
		}

		return $data;
	}

	/**
	 * Normalize and validate a message type.
	 *
	 * @param mixed $type Raw type.
	 * @return string Supported type.
	 */
	private static function normalize_type( $type ): string {
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
	private static function json_encode( $data ) {
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
	private static function role_for_type( string $type ): string {
		if ( in_array( $type, array( self::TYPE_TOOL_RESULT, self::TYPE_INPUT_REQUIRED, self::TYPE_APPROVAL_REQUIRED ), true ) ) {
			return 'tool';
		}

		return 'assistant';
	}
}
