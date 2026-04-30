<?php
/**
 * WP_Agent_Package definition object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package' ) ) {
	/**
	 * Declarative package containing an agent definition and generic artifacts.
	 *
	 * Package parsing is intentionally storage/runtime neutral. It does not create
	 * database rows, files, queues, or scheduled work; adopters decide how to map
	 * this definition into their own runtime.
	 */
	final class WP_Agent_Package {

		/**
		 * Package slug.
		 *
		 * @var string
		 */
		private string $slug;

		/**
		 * Package version.
		 *
		 * @var string
		 */
		private string $version;

		/**
		 * Agent definition.
		 *
		 * @var WP_Agent
		 */
		private WP_Agent $agent;

		/**
		 * Declared capability strings.
		 *
		 * @var array<int, string>
		 */
		private array $capabilities = array();

		/**
		 * Generic artifact definitions.
		 *
		 * @var array<int, WP_Agent_Package_Artifact>
		 */
		private array $artifacts = array();

		/**
		 * Optional package metadata.
		 *
		 * @var array<string, mixed>
		 */
		private array $meta = array();

		/**
		 * Constructor.
		 *
		 * @param string|array<string,mixed> $slug_or_manifest Package slug or manifest array.
		 * @param array<string,mixed>        $args             Package arguments.
		 */
		public function __construct( $slug_or_manifest, array $args = array() ) {
			$manifest = is_array( $slug_or_manifest ) ? $slug_or_manifest : array_merge( $args, array( 'slug' => (string) $slug_or_manifest ) );

			$this->slug         = $this->prepare_slug( $manifest['slug'] ?? '' );
			$this->version      = isset( $manifest['version'] ) ? trim( (string) $manifest['version'] ) : '1.0.0';
			$this->agent        = $this->prepare_agent( $manifest['agent'] ?? null );
			$this->capabilities = $this->prepare_string_list( $manifest['capabilities'] ?? array(), 'capabilities' );
			$this->artifacts    = $this->prepare_artifacts( $manifest['artifacts'] ?? array() );

			if ( isset( $manifest['meta'] ) ) {
				if ( ! is_array( $manifest['meta'] ) ) {
					throw new InvalidArgumentException( 'Agent package meta property must be an array.' );
				}

				$this->meta = $manifest['meta'];
			}

			if ( '' === $this->version ) {
				throw new InvalidArgumentException( 'Agent package version cannot be empty.' );
			}
		}

		/**
		 * Creates a package from a manifest array.
		 *
		 * @param array<string,mixed> $manifest Package manifest.
		 * @return self
		 */
		public static function from_array( array $manifest ): self {
			return new self( $manifest );
		}

		/**
		 * Retrieves the package slug.
		 *
		 * @return string
		 */
		public function get_slug(): string {
			return $this->slug;
		}

		/**
		 * Retrieves the package version.
		 *
		 * @return string
		 */
		public function get_version(): string {
			return $this->version;
		}

		/**
		 * Retrieves the package agent definition.
		 *
		 * @return WP_Agent
		 */
		public function get_agent(): WP_Agent {
			return $this->agent;
		}

		/**
		 * Retrieves capability strings.
		 *
		 * @return array<int, string>
		 */
		public function get_capabilities(): array {
			return $this->capabilities;
		}

		/**
		 * Retrieves artifact definitions.
		 *
		 * @return array<int, WP_Agent_Package_Artifact>
		 */
		public function get_artifacts(): array {
			return $this->artifacts;
		}

		/**
		 * Retrieves package metadata.
		 *
		 * @return array<string, mixed>
		 */
		public function get_meta(): array {
			return $this->meta;
		}

		/**
		 * Exports the normalized package manifest.
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			return array(
				'slug'         => $this->slug,
				'version'      => $this->version,
				'agent'        => $this->agent->to_array(),
				'capabilities' => $this->capabilities,
				'artifacts'    => array_map(
					static function ( WP_Agent_Package_Artifact $artifact ): array {
						return $artifact->to_array();
					},
					$this->artifacts
				),
				'meta'         => $this->meta,
			);
		}

		/**
		 * Prepares the package slug.
		 *
		 * @param mixed $slug Raw slug.
		 * @return string
		 */
		private function prepare_slug( $slug ): string {
			$slug = sanitize_title( (string) $slug );
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'Agent package slug cannot be empty.' );
			}

			return $slug;
		}

		/**
		 * Prepares the agent definition.
		 *
		 * @param mixed $agent Raw agent definition.
		 * @return WP_Agent
		 */
		private function prepare_agent( $agent ): WP_Agent {
			if ( $agent instanceof WP_Agent ) {
				return $agent;
			}

			if ( ! is_array( $agent ) ) {
				throw new InvalidArgumentException( 'Agent package requires an agent definition.' );
			}

			$slug = $agent['slug'] ?? '';
			if ( '' === sanitize_title( (string) $slug ) ) {
				throw new InvalidArgumentException( 'Agent package agent slug cannot be empty.' );
			}

			$args = $agent;
			unset( $args['slug'] );

			return new WP_Agent( (string) $slug, $args );
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
				throw new InvalidArgumentException( sprintf( 'Agent package %s property must be an array.', esc_html( $field ) ) );
			}

			$prepared = array();
			foreach ( $values as $value ) {
				$value = trim( strtolower( (string) $value ) );
				if ( '' !== $value && preg_match( '/^[a-z0-9:_\.\/-]+$/', $value ) ) {
					$prepared[] = $value;
				}
			}

			$prepared = array_values( array_unique( $prepared ) );
			sort( $prepared );

			return $prepared;
		}

		/**
		 * Prepares artifact declarations.
		 *
		 * @param mixed $artifacts Raw artifacts.
		 * @return array<int, WP_Agent_Package_Artifact>
		 */
		private function prepare_artifacts( $artifacts ): array {
			if ( ! is_array( $artifacts ) ) {
				throw new InvalidArgumentException( 'Agent package artifacts property must be an array.' );
			}

			$prepared = array();
			foreach ( $artifacts as $artifact ) {
				if ( $artifact instanceof WP_Agent_Package_Artifact ) {
					$prepared[] = $artifact;
					continue;
				}

				if ( ! is_array( $artifact ) ) {
					throw new InvalidArgumentException( 'Agent package artifacts must be objects.' );
				}

				$prepared[] = new WP_Agent_Package_Artifact( $artifact );
			}

			usort(
				$prepared,
				static function ( WP_Agent_Package_Artifact $a, WP_Agent_Package_Artifact $b ): int {
					return array( $a->get_type(), $a->get_slug() ) <=> array( $b->get_type(), $b->get_slug() );
				}
			);

			return $prepared;
		}
	}
}
