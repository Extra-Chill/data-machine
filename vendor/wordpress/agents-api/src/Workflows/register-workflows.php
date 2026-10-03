<?php
/**
 * Global helper functions for the in-memory workflow registry.
 *
 * Mirrors `src/Registry/register-agents.php` and
 * `src/Packages/register-agent-package-artifacts.php`: the class file
 * contains the class; the helpers live alongside as plain functions so
 * a file is either OO or procedural, never both (PHPCS:
 * Universal.Files.SeparateFunctionsFromOO).
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_register_workflow' ) ) {
	/**
	 * Convenience wrapper: register a code-defined workflow from a raw spec
	 * array. Returns the validated Spec or a WP_Error on validation failure.
	 *
	 * @since 0.103.0
	 *
	 * @param array<mixed> $spec Raw workflow spec — see WP_Agent_Workflow_Spec_Validator.
	 * @return AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec|WP_Error
	 */
	function wp_register_workflow( array $spec ) {
		return AgentsAPI\AI\Workflows\WP_Agent_Workflow_Registry::register( $spec );
	}
}

if ( ! function_exists( 'wp_get_workflow' ) ) {
	/**
	 * Convenience wrapper: look up a registered workflow by id.
	 *
	 * @since 0.103.0
	 *
	 * @param string $workflow_id
	 * @return AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec|null
	 */
	function wp_get_workflow( string $workflow_id ): ?AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec {
		return AgentsAPI\AI\Workflows\WP_Agent_Workflow_Registry::find( $workflow_id );
	}
}
