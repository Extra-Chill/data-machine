<?php
/**
 * Smoke coverage: SectionRegistry reads back from the agents-api context
 * registry instead of a private shadow copy.
 *
 * Proves that a section registered directly on
 * WP_Agent_Context_Section_Registry (by a plugin with no Data Machine
 * dependency) is visible to SectionRegistry::get_sections(), get_section(),
 * has_sections(), get_filenames(), and render_sections(), and that
 * DM-registered sections still expose their operator metadata unchanged.
 *
 * Run with: php tests/section-registry-substrate-readback-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		return preg_replace( '/[^a-zA-Z0-9._\-]/', '', basename( (string) $filename ) );
	}
}

$GLOBALS['datamachine_readback_actions'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_readback_actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$callbacks = $GLOBALS['datamachine_readback_actions'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				call_user_func_array( $callback[0], array_slice( $args, 0, $callback[1] ) );
			}
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		unset( $hook, $args );
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		unset( $args );
	}
}

if ( ! function_exists( 'current_filter' ) ) {
	function current_filter() {
		return 'plugins_loaded';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return $file;
	}
}

require_once __DIR__ . '/../vendor/wordpress/agents-api/agents-api.php';
require_once __DIR__ . '/../inc/Engine/AI/MemoryFileRegistry.php';
require_once __DIR__ . '/../inc/Engine/AI/SectionRegistry.php';

use DataMachine\Engine\AI\MemoryFileRegistry;
use DataMachine\Engine\AI\SectionRegistry;

$failures = array();

function readback_assert( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

MemoryFileRegistry::register(
	'AGENTS.md',
	10,
	array(
		'layer'      => MemoryFileRegistry::LAYER_SHARED,
		'composable' => true,
	)
);

// A DM-owned registration with full operator metadata.
SectionRegistry::register(
	'AGENTS.md',
	'dm-owned',
	20,
	static function () {
		return "## DM Owned\n";
	},
	array(
		'label'       => 'DM Owned',
		'description' => 'Registered through Data Machine.',
		'owner'       => 'data-machine',
		'freshness'   => 'static',
		'conditions'  => 'always',
	)
);

// A substrate-only registration, the way a plugin that depends on agents-api
// but not on Data Machine registers guidance.
WP_Agent_Context_Section_Registry::register(
	'agents-md',
	'substrate-only',
	10,
	static function ( array $context, array $section ) {
		unset( $context, $section );
		return "## Substrate Only\n";
	},
	array(
		'label'       => 'Substrate Only',
		'description' => 'Registered on the agents-api registry directly.',
	)
);

// Inspection surface.
$sections = SectionRegistry::get_sections( 'AGENTS.md' );
readback_assert( array_keys( $sections ) === array( 'substrate-only', 'dm-owned' ), 'get_sections() returns both registrations in priority order.' );
readback_assert( null !== SectionRegistry::get_section( 'AGENTS.md', 'substrate-only' ), 'get_section() resolves a substrate-only section.' );
readback_assert( SectionRegistry::has_sections( 'AGENTS.md' ), 'has_sections() sees substrate registrations.' );
readback_assert( array( 'AGENTS.md' ) === SectionRegistry::get_filenames(), 'get_filenames() maps context slugs back to registered filenames.' );

// DM metadata survives the round-trip unchanged.
$dm = $sections['dm-owned'];
readback_assert( 'data-machine' === $dm['owner'], 'DM owner survives readback.' );
readback_assert( 'static' === $dm['freshness'], 'DM freshness survives readback.' );
readback_assert( 'always' === $dm['conditions'], 'DM conditions survive readback.' );
readback_assert( 'plugins_loaded' === $dm['registered_at'], 'DM registered_at provenance survives readback.' );
readback_assert( 'Closure' === $dm['source_callback'], 'DM source_callback provenance survives readback.' );
readback_assert( 'AGENTS.md' === $dm['filename'], 'DM filename survives readback.' );

// Substrate-only sections degrade to '-' for DM-specific columns rather than
// being dropped or throwing on missing keys.
$sub = $sections['substrate-only'];
readback_assert( 'Substrate Only' === $sub['label'], 'Substrate label is preserved.' );
readback_assert( '-' === $sub['owner'], 'Substrate owner defaults to "-".' );
readback_assert( '-' === $sub['freshness'], 'Substrate freshness defaults to "-".' );
readback_assert( '-' === $sub['conditions'], 'Substrate conditions default to "-".' );
readback_assert( '-' === $sub['source_plugin'], 'Substrate source_plugin defaults to "-".' );
readback_assert( 'AGENTS.md' === $sub['filename'], 'Substrate filename is inferred from the requested file.' );
readback_assert( is_callable( $sub['callback'] ), 'Substrate callback is exposed for render_sections().' );

// Rendering and composition agree on section count.
$rendered = SectionRegistry::render_sections( 'AGENTS.md' );
readback_assert( 2 === count( $rendered ), 'render_sections() renders both registrations.' );
readback_assert( ! isset( $rendered['substrate-only']['callback'] ), 'render_sections() strips callbacks from the snapshot.' );

$content = SectionRegistry::generate( 'AGENTS.md' );
readback_assert( false !== strpos( $content, '## Substrate Only' ), 'generate() composes the substrate-only section.' );
readback_assert( false !== strpos( $content, '## DM Owned' ), 'generate() composes the DM-owned section.' );
readback_assert( strpos( $content, '## Substrate Only' ) < strpos( $content, '## DM Owned' ), 'generate() honors priority across both registries.' );
readback_assert( count( $rendered ) === count( SectionRegistry::get_sections( 'AGENTS.md' ) ), 'Section count matches what was composed.' );

// Deregistration removes from the single source of truth.
SectionRegistry::deregister( 'AGENTS.md', 'dm-owned' );
readback_assert( null === SectionRegistry::get_section( 'AGENTS.md', 'dm-owned' ), 'deregister() removes the section from readback.' );
readback_assert( false === strpos( SectionRegistry::generate( 'AGENTS.md' ), '## DM Owned' ), 'deregister() removes the section from composition.' );

// Reset clears everything.
SectionRegistry::reset();
readback_assert( ! SectionRegistry::has_sections( 'AGENTS.md' ), 'reset() clears the substrate registry.' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAILURES:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "section-registry-substrate-readback-smoke: OK\n";
