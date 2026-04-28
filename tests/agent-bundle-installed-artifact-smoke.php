<?php
/**
 * Pure-PHP smoke test for installed agent bundle artifact tracking (#1531).
 *
 * Run with: php tests/agent-bundle-installed-artifact-smoke.php
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

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentBundleInstalledArtifact;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\BundleValidationException;

$failures = 0;
$total    = 0;

function assert_installed_artifact( string $label, bool $condition ): void {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$failures;
}

function assert_installed_artifact_equals( string $label, $expected, $actual ): void {
	assert_installed_artifact( $label, $expected === $actual );
}

echo "=== Agent Bundle Installed Artifact Smoke (#1531) ===\n";

$manifest = AgentBundleManifest::from_array(
	array(
		'schema_version'  => 1,
		'bundle_slug'     => 'WooCommerce Brain',
		'bundle_version'  => '2026.04.28',
		'exported_at'     => '2026-04-28T00:00:00Z',
		'exported_by'     => 'data-machine/test',
		'agent'           => array(
			'slug'         => 'wc-agent',
			'label'        => 'WooCommerce Agent',
			'description'  => 'Maintains WooCommerce knowledge.',
			'agent_config' => array(),
		),
		'included'        => array(
			'memory'       => array(),
			'pipelines'    => array(),
			'flows'        => array(),
			'handler_auth' => 'refs',
		),
	)
);

echo "\n[1] Artifact hashes are deterministic\n";
$payload_a = array(
	'name'   => 'Daily ingest',
	'config' => array(
		'b'     => 2,
		'a'     => 1,
		'steps' => array( 'fetch', 'ai' ),
	),
);
$payload_b = array(
	'config' => array(
		'steps' => array( 'fetch', 'ai' ),
		'a'     => 1,
		'b'     => 2,
	),
	'name'   => 'Daily ingest',
);
$payload_c = array(
	'name'   => 'Daily ingest',
	'config' => array(
		'a'     => 1,
		'b'     => 2,
		'steps' => array( 'ai', 'fetch' ),
	),
);
$hash_a    = AgentBundleArtifactHasher::hash( $payload_a );
assert_installed_artifact_equals( 'associative key order does not change hash', $hash_a, AgentBundleArtifactHasher::hash( $payload_b ) );
assert_installed_artifact( 'list order does change hash', $hash_a !== AgentBundleArtifactHasher::hash( $payload_c ) );
assert_installed_artifact_equals( 'string payload hashes directly', hash( 'sha256', "Prompt\n" ), AgentBundleArtifactHasher::hash( "Prompt\n" ) );

echo "\n[2] Installed record stores bundle/artifact identity and current status\n";
$installed = AgentBundleInstalledArtifact::from_installed_payload(
	$manifest,
	'pipeline',
	'daily-ingest',
	'pipelines/daily-ingest.json',
	$payload_a,
	'2026-04-28T00:00:00Z'
);
$installed_array = $installed->to_array();
assert_installed_artifact_equals( 'bundle slug normalized', 'woocommerce-brain', $installed_array['bundle_slug'] );
assert_installed_artifact_equals( 'bundle version preserved', '2026.04.28', $installed_array['bundle_version'] );
assert_installed_artifact_equals( 'artifact type preserved', 'pipeline', $installed_array['artifact_type'] );
assert_installed_artifact_equals( 'artifact id preserved', 'daily-ingest', $installed_array['artifact_id'] );
assert_installed_artifact_equals( 'source path preserved', 'pipelines/daily-ingest.json', $installed_array['source_path'] );
assert_installed_artifact_equals( 'installed hash stored', $hash_a, $installed_array['installed_hash'] );
assert_installed_artifact_equals( 'current hash initialized from installed payload', $hash_a, $installed_array['current_hash'] );
assert_installed_artifact_equals( 'fresh install is clean', AgentBundleArtifactStatus::CLEAN, $installed_array['status'] );

echo "\n[3] Clean, modified, missing, and orphaned states classify distinctly\n";
$clean = $installed->with_current_payload( $payload_b, '2026-04-28T01:00:00Z' )->to_array();
assert_installed_artifact_equals( 'same structured payload is clean', AgentBundleArtifactStatus::CLEAN, $clean['status'] );

$modified = $installed->with_current_payload( array( 'name' => 'Edited ingest' ), '2026-04-28T01:00:00Z' )->to_array();
assert_installed_artifact_equals( 'changed runtime payload is modified', AgentBundleArtifactStatus::MODIFIED, $modified['status'] );
assert_installed_artifact( 'modified payload updates current hash', $modified['current_hash'] !== $modified['installed_hash'] );

$missing = $installed->with_current_payload( null, '2026-04-28T01:00:00Z' )->to_array();
assert_installed_artifact_equals( 'missing runtime payload is missing', AgentBundleArtifactStatus::MISSING, $missing['status'] );
assert_installed_artifact_equals( 'missing runtime payload has null current hash', null, $missing['current_hash'] );

assert_installed_artifact_equals( 'untracked runtime payload is orphaned', AgentBundleArtifactStatus::ORPHANED, AgentBundleArtifactStatus::classify( null, $hash_a ) );
assert_installed_artifact_equals( 'empty current hash is treated as missing', AgentBundleArtifactStatus::MISSING, AgentBundleArtifactStatus::classify( $hash_a, '' ) );

echo "\n[4] Stored records round-trip and validate narrowly\n";
$round_trip = AgentBundleInstalledArtifact::from_array( $modified )->to_array();
assert_installed_artifact_equals( 'round-trip recomputes modified status', AgentBundleArtifactStatus::MODIFIED, $round_trip['status'] );
assert_installed_artifact_equals( 'round-trip preserves updated timestamp', '2026-04-28T01:00:00Z', $round_trip['updated_at'] );

$threw = false;
try {
	AgentBundleInstalledArtifact::from_array( array_merge( $installed_array, array( 'artifact_type' => 'secret' ) ) );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'artifact_type must be one of' );
}
assert_installed_artifact( 'unsupported artifact type fails clearly', $threw );

$threw = false;
try {
	AgentBundleInstalledArtifact::from_array( array_merge( $installed_array, array( 'source_path' => '../secret.txt' ) ) );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'source_path must be bundle-local' );
}
assert_installed_artifact( 'source path traversal fails clearly', $threw );

echo "\n[5] Documentation names the storage fields and statuses\n";
$docs = file_get_contents( dirname( __DIR__ ) . '/docs/core-system/agent-bundles.md' ) ?: '';
foreach ( array( 'bundle_slug', 'bundle_version', 'artifact_type', 'artifact_id', 'installed_hash', 'current_hash', 'status' ) as $field ) {
	assert_installed_artifact( "docs include {$field}", str_contains( $docs, $field ) );
}
foreach ( array( 'clean', 'modified', 'missing', 'orphaned' ) as $status ) {
	assert_installed_artifact( "docs include {$status} status", str_contains( $docs, "`{$status}`" ) );
}

echo "\nTotal assertions: {$total}\n";
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}

echo "All assertions passed.\n";
