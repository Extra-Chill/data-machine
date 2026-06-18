<?php
/**
 * Pure-PHP smoke test for network-wide agent scope preservation through the
 * bundle export -> import/adopt round-trip (#2749).
 *
 * Run with: php tests/agent-bundle-site-scope-roundtrip-smoke.php
 *
 * This is the exact path that broke the network-wide platform agent in
 * production: the bundle adapter hardcoded `site_scope = 'site'` on export,
 * which the canonical round-trip then resolved to the installing blog. A
 * network-wide (NULL) agent bundled from one subsite came back pinned to that
 * subsite and was dead everywhere else.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		return 0;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		// no-op
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		// no-op stub for standalone smoke runs.
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value = null, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {
		// no-op stub for standalone smoke runs.
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Engine\Bundle\AgentBundleArrayAdapter;
use DataMachine\Engine\Bundle\BundleSchema;

$failures = array();
$passes   = 0;

function assert_scope( string $label, bool $condition ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		echo "  PASS: {$label}\n";
		return;
	}
	$failures[] = $label;
	echo "  FAIL: {$label}\n";
}

function assert_scope_equals( string $label, $expected, $actual ): void {
	assert_scope( $label, $expected === $actual );
}

/**
 * Build a minimal bundle array carrying a given agent scope value.
 *
 * @param mixed $site_scope Value (or sentinel-absent) for the agent block.
 * @param bool  $include    Whether to include the site_scope key at all.
 * @return array<string,mixed>
 */
function scope_bundle( $site_scope, bool $include = true ): array {
	$agent = array(
		'agent_slug'   => 'platform-agent',
		'agent_name'   => 'Platform Agent',
		'agent_config' => array( 'model' => 'gpt-5.5' ),
	);
	if ( $include ) {
		$agent['site_scope'] = $site_scope;
	}

	return array(
		'bundle_version' => 1,
		'exported_at'    => '2026-06-18T12:00:00Z',
		'agent'          => $agent,
		'files'          => array( 'SOUL.md' => "# Soul\n" ),
		'pipelines'      => array(),
		'flows'          => array(),
	);
}

/**
 * Run a bundle array through the canonical round-trip the importer uses
 * (from_array_bundle -> to_array_bundle), returning the resulting agent block.
 *
 * @param array<string,mixed> $bundle Bundle array.
 * @return array<string,mixed>
 */
function round_trip_agent( array $bundle ): array {
	$directory = AgentBundleArrayAdapter::from_array_bundle( $bundle );
	$result    = AgentBundleArrayAdapter::to_array_bundle( $directory );
	return is_array( $result['agent'] ?? null ) ? $result['agent'] : array();
}

echo "=== Agent Bundle Site-Scope Round-Trip Smoke (#2749) ===\n";

echo "\n[1] BundleSchema::normalize_agent_site_scope classifies scope values\n";
assert_scope_equals( 'explicit null is first-class network-wide', null, BundleSchema::normalize_agent_site_scope( null ) );
assert_scope_equals( 'positive int is a specific blog', 12, BundleSchema::normalize_agent_site_scope( 12 ) );
assert_scope_equals( 'numeric string is a specific blog', 7, BundleSchema::normalize_agent_site_scope( '7' ) );
assert_scope_equals( 'string "null" is network-wide', null, BundleSchema::normalize_agent_site_scope( 'null' ) );
assert_scope_equals( 'legacy "site" literal is unspecified', BundleSchema::SITE_SCOPE_UNSPECIFIED, BundleSchema::normalize_agent_site_scope( 'site' ) );
assert_scope_equals( 'empty string is unspecified', BundleSchema::SITE_SCOPE_UNSPECIFIED, BundleSchema::normalize_agent_site_scope( '' ) );
assert_scope_equals( 'zero collapses to unspecified', BundleSchema::SITE_SCOPE_UNSPECIFIED, BundleSchema::normalize_agent_site_scope( 0 ) );
assert_scope( 'unspecified sentinel is distinct from null', null !== BundleSchema::SITE_SCOPE_UNSPECIFIED );

echo "\n[2] Network-wide (NULL) scope survives the round-trip — the roadie regression\n";
$agent = round_trip_agent( scope_bundle( null ) );
assert_scope( 'network-wide agent carries a site_scope key', array_key_exists( 'site_scope', $agent ) );
assert_scope( 'network-wide agent round-trips to NULL, not the installing blog', array_key_exists( 'site_scope', $agent ) && null === $agent['site_scope'] );
assert_scope( 'round-trip never re-stamps the legacy "site" literal', 'site' !== ( $agent['site_scope'] ?? null ) );

echo "\n[3] Site-specific scope survives the round-trip\n";
$agent = round_trip_agent( scope_bundle( 12 ) );
assert_scope_equals( 'site-specific agent round-trips to its blog ID', 12, $agent['site_scope'] ?? 'MISSING' );

echo "\n[4] Legacy/unknown scope is dropped (no silent re-pin to current blog)\n";
$agent = round_trip_agent( scope_bundle( 'site' ) );
assert_scope( 'legacy "site" literal is omitted from the round-tripped agent', ! array_key_exists( 'site_scope', $agent ) );
$agent = round_trip_agent( scope_bundle( null, false ) );
assert_scope( 'absent scope stays absent through the round-trip', ! array_key_exists( 'site_scope', $agent ) );

echo "\n[5] Importer create-scope resolution honors the round-tripped value\n";
// Mirror AgentBundler::import()'s create-branch resolution: unspecified -> false
// (use DB default NULL = network-wide); null/int -> explicit scope on create.
$resolve = static function ( $bundle_agent ) {
	$raw   = array_key_exists( 'site_scope', $bundle_agent ) ? $bundle_agent['site_scope'] : BundleSchema::SITE_SCOPE_UNSPECIFIED;
	$scope = BundleSchema::normalize_agent_site_scope( $raw );
	return ( BundleSchema::SITE_SCOPE_UNSPECIFIED === $scope ) ? false : $scope;
};
assert_scope_equals( 'network-wide bundle creates with explicit NULL scope', null, $resolve( round_trip_agent( scope_bundle( null ) ) ) );
assert_scope_equals( 'site-specific bundle creates scoped to its blog', 12, $resolve( round_trip_agent( scope_bundle( 12 ) ) ) );
assert_scope_equals( 'legacy bundle falls to the column default (network-wide)', false, $resolve( round_trip_agent( scope_bundle( 'site' ) ) ) );

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " site-scope round-trip assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} site-scope round-trip assertions passed.\n";
