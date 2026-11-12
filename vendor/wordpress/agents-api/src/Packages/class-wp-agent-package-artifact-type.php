<?php
/**
 * WP_Agent_Package_Artifact_Type definition object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Artifact_Type' ) ) {
	/**
	 * Metadata and lifecycle callbacks for a package artifact type.
	 */
	final class WP_Agent_Package_Artifact_Type {

		/**
		 * Artifact type slug.
		 *
		 * @var string
		 */
		private string $type;

		/**
		 * Human-readable label.
		 *
		 * @var string
		 */
		private string $label;

		/**
		 * Type description.
		 *
		 * @var string
		 */
		private string $description = '';

		/**
		 * Validation callback.
		 *
		 * @var callable|null
		 */
		private $validate_callback = null;

		/**
		 * Diff callback.
		 *
		 * @var callable|null
		 */
		private $diff_callback = null;

		/**
		 * Import callback.
		 *
		 * @var callable|null
		 */
		private $import_callback = null;

		/**
		 * Delete callback.
		 *
		 * @var callable|null
		 */
		private $delete_callback = null;

		/**
		 * Optional metadata.
		 *
		 * @var array<string, mixed>
		 */
		private array $meta = array();

		/**
		 * Example artifact declarations for documentation and host validation.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		private array $example_artifacts = array();

		/**
		 * Constructor.
		 *
		 * @param string              $type Artifact type slug.
		 * @param array<string,mixed> $args Registration arguments.
		 */
		public function __construct( string $type, array $args = array() ) {
			$this->type  = WP_Agent_Package_Artifact::prepare_type( $type );
			$this->label = is_scalar( $args['label'] ?? null ) ? trim( (string) $args['label'] ) : $this->type;

			if ( '' === $this->label ) {
				$this->label = $this->type;
			}

			$this->description = is_scalar( $args['description'] ?? null ) ? trim( (string) $args['description'] ) : '';

			foreach ( array( 'validate_callback', 'diff_callback', 'import_callback', 'delete_callback' ) as $property ) {
				if ( ! array_key_exists( $property, $args ) ) {
					continue;
				}

				if ( null !== $args[ $property ] && ! is_callable( $args[ $property ] ) ) {
					throw new InvalidArgumentException( sprintf( 'Agent package artifact type %s property must be callable.', esc_html( $property ) ) );
				}

				$this->$property = $args[ $property ];
			}

			if ( isset( $args['meta'] ) ) {
				if ( ! is_array( $args['meta'] ) ) {
					throw new InvalidArgumentException( 'Agent package artifact type meta property must be an array.' );
				}

				$this->meta = self::prepare_meta( $args['meta'] );
			}

			if ( isset( $args['example_artifacts'] ) ) {
				if ( ! is_array( $args['example_artifacts'] ) ) {
					throw new InvalidArgumentException( 'Agent package artifact type example_artifacts property must be an array.' );
				}

				$this->example_artifacts = $this->prepare_example_artifacts( $args['example_artifacts'] );
			}
		}

		/**
		 * @param array<mixed> $meta Raw metadata.
		 * @return array<string,mixed>
		 */
		private static function prepare_meta( array $meta ): array {
			$prepared = array();
			foreach ( $meta as $key => $value ) {
				if ( is_string( $key ) ) {
					$prepared[ $key ] = $value;
				}
			}

			return $prepared;
		}

		/**
		 * Retrieves the artifact type slug.
		 *
		 * @return string
		 */
		public function get_type(): string {
			return $this->type;
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
		 * Retrieves the validation callback.
		 *
		 * @return callable|null
		 */
		public function get_validate_callback() {
			return $this->validate_callback;
		}

		/**
		 * Retrieves the diff callback.
		 *
		 * @return callable|null
		 */
		public function get_diff_callback() {
			return $this->diff_callback;
		}

		/**
		 * Retrieves the import callback.
		 *
		 * @return callable|null
		 */
		public function get_import_callback() {
			return $this->import_callback;
		}

		/**
		 * Retrieves the delete callback.
		 *
		 * @return callable|null
		 */
		public function get_delete_callback() {
			return $this->delete_callback;
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
		 * Retrieves example artifact declarations.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public function get_example_artifacts(): array {
			return $this->example_artifacts;
		}

		/**
		 * Exports registration arguments.
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			return array(
				'type'              => $this->type,
				'label'             => $this->label,
				'description'       => $this->description,
				'validate_callback' => $this->validate_callback,
				'diff_callback'     => $this->diff_callback,
				'import_callback'   => $this->import_callback,
				'delete_callback'   => $this->delete_callback,
				'example_artifacts' => $this->example_artifacts,
				'meta'              => $this->meta,
			);
		}

		/**
		 * Normalizes example artifact declarations against the generic artifact shape.
		 *
		 * @param array<mixed> $examples Raw example declarations.
		 * @return array<int, array<string, mixed>>
		 */
		private function prepare_example_artifacts( array $examples ): array {
			$prepared = array();
			foreach ( $examples as $example ) {
				if ( ! is_array( $example ) ) {
					throw new InvalidArgumentException( 'Agent package artifact type example_artifacts entries must be arrays.' );
				}

				$artifact = WP_Agent_Package_Artifact::from_array(
					array_merge(
						$this->string_keyed_array( $example ),
						array( 'type' => $this->type )
					)
				);

				$prepared[] = $artifact->to_array();
			}

			return $prepared;
		}

		/**
		 * @param array<mixed> $values Raw values.
		 * @return array<string, mixed>
		 */
		private function string_keyed_array( array $values ): array {
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
