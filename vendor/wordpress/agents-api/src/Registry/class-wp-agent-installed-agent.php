<?php
/**
 * WP_Agent_Installed_Agent value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Installed_Agent' ) ) {
	/**
	 * Describes durable installed agent state without defining storage.
	 */
	final class WP_Agent_Installed_Agent {

		private string $id;
		private string $agent_slug;
		private ?int $owner_user_id;
		private string $instance_key;
		/** @var array<string,mixed> */
		private array $config;
		/** @var array<string,mixed> */
		private array $meta;
		private string $status;
		private ?string $package_slug;
		private ?string $package_version;
		private ?string $created_at;
		private ?string $updated_at;

		/**
		 * Constructor.
		 *
		 * @param array<string,mixed> $args Installed agent state.
		 */
		public function __construct( array $args ) {
			$this->id              = self::prepare_required_string( $args['id'] ?? '', 'Installed agent id cannot be empty.' );
			$this->agent_slug      = self::prepare_slug( $args['agent_slug'] ?? '', 'Installed agent slug cannot be empty.' );
			$this->owner_user_id   = array_key_exists( 'owner_user_id', $args ) && null !== $args['owner_user_id'] ? max( 0, self::int_value( $args['owner_user_id'] ) ) : null;
			$this->instance_key    = self::prepare_instance_key( $args['instance_key'] ?? 'default' );
			$this->config          = self::string_keyed_array( $args['config'] ?? array() );
			$this->meta            = self::string_keyed_array( $args['meta'] ?? array() );
			$this->status          = self::prepare_status( $args['status'] ?? 'installed' );
			$this->package_slug    = self::optional_slug( $args['package_slug'] ?? null, 'Installed agent package slug cannot be empty.' );
			$this->package_version = self::optional_string( $args['package_version'] ?? null );
			$this->created_at      = self::optional_string( $args['created_at'] ?? null );
			$this->updated_at      = self::optional_string( $args['updated_at'] ?? null );
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_agent_slug(): string {
			return $this->agent_slug;
		}

		public function get_owner_user_id(): ?int {
			return $this->owner_user_id;
		}

		public function get_instance_key(): string {
			return $this->instance_key;
		}

		/** @return array<string,mixed> */
		public function get_config(): array {
			return $this->config;
		}

		/** @return array<string,mixed> */
		public function get_meta(): array {
			return $this->meta;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function get_package_slug(): ?string {
			return $this->package_slug;
		}

		public function get_package_version(): ?string {
			return $this->package_version;
		}

		public function get_created_at(): ?string {
			return $this->created_at;
		}

		public function get_updated_at(): ?string {
			return $this->updated_at;
		}

		public function key(): string {
			$owner = null === $this->owner_user_id ? 'none' : (string) $this->owner_user_id;
			return $this->agent_slug . ':' . $owner . ':' . $this->instance_key;
		}

		/** @return array<string,mixed> */
		public function to_array(): array {
			return array(
				'id'              => $this->id,
				'agent_slug'      => $this->agent_slug,
				'owner_user_id'   => $this->owner_user_id,
				'instance_key'    => $this->instance_key,
				'config'          => $this->config,
				'meta'            => $this->meta,
				'status'          => $this->status,
				'package_slug'    => $this->package_slug,
				'package_version' => $this->package_version,
				'created_at'      => $this->created_at,
				'updated_at'      => $this->updated_at,
			);
		}

		private static function prepare_required_string( mixed $value, string $message ): string {
			$value = trim( self::string_value( $value ) );
			if ( '' === $value ) {
				throw new InvalidArgumentException( esc_html( $message ) );
			}

			return $value;
		}

		private static function prepare_slug( mixed $value, string $message ): string {
			$value = sanitize_title( self::string_value( $value ) );
			if ( '' === $value ) {
				throw new InvalidArgumentException( esc_html( $message ) );
			}

			return $value;
		}

		private static function prepare_instance_key( mixed $value ): string {
			$value = trim( strtolower( str_replace( '\\', '/', self::string_value( $value ) ) ) );
			$value = preg_replace( '#\s*/\s*#', '/', $value );
			$value = is_string( $value ) ? $value : '';
			$value = preg_replace( '#/+#', '/', $value );
			return ! is_string( $value ) || '' === $value ? 'default' : $value;
		}

		private static function prepare_status( mixed $status ): string {
			$status  = sanitize_title( self::string_value( $status ) );
			$allowed = array( 'installed', 'updated', 'disabled', 'removed', 'projected' );
			if ( ! in_array( $status, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Installed agent status is invalid.' );
			}

			return $status;
		}

		private static function optional_string( mixed $value ): ?string {
			$value = trim( self::string_value( $value ) );
			return '' === $value ? null : $value;
		}

		private static function optional_slug( mixed $value, string $message ): ?string {
			$value = trim( self::string_value( $value ) );
			return '' === $value ? null : self::prepare_slug( $value, $message );
		}

		private static function string_value( mixed $value ): string {
			return is_scalar( $value ) ? (string) $value : '';
		}

		private static function int_value( mixed $value ): int {
			return is_numeric( $value ) ? (int) $value : 0;
		}

		/** @return array<string,mixed> */
		private static function string_keyed_array( mixed $values ): array {
			if ( ! is_array( $values ) ) {
				return array();
			}

			$prepared = array();
			foreach ( $values as $key => $value ) {
				if ( is_string( $key ) ) {
					$prepared[ $key ] = $value;
				}
			}

			return $prepared;
		}
	}
}
