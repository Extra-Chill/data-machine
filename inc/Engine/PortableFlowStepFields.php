<?php
/**
 * Portable flow-step field normalization primitives.
 *
 * @package DataMachine\Engine
 */

namespace DataMachine\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Shared normalization for portable flow-step boundary fields.
 */
final class PortableFlowStepFields {

	public const PROMPT_QUEUE       = 'prompt_queue';
	public const CONFIG_PATCH_QUEUE = 'config_patch_queue';
	public const FIELD_PROMPT       = 'prompt';
	public const FIELD_PATCH        = 'patch';

	private const STRING_LIST_FIELDS = array( 'handler_slugs', 'enabled_tools', 'disabled_tools' );
	private const QUEUE_MODES        = array( 'drain', 'loop', 'static' );

	/**
	 * Normalize optional portable flow-step settings present in a source array.
	 *
	 * @return array<string,mixed>
	 */
	public static function normalize_settings( array $source, string $message_prefix = '' ): array {
		$normalized = array();

		foreach ( self::STRING_LIST_FIELDS as $field ) {
			if ( array_key_exists( $field, $source ) ) {
				$normalized[ $field ] = self::normalize_field( $field, $source[ $field ], $message_prefix );
			}
		}

		if ( array_key_exists( self::PROMPT_QUEUE, $source ) ) {
			$normalized[ self::PROMPT_QUEUE ] = self::normalize_field( self::PROMPT_QUEUE, $source[ self::PROMPT_QUEUE ], $message_prefix );
		}

		if ( array_key_exists( self::CONFIG_PATCH_QUEUE, $source ) ) {
			$normalized[ self::CONFIG_PATCH_QUEUE ] = self::normalize_field( self::CONFIG_PATCH_QUEUE, $source[ self::CONFIG_PATCH_QUEUE ], $message_prefix );
		}

		if ( array_key_exists( 'queue_mode', $source ) && in_array( $source['queue_mode'], self::QUEUE_MODES, true ) ) {
			$normalized['queue_mode'] = $source['queue_mode'];
		}

		return $normalized;
	}

	/**
	 * Normalize one optional portable flow-step field.
	 *
	 * @return mixed
	 */
	public static function normalize_field( string $field, $value, string $message_prefix = '' ) {
		if ( 'step_type' === $field ) {
			return (string) $value;
		}

		if ( 'enabled' === $field ) {
			return (bool) $value;
		}

		if ( in_array( $field, array( 'flow_step_settings', 'completion_assertions' ), true ) ) {
			if ( ! is_array( $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( sprintf( '%s must be an object.', $field ), $message_prefix ) );
			}
			return $value;
		}

		if ( 'tool_runtime_rules' === $field ) {
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( 'tool_runtime_rules must be a list.', $message_prefix ) );
			}
			return $value;
		}

		if ( in_array( $field, self::STRING_LIST_FIELDS, true ) ) {
			return self::normalize_string_list( $field, $value, $message_prefix );
		}

		if ( self::PROMPT_QUEUE === $field ) {
			return self::normalize_prompt_queue( $value, $message_prefix );
		}

		if ( self::CONFIG_PATCH_QUEUE === $field ) {
			return self::normalize_config_patch_queue( $value, $message_prefix );
		}

		if ( 'queue_mode' === $field ) {
			if ( ! in_array( $value, self::QUEUE_MODES, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( 'queue_mode must be one of drain, loop, static.', $message_prefix ) );
			}
			return $value;
		}

		return $value;
	}

	private static function normalize_string_list( string $field, $value, string $message_prefix ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
			throw new \InvalidArgumentException( self::message( sprintf( '%s must be a list of strings.', $field ), $message_prefix ) );
		}

		$normalized = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( sprintf( '%s must be a list of strings.', $field ), $message_prefix ) );
			}
			$normalized[] = $item;
		}

		return $normalized;
	}

	private static function normalize_prompt_queue( $value, string $message_prefix ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
			throw new \InvalidArgumentException( self::message( 'prompt_queue must be a list of objects.', $message_prefix ) );
		}

		$normalized = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( 'prompt_queue must be a list of objects.', $message_prefix ) );
			}
			if ( ! array_key_exists( self::FIELD_PROMPT, $entry ) || ! is_string( $entry[ self::FIELD_PROMPT ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( 'prompt_queue entries must include a string prompt.', $message_prefix ) );
			}
			if ( array_key_exists( 'added_at', $entry ) && ! is_string( $entry['added_at'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( 'prompt_queue added_at must be a string when present.', $message_prefix ) );
			}
			$normalized[] = $entry;
		}

		return $normalized;
	}

	private static function normalize_config_patch_queue( $value, string $message_prefix ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
			throw new \InvalidArgumentException( self::message( 'config_patch_queue must be a list of objects.', $message_prefix ) );
		}

		$normalized = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( 'config_patch_queue must be a list of objects.', $message_prefix ) );
			}
			if ( ! array_key_exists( self::FIELD_PATCH, $entry ) || ! is_array( $entry[ self::FIELD_PATCH ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( 'config_patch_queue entries must include an object patch.', $message_prefix ) );
			}
			if ( array_key_exists( 'added_at', $entry ) && ! is_string( $entry['added_at'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
				throw new \InvalidArgumentException( self::message( 'config_patch_queue added_at must be a string when present.', $message_prefix ) );
			}
			$normalized[] = $entry;
		}

		return $normalized;
	}

	private static function message( string $message, string $prefix ): string {
		$prefix = trim( $prefix );
		return '' === $prefix ? $message : $prefix . ' ' . $message;
	}
}
