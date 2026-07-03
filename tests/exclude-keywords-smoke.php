<?php
/**
 * Pure-PHP smoke test for fetch-handler `exclude_keywords` filtering.
 *
 * Run with: php tests/exclude-keywords-smoke.php
 *
 * Issue #1190: `FetchHandlerSettings::get_common_fields()` declares an
 * `exclude_keywords` field that was previously a UI-only no-op for every
 * fetch handler. This test exercises the new `applyKeywordExclusion()`
 * filter that each fetch ability now applies after `applyKeywordSearch()`.
 *
 * The four fetch abilities (RSS, WordPress API, Query WordPress Posts,
 * WordPress Media) each ship their own private copy of the matcher so
 * they remain self-contained. The matcher shape is identical across
 * abilities; we exercise it through one ability via reflection.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/smoke-wp-stubs.php';

// Stub the WordPress functions FetchRssAbility's class definition references
// before we autoload it. Only stub if not already provided so tests stay
// composable with other smoke tests.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}
require_once __DIR__ . '/../inc/Abilities/PermissionHelper.php';
require_once __DIR__ . '/../inc/Abilities/Fetch/FetchRssAbility.php';

use DataMachine\Abilities\Fetch\FetchRssAbility;

function datamachine_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

/**
 * Invoke the private applyKeywordExclusion method via reflection.
 *
 * Private/protected methods are invocable through reflection without
 * setAccessible() since PHP 8.1; calling it is a no-op + deprecation in 8.5.
 */
function call_exclude( FetchRssAbility $ability, string $text, string $excludes ): bool {
	$ref = new ReflectionMethod( FetchRssAbility::class, 'applyKeywordExclusion' );
	return (bool) $ref->invoke( $ability, $text, $excludes );
}

/**
 * Same for applyKeywordSearch — verifies semantics didn't regress.
 */
function call_search( FetchRssAbility $ability, string $text, string $search ): bool {
	$ref = new ReflectionMethod( FetchRssAbility::class, 'applyKeywordSearch' );
	return (bool) $ref->invoke( $ability, $text, $search );
}

$ability = new FetchRssAbility();

echo "applyKeywordExclusion — empty exclusion list never matches\n";
datamachine_assert( false === call_exclude( $ability, 'Anything', '' ), 'empty string returns false' );
datamachine_assert( false === call_exclude( $ability, 'Anything', '   ' ), 'whitespace-only returns false' );
datamachine_assert( false === call_exclude( $ability, 'Anything', ',,,' ), 'commas-only returns false' );

echo "applyKeywordExclusion — single keyword match\n";
datamachine_assert( true === call_exclude( $ability, 'Foo dot release bar', 'dot release' ), 'substring matches' );
datamachine_assert( true === call_exclude( $ability, 'FOO DOT RELEASE BAR', 'dot release' ), 'case-insensitive uppercase text' );
datamachine_assert( true === call_exclude( $ability, 'Foo Dot Release Bar', 'DOT RELEASE' ), 'case-insensitive uppercase keyword' );

echo "applyKeywordExclusion — non-matching\n";
datamachine_assert( false === call_exclude( $ability, 'Foo bar baz', 'qux' ), 'unrelated keyword returns false' );
datamachine_assert( false === call_exclude( $ability, '', 'qux' ), 'empty text returns false' );

echo "applyKeywordExclusion — multiple comma-separated keywords (any-match)\n";
datamachine_assert( true === call_exclude( $ability, 'A B C D', 'X, B, Y' ), 'second keyword matches' );
datamachine_assert( true === call_exclude( $ability, 'A B C D', 'A,X,Y' ), 'first keyword matches' );
datamachine_assert( false === call_exclude( $ability, 'A B C D', 'X, Y, Z' ), 'no keyword matches' );
datamachine_assert( true === call_exclude( $ability, 'A B C D', '   ,B,   ' ), 'empty terms ignored, real term matches' );

echo "applyKeywordExclusion vs applyKeywordSearch — inverse semantics\n";
// "search" is include-first: empty search means "keep everything"; non-empty must match to keep.
// "exclude" is the inverse: empty means "skip nothing"; non-empty must match to skip.
datamachine_assert( true === call_search( $ability, 'A B', '' ), 'empty search keeps item' );
datamachine_assert( false === call_exclude( $ability, 'A B', '' ), 'empty exclude does NOT skip item' );
datamachine_assert( true === call_search( $ability, 'A B', 'B' ), 'matching search keeps item' );
datamachine_assert( true === call_exclude( $ability, 'A B', 'B' ), 'matching exclude SKIPS item' );
datamachine_assert( false === call_search( $ability, 'A B', 'C' ), 'non-matching search drops item' );
datamachine_assert( false === call_exclude( $ability, 'A B', 'C' ), 'non-matching exclude does NOT skip item' );

echo "\n[OK] All exclude_keywords smoke tests passed.\n";
