<?php
/**
 * Pure-PHP smoke test for agent bundle extras transport (#1828).
 *
 * Run with: php tests/agent-bundle-extras-transport-smoke.php
 *
 * Covers the generic top-level extras-tree transport added in #1828:
 * - Directory read collects arbitrary top-level subdirectories as
 *   $bundle['extras'] keyed by directory name.
 * - Reserved trees (memory, pipelines, flows, prompts, ...) are NOT
 *   collected as extras.
 * - Hidden files (`.DS_Store`) are skipped.
 * - Symlinks that escape the bundle root are skipped with a warning.
 * - Binary files are skipped with a warning.
 * - Empty extras directories are dropped.
 * - ZIP round-trips preserve extras byte-for-byte.
 * - Schema validation rejects malformed extras (path with `..`, non-string
 *   contents, reserved key collision, list shape).
 * - The `datamachine_bundle_export_extras` filter contributes extras at
 *   export time.
 * - Legacy adapter round-trip preserves extras.
 *
 * These assertions are pure-PHP. The post-install hook side of the contract
 * is exercised in tests that already run against a WP runtime.
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

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9_\-]+/', '-', $title );
		return trim( (string) $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', (string) $key );
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
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $priority, $accepted_args );
		$GLOBALS['__bundle_smoke_filters'][ $hook ][] = $callback;
		return true;
	}
}

// Lightweight filter/action registry for the export filter assertion. The bundle
// codebase guards filter usage behind function_exists() / fallbacks, but the
// AgentBundler::collect_export_extras path requires real apply_filters semantics.
$GLOBALS['__bundle_smoke_filters'] = array();
$GLOBALS['__bundle_smoke_actions'] = array();

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		$args = func_get_args();
		array_shift( $args );
		$value = array_shift( $args );
		$callbacks = $GLOBALS['__bundle_smoke_filters'][ $hook ] ?? array();
		foreach ( $callbacks as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$callbacks = $GLOBALS['__bundle_smoke_actions'][ $hook ] ?? array();
		foreach ( $callbacks as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}
}

function bundle_smoke_register_filter( string $hook, callable $callback ): void {
	if ( function_exists( 'add_filter' ) ) {
		add_filter( $hook, $callback, 10, 3 );
		return;
	}

	$GLOBALS['__bundle_smoke_filters'][ $hook ][] = $callback;
}

function bundle_smoke_register_action( string $hook, callable $callback ): void {
	if ( function_exists( 'add_action' ) ) {
		add_action( $hook, $callback, 10, 3 );
		return;
	}

	$GLOBALS['__bundle_smoke_actions'][ $hook ][] = $callback;
}

function bundle_smoke_clear_hooks(): void {
	foreach ( array( 'datamachine_bundle_export_extras', 'datamachine_bundle_install_succeeded' ) as $hook ) {
		if ( function_exists( 'remove_all_filters' ) ) {
			remove_all_filters( $hook );
		}
		if ( function_exists( 'remove_all_actions' ) ) {
			remove_all_actions( $hook );
		}
	}

	$GLOBALS['__bundle_smoke_filters'] = array();
	$GLOBALS['__bundle_smoke_actions'] = array();
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		// no-op stub for standalone smoke runs.
	}
}


require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleFlowFile;
use DataMachine\Engine\Bundle\AgentBundleArrayAdapter;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundlePipelineFile;
use DataMachine\Engine\Bundle\BundleSchema;
use DataMachine\Engine\Bundle\BundleValidationException;

$GLOBALS['__bundle_extras_failures'] = 0;
$GLOBALS['__bundle_extras_total']    = 0;

function assert_extras( string $label, bool $condition ): void {
	++$GLOBALS['__bundle_extras_total'];
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$GLOBALS['__bundle_extras_failures'];
}

function assert_extras_equals( string $label, $expected, $actual ): void {
	$ok = $expected === $actual;
	if ( ! $ok ) {
		echo "  FAIL: {$label}\n";
		echo "    expected: " . var_export( $expected, true ) . "\n";
		echo "    actual:   " . var_export( $actual, true ) . "\n";
		++$GLOBALS['__bundle_extras_failures'];
		++$GLOBALS['__bundle_extras_total'];
		return;
	}
	echo "  PASS: {$label}\n";
	++$GLOBALS['__bundle_extras_total'];
}

function rm_tree( string $path ): void {
	if ( is_link( $path ) ) {
		unlink( $path );
		return;
	}
	if ( ! file_exists( $path ) ) {
		return;
	}
	if ( ! is_dir( $path ) ) {
		unlink( $path );
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		if ( $file->isLink() ) {
			unlink( $file->getPathname() );
			continue;
		}
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $path );
}

function make_minimal_manifest(): AgentBundleManifest {
	return AgentBundleManifest::from_array(
		array(
			'schema_version'  => 1,
			'bundle_slug'     => 'extras-transport',
			'bundle_version'  => '1.0.0',
			'source_ref'      => '',
			'source_revision' => '',
			'exported_at'     => '2026-04-26T15:30:00Z',
			'exported_by'     => 'data-machine/extras-smoke',
			'agent'           => array(
				'slug'         => 'extras-agent',
				'label'        => 'Extras Agent',
				'description'  => 'Smoke fixture.',
				'agent_config' => array(),
			),
			'included'        => array(
				'memory'        => array(),
				'pipelines'     => array(),
				'flows'         => array(),
				'prompts'       => array(),
				'rubrics'       => array(),
				'tool_policies' => array(),
				'auth_refs'     => array(),
				'seed_queues'   => array(),
				'extensions'    => array(),
				'handler_auth'  => 'refs',
			),
		)
	);
}

echo "=== Agent Bundle Extras Transport Smoke (#1828) ===\n";

// ---------------------------------------------------------------------------
// [1] RESERVED_TREES contract
// ---------------------------------------------------------------------------
echo "\n[1] RESERVED_TREES is the single source of truth for owned directories\n";
assert_extras( 'memory is reserved', in_array( 'memory', BundleSchema::RESERVED_TREES, true ) );
assert_extras( 'pipelines is reserved', in_array( 'pipelines', BundleSchema::RESERVED_TREES, true ) );
assert_extras( 'flows is reserved', in_array( 'flows', BundleSchema::RESERVED_TREES, true ) );
assert_extras( 'extensions is reserved', in_array( 'extensions', BundleSchema::RESERVED_TREES, true ) );
assert_extras( 'manifest.json is reserved at root', in_array( 'manifest.json', BundleSchema::RESERVED_ROOT_ENTRIES, true ) );
assert_extras( 'wiki is NOT reserved', ! in_array( 'wiki', BundleSchema::RESERVED_TREES, true ) );

// ---------------------------------------------------------------------------
// [2] Read path: collect extras from disk
// ---------------------------------------------------------------------------
echo "\n[2] AgentBundleDirectory::read() picks up arbitrary extras\n";
$tmp = sys_get_temp_dir() . '/datamachine-bundle-extras-' . getmypid();
rm_tree( $tmp );
$directory_obj = new AgentBundleDirectory( make_minimal_manifest(), array(), array(), array() );
$directory_obj->write( $tmp );

// Add wiki extras with a nested file.
mkdir( $tmp . '/wiki/sub', 0775, true );
file_put_contents( $tmp . '/wiki/index.md', "# Wiki root\n" );
file_put_contents( $tmp . '/wiki/sub/article.md', "# Article\n" );

// Add datasets extras.
mkdir( $tmp . '/datasets', 0775, true );
file_put_contents( $tmp . '/datasets/seed.json', '{"foo":"bar"}' );

// Add hidden files at root and inside an extras directory; should be skipped.
file_put_contents( $tmp . '/.DS_Store', 'mac noise' );
file_put_contents( $tmp . '/wiki/.gitkeep', '' );
file_put_contents( $tmp . '/wiki/sub/.hidden', 'noise' );

// Add an empty extras directory; should be dropped.
mkdir( $tmp . '/empty-extra', 0775, true );

// Stray top-level file (not a directory): should not be collected.
file_put_contents( $tmp . '/README.md', "# Bundle README\n" );

$read = AgentBundleDirectory::read( $tmp );
$extras = $read->extras();
assert_extras_equals( 'two extras keys collected', array( 'datasets', 'wiki' ), array_keys( $extras ) );
assert_extras( 'wiki/index.md collected', isset( $extras['wiki']['wiki/index.md'] ) );
assert_extras( 'wiki/sub/article.md collected', isset( $extras['wiki']['wiki/sub/article.md'] ) );
assert_extras_equals( 'wiki contents preserved', "# Article\n", $extras['wiki']['wiki/sub/article.md'] ?? '' );
assert_extras_equals( 'datasets/seed.json collected', '{"foo":"bar"}', $extras['datasets']['datasets/seed.json'] ?? '' );
assert_extras( 'hidden .gitkeep not collected', ! isset( $extras['wiki']['wiki/.gitkeep'] ) );
assert_extras( 'hidden .hidden not collected', ! isset( $extras['wiki']['wiki/sub/.hidden'] ) );
assert_extras( 'empty extras directory dropped', ! array_key_exists( 'empty-extra', $extras ) );
assert_extras( 'reserved memory tree never appears as extra', ! array_key_exists( 'memory', $extras ) );
assert_extras( 'reserved flows tree never appears as extra', ! array_key_exists( 'flows', $extras ) );
assert_extras( 'top-level files never become extras', ! array_key_exists( 'README.md', $extras ) );

// ---------------------------------------------------------------------------
// [3] Reserved tree with content does not surface as extras
// ---------------------------------------------------------------------------
echo "\n[3] Reserved trees never bleed into extras\n";
if ( ! is_dir( $tmp . '/memory' ) ) {
	mkdir( $tmp . '/memory', 0775, true );
}
file_put_contents( $tmp . '/memory/MEMORY.md', "# Memory\n" );
$read2 = AgentBundleDirectory::read( $tmp );
$extras2 = $read2->extras();
assert_extras( 'memory not collected as extra after writing memory file', ! array_key_exists( 'memory', $extras2 ) );

// ---------------------------------------------------------------------------
// [4] Symlinks escaping bundle root are skipped
// ---------------------------------------------------------------------------
echo "\n[4] Symlinks escaping the bundle root are skipped\n";
$outside = sys_get_temp_dir() . '/datamachine-bundle-outside-' . getmypid();
rm_tree( $outside );
mkdir( $outside, 0775, true );
file_put_contents( $outside . '/leak.md', "# Leak\n" );

if ( @symlink( $outside . '/leak.md', $tmp . '/wiki/leak-link.md' ) ) {
	$read3 = AgentBundleDirectory::read( $tmp );
	$wiki  = $read3->extras()['wiki'] ?? array();
	assert_extras( 'symlink escaping bundle root not collected', ! isset( $wiki['wiki/leak-link.md'] ) );
} else {
	echo "  SKIP: symlink unsupported on this filesystem\n";
}
rm_tree( $outside );

// ---------------------------------------------------------------------------
// [5] Binary files are skipped
// ---------------------------------------------------------------------------
echo "\n[5] Binary files are skipped (NUL-byte heuristic)\n";
file_put_contents( $tmp . '/wiki/blob.bin', "binary\0content" );
$read4 = AgentBundleDirectory::read( $tmp );
$wiki4 = $read4->extras()['wiki'] ?? array();
assert_extras( 'binary file with NUL byte skipped', ! isset( $wiki4['wiki/blob.bin'] ) );

// ---------------------------------------------------------------------------
// [6] Schema validation
// ---------------------------------------------------------------------------
echo "\n[6] BundleSchema::validate_extras enforces the contract\n";
$threw = false;
try {
	BundleSchema::validate_extras( array( 'memory' => array( 'memory/foo.md' => 'x' ) ) );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'reserved' );
}
assert_extras( 'reserved key collision rejected', $threw );

$threw = false;
try {
	BundleSchema::validate_extras( array( 'wiki' => array( 'wiki/../escape.md' => 'x' ) ) );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), '..' );
}
assert_extras( 'path containing .. rejected', $threw );

$threw = false;
try {
	BundleSchema::validate_extras( array( 'wiki' => array( 'wiki/foo.md' => 42 ) ) );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'must be a string' );
}
assert_extras( 'non-string contents rejected', $threw );

$threw = false;
try {
	BundleSchema::validate_extras( array( 'wiki' => array( 'other/foo.md' => 'x' ) ) );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'must start with' );
}
assert_extras( 'path missing key prefix rejected', $threw );

$threw = false;
try {
	BundleSchema::validate_extras( array( 'wiki/sub' => array( 'wiki/sub/foo.md' => 'x' ) ) );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'slug-like' );
}
assert_extras( 'key with slash rejected', $threw );

$threw = false;
try {
	BundleSchema::validate_extras( array( 'wiki' => array( 0 => 'x' ) ) );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'must be an associative object' );
}
assert_extras( 'list-shaped value rejected', $threw );

$threw = false;
try {
	BundleSchema::validate_extras( array( 'list', 'shape' ) );
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'associative object' );
}
assert_extras( 'list-shaped extras rejected', $threw );

assert_extras_equals( 'empty extras returns empty array', array(), BundleSchema::validate_extras( array() ) );
assert_extras_equals( 'null extras returns empty array', array(), BundleSchema::validate_extras( null ) );

// Valid payload normalizes deterministically.
$valid = BundleSchema::validate_extras(
	array(
		'datasets' => array(
			'datasets/b.json' => '{}',
			'datasets/a.json' => '{}',
		),
		'wiki'     => array(
			'wiki/index.md' => "# w\n",
		),
	)
);
assert_extras_equals( 'valid extras keys sorted', array( 'datasets', 'wiki' ), array_keys( $valid ) );
assert_extras_equals( 'valid extras inner paths sorted', array( 'datasets/a.json', 'datasets/b.json' ), array_keys( $valid['datasets'] ) );

// ---------------------------------------------------------------------------
// [7] Constructor rejects malformed extras
// ---------------------------------------------------------------------------
echo "\n[7] Constructor delegates to validate_extras\n";
$threw = false;
try {
	new AgentBundleDirectory(
		make_minimal_manifest(),
		array(),
		array(),
		array(),
		array(),
		array(),
		array( 'pipelines' => array( 'pipelines/foo.json' => '{}' ) )
	);
} catch ( BundleValidationException $e ) {
	$threw = str_contains( $e->getMessage(), 'reserved' );
}
assert_extras( 'constructor rejects reserved key in extras', $threw );

// ---------------------------------------------------------------------------
// [8] Round-trip: directory → extras → write → read preserves extras
// ---------------------------------------------------------------------------
echo "\n[8] Directory round-trip preserves extras\n";
rm_tree( $tmp );
$with_extras = new AgentBundleDirectory(
	make_minimal_manifest(),
	array(),
	array(),
	array(),
	array(),
	array(),
	array(
		'wiki'     => array( 'wiki/page.md' => "# Page\n" ),
		'datasets' => array( 'datasets/seed.json' => '{"a":1}' ),
	)
);
$with_extras->write( $tmp );
assert_extras( 'wiki dir written', is_dir( $tmp . '/wiki' ) );
assert_extras( 'wiki/page.md written', is_file( $tmp . '/wiki/page.md' ) );
assert_extras_equals( 'wiki/page.md contents preserved', "# Page\n", file_get_contents( $tmp . '/wiki/page.md' ) );
assert_extras( 'datasets/seed.json written', is_file( $tmp . '/datasets/seed.json' ) );

$round_read   = AgentBundleDirectory::read( $tmp );
$round_extras = $round_read->extras();
assert_extras_equals( 'round-trip wiki extra path preserved', "# Page\n", $round_extras['wiki']['wiki/page.md'] ?? '' );
assert_extras_equals( 'round-trip datasets extra path preserved', '{"a":1}', $round_extras['datasets']['datasets/seed.json'] ?? '' );

// ---------------------------------------------------------------------------
// [9] Array adapter round-trip preserves extras
// ---------------------------------------------------------------------------
echo "\n[9] Legacy bundle array round-trip preserves extras\n";
$legacy = AgentBundleArrayAdapter::to_array_bundle( $with_extras );
assert_extras( 'legacy bundle array contains extras key', isset( $legacy['extras'] ) );
assert_extras_equals( 'legacy extras roundtrip wiki', "# Page\n", $legacy['extras']['wiki']['wiki/page.md'] ?? '' );

$rebuilt = AgentBundleArrayAdapter::from_array_bundle( $legacy );
assert_extras_equals( 'legacy → directory → extras preserved', $with_extras->extras(), $rebuilt->extras() );

// ---------------------------------------------------------------------------
// [10] from_directory() / from_zip() through AgentBundler picks up extras
// ---------------------------------------------------------------------------
echo "\n[10] AgentBundler::from_directory and from_zip carry extras through\n";

// We can construct AgentBundler (its constructor instantiates DB classes that
// inherit from base classes which don't run anything dangerous in pure-PHP).
// Instead, drive AgentBundleArrayAdapter directly which is what AgentBundler
// uses under the hood, then verify the same shape via the directory adapter.
rm_tree( $tmp );
$with_extras->write( $tmp );

// Equivalent of AgentBundler::from_directory():
$dir_bundle = AgentBundleArrayAdapter::to_array_bundle( AgentBundleDirectory::read( $tmp ) );
assert_extras( 'from_directory equivalent: bundle has extras key', isset( $dir_bundle['extras'] ) );
assert_extras_equals( 'from_directory equivalent: wiki/page.md preserved', "# Page\n", $dir_bundle['extras']['wiki']['wiki/page.md'] ?? '' );

// ZIP round-trip: zip $tmp, extract, read.
$zip_path = sys_get_temp_dir() . '/datamachine-bundle-extras-' . getmypid() . '.zip';
@unlink( $zip_path );
$zip = new ZipArchive();
if ( true === $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $item ) {
		$relative = substr( $item->getPathname(), strlen( $tmp ) + 1 );
		if ( $item->isDir() ) {
			$zip->addEmptyDir( $relative );
		} else {
			$zip->addFile( $item->getPathname(), $relative );
		}
	}
	$zip->close();
}
$extract_dir = sys_get_temp_dir() . '/datamachine-bundle-extras-extract-' . getmypid();
rm_tree( $extract_dir );
mkdir( $extract_dir, 0775, true );
$zip2 = new ZipArchive();
if ( true === $zip2->open( $zip_path ) ) {
	$zip2->extractTo( $extract_dir );
	$zip2->close();
}
$zip_bundle = AgentBundleArrayAdapter::to_array_bundle( AgentBundleDirectory::read( $extract_dir ) );
assert_extras_equals( 'zip round-trip preserves wiki extra', "# Page\n", $zip_bundle['extras']['wiki']['wiki/page.md'] ?? '' );

// ---------------------------------------------------------------------------
// [11] datamachine_bundle_export_extras filter contributes extras
// ---------------------------------------------------------------------------
echo "\n[11] Export filter is invoked when DM is in WP runtime\n";
bundle_smoke_clear_hooks();
bundle_smoke_register_filter(
	'datamachine_bundle_export_extras',
	function ( $extras, $agent_id, $agent ) {
		if ( ! is_array( $extras ) ) {
			$extras = array();
		}
		$extras['wiki'] = array( 'wiki/seed.md' => "# from filter $agent_id\n" );
		return $extras;
	}
);

// Reflect collect_export_extras (private static) to assert behavior.
$ref = new ReflectionMethod( DataMachine\Core\Agents\AgentBundler::class, 'collect_export_extras' );
if ( PHP_VERSION_ID < 80100 ) {
	// Older PHP requires the explicit toggle.
	$ref->setAccessible( true );
}
$collected = $ref->invoke( null, 7, array( 'agent_slug' => 'extras-agent' ) );
assert_extras_equals(
	'filter contribution surfaces in collected extras',
	"# from filter 7\n",
	$collected['wiki']['wiki/seed.md'] ?? ''
);

// Listener returning malformed payload is logged and dropped.
bundle_smoke_clear_hooks();
bundle_smoke_register_filter(
	'datamachine_bundle_export_extras',
	function () {
		return array( 'memory' => array( 'memory/x.md' => 'collides' ) );
	}
);
$collected = $ref->invoke( null, 7, array( 'agent_slug' => 'extras-agent' ) );
assert_extras_equals( 'invalid filter payload dropped to empty array', array(), $collected );

bundle_smoke_clear_hooks();

// ---------------------------------------------------------------------------
// [12] Post-install hook is wired correctly in AgentBundler::import()
// ---------------------------------------------------------------------------
echo "\n[12] datamachine_bundle_install_succeeded hook is wired into the success path\n";
$bundler_src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Core/Agents/AgentBundler.php' );
assert_extras( 'hook do_action present', str_contains( $bundler_src, "do_action(\n\t\t\t\t'datamachine_bundle_install_succeeded'" ) );
assert_extras( 'hook fires AFTER commit_transaction', strpos( $bundler_src, "commit_transaction( \$transaction_started )" ) < strpos( $bundler_src, 'datamachine_bundle_install_succeeded' ) );
assert_extras( 'hook does NOT fire on dry-run path', strpos( $bundler_src, "'message' => 'Dry run — no changes made.'" ) < strpos( $bundler_src, 'datamachine_bundle_install_succeeded' ) );
assert_extras( 'listener exceptions are caught', str_contains( $bundler_src, 'catch ( \\Throwable $hook_error )' ) );
assert_extras( 'extras payload validated before hook', str_contains( $bundler_src, 'BundleSchema::validate_extras( $extras )' ) );
assert_extras( 'context carries is_upgrade flag', str_contains( $bundler_src, "'is_upgrade' => \$is_upgrade," ) );
assert_extras( 'export filter present', str_contains( $bundler_src, 'datamachine_bundle_export_extras' ) );

// ---------------------------------------------------------------------------
// Tear-down
// ---------------------------------------------------------------------------
rm_tree( $tmp );
rm_tree( $extract_dir );
@unlink( $zip_path );

$total    = (int) $GLOBALS['__bundle_extras_total'];
$failures = (int) $GLOBALS['__bundle_extras_failures'];
echo "\nTotal assertions: {$total}\n";
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}
echo "All assertions passed.\n";
