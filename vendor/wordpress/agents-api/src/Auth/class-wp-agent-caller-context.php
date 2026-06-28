<?php
/**
 * WP_Agent_Caller_Context value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Caller_Context' ) ) {
	/**
	 * Caller context claimed by an agent-to-agent request.
	 *
	 * The substrate parses and carries this shape only. Deciding whether to trust
	 * the caller host, token, or chain is a host concern.
	 */
	final class WP_Agent_Caller_Context {

		public const HEADER_CALLER_AGENT = 'X-Agents-Api-Caller-Agent';
		public const HEADER_CALLER_USER  = 'X-Agents-Api-Caller-User';
		public const HEADER_CALLER_HOST  = 'X-Agents-Api-Caller-Host';
		public const HEADER_CHAIN_DEPTH  = 'X-Agents-Api-Chain-Depth';
		public const HEADER_CHAIN_ROOT   = 'X-Agents-Api-Chain-Root';

		public const SELF_HOST               = 'self';
		public const DEFAULT_MAX_CHAIN_DEPTH = 16;

		/**
		 * @param string $caller_agent_id      Agent ID/slug on the caller host. Empty string means top-of-chain.
		 * @param int    $caller_user_id       User ID on the caller host. 0 means no user-on-caller-host.
		 * @param string $caller_host          "self" or an absolute URL for a remote caller host.
		 * @param int    $chain_depth          0 means top-of-chain.
		 * @param string $chain_root_request_id Stable identifier for the originating request.
		 * @param array<mixed>  $metadata             JSON-serializable host-owned extension payload.
		 */
		public function __construct(
			public readonly string $caller_agent_id = '',
			public readonly int $caller_user_id = 0,
			public readonly string $caller_host = self::SELF_HOST,
			public readonly int $chain_depth = 0,
			public readonly string $chain_root_request_id = '',
			public readonly array $metadata = array(),
		) {
			if ( $this->caller_user_id < 0 ) {
				throw self::invalid( 'caller_user_id', 'must be zero or a positive integer' );
			}

			if ( '' === $this->caller_host ) {
				throw self::invalid( 'caller_host', 'must be "self" or an absolute URL' );
			}

			if ( self::SELF_HOST !== $this->caller_host && ! self::is_absolute_url( $this->caller_host ) ) {
				throw self::invalid( 'caller_host', 'must be "self" or an absolute URL' );
			}

			if ( $this->chain_depth < 0 ) {
				throw self::invalid( 'chain_depth', 'must be zero or a positive integer' );
			}

			if ( '' === $this->chain_root_request_id ) {
				throw self::invalid( 'chain_root_request_id', 'must be a non-empty string' );
			}

			if ( 0 === $this->chain_depth && ( '' !== $this->caller_agent_id || 0 !== $this->caller_user_id || self::SELF_HOST !== $this->caller_host ) ) {
				throw self::invalid( 'chain_depth', 'top-of-chain context cannot include remote caller identity' );
			}

			if ( $this->chain_depth > 0 && self::SELF_HOST === $this->caller_host ) {
				throw self::invalid( 'caller_host', 'chained context (chain_depth > 0) must specify a remote caller host, not "self"' );
			}

			if ( false === self::json_encode( $this->metadata ) ) {
				throw self::invalid( 'metadata', 'must be JSON serializable' );
			}
		}

		/**
		 * Build a top-of-chain caller context.
		 *
		 * @param string|null $chain_root_request_id Optional request ID. Generated when omitted.
		 * @return self
		 */
		public static function top_of_chain( ?string $chain_root_request_id = null ): self {
			$chain_root_request_id = null !== $chain_root_request_id && '' !== $chain_root_request_id ? $chain_root_request_id : self::generate_request_id();

			return new self( '', 0, self::SELF_HOST, 0, $chain_root_request_id );
		}

		/**
		 * Parse caller context headers from a request-like source.
		 *
		 * Missing headers produce a top-of-chain context. Malformed caller headers
		 * throw so request-edge authenticators can fail closed.
		 *
		 * @param array<mixed,mixed>|object|null $source Header source. Accepts WP_REST_Request-style get_header() or arrays.
		 * @param int              $max_chain_depth Maximum accepted chain depth.
		 * @return self
		 */
		public static function from_headers( $source = null, int $max_chain_depth = self::DEFAULT_MAX_CHAIN_DEPTH ): self {
			if ( $max_chain_depth < 0 ) {
				throw self::invalid( 'max_chain_depth', 'must be zero or a positive integer' );
			}

			$get = self::header_accessor( $source );
			$raw = array(
				'caller_agent_id'       => trim( $get( self::HEADER_CALLER_AGENT ) ),
				'caller_user_id'        => trim( $get( self::HEADER_CALLER_USER ) ),
				'caller_host'           => trim( $get( self::HEADER_CALLER_HOST ) ),
				'chain_depth'           => trim( $get( self::HEADER_CHAIN_DEPTH ) ),
				'chain_root_request_id' => trim( $get( self::HEADER_CHAIN_ROOT ) ),
			);

			$has_headers = false;
			foreach ( $raw as $value ) {
				if ( '' !== $value ) {
					$has_headers = true;
					break;
				}
			}

			if ( ! $has_headers ) {
				return self::top_of_chain();
			}

			$caller_user_id = self::parse_non_negative_int( $raw['caller_user_id'], 'caller_user_id' );
			$chain_depth    = self::parse_non_negative_int( $raw['chain_depth'], 'chain_depth' );

			if ( $chain_depth > $max_chain_depth ) {
				throw self::invalid( 'chain_depth', 'exceeds maximum chain depth' );
			}

			if ( 0 === $chain_depth ) {
				if ( '' !== $raw['caller_agent_id'] || 0 !== $caller_user_id || '' !== $raw['caller_host'] ) {
					throw self::invalid( 'chain_depth', 'top-of-chain context cannot include remote caller identity' );
				}

				$chain_root_request_id = '' !== $raw['chain_root_request_id'] ? $raw['chain_root_request_id'] : self::generate_request_id();

				return new self( '', 0, self::SELF_HOST, 0, $chain_root_request_id );
			}

			if ( '' === $raw['caller_agent_id'] ) {
				throw self::invalid( 'caller_agent_id', 'must be present when chain_depth is greater than zero' );
			}

			if ( '' === $raw['caller_host'] ) {
				throw self::invalid( 'caller_host', 'must be present when chain_depth is greater than zero' );
			}

			if ( self::SELF_HOST === $raw['caller_host'] ) {
				throw self::invalid( 'caller_host', 'chained context (chain_depth > 0) must specify a remote caller host, not "self"' );
			}

			if ( '' === $raw['chain_root_request_id'] ) {
				throw self::invalid( 'chain_root_request_id', 'must be present when chain_depth is greater than zero' );
			}

			return new self(
				$raw['caller_agent_id'],
				$caller_user_id,
				$raw['caller_host'],
				$chain_depth,
				$raw['chain_root_request_id']
			);
		}

		/**
		 * Export headers for a follow-up agent-to-agent request.
		 *
		 * @return array<string,string>
		 */
		public function to_headers(): array {
			return array(
				self::HEADER_CALLER_AGENT => $this->caller_agent_id,
				self::HEADER_CALLER_USER  => (string) $this->caller_user_id,
				self::HEADER_CALLER_HOST  => $this->caller_host,
				self::HEADER_CHAIN_DEPTH  => (string) $this->chain_depth,
				self::HEADER_CHAIN_ROOT   => $this->chain_root_request_id,
			);
		}

		/**
		 * Whether this context describes a remote caller host.
		 *
		 * @return bool
		 */
		public function is_cross_site(): bool {
			return self::SELF_HOST !== $this->caller_host;
		}

		/**
		 * Build from a JSON-friendly array.
		 *
		 * @param array<string,mixed> $context Raw context.
		 * @return self
		 */
		public static function from_array( array $context ): self {
			return new self(
				isset( $context['caller_agent_id'] ) ? self::string_value( $context['caller_agent_id'] ) : '',
				isset( $context['caller_user_id'] ) ? self::int_value( $context['caller_user_id'] ) : 0,
				isset( $context['caller_host'] ) ? self::string_value( $context['caller_host'] ) : self::SELF_HOST,
				isset( $context['chain_depth'] ) ? self::int_value( $context['chain_depth'] ) : 0,
				isset( $context['chain_root_request_id'] ) ? self::string_value( $context['chain_root_request_id'] ) : '',
				isset( $context['metadata'] ) && is_array( $context['metadata'] ) ? $context['metadata'] : array()
			);
		}

		/**
		 * Export to a stable, JSON-friendly shape.
		 *
		 * @return array<string,mixed>
		 */
		public function to_array(): array {
			return array(
				'caller_agent_id'       => $this->caller_agent_id,
				'caller_user_id'        => $this->caller_user_id,
				'caller_host'           => $this->caller_host,
				'chain_depth'           => $this->chain_depth,
				'chain_root_request_id' => $this->chain_root_request_id,
				'metadata'              => $this->metadata,
			);
		}

		/**
		 * Build a case-insensitive header accessor.
		 *
		 * @param array<mixed,mixed>|object|null $source Header source.
		 * @return callable(string): string
		 */
		private static function header_accessor( $source ): callable {
			if ( is_object( $source ) && method_exists( $source, 'get_header' ) ) {
				return static function ( string $name ) use ( $source ): string {
					$value = $source->get_header( $name );
					return self::string_value( $value );
				};
			}

			$normalized = array();
			if ( is_array( $source ) ) {
				foreach ( $source as $key => $value ) {
					$normalized[ strtolower( self::string_value( $key ) ) ] = is_array( $value ) ? self::string_value( reset( $value ) ) : self::string_value( $value );
				}
			}

			return static function ( string $name ) use ( $normalized ): string {
				return $normalized[ strtolower( $name ) ] ?? '';
			};
		}

		/**
		 * Parse a non-negative integer header value.
		 *
		 * @param string $value Raw value.
		 * @param string $path Field path for validation errors.
		 * @return int
		 */
		private static function parse_non_negative_int( string $value, string $path ): int {
			if ( '' === $value ) {
				return 0;
			}

			if ( ! ctype_digit( $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
				throw self::invalid( $path, 'must be zero or a positive integer' );
			}

			return (int) $value;
		}

		/**
		 * Convert scalar/Stringable input using the same request/header coercion rules.
		 *
		 * @param mixed $value Raw value.
		 * @return string String value, or empty string for non-stringable input.
		 */
		private static function string_value( $value ): string {
			return is_scalar( $value ) || $value instanceof Stringable ? (string) $value : '';
		}

		/**
		 * Convert scalar/Stringable input to an integer.
		 *
		 * @param mixed $value Raw value.
		 * @return int Integer value, or zero for non-stringable input.
		 */
		private static function int_value( $value ): int {
			if ( is_int( $value ) ) {
				return $value;
			}

			if ( is_bool( $value ) ) {
				return $value ? 1 : 0;
			}

			if ( is_float( $value ) ) {
				return (int) $value;
			}

			if ( is_string( $value ) || $value instanceof Stringable ) {
				return (int) (string) $value;
			}

			return 0;
		}

		/**
		 * Whether a string is an absolute HTTP(S) URL.
		 *
		 * @param string $value Value to inspect.
		 * @return bool
		 */
		private static function is_absolute_url( string $value ): bool {
			$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
			$host   = wp_parse_url( $value, PHP_URL_HOST );

			return is_string( $host ) && '' !== $host && in_array( $scheme, array( 'http', 'https' ), true );
		}

		/**
		 * Generate a request/chain identifier.
		 *
		 * @return string
		 */
		private static function generate_request_id(): string {
			if ( function_exists( 'wp_generate_uuid4' ) ) {
				return wp_generate_uuid4();
			}

			return bin2hex( random_bytes( 16 ) );
		}

		/**
		 * Encode JSON without throwing on older PHP configurations.
		 *
		 * @param mixed $value Value to encode.
		 * @return string|false
		 */
		private static function json_encode( $value ) {
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP value object smoke tests run outside WordPress.
				return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
			} catch ( JsonException $e ) {
				return false;
			}
		}

		/**
		 * Build a machine-readable validation exception.
		 *
		 * @param string $path Field path.
		 * @param string $reason Failure reason.
		 * @return InvalidArgumentException Validation exception.
		 */
		private static function invalid( string $path, string $reason ): InvalidArgumentException {
			return new InvalidArgumentException( 'invalid_agent_caller_context: ' . $path . ' ' . $reason );
		}
	}
}
