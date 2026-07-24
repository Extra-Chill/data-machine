<?php
/**
 * WP_Agent_Package_Capability_Report value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Capability_Report' ) ) {
	/**
	 * Storage-neutral compatibility report for an agent package and host runtime.
	 */
	final class WP_Agent_Package_Capability_Report {

		/** @var array<int,string> */
		private array $required_capabilities;

		/** @var array<int,string> */
		private array $host_capabilities;

		/** @var array<int,string> */
		private array $unsupported_capabilities;

		/** @var array<int,string> */
		private array $unknown_artifact_types;

		/** @var array<int,array<string,mixed>> */
		private array $unsupported_artifacts;

		/**
		 * Constructor.
		 *
		 * @param array<int,string>              $required_capabilities    Required package/artifact capabilities.
		 * @param array<int,string>              $host_capabilities        Capabilities supported by the host runtime.
		 * @param array<int,string>              $unsupported_capabilities Unsupported required capabilities.
		 * @param array<int,string>              $unknown_artifact_types   Artifact types the host cannot interpret.
		 * @param array<int,array<string,mixed>> $unsupported_artifacts    Artifact-level compatibility details.
		 */
		public function __construct( array $required_capabilities, array $host_capabilities, array $unsupported_capabilities, array $unknown_artifact_types, array $unsupported_artifacts ) {
			$this->required_capabilities    = $this->normalize_string_list( $required_capabilities );
			$this->host_capabilities        = $this->normalize_string_list( $host_capabilities );
			$this->unsupported_capabilities = $this->normalize_string_list( $unsupported_capabilities );
			$this->unknown_artifact_types   = $this->normalize_string_list( $unknown_artifact_types );
			$this->unsupported_artifacts    = array_values( $unsupported_artifacts );
		}

		/**
		 * Whether the host can safely adopt every declared package artifact.
		 *
		 * @return bool
		 */
		public function is_compatible(): bool {
			return empty( $this->unsupported_capabilities ) && empty( $this->unknown_artifact_types ) && empty( $this->unsupported_artifacts );
		}

		/**
		 * Retrieves unsupported required capability strings.
		 *
		 * @return array<int,string>
		 */
		public function get_unsupported_capabilities(): array {
			return $this->unsupported_capabilities;
		}

		/**
		 * Retrieves artifact types the host does not know how to handle.
		 *
		 * @return array<int,string>
		 */
		public function get_unknown_artifact_types(): array {
			return $this->unknown_artifact_types;
		}

		/**
		 * Retrieves artifact-level unsupported details.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_unsupported_artifacts(): array {
			return $this->unsupported_artifacts;
		}

		/**
		 * Exports the report for adoption results and previews.
		 *
		 * @return array<string,mixed>
		 */
		public function to_array(): array {
			return array(
				'compatible'               => $this->is_compatible(),
				'status'                   => $this->is_compatible() ? 'compatible' : 'unsupported',
				'required_capabilities'    => $this->required_capabilities,
				'host_capabilities'        => $this->host_capabilities,
				'unsupported_capabilities' => $this->unsupported_capabilities,
				'unknown_artifact_types'   => $this->unknown_artifact_types,
				'unsupported_artifacts'    => $this->unsupported_artifacts,
			);
		}

		/**
		 * Normalizes a capability list.
		 *
		 * @param array<int,string> $values Raw strings.
		 * @return array<int,string>
		 */
		private function normalize_string_list( array $values ): array {
			$prepared = array();
			foreach ( $values as $value ) {
				$value = trim( strtolower( (string) $value ) );
				if ( '' !== $value ) {
					$prepared[] = $value;
				}
			}

			$prepared = array_values( array_unique( $prepared ) );
			sort( $prepared );

			return $prepared;
		}
	}
}
