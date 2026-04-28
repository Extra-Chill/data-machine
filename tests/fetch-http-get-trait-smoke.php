<?php
/**
 * Pure-PHP smoke test for shared fetch HTTP helper (#1322).
 *
 * Run with: php tests/fetch-http-get-trait-smoke.php
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );

$files = array(
	'trait' => file_get_contents( $root . '/inc/Abilities/Fetch/FetchHttpGetTrait.php' ) ?: '',
	'rss'   => file_get_contents( $root . '/inc/Abilities/Fetch/FetchRssAbility.php' ) ?: '',
	'wpapi' => file_get_contents( $root . '/inc/Abilities/Fetch/FetchWordPressApiAbility.php' ) ?: '',
);

$failed = 0;
$total  = 0;

function assert_fetch_http_get_trait( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
}

function fetch_http_get_trait_failed_count(): int {
	global $failed;
	return $failed;
}

echo "=== fetch-http-get-trait-smoke ===\n";

assert_fetch_http_get_trait(
	'FetchHttpGetTrait defines the shared HTTP helper',
	str_contains( $files['trait'], 'trait FetchHttpGetTrait' )
		&& substr_count( $files['trait'], 'function httpGet' ) === 1
);

assert_fetch_http_get_trait(
	'RSS ability consumes shared helper trait',
	str_contains( $files['rss'], 'use FetchHttpGetTrait;' )
);

assert_fetch_http_get_trait(
	'WordPress API ability consumes shared helper trait',
	str_contains( $files['wpapi'], 'use FetchHttpGetTrait;' )
);

assert_fetch_http_get_trait(
	'RSS ability no longer defines a private duplicate httpGet method',
	substr_count( $files['rss'], 'function httpGet' ) === 0
);

assert_fetch_http_get_trait(
	'WordPress API ability no longer defines a private duplicate httpGet method',
	substr_count( $files['wpapi'], 'function httpGet' ) === 0
);

assert_fetch_http_get_trait(
	'Shared helper preserves WordPress HTTP API call',
	str_contains( $files['trait'], 'wp_remote_get( $url, $args )' )
		&& str_contains( $files['trait'], 'wp_remote_retrieve_response_code( $response )' )
		&& str_contains( $files['trait'], 'wp_remote_retrieve_body( $response )' )
);

if ( fetch_http_get_trait_failed_count() > 0 ) {
	echo "\nfetch-http-get-trait-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\nfetch-http-get-trait-smoke passed: {$total} assertions.\n";
