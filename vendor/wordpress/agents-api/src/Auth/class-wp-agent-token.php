<?php
/**
 * WP_Agent_Token value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Token' ) ) {
	/**
	 * Metadata for a hashed agent bearer token.
	 *
	 * Raw token material is never accepted or exposed by this value object.
	 */
	final class WP_Agent_Token {

		/**
		 * @param int             $token_id             Store-owned token ID.
		 * @param string          $agent_id             Effective agent identifier.
		 * @param int             $owner_user_id        WordPress user ID whose capabilities bound execution.
		 * @param string          $token_hash           Hash of the raw token, never the raw token.
		 * @param string          $token_prefix         Short non-secret token prefix for display/logging.
		 * @param string          $label                Human-readable label.
		 * @param string[]|null   $allowed_capabilities Optional token capability allow-list.
		 * @param string|null     $expires_at           Optional UTC expiry datetime.
		 * @param string|null     $last_used_at         Optional UTC last-used datetime.
		 * @param string|null     $created_at           Optional UTC created datetime.
		 * @param string|null     $client_id            Optional client/login identifier.
		 * @param string|null     $workspace_id         Optional workspace/scope identifier.
		 * @param array<string,mixed> $metadata          Host-owned metadata.
		 */
		public function __construct(
			public readonly int $token_id,
			public readonly string $agent_id,
			public readonly int $owner_user_id,
			public readonly string $token_hash,
			public readonly string $token_prefix,
			public readonly string $label = '',
			public readonly ?array $allowed_capabilities = null,
			public readonly ?string $expires_at = null,
			public readonly ?string $last_used_at = null,
			public readonly ?string $created_at = null,
			public readonly ?string $client_id = null,
			public readonly ?string $workspace_id = null,
			public readonly array $metadata = array(),
		) {
			if ( $this->token_id <= 0 ) {
				throw self::invalid( 'token_id', 'must be a positive integer' );
			}

			if ( '' === trim( $this->agent_id ) ) {
				throw self::invalid( 'agent_id', 'must be a non-empty string' );
			}

			if ( $this->owner_user_id <= 0 ) {
				throw self::invalid( 'owner_user_id', 'must be a positive integer' );
			}

			if ( ! self::is_sha256_hash( $this->token_hash ) ) {
				throw self::invalid( 'token_hash', 'must be a SHA-256 hex hash' );
			}

			if ( '' === trim( $this->token_prefix ) ) {
				throw self::invalid( 'token_prefix', 'must be a non-empty string' );
			}

			if ( null !== $this->allowed_capabilities ) {
				foreach ( $this->allowed_capabilities as $capability ) {
					if ( '' === trim( $capability ) ) {
						throw self::invalid( 'allowed_capabilities', 'must contain non-empty capability strings' );
					}
				}
			}

			if ( false === self::json_encode( $this->metadata ) ) {
				throw self::invalid( 'metadata', 'must be JSON serializable' );
			}
		}

		/**
		 * Hash raw token material for storage/lookup.
		 */
		public static function hash_token( string $raw_token ): string {
			return hash( 'sha256', $raw_token );
		}

		/**
		 * Build token metadata from a raw array.
		 *
		 * @param array<string,mixed> $token Raw token fields.
		 */
		public static function from_array( array $token ): self {
			return new self(
				self::int_field( $token, 'token_id' ),
				self::string_field( $token, 'agent_id' ),
				self::int_field( $token, 'owner_user_id' ),
				self::string_field( $token, 'token_hash' ),
				self::string_field( $token, 'token_prefix' ),
				self::string_field( $token, 'label' ),
				array_key_exists( 'allowed_capabilities', $token ) && null !== $token['allowed_capabilities'] ? self::string_list( $token['allowed_capabilities'] ) : null,
				self::nullable_string_field( $token, 'expires_at' ),
				self::nullable_string_field( $token, 'last_used_at' ),
				self::nullable_string_field( $token, 'created_at' ),
				self::nullable_string_field( $token, 'client_id' ),
				self::nullable_string_field( $token, 'workspace_id' ),
				self::assoc_array_field( $token, 'metadata' )
			);
		}

		/**
		 * @param array<string,mixed> $source Source fields.
		 */
		private static function int_field( array $source, string $key ): int {
			$value = $source[ $key ] ?? null;
			return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (int) $value : 0;
		}

		/**
		 * @param array<string,mixed> $source Source fields.
		 */
		private static function string_field( array $source, string $key ): string {
			$value = $source[ $key ] ?? null;
			return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (string) $value : '';
		}

		/**
		 * @param array<string,mixed> $source Source fields.
		 */
		private static function nullable_string_field( array $source, string $key ): ?string {
			if ( ! array_key_exists( $key, $source ) || null === $source[ $key ] ) {
				return null;
			}

			return self::string_field( $source, $key );
		}

		/**
		 * @param mixed $value Raw list.
		 * @return string[]
		 */
		private static function string_list( $value ): array {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$strings = array();
			foreach ( $value as $item ) {
				if ( is_int( $item ) || is_float( $item ) || is_string( $item ) || is_bool( $item ) ) {
					$strings[] = (string) $item;
				}
			}

			return $strings;
		}

		/**
		 * @param array<string,mixed> $source Source fields.
		 * @return array<string,mixed>
		 */
		private static function assoc_array_field( array $source, string $key ): array {
			$value = $source[ $key ] ?? null;
			if ( ! is_array( $value ) ) {
				return array();
			}

			$assoc = array();
			foreach ( $value as $field => $field_value ) {
				if ( is_string( $field ) ) {
					$assoc[ $field ] = $field_value;
				}
			}

			return $assoc;
		}

		/**
		 * Whether the token is expired at the given Unix timestamp.
		 */
		public function is_expired( ?int $now = null ): bool {
			if ( null === $this->expires_at || '' === $this->expires_at ) {
				return false;
			}

			$expires = strtotime( $this->expires_at );
			if ( false === $expires ) {
				return true;
			}

			return $expires <= ( $now ?? time() );
		}

		/**
		 * Return this token's execution ceiling.
		 */
		public function capability_ceiling(): WP_Agent_Capability_Ceiling {
			return new WP_Agent_Capability_Ceiling(
				$this->owner_user_id,
				$this->allowed_capabilities,
				array(
					'token_id'     => $this->token_id,
					'client_id'    => $this->client_id,
					'workspace_id' => $this->workspace_id,
				)
			);
		}

		/**
		 * Export token metadata without token hash or raw token material.
		 *
		 * @return array<string,mixed>
		 */
		public function to_metadata_array(): array {
			return array(
				'token_id'             => $this->token_id,
				'agent_id'             => $this->agent_id,
				'owner_user_id'        => $this->owner_user_id,
				'token_prefix'         => $this->token_prefix,
				'label'                => $this->label,
				'allowed_capabilities' => $this->allowed_capabilities,
				'expires_at'           => $this->expires_at,
				'last_used_at'         => $this->last_used_at,
				'created_at'           => $this->created_at,
				'client_id'            => $this->client_id,
				'workspace_id'         => $this->workspace_id,
				'metadata'             => $this->metadata,
			);
		}

		/**
		 * Validate a SHA-256 hex hash.
		 */
		private static function is_sha256_hash( string $hash ): bool {
			return 1 === preg_match( '/\A[a-f0-9]{64}\z/i', $hash );
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
			return new InvalidArgumentException( 'invalid_wp_agent_token: ' . $path . ' ' . $reason );
		}
	}
}
