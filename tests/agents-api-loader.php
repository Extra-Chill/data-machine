<?php
/**
 * Test helper for loading the standalone Agents API dependency.
 *
 * @package DataMachine\Tests
 */

function datamachine_tests_agents_api_bootstrap_path(): string {
	$path = dirname( __DIR__ ) . '/vendor/extra-chill/agents-api/agents-api.php';
	if ( ! file_exists( $path ) ) {
		throw new RuntimeException( 'Agents API dependency is missing. Run composer install before this smoke.' );
	}

	return $path;
}

function datamachine_tests_require_agents_api(): void {
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $_hook, callable $_callback, int $_priority = 10, int $_accepted_args = 1 ): void {}
	}

	require_once datamachine_tests_agents_api_bootstrap_path();
}
