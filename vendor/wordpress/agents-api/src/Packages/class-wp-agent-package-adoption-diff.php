<?php
/**
 * WP_Agent_Package_Adoption_Diff value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Adoption_Diff' ) ) {
	/**
	 * Describes what an adopter would change for an agent package.
	 */
	final class WP_Agent_Package_Adoption_Diff {

		/**
		 * Adoption status.
		 *
		 * @var string
		 */
		private string $status;

		/**
		 * Change entries.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		private array $changes;

		/**
		 * Warning messages.
		 *
		 * @var array<int, string>
		 */
		private array $warnings;

		/**
		 * Optional bucketed package artifact update plan.
		 *
		 * @var array<string, mixed>
		 */
		private array $artifact_plan;

		/**
		 * Constructor.
		 *
		 * @param string                         $status   Diff status.
		 * @param array<int, mixed>               $changes  Change entries.
		 * @param array<int, string>             $warnings Warning messages.
		 * @param array<string, mixed>|WP_Agent_Package_Update_Plan $artifact_plan Optional bucketed artifact plan.
		 */
		public function __construct( string $status, array $changes = array(), array $warnings = array(), $artifact_plan = array() ) {
			$this->status        = $this->prepare_status( $status );
			$this->changes       = $this->prepare_changes( $changes );
			$this->warnings      = $this->prepare_strings( $warnings );
			$this->artifact_plan = $this->prepare_artifact_plan( $artifact_plan );
		}

		/**
		 * Retrieves the diff status.
		 *
		 * @return string
		 */
		public function get_status(): string {
			return $this->status;
		}

		/**
		 * Retrieves normalized changes.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public function get_changes(): array {
			return $this->changes;
		}

		/**
		 * Retrieves warnings.
		 *
		 * @return array<int, string>
		 */
		public function get_warnings(): array {
			return $this->warnings;
		}

		/**
		 * Retrieves the bucketed artifact plan.
		 *
		 * @return array<string, mixed>
		 */
		public function get_artifact_plan(): array {
			return $this->artifact_plan;
		}

		/**
		 * Exports the value object.
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			$data = array(
				'status'   => $this->status,
				'changes'  => $this->changes,
				'warnings' => $this->warnings,
			);

			if ( ! empty( $this->artifact_plan ) ) {
				$data['artifact_plan'] = $this->artifact_plan;
			}

			return $data;
		}

		/**
		 * Validates status.
		 *
		 * @param string $status Raw status.
		 * @return string
		 */
		private function prepare_status( string $status ): string {
			$status  = sanitize_title( $status );
			$allowed = array( 'clean', 'needs-adoption', 'needs-update', 'blocked' );
			if ( ! in_array( $status, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Agent package adoption diff status must be one of clean, needs-adoption, needs-update, blocked.' );
			}

			return $status;
		}

		/**
		 * Normalizes change entries.
		 *
		 * @param array<int, mixed> $changes Raw changes.
		 * @return array<int, array<string, mixed>>
		 */
		private function prepare_changes( array $changes ): array {
			$prepared = array();
			foreach ( $changes as $change ) {
				if ( ! is_array( $change ) ) {
					continue;
				}

				$entry = array();
				foreach ( $change as $key => $value ) {
					if ( is_string( $key ) ) {
						$entry[ $key ] = $value;
					}
				}

				if ( ! empty( $entry ) ) {
					$prepared[] = $entry;
				}
			}

			return $prepared;
		}

		/**
		 * Normalizes string lists.
		 *
		 * @param array<int, string> $values Raw values.
		 * @return array<int, string>
		 */
		private function prepare_strings( array $values ): array {
			$prepared = array();
			foreach ( $values as $value ) {
				$value = trim( (string) $value );
				if ( '' !== $value ) {
					$prepared[] = $value;
				}
			}

			return array_values( array_unique( $prepared ) );
		}

		/**
		 * Normalizes an optional package artifact plan.
		 *
		 * @param mixed $artifact_plan Raw plan.
		 * @return array<string, mixed>
		 */
		private function prepare_artifact_plan( $artifact_plan ): array {
			if ( $artifact_plan instanceof WP_Agent_Package_Update_Plan ) {
				return $artifact_plan->to_array();
			}

			if ( ! is_array( $artifact_plan ) ) {
				return array();
			}

			$prepared = array();
			foreach ( $artifact_plan as $key => $value ) {
				if ( is_string( $key ) ) {
					$prepared[ $key ] = $value;
				}
			}

			return $prepared;
		}
	}
}
