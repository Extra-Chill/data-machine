<?php
/**
 * WP_Agent_Package_Artifact definition object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Artifact' ) ) {
	/**
	 * Portable package artifact declaration.
	 *
	 * The artifact records identity and payload location only. Product plugins own
	 * interpretation of the payload for their registered artifact type.
	 */
	final class WP_Agent_Package_Artifact {

		/**
		 * Artifact type slug.
		 *
		 * @var string
		 */
		private string $type;

		/**
		 * Artifact slug.
		 *
		 * @var string
		 */
		private string $slug;

		/**
		 * Human-readable label.
		 *
		 * @var string
		 */
		private string $label;

		/**
		 * Artifact description.
		 *
		 * @var string
		 */
		private string $description = '';

		/**
		 * Relative source path inside the package.
		 *
		 * @var string
		 */
		private string $source = '';

		/**
		 * Optional checksum string.
		 *
		 * @var string
		 */
		private string $checksum = '';

		/**
		 * Required capability or component strings.
		 *
		 * @var array<int, string>
		 */
		private array $requires = array();

		/**
		 * Optional artifact metadata.
		 *
		 * @var array<string, mixed>
		 */
		private array $meta = array();

		/**
		 * Constructor.
		 *
		 * @param array<string,mixed> $artifact Artifact declaration.
		 */
		public function __construct( array $artifact ) {
			$this->type        = self::prepare_type( $artifact['type'] ?? '' );
			$this->slug        = $this->prepare_slug( $artifact['slug'] ?? '' );
			$this->label       = isset( $artifact['label'] ) ? trim( (string) $artifact['label'] ) : $this->slug;
			$this->description = isset( $artifact['description'] ) ? trim( (string) $artifact['description'] ) : '';
			$this->source      = $this->prepare_source( $artifact['source'] ?? '' );
			$this->checksum    = isset( $artifact['checksum'] ) ? trim( (string) $artifact['checksum'] ) : '';

			if ( isset( $artifact['requires'] ) ) {
				$this->requires = $this->prepare_string_list( $artifact['requires'], 'requires' );
			}

			if ( isset( $artifact['meta'] ) ) {
				if ( ! is_array( $artifact['meta'] ) ) {
					throw new InvalidArgumentException( 'Agent package artifact meta property must be an array.' );
				}

				$this->meta = $artifact['meta'];
			}

			if ( '' === $this->label ) {
				$this->label = $this->slug;
			}
		}

		/**
		 * Creates an artifact from an array.
		 *
		 * @param array<string,mixed> $artifact Artifact declaration.
		 * @return self
		 */
		public static function from_array( array $artifact ): self {
			return new self( $artifact );
		}

		/**
		 * Normalizes an artifact type slug.
		 *
		 * @param mixed $type Raw type.
		 * @return string
		 */
		public static function prepare_type( $type ): string {
			$type = strtolower( trim( (string) $type ) );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.\/-]*$/', $type ) ) {
				throw new InvalidArgumentException( 'Agent package artifact type must be a namespaced slug.' );
			}

			return $type;
		}

		/**
		 * Retrieves the artifact type.
		 *
		 * @return string
		 */
		public function get_type(): string {
			return $this->type;
		}

		/**
		 * Retrieves the artifact slug.
		 *
		 * @return string
		 */
		public function get_slug(): string {
			return $this->slug;
		}

		/**
		 * Retrieves the label.
		 *
		 * @return string
		 */
		public function get_label(): string {
			return $this->label;
		}

		/**
		 * Retrieves the description.
		 *
		 * @return string
		 */
		public function get_description(): string {
			return $this->description;
		}

		/**
		 * Retrieves the source path.
		 *
		 * @return string
		 */
		public function get_source(): string {
			return $this->source;
		}

		/**
		 * Retrieves the checksum.
		 *
		 * @return string
		 */
		public function get_checksum(): string {
			return $this->checksum;
		}

		/**
		 * Retrieves required capability or component strings.
		 *
		 * @return array<int, string>
		 */
		public function get_requires(): array {
			return $this->requires;
		}

		/**
		 * Retrieves metadata.
		 *
		 * @return array<string, mixed>
		 */
		public function get_meta(): array {
			return $this->meta;
		}

		/**
		 * Exports the normalized declaration.
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			return array(
				'type'        => $this->type,
				'slug'        => $this->slug,
				'label'       => $this->label,
				'description' => $this->description,
				'source'      => $this->source,
				'checksum'    => $this->checksum,
				'requires'    => $this->requires,
				'meta'        => $this->meta,
			);
		}

		/**
		 * Prepares the artifact slug.
		 *
		 * @param mixed $slug Raw slug.
		 * @return string
		 */
		private function prepare_slug( $slug ): string {
			$slug = sanitize_title( (string) $slug );
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'Agent package artifact slug cannot be empty.' );
			}

			return $slug;
		}

		/**
		 * Prepares the package-relative source path.
		 *
		 * @param mixed $source Raw source path.
		 * @return string
		 */
		private function prepare_source( $source ): string {
			$source = trim( str_replace( '\\', '/', (string) $source ) );
			if ( '' === $source ) {
				return '';
			}

			if ( str_starts_with( $source, '/' ) || preg_match( '/^[A-Za-z]:\//', $source ) ) {
				throw new InvalidArgumentException( 'Agent package artifact source must be relative to the package.' );
			}

			$parts = array_filter(
				explode( '/', $source ),
				static function ( string $part ): bool {
					return '' !== $part;
				}
			);
			if ( in_array( '..', $parts, true ) ) {
				throw new InvalidArgumentException( 'Agent package artifact source cannot contain parent directory segments.' );
			}

			return implode( '/', $parts );
		}

		/**
		 * Prepares a unique string list.
		 *
		 * @param mixed  $values Raw values.
		 * @param string $field  Field label for errors.
		 * @return array<int, string>
		 */
		private function prepare_string_list( $values, string $field ): array {
			if ( ! is_array( $values ) ) {
				throw new InvalidArgumentException( sprintf( 'Agent package artifact %s property must be an array.', esc_html( $field ) ) );
			}

			$prepared = array();
			foreach ( $values as $value ) {
				$value = strtolower( trim( (string) $value ) );
				if ( '' !== $value && preg_match( '/^[a-z0-9:_\.\/-]+$/', $value ) ) {
					$prepared[] = $value;
				}
			}

			$prepared = array_values( array_unique( $prepared ) );
			sort( $prepared );

			return $prepared;
		}
	}
}
