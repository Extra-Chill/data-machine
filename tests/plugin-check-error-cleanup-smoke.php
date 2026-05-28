<?php
/**
 * Pure-PHP smoke test for Plugin Check error cleanup fixtures.
 *
 * Run with: php tests/plugin-check-error-cleanup-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "plugin-check-error-cleanup-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

function datamachine_plugin_check_source( string $relative_path ): string {
	$path = dirname( __DIR__ ) . '/' . $relative_path;
	return (string) file_get_contents( $path );
}

function datamachine_plugin_check_has_heredoc( string $source ): bool {
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && T_START_HEREDOC === $token[0] ) {
			return true;
		}
	}

	return false;
}

$buildignore = datamachine_plugin_check_source( '.buildignore' );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, ".git\n" ), 'gitfile metadata is excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, ".datamachine/\n" ), 'DMC metadata is excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "AGENTS.md\n" ), 'agent context is excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "bin/install-wp-tests.sh\n" ), 'test install script is excluded from distribution package', $failures, $passes );

foreach ( array( 'inc/Cli/Bootstrap.php', 'inc/Core/Admin/AdminRootFilters.php', 'inc/Engine/AI/Directives/ClientContextDirective.php' ) as $guarded_file ) {
	agents_api_smoke_assert_equals( true, str_contains( datamachine_plugin_check_source( $guarded_file ), "defined( 'ABSPATH' ) || exit;" ), "{$guarded_file} has a direct access guard", $failures, $passes );
}

$fetch_disposition_source = datamachine_plugin_check_source( 'inc/Core/Steps/Fetch/Tools/FetchItemDispositionTool.php' );
agents_api_smoke_assert_equals( true, str_contains( $fetch_disposition_source, 'wp_strip_all_tags( $text )' ), 'fetch disposition redaction uses wp_strip_all_tags', $failures, $passes );
agents_api_smoke_assert_equals( false, str_contains( $fetch_disposition_source, 'strip_tags(' ), 'fetch disposition redaction avoids strip_tags', $failures, $passes );

$memory_store_source = datamachine_plugin_check_source( 'inc/Core/FilesRepository/GuidelineAgentMemoryStore.php' );
agents_api_smoke_assert_equals( false, str_contains( $memory_store_source, "'suppress_filters'" ), 'memory store relies on get_posts default suppress_filters behavior', $failures, $passes );

$scaffolding_source = datamachine_plugin_check_source( 'inc/migrations/scaffolding.php' );
agents_api_smoke_assert_equals( false, datamachine_plugin_check_has_heredoc( $scaffolding_source ), 'scaffolding source avoids heredoc syntax', $failures, $passes );

agents_api_smoke_finish( 'Plugin Check error cleanup', $failures, $passes );
