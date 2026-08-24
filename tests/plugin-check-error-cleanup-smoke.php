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
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "phpunit.xml.dist\n" ), 'PHPUnit configuration is excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "phpunit.sqlite.xml.dist\n" ), 'SQLite PHPUnit configuration is excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "vendor/wordpress/agents-api/tests/\n" ), 'bundled Agents API tests are excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "vendor/wordpress/agents-api/stubs/\n" ), 'unused bundled Agents API stubs are excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "vendor/automattic/blocks-engine-php-transformer/fixtures/\n" ), 'bundled Blocks Engine fixtures are excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "vendor/**/phpstan.neon.dist\n" ), 'vendor PHPStan configs are excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "vendor/**/psalm.xml\n" ), 'vendor Psalm configs are excluded from distribution package', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( $buildignore, "vendor/**/phpcs.xml.dist\n" ), 'vendor PHPCS configs are excluded from distribution package', $failures, $passes );

$package_zip = (string) getenv( 'DATAMACHINE_PACKAGE_ZIP' );
if ( '' !== $package_zip ) {
	$zip = new ZipArchive();
	agents_api_smoke_assert_equals( true, true === $zip->open( $package_zip ), 'candidate ZIP opens for inventory assertions', $failures, $passes );

	$forbidden_paths = array();
	for ( $index = 0; $index < $zip->numFiles; ++$index ) {
		$path = (string) $zip->getNameIndex( $index );
		if (
			1 === preg_match( '#/(?:test|tests|fixture|fixtures|__tests__)/#', $path )
			|| str_starts_with( $path, 'data-machine/vendor/wordpress/agents-api/stubs/' )
			|| 1 === preg_match( '#/(?:phpstan\.neon\.dist|psalm\.xml|phpcs\.xml\.dist|phpunit(?:\.sqlite)?\.xml\.dist)$#', $path )
			|| 1 === preg_match( '#\.(?:zip|phar|tar|gz)$#', $path )
		) {
			$forbidden_paths[] = $path;
		}
	}
	$zip->close();

	agents_api_smoke_assert_equals( array(), $forbidden_paths, 'candidate ZIP contains no forbidden test, stub, development-config, or nested-archive paths', $failures, $passes );
}

foreach ( array( 'inc/Core/Admin/AdminRootFilters.php', 'inc/Engine/AI/Directives/ClientContextDirective.php' ) as $guarded_file ) {
	agents_api_smoke_assert_equals( true, str_contains( datamachine_plugin_check_source( $guarded_file ), "defined( 'ABSPATH' ) || exit;" ), "{$guarded_file} has a direct access guard", $failures, $passes );
}

$fetch_disposition_source = datamachine_plugin_check_source( 'inc/Core/Steps/Fetch/Tools/FetchItemDispositionTool.php' );
agents_api_smoke_assert_equals( true, str_contains( $fetch_disposition_source, 'wp_strip_all_tags( $text )' ), 'fetch disposition redaction uses wp_strip_all_tags', $failures, $passes );
agents_api_smoke_assert_equals( false, str_contains( $fetch_disposition_source, 'strip_tags(' ), 'fetch disposition redaction avoids strip_tags', $failures, $passes );

$memory_store_source = datamachine_plugin_check_source( 'inc/Core/FilesRepository/GuidelineAgentMemoryStore.php' );
agents_api_smoke_assert_equals( false, str_contains( $memory_store_source, "'suppress_filters'" ), 'memory store relies on get_posts default suppress_filters behavior', $failures, $passes );

$scaffolding_source = datamachine_plugin_check_source( 'inc/setup/scaffolding.php' );
agents_api_smoke_assert_equals( false, datamachine_plugin_check_has_heredoc( $scaffolding_source ), 'scaffolding source avoids heredoc syntax', $failures, $passes );

agents_api_smoke_finish( 'Plugin Check error cleanup', $failures, $passes );
