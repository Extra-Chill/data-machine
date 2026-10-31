<?php
/**
 * WP_Agent_Capability_Ceiling value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Capability_Ceiling' ) ) {
	/**
	 * Capability ceiling for an agent execution.
	 *
	 * The ceiling is the intersection of the acting user's WordPress capabilities
	 * and optional token/client capability restrictions. Hosts decide how broad
	 * the capability vocabulary is; Agents API keeps the shape generic.
	 */
	final class WP_Agent_Capability_Ceiling {

		/**
		 * @param int             $user_id              WordPress user ID that bounds execution.
		 * @param string[]|null   $allowed_capabilities Optional capability allow-list. Null means unrestricted by token/client.
		 * @param array<string,mixed> $metadata          Host-owned metadata about how this ceiling was derived.
		 * @param string[]|null   $denied_capabilities   Optional capability deny-list. Takes precedence over the allow-list.
		 */
		public function __construct(
			public readonly int $user_id,
			public readonly ?array $allowed_capabilities = null,
			public readonly array $metadata = array(),
			public readonly ?array $denied_capabilities = null,
		) {
			if ( $this->user_id < 0 ) {
				throw self::invalid( 'user_id', 'must be zero or a positive integer' );
			}

			if ( null !== $this->allowed_capabilities ) {
				foreach ( $this->allowed_capabilities as $capability ) {
					if ( '' === trim( $capability ) ) {
						throw self::invalid( 'allowed_capabilities', 'must contain non-empty capability strings' );
					}
				}
			}

			if ( null !== $this->denied_capabilities ) {
				foreach ( $this->denied_capabilities as $capability ) {
					if ( '' === trim( $capability ) ) {
						throw self::invalid( 'denied_capabilities', 'must contain non-empty capability strings' );
					}
				}
			}

			if ( false === self::json_encode( $this->metadata ) ) {
				throw self::invalid( 'metadata', 'must be JSON serializable' );
			}
		}

		/**
		 * Build a ceiling from a raw array.
		 *
		 * @param array<string,mixed> $ceiling Raw ceiling fields.
		 */
		public static function from_array( array $ceiling ): self {
			return new self(
				isset( $ceiling['user_id'] ) && is_scalar( $ceiling['user_id'] ) ? self::int_value( $ceiling['user_id'] ) : 0,
				array_key_exists( 'allowed_capabilities', $ceiling ) && null !== $ceiling['allowed_capabilities'] ? self::string_list( $ceiling['allowed_capabilities'] ) : null,
				isset( $ceiling['metadata'] ) && is_array( $ceiling['metadata'] ) ? self::string_keyed_array( $ceiling['metadata'] ) : array(),
				array_key_exists( 'denied_capabilities', $ceiling ) && null !== $ceiling['denied_capabilities'] ? self::string_list( $ceiling['denied_capabilities'] ) : null,
			);
		}

		/**
		 * Whether this ceiling has a token/client capability allow-list restriction.
		 */
		public function has_capability_restrictions(): bool {
			return null !== $this->allowed_capabilities;
		}

		/**
		 * Whether this ceiling carries a capability deny-list restriction.
		 */
		public function has_denied_capabilities(): bool {
			return null !== $this->denied_capabilities;
		}

		/**
		 * Whether token/client restrictions allow the capability.
		 *
		 * This does not call WordPress. It only answers whether the local ceiling
		 * restrictions include the requested capability. A denied capability is
		 * never allowed, even when the allow-list is unrestricted.
		 *
		 * @param string $capability WordPress capability name.
		 */
		public function allows_capability( string $capability ): bool {
			$capability = trim( $capability );
			if ( '' === $capability ) {
				return false;
			}

			if ( null !== $this->denied_capabilities && in_array( $capability, $this->denied_capabilities, true ) ) {
				return false;
			}

			if ( null === $this->allowed_capabilities ) {
				return true;
			}

			return in_array( $capability, $this->allowed_capabilities, true );
		}

		/**
		 * Return a copy with a narrower capability allow-list.
		 *
		 * @param string[] $allowed_capabilities Allowed capability names.
		 */
		public function with_allowed_capabilities( array $allowed_capabilities ): self {
			return new self( $this->user_id, array_values( $allowed_capabilities ), $this->metadata, $this->denied_capabilities );
		}

		/**
		 * Return a copy with an additional capability deny-list.
		 *
		 * @param string[] $denied_capabilities Denied capability names.
		 */
		public function with_denied_capabilities( array $denied_capabilities ): self {
			return new self( $this->user_id, $this->allowed_capabilities, $this->metadata, array_values( $denied_capabilities ) );
		}

		/**
		 * Export the ceiling to a stable JSON-friendly shape.
		 *
		 * @return array<string,mixed>
		 */
		public function to_array(): array {
			return array(
				'user_id'              => $this->user_id,
				'allowed_capabilities' => $this->allowed_capabilities,
				'denied_capabilities'  => $this->denied_capabilities,
				'metadata'             => $this->metadata,
			);
		}

		/**
		 * Encode JSON without throwing on older PHP configurations.
		 *
		 * @param mixed $value Value to encode.
		 * @return string|false
		 */
		private static function json_encode( $value ) {
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure value object also runs outside WordPress in smoke tests.
				return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
			} catch ( JsonException $e ) {
				return false;
			}
		}

		/**
		 * Build a machine-readable validation exception.
		 */
		private static function invalid( string $path, string $reason ): InvalidArgumentException {
			return new InvalidArgumentException( 'invalid_wp_agent_capability_ceiling: ' . $path . ' ' . $reason );
		}

		/**
		 * @param int|float|string|bool $value Scalar value.
		 */
		private static function int_value( $value ): int {
			return (int) $value;
		}

		/**
		 * @param mixed $value Raw capability list.
		 * @return string[]
		 */
		private static function string_list( $value ): array {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$strings = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$strings[] = (string) $item;
				}
			}

			return $strings;
		}

		/**
		 * @param array<mixed,mixed> $value Raw metadata.
		 * @return array<string,mixed>
		 */
		private static function string_keyed_array( array $value ): array {
			$result = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) ) {
					$result[ $key ] = $item;
				}
			}

			return $result;
		}
	}
}
