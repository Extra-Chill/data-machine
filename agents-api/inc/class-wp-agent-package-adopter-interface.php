<?php
/**
 * WP_Agent_Package_Adopter_Interface contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Package_Adopter_Interface' ) ) {
	/**
	 * Contract for runtimes that can materialize agent package definitions.
	 */
	interface WP_Agent_Package_Adopter_Interface {

		/**
		 * Returns the adoption diff for a package without applying changes.
		 *
		 * @param WP_Agent_Package $package Agent package definition.
		 * @return WP_Agent_Package_Adoption_Diff
		 */
		public function diff( WP_Agent_Package $package ): WP_Agent_Package_Adoption_Diff;

		/**
		 * Adopts or updates a package in the implementing runtime.
		 *
		 * @param WP_Agent_Package  $package Agent package definition.
		 * @param array<string,mixed> $options Implementation-specific adoption options.
		 * @return WP_Agent_Package_Adoption_Result
		 */
		public function adopt( WP_Agent_Package $package, array $options = array() ): WP_Agent_Package_Adoption_Result;
	}
}
