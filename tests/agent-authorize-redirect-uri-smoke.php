<?php
/**
 * Smoke tests for agent authorize redirect URI matching.
 *
 *   php tests/agent-authorize-redirect-uri-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/Auth/AgentAuthorize.php';

$reflection = new ReflectionClass( DataMachine\Core\Auth\AgentAuthorize::class );
$authorize  = $reflection->newInstanceWithoutConstructor();
$matches    = new ReflectionMethod( $authorize, 'uri_matches_pattern' );

$pass     = 0;
$fail     = 0;
$failures = array();

$assert = static function ( string $label, bool $condition, string $detail = '' ) use ( &$pass, &$fail, &$failures ): void {
	if ( $condition ) {
		++$pass;
		echo "PASS: {$label}\n";
		return;
	}

	++$fail;
	$failures[] = array( $label, $detail );
	echo "FAIL: {$label}" . ( '' !== $detail ? "\n  {$detail}" : '' ) . "\n";
};

$uri_matches = static function ( string $uri, string $pattern ) use ( $matches, $authorize ): bool {
	return $matches->invoke( $authorize, $uri, $pattern );
};

$assert(
	'wildcard rejects prefix-confusable sibling host',
	false === $uri_matches( 'https://example.com.evil/x', 'https://example.com/*' )
);
$assert(
	'wildcard rejects prefix-confusable longer domain',
	false === $uri_matches( 'https://example.community/x', 'https://example.com/*' )
);
$assert(
	'wildcard rejects scheme downgrade',
	false === $uri_matches( 'http://example.com/x', 'https://example.com/*' )
);
$assert(
	'wildcard rejects port change',
	false === $uri_matches( 'https://example.com:8443/x', 'https://example.com/*' )
);
$assert(
	'wildcard accepts same host nested callback path',
	true === $uri_matches( 'https://example.com/cb', 'https://example.com/*' )
);
$assert(
	'wildcard accepts same host root path',
	true === $uri_matches( 'https://example.com/', 'https://example.com/*' )
);
$assert(
	'wildcard path boundary rejects sibling segment',
	false === $uri_matches( 'https://example.com/cbx', 'https://example.com/cb/*' )
);
$assert(
	'wildcard path boundary accepts exact base path',
	true === $uri_matches( 'https://example.com/cb', 'https://example.com/cb/*' )
);
$assert(
	'wildcard path boundary accepts child path',
	true === $uri_matches( 'https://example.com/cb/x', 'https://example.com/cb/*' )
);
$assert(
	'wildcard treats implicit and explicit default ports consistently',
	true === $uri_matches( 'https://example.com:443/cb', 'https://example.com/*' )
);

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
