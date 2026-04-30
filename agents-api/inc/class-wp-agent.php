<?php
/**
 * WP_Agent definition object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent' ) ) {
	/**
	 * Declarative agent definition.
	 *
	 * Constructing an agent definition only prepares registration arguments. It
	 * does not create database rows, access records, directories, or scaffold
	 * files.
	 */
	class WP_Agent {

		/**
		 * Agent slug.
		 *
		 * @var string
		 */
		protected string $slug;

		/**
		 * Human-readable label.
		 *
		 * @var string
		 */
		protected string $label;

		/**
		 * Agent description.
		 *
		 * @var string
		 */
		protected string $description = '';

		/**
		 * Memory seed files keyed by scaffold filename.
		 *
		 * @var array<string, string>
		 */
		protected array $memory_seeds = array();

		/**
		 * Optional owner resolver callback.
		 *
		 * @var callable|null
		 */
		protected $owner_resolver = null;

		/**
		 * Initial agent config for first materialization.
		 *
		 * @var array<string, mixed>
		 */
		protected array $default_config = array();

		/**
		 * Optional metadata.
		 *
		 * @var array<string, mixed>
		 */
		protected array $meta = array();

		/**
		 * Constructor.
		 *
		 * @param string $slug Unique agent slug.
		 * @param array  $args Registration arguments.
		 */
		public function __construct( string $slug, array $args = array() ) {
			$this->slug = sanitize_title( $slug );
			if ( '' === $this->slug ) {
				throw new InvalidArgumentException( 'Agent slug cannot be empty.' );
			}

			$properties = $this->prepare_properties( $args );
			foreach ( $properties as $property_name => $property_value ) {
				if ( ! property_exists( $this, $property_name ) ) {
					$this->notice_invalid_property( __METHOD__, sprintf( 'Property "%s" is not a valid property for agent "%s".', $property_name, $this->slug ) );
					continue;
				}

				$this->$property_name = $property_value;
			}
		}

		/**
		 * Prepare and validate constructor properties.
		 *
		 * @param array<string, mixed> $args Registration arguments.
		 * @return array<string, mixed>
		 * @throws InvalidArgumentException When an argument has an invalid type.
		 */
		protected function prepare_properties( array $args ): array {
			$properties = array();

			$properties['label'] = isset( $args['label'] ) ? (string) $args['label'] : $this->slug;
			if ( '' === $properties['label'] ) {
				$properties['label'] = $this->slug;
			}

			$properties['description'] = isset( $args['description'] ) ? (string) $args['description'] : '';

			if ( isset( $args['memory_seeds'] ) ) {
				if ( ! is_array( $args['memory_seeds'] ) ) {
					throw new InvalidArgumentException( 'Agent memory_seeds property must be an array.' );
				}

				$properties['memory_seeds'] = $this->prepare_memory_seeds( $args['memory_seeds'] );
			}

			if ( isset( $args['owner_resolver'] ) ) {
				if ( ! is_callable( $args['owner_resolver'] ) ) {
					throw new InvalidArgumentException( 'Agent owner_resolver property must be callable.' );
				}

				$properties['owner_resolver'] = $args['owner_resolver'];
			}

			if ( isset( $args['default_config'] ) ) {
				if ( ! is_array( $args['default_config'] ) ) {
					throw new InvalidArgumentException( 'Agent default_config property must be an array.' );
				}

				$properties['default_config'] = $args['default_config'];
			}

			if ( isset( $args['meta'] ) ) {
				if ( ! is_array( $args['meta'] ) ) {
					throw new InvalidArgumentException( 'Agent meta property must be an array.' );
				}

				$properties['meta'] = $args['meta'];
			}

			foreach ( $args as $property_name => $property_value ) {
				if ( ! array_key_exists( $property_name, $properties ) && ! property_exists( $this, (string) $property_name ) ) {
					$properties[ (string) $property_name ] = $property_value;
				}
			}

			return $properties;
		}

		/**
		 * Prepare memory seed file map.
		 *
		 * @param array<mixed, mixed> $memory_seeds Raw memory seed map.
		 * @return array<string, string>
		 */
		private function prepare_memory_seeds( array $memory_seeds ): array {
			$prepared = array();

			foreach ( $memory_seeds as $filename => $path ) {
				$filename = sanitize_file_name( (string) $filename );
				$path     = (string) $path;
				if ( '' !== $filename && '' !== $path ) {
					$prepared[ $filename ] = $path;
				}
			}

			return $prepared;
		}

		/**
		 * Retrieves the agent slug.
		 *
		 * @return string
		 */
		public function get_slug(): string {
			return $this->slug;
		}

		/**
		 * Retrieves the agent label.
		 *
		 * @return string
		 */
		public function get_label(): string {
			return $this->label;
		}

		/**
		 * Retrieves the agent description.
		 *
		 * @return string
		 */
		public function get_description(): string {
			return $this->description;
		}

		/**
		 * Retrieves memory seed file map.
		 *
		 * @return array<string, string>
		 */
		public function get_memory_seeds(): array {
			return $this->memory_seeds;
		}

		/**
		 * Retrieves the owner resolver callback.
		 *
		 * @return callable|null
		 */
		public function get_owner_resolver() {
			return $this->owner_resolver;
		}

		/**
		 * Retrieves initial materialization config.
		 *
		 * @return array<string, mixed>
		 */
		public function get_default_config(): array {
			return $this->default_config;
		}

		/**
		 * Retrieves optional metadata.
		 *
		 * @return array<string, mixed>
		 */
		public function get_meta(): array {
			return $this->meta;
		}

		/**
		 * Return registration arguments for the registry.
		 *
		 * @return array
		 */
		public function to_array(): array {
			return array(
				'slug'           => $this->slug,
				'label'          => $this->label,
				'description'    => $this->description,
				'memory_seeds'   => $this->memory_seeds,
				'owner_resolver' => $this->owner_resolver,
				'default_config' => $this->default_config,
				'meta'           => $this->meta,
			);
		}

		/**
		 * Emit a WordPress-style invalid-usage notice when available.
		 *
		 * @param string $function_name Function or method name.
		 * @param string $message       Notice message.
		 * @return void
		 */
		private function notice_invalid_property( string $function_name, string $message ): void {
			if ( function_exists( '_doing_it_wrong' ) ) {
				$function_name = function_exists( 'esc_html' ) ? esc_html( $function_name ) : $function_name;
				$message       = function_exists( 'esc_html' ) ? esc_html( $message ) : $message;
				_doing_it_wrong( $function_name, $message, '0.71.0' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong receives a message, not direct output.
			}
		}

		/**
		 * Wakeup magic method.
		 *
		 * @throws LogicException If the agent object is unserialized.
		 */
		public function __wakeup(): void {
			throw new LogicException( __CLASS__ . ' should never be unserialized.' );
		}

		/**
		 * Sleep magic method.
		 *
		 * @throws LogicException If the agent object is serialized.
		 */
		public function __sleep(): array {
			throw new LogicException( __CLASS__ . ' should never be serialized.' );
		}
	}
}
