<?php
/**
 * Pure-PHP smoke test for agent-level memory bundle artifact scoping (#2818).
 *
 * Verifies that a flow-less, memory-bearing bundle's authored agent identity
 * (agent/SOUL.md) is surfaced as a planner target artifact and applied to the
 * live store, while learned runtime memory (MEMORY.md, daily/*) is never
 * materialized from a bundle.
 *
 * Run with: php tests/agent-bundle-memory-artifact-smoke.php
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
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		return preg_replace( '/[^A-Za-z0-9._\-\/]/', '', (string) $filename );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
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
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value = null, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Engine\AI\MemoryFileRegistry;
use DataMachine\Engine\Bundle\AgentBundleMemoryArtifact;

$failures = 0;
$total    = 0;

function assert_memory( string $label, bool $condition ): void {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$failures;
}

function assert_memory_equals( string $label, $expected, $actual ): void {
	$ok = $expected === $actual;
	assert_memory( $label, $ok );
	if ( ! $ok ) {
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}
}

echo "=== Agent Bundle Memory Artifact Smoke (#2818) ===\n";

// Register the canonical identity + learned files so the authority-tier gate
// resolves deterministically (mirrors bootstrap registrations).
MemoryFileRegistry::reset();
MemoryFileRegistry::register( 'SOUL.md', 20, array( 'layer' => MemoryFileRegistry::LAYER_AGENT ) );
MemoryFileRegistry::register( 'MEMORY.md', 30, array( 'layer' => MemoryFileRegistry::LAYER_AGENT ) );

echo "\n[1] Authored identity (SOUL.md) is a target artifact; learned memory is not\n";

// Bundle 'files' is the agent-layer memory map with the agent/ prefix stripped,
// exactly as AgentBundleArrayAdapter::to_array_bundle produces it.
$bundle = array(
	'files' => array(
		'SOUL.md'           => "new soul\n",
		'MEMORY.md'         => "learned\n",
		'daily/2026/06/28.md' => "today\n",
	),
);

$targets = AgentBundleMemoryArtifact::target_artifacts( $bundle );
$by_id   = array();
foreach ( $targets as $artifact ) {
	$by_id[ (string) $artifact['artifact_id'] ] = $artifact;
}

assert_memory_equals( 'exactly one memory target emitted', 1, count( $targets ) );
assert_memory( 'SOUL.md surfaces as agent/SOUL.md target', isset( $by_id['agent/SOUL.md'] ) );
assert_memory( 'learned MEMORY.md is NOT a target', ! isset( $by_id['agent/MEMORY.md'] ) );
assert_memory( 'learned daily/* is NOT a target', ! isset( $by_id['agent/daily/2026/06/28.md'] ) );
assert_memory_equals( 'target artifact type is memory', 'memory', $by_id['agent/SOUL.md']['artifact_type'] ?? null );
assert_memory_equals( 'target source path points at memory/agent/SOUL.md', 'memory/agent/SOUL.md', $by_id['agent/SOUL.md']['source_path'] ?? null );
assert_memory_equals( 'target payload carries the new identity', "new soul\n", $by_id['agent/SOUL.md']['payload'] ?? null );

echo "\n[2] Upgrade-time guard only materializes authored identity\n";
assert_memory( 'SOUL.md is upgradeable', AgentBundleMemoryArtifact::is_upgradeable_agent_file( 'SOUL.md' ) );
assert_memory( 'MEMORY.md is NOT upgradeable', ! AgentBundleMemoryArtifact::is_upgradeable_agent_file( 'MEMORY.md' ) );
assert_memory( 'daily/* is NOT upgradeable', ! AgentBundleMemoryArtifact::is_upgradeable_agent_file( 'daily/2026/06/28.md' ) );

echo "\n[3] apply() refuses learned memory even if forced through\n";
$forced = AgentBundleMemoryArtifact::apply(
	array(
		'artifact_type' => 'memory',
		'artifact_id'   => 'agent/MEMORY.md',
		'payload'       => "clobber\n",
	),
	7
);
assert_memory( 'applying learned memory returns WP_Error', is_wp_error( $forced ) );
assert_memory_equals( 'error code names the authored-identity guard', 'datamachine_bundle_memory_not_authored', $forced instanceof WP_Error ? $forced->get_error_code() : null );

$ignored = AgentBundleMemoryArtifact::apply(
	array(
		'artifact_type' => 'prompt',
		'artifact_id'   => 'something',
		'payload'       => "x\n",
	),
	7
);
assert_memory( 'apply() declines non-memory artifacts', null === $ignored );

echo "\n[4] Fallback gate: unregistered SOUL.md still treated as authored identity\n";
MemoryFileRegistry::reset();
assert_memory( 'SOUL.md authored-identity fallback holds without registration', AgentBundleMemoryArtifact::is_upgradeable_agent_file( 'SOUL.md' ) );
assert_memory( 'unregistered MEMORY.md is not authored identity', ! AgentBundleMemoryArtifact::is_upgradeable_agent_file( 'MEMORY.md' ) );

echo "\nTotal assertions: {$total}\n";
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}

echo "All assertions passed.\n";
