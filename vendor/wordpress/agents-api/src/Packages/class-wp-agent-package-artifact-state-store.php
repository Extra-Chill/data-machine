<?php
/**
 * WP_Agent_Package_Artifact_State_Store contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Package_Artifact_State_Store' ) ) {
	/**
	 * Storage-neutral artifact state contract for package adoption orchestration.
	 */
	interface WP_Agent_Package_Artifact_State_Store {

		/**
		 * Returns install-time artifact snapshots for a package.
		 *
		 * @param WP_Agent_Package   $package Package definition.
		 * @param array<string,mixed> $context Consumer context.
		 * @return array<int,array<string,mixed>|WP_Agent_Package_Installed_Artifact>
		 */
		public function get_installed_artifacts( WP_Agent_Package $package, array $context = array() ): array;

		/**
		 * Returns current runtime artifact state for a package.
		 *
		 * @param WP_Agent_Package   $package Package definition.
		 * @param array<string,mixed> $context Consumer context.
		 * @return array<int,array<string,mixed>>
		 */
		public function get_current_artifacts( WP_Agent_Package $package, array $context = array() ): array;

		/**
		 * Returns target artifact state from the package source.
		 *
		 * @param WP_Agent_Package   $package Package definition.
		 * @param array<string,mixed> $context Consumer context.
		 * @return array<int,array<string,mixed>>
		 */
		public function get_target_artifacts( WP_Agent_Package $package, array $context = array() ): array;

		/**
		 * Records install-time snapshots generated for applied artifacts.
		 *
		 * @param WP_Agent_Package                    $package Package definition.
		 * @param array<int,WP_Agent_Package_Installed_Artifact> $artifacts Applied artifact snapshots.
		 * @param array<string,mixed>                  $context Consumer context.
		 * @return bool Whether snapshots were recorded.
		 */
		public function record_installed_artifacts( WP_Agent_Package $package, array $artifacts, array $context = array() ): bool;
	}
}
