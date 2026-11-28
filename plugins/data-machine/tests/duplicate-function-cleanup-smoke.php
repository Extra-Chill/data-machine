<?php
/**
 * Pure-PHP smoke test for duplicate-function cleanup (#674).
 *
 * Run with: php tests/duplicate-function-cleanup-smoke.php
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

function assert_duplicate_cleanup( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] $name\n";
		return;
	}

	echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
	++$failed;
}

function source_file( string $relative_path ): string {
	$path = dirname( __DIR__ ) . '/' . ltrim( $relative_path, '/' );
	$contents = file_get_contents( $path );
	if ( ! is_string( $contents ) ) {
		throw new RuntimeException( "Unable to read source file: {$relative_path}" );
	}
	return $contents;
}

function finish_duplicate_cleanup_smoke(): void {
	global $failed, $total;

	if ( $failed > 0 ) {
		echo "\nFAIL: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nPASS: {$total} assertions passed.\n";
}

echo "=== duplicate-function-cleanup-smoke ===\n";

$agent_files     = source_file( 'inc/Abilities/File/AgentFileAbilities.php' );
$registry        = source_file( 'inc/Engine/AI/MemoryFileRegistry.php' );
$oauth1          = source_file( 'inc/Core/OAuth/OAuth1Handler.php' );
$oauth2          = source_file( 'inc/Core/OAuth/OAuth2Handler.php' );
$oauth_redirects = source_file( 'inc/Core/OAuth/OAuthRedirects.php' );
$fetch_handler   = source_file( 'inc/Core/Steps/Fetch/Handlers/FetchHandler.php' );
$publish_handler = source_file( 'inc/Core/Steps/Publish/Handlers/PublishHandler.php' );
$http_helpers    = source_file( 'inc/Core/Steps/Handlers/HttpRequestHelpers.php' );

assert_duplicate_cleanup(
	'Agent file abilities delegates filename labels to MemoryFileRegistry',
	str_contains( $agent_files, 'MemoryFileRegistry::filename_to_label(' )
);
assert_duplicate_cleanup(
	'Agent file abilities no longer defines filename_to_label',
	! str_contains( $agent_files, 'function filename_to_label' )
);
assert_duplicate_cleanup(
	'MemoryFileRegistry exposes filename_to_label once',
	substr_count( $registry, 'function filename_to_label' ) === 1 && str_contains( $registry, 'public static function filename_to_label' )
);

assert_duplicate_cleanup( 'OAuth1 uses shared redirect trait', str_contains( $oauth1, 'use OAuthRedirects;' ) );
assert_duplicate_cleanup( 'OAuth2 uses shared redirect trait', str_contains( $oauth2, 'use OAuthRedirects;' ) );
assert_duplicate_cleanup(
	'OAuth handlers do not define redirect helpers locally',
	! str_contains( $oauth1, 'function redirect_with_error' )
		&& ! str_contains( $oauth1, 'function redirect_with_success' )
		&& ! str_contains( $oauth2, 'function redirect_with_error' )
		&& ! str_contains( $oauth2, 'function redirect_with_success' )
);
assert_duplicate_cleanup( 'OAuthRedirects trait owns error redirect', substr_count( $oauth_redirects, 'function redirect_with_error' ) === 1 );
assert_duplicate_cleanup( 'OAuthRedirects trait owns success redirect', substr_count( $oauth_redirects, 'function redirect_with_success' ) === 1 );

assert_duplicate_cleanup( 'FetchHandler uses shared HTTP helper trait', str_contains( $fetch_handler, 'use HttpRequestHelpers;' ) );
assert_duplicate_cleanup( 'PublishHandler uses shared HTTP helper trait', str_contains( $publish_handler, 'use HttpRequestHelpers;' ) );
foreach ( array( 'httpRequest', 'httpPost', 'httpDelete' ) as $method ) {
	assert_duplicate_cleanup(
		"Fetch/Publish handlers do not define {$method} locally",
		! str_contains( $fetch_handler, "function {$method}" )
			&& ! str_contains( $publish_handler, "function {$method}" )
	);
}
assert_duplicate_cleanup( 'HTTP helper trait owns httpRequest once', substr_count( $http_helpers, 'function httpRequest' ) === 1 );
assert_duplicate_cleanup( 'HTTP helper trait owns httpPost once', substr_count( $http_helpers, 'function httpPost' ) === 1 );
assert_duplicate_cleanup( 'HTTP helper trait owns httpDelete once', substr_count( $http_helpers, 'function httpDelete' ) === 1 );

finish_duplicate_cleanup_smoke();
