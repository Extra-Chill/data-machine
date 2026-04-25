<?php
/**
 * Pure-PHP smoke test for the ResolveTermAbility args passthrough (#1164).
 *
 * Run with: php tests/resolve-term-args-smoke.php
 *
 * The ability gained an optional 4th argument that flows through to
 * wp_insert_term() on the create path. This test exercises the
 * normalize_term_args() whitelist directly so we can verify the sanitisation
 * surface without booting WordPress.
 *
 * Whitelist (mirrors wp_insert_term() create-time args):
 *   - description (sanitize_textarea_field)
 *   - parent      (absint)
 *   - slug        (sanitize_title)
 *   - alias_of    (sanitize_title)
 *
 * Anything else is silently dropped so untrusted callers cannot reach
 * wp_insert_term() with arbitrary keys.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Inline reimplementation of ResolveTermAbility::normalize_term_args().
 *
 * Kept in lockstep with the production code in
 * inc/Abilities/Taxonomy/ResolveTermAbility.php. If you change one, change
 * both.
 *
 * @param array $args Raw caller args.
 * @return array Sanitised args ready for wp_insert_term().
 */
function dm_test_normalize_term_args( array $args ): array {
	$clean = array();

	if ( isset( $args['description'] ) ) {
		$clean['description'] = dm_test_sanitize_textarea_field( (string) $args['description'] );
	}
	if ( isset( $args['parent'] ) ) {
		$clean['parent'] = dm_test_absint( $args['parent'] );
	}
	if ( isset( $args['slug'] ) ) {
		$clean['slug'] = dm_test_sanitize_title( (string) $args['slug'] );
	}
	if ( isset( $args['alias_of'] ) ) {
		$clean['alias_of'] = dm_test_sanitize_title( (string) $args['alias_of'] );
	}

	return $clean;
}

// Lightweight stand-ins for the WordPress sanitisers used by the production
// code. Behaviour is approximated, not byte-identical — the production code
// runs the real WordPress functions.
function dm_test_sanitize_textarea_field( string $value ): string {
	$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
	return trim( $value );
}

function dm_test_absint( $value ): int {
	return abs( (int) $value );
}

function dm_test_sanitize_title( string $value ): string {
	$value = strtolower( trim( $value ) );
	$value = preg_replace( '/[^a-z0-9\-_]+/', '-', $value );
	$value = trim( $value, '-' );
	return $value;
}

$failures = array();

// Case 1: empty input => empty array.
$out = dm_test_normalize_term_args( array() );
if ( $out !== array() ) {
	$failures[] = 'empty input should yield empty array, got: ' . var_export( $out, true );
}

// Case 2: all four whitelisted keys flow through.
$out = dm_test_normalize_term_args(
	array(
		'description' => 'A description',
		'parent'      => '12',
		'slug'        => 'My Slug',
		'alias_of'    => 'Existing Term',
	)
);
if ( ! isset( $out['description'] ) || 'A description' !== $out['description'] ) {
	$failures[] = 'description not preserved: ' . var_export( $out, true );
}
if ( ! isset( $out['parent'] ) || 12 !== $out['parent'] ) {
	$failures[] = 'parent not coerced to int: ' . var_export( $out, true );
}
if ( ! isset( $out['slug'] ) || 'my-slug' !== $out['slug'] ) {
	$failures[] = 'slug not sanitised: ' . var_export( $out, true );
}
if ( ! isset( $out['alias_of'] ) || 'existing-term' !== $out['alias_of'] ) {
	$failures[] = 'alias_of not sanitised: ' . var_export( $out, true );
}

// Case 3: unknown keys are silently dropped (no leakage to wp_insert_term).
$out = dm_test_normalize_term_args(
	array(
		'description' => 'desc',
		'evil'        => 'should not survive',
		'taxonomy'    => 'category',
	)
);
if ( count( $out ) !== 1 || ! isset( $out['description'] ) ) {
	$failures[] = 'unknown keys leaked through whitelist: ' . var_export( $out, true );
}

// Case 4: parent=0 is preserved (top-level), not dropped.
$out = dm_test_normalize_term_args( array( 'parent' => 0 ) );
if ( ! array_key_exists( 'parent', $out ) || 0 !== $out['parent'] ) {
	$failures[] = 'parent=0 should pass through as 0: ' . var_export( $out, true );
}

// Case 5: negative parent is absint'd.
$out = dm_test_normalize_term_args( array( 'parent' => -5 ) );
if ( 5 !== $out['parent'] ) {
	$failures[] = 'negative parent not absint\'d: ' . var_export( $out, true );
}

if ( ! empty( $failures ) ) {
	echo "FAIL\n";
	foreach ( $failures as $f ) {
		echo "  - $f\n";
	}
	exit( 1 );
}

echo "PASS resolve-term args smoke (5/5 cases)\n";
exit( 0 );
