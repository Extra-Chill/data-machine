<?php
/**
 * WP_Agent_Access_Grant value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Access_Grant' ) ) {
	/**
	 * Role-based access grant between a WordPress user/audience and an agent.
	 */
	final class WP_Agent_Access_Grant {

		public const ROLE_ADMIN    = 'admin';
		public const ROLE_OPERATOR = 'operator';
		public const ROLE_VIEWER   = 'viewer';

		/**
		 * @param string          $agent_id            Registered/effective agent identifier.
		 * @param int             $user_id             WordPress user ID receiving access.
		 * @param string          $role                Access role.
		 * @param string|null     $workspace_id        Optional host workspace/scope identifier.
		 * @param int|null        $grant_id            Optional store-owned grant ID.
		 * @param int|null        $granted_by_user_id  Optional WordPress user ID that created the grant.
		 * @param string|null     $granted_at          Optional UTC datetime string.
		 * @param array<string,mixed> $metadata         Host-owned metadata.
		 * @param string|null     $audience_id         Optional non-user audience receiving access.
		 */
		public function __construct(
			public readonly string $agent_id,
			public readonly int $user_id,
			public readonly string $role = self::ROLE_VIEWER,
			public readonly ?string $workspace_id = null,
			public readonly ?int $grant_id = null,
			public readonly ?int $granted_by_user_id = null,
			public readonly ?string $granted_at = null,
			public readonly array $metadata = array(),
			public readonly ?string $audience_id = null,
		) {
			if ( '' === trim( $this->agent_id ) ) {
				throw self::invalid( 'agent_id', 'must be a non-empty string' );
			}

			if ( $this->user_id < 0 ) {
				throw self::invalid( 'user_id', 'must be zero or a positive integer' );
			}

			if ( 0 === $this->user_id && null === $this->audience_id ) {
				throw self::invalid( 'user_id', 'must be positive unless audience_id is present' );
			}

			if ( null !== $this->audience_id && '' === trim( $this->audience_id ) ) {
				throw self::invalid( 'audience_id', 'must be null or a non-empty string' );
			}

			if ( null !== $this->grant_id && $this->grant_id <= 0 ) {
				throw self::invalid( 'grant_id', 'must be null or a positive integer' );
			}

			if ( null !== $this->granted_by_user_id && $this->granted_by_user_id <= 0 ) {
				throw self::invalid( 'granted_by_user_id', 'must be null or a positive integer' );
			}

			if ( ! self::is_valid_role( $this->role ) ) {
				throw self::invalid( 'role', 'must be admin, operator, or viewer' );
			}

			if ( false === self::json_encode( $this->metadata ) ) {
				throw self::invalid( 'metadata', 'must be JSON serializable' );
			}
		}

		/**
		 * Return all valid access roles from lowest to highest privilege.
		 *
		 * @return string[]
		 */
		public static function roles(): array {
			return array( self::ROLE_VIEWER, self::ROLE_OPERATOR, self::ROLE_ADMIN );
		}

		/**
		 * Determine whether a role is valid.
		 *
		 * @param string $role Role value.
		 */
		public static function is_valid_role( string $role ): bool {
			return in_array( $role, self::roles(), true );
		}

		/**
		 * Build a grant from a raw array.
		 *
		 * @param array<string,mixed> $grant Raw grant fields.
		 */
		public static function from_array( array $grant ): self {
			return new self(
				self::string_field( $grant, 'agent_id' ),
				self::int_field( $grant, 'user_id' ) ?? 0,
				self::string_field( $grant, 'role', self::ROLE_VIEWER ),
				self::nullable_string_field( $grant, 'workspace_id' ),
				self::int_field( $grant, 'grant_id' ),
				self::int_field( $grant, 'granted_by_user_id' ),
				self::nullable_string_field( $grant, 'granted_at' ),
				self::metadata_field( $grant, 'metadata' ),
				self::nullable_string_field( $grant, 'audience_id' )
			);
		}

		/**
		 * Whether this grant's role meets or exceeds the required role.
		 */
		public function role_meets( string $minimum_role ): bool {
			$roles          = self::roles();
			$actual_index   = array_search( $this->role, $roles, true );
			$required_index = array_search( $minimum_role, $roles, true );

			return false !== $actual_index && false !== $required_index && $actual_index >= $required_index;
		}

		/**
		 * Export the grant to a stable JSON-friendly shape.
		 *
		 * @return array<string,mixed>
		 */
		public function to_array(): array {
			return array(
				'grant_id'           => $this->grant_id,
				'agent_id'           => $this->agent_id,
				'user_id'            => $this->user_id,
				'role'               => $this->role,
				'workspace_id'       => $this->workspace_id,
				'granted_by_user_id' => $this->granted_by_user_id,
				'granted_at'         => $this->granted_at,
				'metadata'           => $this->metadata,
				'audience_id'        => $this->audience_id,
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
		 * @param array<string,mixed> $source Raw source array.
		 */
		private static function string_field( array $source, string $key, string $fallback = '' ): string {
			$value = $source[ $key ] ?? null;
			return is_scalar( $value ) ? (string) $value : $fallback;
		}

		/**
		 * @param array<string,mixed> $source Raw source array.
		 */
		private static function nullable_string_field( array $source, string $key ): ?string {
			if ( ! array_key_exists( $key, $source ) || null === $source[ $key ] ) {
				return null;
			}

			return self::string_field( $source, $key );
		}

		/**
		 * @param array<string,mixed> $source Raw source array.
		 */
		private static function int_field( array $source, string $key ): ?int {
			$value = $source[ $key ] ?? null;
			if ( is_int( $value ) ) {
				return $value;
			}

			if ( is_float( $value ) || is_string( $value ) ) {
				return (int) $value;
			}

			return null;
		}

		/**
		 * @param array<string,mixed> $source Raw source array.
		 * @return array<string,mixed>
		 */
		private static function metadata_field( array $source, string $key ): array {
			$value = $source[ $key ] ?? null;
			if ( ! is_array( $value ) ) {
				return array();
			}

			$metadata = array();
			foreach ( $value as $metadata_key => $metadata_value ) {
				if ( is_string( $metadata_key ) ) {
					$metadata[ $metadata_key ] = $metadata_value;
				}
			}

			return $metadata;
		}

		/**
		 * Build a machine-readable validation exception.
		 */
		private static function invalid( string $path, string $reason ): InvalidArgumentException {
			return new InvalidArgumentException( 'invalid_wp_agent_access_grant: ' . $path . ' ' . $reason );
		}
	}
}
