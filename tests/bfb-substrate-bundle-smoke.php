<?php
/**
 * Pure-PHP smoke test for the bundled Block Format Bridge substrate (#1469).
 *
 * Run with: php tests/bfb-substrate-bundle-smoke.php
 *
 * This PR only makes the content-format substrate available to Data Machine.
 * Ability-level `content_format` behavior belongs to #1470, so this smoke
 * focuses on Composer/package loading boundaries instead of ability behavior.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

/**
 * Assert helper.
 *
 * @param string $name      Test case name.
 * @param bool   $condition Pass/fail.
 */
function assert_bfb_bundle( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

// --- Minimal WordPress stubs ----------------------------------------

$GLOBALS['__bfb_bundle_actions'] = array();
$GLOBALS['__bfb_bundle_filters'] = array();
$GLOBALS['__bfb_bundle_ability_categories'] = array();
$GLOBALS['__bfb_bundle_abilities'] = array();

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		return 'plugins_loaded' === $hook ? 1 : 0;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__bfb_bundle_actions'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__bfb_bundle_filters'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
		return true;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $callback = false ) {
		return false;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook, $callback = false ) {
		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		return null;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $path ) {
		return rtrim( (string) $path, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		return array();
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( $blocks ) {
		return '';
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array() ) {
		return array();
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $slug, array $args ): void {
		$GLOBALS['__bfb_bundle_ability_categories'][ $slug ] = $args;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): void {
		$GLOBALS['__bfb_bundle_abilities'][ $name ] = $args;
	}
}

// --- Composer/package assertions ------------------------------------

$root          = dirname( __DIR__ );
$composer_json_contents = file_get_contents( $root . '/composer.json' );
$composer_lock_contents = file_get_contents( $root . '/composer.lock' );
$composer_json          = json_decode( false === $composer_json_contents ? '{}' : $composer_json_contents, true );
$composer_lock          = json_decode( false === $composer_lock_contents ? '{}' : $composer_lock_contents, true );

assert_bfb_bundle(
	'composer-json-requires-bfb',
	isset( $composer_json['require']['chubes4/block-format-bridge'] )
);

$locked_packages = array();
foreach ( $composer_lock['packages'] ?? array() as $package ) {
	$locked_packages[ $package['name'] ?? '' ] = $package;
}

assert_bfb_bundle(
	'composer-lock-contains-bfb',
	isset( $locked_packages['chubes4/block-format-bridge'] )
);

assert_bfb_bundle(
	'composer-lock-does-not-contain-unscoped-h2bc',
	! isset( $locked_packages['chubes4/html-to-blocks-converter'] )
);

$bfb_path = $root . '/vendor/chubes4/block-format-bridge';

assert_bfb_bundle( 'bfb-library-installed', file_exists( $bfb_path . '/library.php' ) );
assert_bfb_bundle( 'bfb-prefixed-autoload-installed', file_exists( $bfb_path . '/vendor_prefixed/autoload.php' ) );
assert_bfb_bundle( 'unscoped-h2bc-vendor-dir-absent', ! is_dir( $root . '/vendor/chubes4/html-to-blocks-converter' ) );

require $root . '/vendor/autoload.php';

assert_bfb_bundle( 'bfb-convert-function-available', function_exists( 'bfb_convert' ) );
assert_bfb_bundle( 'bfb-version-registry-loaded', class_exists( 'BFB_Versions', false ) );
assert_bfb_bundle( 'bfb-adapter-registry-loaded', class_exists( 'BFB_Adapter_Registry', false ) );
assert_bfb_bundle( 'scoped-h2bc-version-registry-loaded', class_exists( 'BlockFormatBridge\\Vendor\\HTML_To_Blocks_Versions', false ) );

$h2bc_globals = array(
	'HTML_To_Blocks_Versions',
	'HTML_To_Blocks_HTML_Element',
	'HTML_To_Blocks_Block_Factory',
	'HTML_To_Blocks_Attribute_Parser',
	'HTML_To_Blocks_Transform_Registry',
);

foreach ( $h2bc_globals as $class_name ) {
	assert_bfb_bundle( "global-{$class_name}-not-created", ! class_exists( $class_name, false ) );
}

$registered_actions = $GLOBALS['__bfb_bundle_actions'];
/** @var array<int, array{hook:string, callback:mixed}> $registered_actions */
foreach ( $registered_actions as $action ) {
	if ( 'wp_abilities_api_categories_init' === $action['hook'] && is_callable( $action['callback'] ) ) {
		call_user_func( $action['callback'] );
	}
}

$registered_actions = $GLOBALS['__bfb_bundle_actions'];
/** @var array<int, array{hook:string, callback:mixed}> $registered_actions */
foreach ( $registered_actions as $action ) {
	if ( 'wp_abilities_api_init' === $action['hook'] && is_callable( $action['callback'] ) ) {
		call_user_func( $action['callback'] );
	}
}

$registered_ability_categories = $GLOBALS['__bfb_bundle_ability_categories'];
$registered_abilities          = $GLOBALS['__bfb_bundle_abilities'];
/** @var array<string, array<string, mixed>> $registered_ability_categories */
/** @var array<string, array<string, mixed>> $registered_abilities */

assert_bfb_bundle( 'bfb-ability-category-registered', isset( $registered_ability_categories['block-format-bridge'] ) );

$bfb_ability_names = array(
	'block-format-bridge/get-capabilities',
	'block-format-bridge/convert',
	'block-format-bridge/normalize',
);

foreach ( $bfb_ability_names as $ability_name ) {
	assert_bfb_bundle( "{$ability_name}-registered", isset( $registered_abilities[ $ability_name ] ) );
	assert_bfb_bundle(
		"{$ability_name}-has-category",
		'block-format-bridge' === ( $registered_abilities[ $ability_name ]['category'] ?? null )
	);
}

echo "\nBFB substrate bundle smoke: {$total} assertions, {$failed} failures.\n";

if ( $failed > 0 ) { // @phpstan-ignore-line Smoke assertion counter is mutated through assert_bfb_bundle().
	exit( 1 );
}
