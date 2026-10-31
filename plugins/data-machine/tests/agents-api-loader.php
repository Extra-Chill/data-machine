<?php
/**
 * Test helper for loading the standalone Agents API dependency.
 *
 * @package DataMachine\Tests
 */

function datamachine_tests_agents_api_bootstrap_path(): string {
	$override = getenv( 'DATAMACHINE_TESTS_AGENTS_API_PATH' );
	if ( is_string( $override ) && '' !== $override ) {
		$override_path = rtrim( $override, '/\\' ) . '/agents-api.php';
		if ( file_exists( $override_path ) ) {
			return $override_path;
		}
	}

	$path = dirname( __DIR__ ) . '/vendor/wordpress/agents-api/agents-api.php';
	if ( ! file_exists( $path ) ) {
		throw new RuntimeException( 'Agents API dependency is missing. Run composer install before this smoke.' );
	}

	return $path;
}

function datamachine_tests_require_agents_api(): void {
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $_hook, callable $_callback, int $_priority = 10, int $_accepted_args = 1 ): void {}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $_hook, callable $_callback, int $_priority = 10, int $_accepted_args = 1 ): void {}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $_hook, $value, ...$_args ) {
			return $value;
		}
	}

	require_once datamachine_tests_agents_api_bootstrap_path();
}
