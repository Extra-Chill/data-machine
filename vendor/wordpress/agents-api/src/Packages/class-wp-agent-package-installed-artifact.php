<?php
/**
 * WP_Agent_Package_Installed_Artifact value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Installed_Artifact' ) ) {
	/**
	 * Immutable install-time snapshot for one package artifact.
	 */
	final class WP_Agent_Package_Installed_Artifact {

		private string $package_slug;
		private string $package_version;
		private string $artifact_type;
		private string $artifact_id;
		private string $source;
		private ?string $installed_hash;
		private ?string $current_hash;
		private mixed $installed_payload;
		private string $status;
		private string $installed_at;
		private string $updated_at;

		/**
		 * Constructor.
		 *
		 * @param array<string,mixed> $artifact Installed artifact row.
		 */
		public function __construct( array $artifact ) {
			$this->package_slug      = $this->prepare_slug( $artifact['package_slug'] ?? '', 'package_slug' );
			$this->package_version   = $this->prepare_string( $artifact['package_version'] ?? '', 'package_version' );
			$this->artifact_type     = WP_Agent_Package_Artifact::prepare_type( $artifact['artifact_type'] ?? '' );
			$this->artifact_id       = $this->prepare_id( $artifact['artifact_id'] ?? ( $artifact['artifact_slug'] ?? '' ) );
			$this->source            = $this->prepare_source( $artifact['source'] ?? ( $artifact['source_path'] ?? '' ) );
			$this->installed_hash    = $this->prepare_optional_string( $artifact['installed_hash'] ?? null );
			$this->current_hash      = $this->prepare_optional_string( $artifact['current_hash'] ?? null );
			$this->installed_payload = array_key_exists( 'installed_payload', $artifact ) ? $artifact['installed_payload'] : null;
			$this->status            = WP_Agent_Package_Artifact_Status::classify( $this->installed_hash, $this->current_hash );
			$this->installed_at      = $this->prepare_string( $artifact['installed_at'] ?? '', 'installed_at' );
			$this->updated_at        = $this->prepare_string( $artifact['updated_at'] ?? '', 'updated_at' );
		}

		/**
		 * Creates an installed artifact from an applied payload.
		 *
		 * @param WP_Agent_Package          $package Package definition.
		 * @param WP_Agent_Package_Artifact $artifact Artifact declaration.
		 * @param mixed                     $payload Installed artifact payload.
		 * @param string                    $timestamp Install/update timestamp.
		 * @return self
		 */
		public static function from_installed_payload( WP_Agent_Package $package, WP_Agent_Package_Artifact $artifact, $payload, string $timestamp ): self {
			$hash = WP_Agent_Package_Artifact_Hasher::hash( $payload );

			return new self(
				array(
					'package_slug'      => $package->get_slug(),
					'package_version'   => $package->get_version(),
					'artifact_type'     => $artifact->get_type(),
					'artifact_id'       => $artifact->get_slug(),
					'source'            => $artifact->get_source(),
					'installed_hash'    => $hash,
					'current_hash'      => $hash,
					'installed_payload' => $payload,
					'installed_at'      => $timestamp,
					'updated_at'        => $timestamp,
				)
			);
		}

		/**
		 * Creates an installed artifact from an array.
		 *
		 * @param array<string,mixed> $artifact Installed artifact row.
		 * @return self
		 */
		public static function from_array( array $artifact ): self {
			return new self( $artifact );
		}

		/**
		 * Returns a copy with refreshed current payload state.
		 *
		 * @param mixed|null $current_payload Current payload, or null when missing.
		 * @param string     $updated_at Updated timestamp.
		 * @return self
		 */
		public function with_current_payload( $current_payload, string $updated_at ): self {
			return new self(
				array(
					'package_slug'      => $this->package_slug,
					'package_version'   => $this->package_version,
					'artifact_type'     => $this->artifact_type,
					'artifact_id'       => $this->artifact_id,
					'source'            => $this->source,
					'installed_hash'    => $this->installed_hash,
					'current_hash'      => null === $current_payload ? null : WP_Agent_Package_Artifact_Hasher::hash( $current_payload ),
					'installed_payload' => $this->installed_payload,
					'installed_at'      => $this->installed_at,
					'updated_at'        => $updated_at,
				)
			);
		}

		public function get_package_slug(): string {
			return $this->package_slug;
		}

		public function get_package_version(): string {
			return $this->package_version;
		}

		public function get_artifact_type(): string {
			return $this->artifact_type;
		}

		public function get_artifact_slug(): string {
			return $this->artifact_id;
		}

		public function get_artifact_id(): string {
			return $this->artifact_id;
		}

		public function get_source(): string {
			return $this->source;
		}

		public function get_installed_hash(): ?string {
			return $this->installed_hash;
		}

		public function get_current_hash(): ?string {
			return $this->current_hash;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function get_installed_payload(): mixed {
			return $this->installed_payload;
		}

		/**
		 * Exports the installed artifact row.
		 *
		 * @return array<string,mixed>
		 */
		public function to_array(): array {
			$row = array(
				'package_slug'    => $this->package_slug,
				'package_version' => $this->package_version,
				'artifact_type'   => $this->artifact_type,
				'artifact_id'     => $this->artifact_id,
				'source'          => $this->source,
				'installed_hash'  => $this->installed_hash,
				'current_hash'    => $this->current_hash,
				'status'          => $this->status,
				'installed_at'    => $this->installed_at,
				'updated_at'      => $this->updated_at,
			);

			if ( null !== $this->installed_payload ) {
				$row['installed_payload'] = $this->installed_payload;
			}

			return $row;
		}

		private function prepare_slug( mixed $value, string $field ): string {
			$value = sanitize_title( $this->string_value( $value ) );
			if ( '' === $value ) {
				throw new InvalidArgumentException( sprintf( 'Agent package installed artifact %s cannot be empty.', esc_html( $field ) ) );
			}

			return $value;
		}

		private function prepare_id( mixed $value ): string {
			$value = trim( str_replace( '\\', '/', $this->string_value( $value ) ) );
			if ( '' === $value || str_starts_with( $value, '/' ) || str_contains( $value, '..' ) ) {
				throw new InvalidArgumentException( 'Agent package installed artifact artifact_id must be a non-empty package-local identifier.' );
			}

			return $value;
		}

		private function prepare_string( mixed $value, string $field ): string {
			$value = trim( $this->string_value( $value ) );
			if ( '' === $value ) {
				throw new InvalidArgumentException( sprintf( 'Agent package installed artifact %s cannot be empty.', esc_html( $field ) ) );
			}

			return $value;
		}

		private function prepare_optional_string( mixed $value ): ?string {
			$value = null === $value ? '' : trim( $this->string_value( $value ) );
			return '' === $value ? null : $value;
		}

		private function prepare_source( mixed $source ): string {
			$artifact = new WP_Agent_Package_Artifact(
				array(
					'type'   => $this->artifact_type,
					'slug'   => sanitize_title( $this->artifact_id ),
					'source' => $source,
				)
			);

			return $artifact->get_source();
		}

		/**
		 * Convert scalar/Stringable input to a string.
		 *
		 * @param mixed $value Raw value.
		 * @return string String value, or empty string for non-stringable input.
		 */
		private function string_value( mixed $value ): string {
			return is_scalar( $value ) || $value instanceof Stringable ? (string) $value : '';
		}
	}
}
