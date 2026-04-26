<?php
/**
 * Pure-PHP smoke test for `datamachine_post_types_for_meta_description`.
 *
 * Run with: php tests/meta-description-post-types-filter-smoke.php
 *
 * Issue #1246: `MetaDescriptionAbilities::findPostsMissingExcerpt` and the
 * surrounding batch discovery path were post-type-blind. This test exercises
 * the new `getEligiblePostTypes()` helper and the
 * `datamachine_post_types_for_meta_description` filter that lets plugins
 * extend batch discovery to custom post types (e.g. Intelligence's `wiki`
 * articles).
 *
 * The test stubs WordPress filter functions with a tiny in-memory registry
 * so it can run under plain PHP with no WP bootstrap.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/smoke-wp-stubs.php';

// Tiny filter registry — enough for getEligiblePostTypes().
$GLOBALS['_dm_test_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['_dm_test_filters'][ $hook ][ $priority ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['_dm_test_filters'][ $hook ][ $priority ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['_dm_test_filters'][ $hook ][ $priority ] as $i => $registered ) {
			if ( $registered === $callback ) {
				unset( $GLOBALS['_dm_test_filters'][ $hook ][ $priority ][ $i ] );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		if ( empty( $GLOBALS['_dm_test_filters'][ $hook ] ) ) {
			return $value;
		}
		$args = func_get_args();
		array_shift( $args ); // drop hook name
		ksort( $GLOBALS['_dm_test_filters'][ $hook ] );
		foreach ( $GLOBALS['_dm_test_filters'][ $hook ] as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$args[0] = call_user_func_array( $callback, $args );
			}
		}
		return $args[0];
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

require_once __DIR__ . '/../inc/Abilities/PermissionHelper.php';
require_once __DIR__ . '/../inc/Abilities/SEO/MetaDescriptionAbilities.php';

use DataMachine\Abilities\SEO\MetaDescriptionAbilities;

function dm_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

echo "getEligiblePostTypes — defaults\n";
$default = MetaDescriptionAbilities::getEligiblePostTypes();
dm_assert( is_array( $default ), 'returns an array' );
dm_assert( in_array( 'post', $default, true ), 'includes "post" by default' );
dm_assert( in_array( 'page', $default, true ), 'includes "page" by default' );
dm_assert( 2 === count( $default ), 'returns exactly the two defaults' );

echo "getEligiblePostTypes — filter appends a custom post type\n";
$append_wiki = function ( array $types ): array {
	$types[] = 'wiki';
	return $types;
};
add_filter( 'datamachine_post_types_for_meta_description', $append_wiki );

$with_wiki = MetaDescriptionAbilities::getEligiblePostTypes();
dm_assert( in_array( 'wiki', $with_wiki, true ), 'includes "wiki" after filter' );
dm_assert( in_array( 'post', $with_wiki, true ), 'still includes "post"' );
dm_assert( in_array( 'page', $with_wiki, true ), 'still includes "page"' );
dm_assert( 3 === count( $with_wiki ), 'returns three post types after filter' );

echo "getEligiblePostTypes — removing the filter restores defaults\n";
remove_filter( 'datamachine_post_types_for_meta_description', $append_wiki );
$restored = MetaDescriptionAbilities::getEligiblePostTypes();
dm_assert( ! in_array( 'wiki', $restored, true ), '"wiki" gone after remove_filter' );
dm_assert( in_array( 'post', $restored, true ), '"post" still present' );
dm_assert( in_array( 'page', $restored, true ), '"page" still present' );
dm_assert( 2 === count( $restored ), 'returns exactly the two defaults again' );

echo "getEligiblePostTypes — sanitization + dedupe\n";
$noisy = function ( array $_types ): array {
	// Caller returns junk: mixed case, dupes, empty strings, invalid chars.
	return array( 'POST', 'post', '', 'wiki', 'wiki', 'bad type!' );
};
add_filter( 'datamachine_post_types_for_meta_description', $noisy );

$cleaned = MetaDescriptionAbilities::getEligiblePostTypes();
dm_assert( in_array( 'post', $cleaned, true ), 'lowercases "POST" to "post"' );
dm_assert( in_array( 'wiki', $cleaned, true ), 'preserves "wiki"' );
dm_assert( in_array( 'badtype', $cleaned, true ), 'sanitize_key strips invalid chars from "bad type!"' );
dm_assert( 1 === count( array_filter( $cleaned, fn( $t ) => 'post' === $t ) ), 'dedupes "post"' );
dm_assert( 1 === count( array_filter( $cleaned, fn( $t ) => 'wiki' === $t ) ), 'dedupes "wiki"' );
dm_assert( ! in_array( '', $cleaned, true ), 'empty strings filtered out' );
remove_filter( 'datamachine_post_types_for_meta_description', $noisy );

echo "getEligiblePostTypes — non-array return falls back to defaults\n";
$broken = function ( array $_types ) {
	return 'not an array';
};
add_filter( 'datamachine_post_types_for_meta_description', $broken );
$fallback = MetaDescriptionAbilities::getEligiblePostTypes();
dm_assert( is_array( $fallback ), 'still returns an array when filter misbehaves' );
dm_assert( array( 'post', 'page' ) === $fallback, 'falls back to default array exactly' );
remove_filter( 'datamachine_post_types_for_meta_description', $broken );

echo "getEligiblePostTypes — empty filter return falls back to defaults\n";
$empty = function ( array $_types ): array {
	return array();
};
add_filter( 'datamachine_post_types_for_meta_description', $empty );
$still_default = MetaDescriptionAbilities::getEligiblePostTypes();
dm_assert( array( 'post', 'page' ) === $still_default, 'empty filter return falls back to defaults' );
remove_filter( 'datamachine_post_types_for_meta_description', $empty );

echo "\n[OK] All meta-description post-types filter smoke tests passed.\n";
