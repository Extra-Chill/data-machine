<?php
/**
 * SITE.md "Active Plugins" / NETWORK.md "Network Plugins" regression coverage
 * for issue #3521 (ghost entries) and its paired scope reduction (names only,
 * no descriptions).
 *
 * A plugin recorded in the `active_plugins` / `active_sitewide_plugins`
 * options but deleted from disk without being deactivated must not appear
 * in the generated output. Plugins that do appear must be printed as a bare
 * name, with no description text.
 *
 * Run with: php tests/site-md-plugin-ghost-entries-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', '/tmp/' );

$plugins_root = sys_get_temp_dir() . '/dm-site-md-smoke-' . uniqid();
mkdir( $plugins_root . '/present-plugin', 0777, true );
mkdir( $plugins_root . '/data-machine', 0777, true );
file_put_contents(
	$plugins_root . '/present-plugin/present-plugin.php',
	"<?php\n/**\n * Plugin Name: Present Plugin\n * Description: This plugin still exists on disk.\n */\n"
);
file_put_contents(
	$plugins_root . '/data-machine/data-machine.php',
	"<?php\n/**\n * Plugin Name: Data Machine\n */\n"
);

define( 'WP_PLUGIN_DIR', $plugins_root );

$GLOBALS['dm_smoke_options']      = array(
	// present-plugin exists on disk; ghost-plugin does not (deleted without
	// deactivation — this is the exact breeze scenario from #3521).
	'active_plugins' => array(
		'present-plugin/present-plugin.php',
		'ghost-plugin/ghost-plugin.php',
		'data-machine/data-machine.php',
	),
);
$GLOBALS['dm_smoke_site_options'] = array(
	'active_sitewide_plugins' => array(
		'present-plugin/present-plugin.php' => time(),
		'ghost-plugin/ghost-plugin.php'     => time(),
		'data-machine/data-machine.php'     => time(),
	),
);
$GLOBALS['dm_smoke_is_multisite'] = true;

function get_option( string $name, $default = false ) {
	return $GLOBALS['dm_smoke_options'][ $name ] ?? $default;
}

function get_site_option( string $name, $default = false ) {
	return $GLOBALS['dm_smoke_site_options'][ $name ] ?? $default;
}

function is_multisite(): bool {
	return $GLOBALS['dm_smoke_is_multisite'];
}

function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
	unset( $markup, $translate );
	$contents = file_get_contents( $plugin_file );
	$name     = '';
	$desc     = '';
	if ( false !== $contents && preg_match( '/Plugin Name:\s*(.+)/', $contents, $m ) ) {
		$name = trim( $m[1] );
	}
	if ( false !== $contents && preg_match( '/Description:\s*(.+)/', $contents, $m ) ) {
		$desc = trim( $m[1] );
	}
	return array(
		'Name'        => $name,
		'Description' => $desc,
	);
}

function wp_strip_all_tags( string $text ): string {
	return trim( strip_tags( $text ) );
}

function add_action( ...$args ): void {
	// no-op; SectionRegistry::register() calls are irrelevant to this smoke.
}

function add_filter( ...$args ): void {
	// no-op; the invalidation-hooks filter is irrelevant to this smoke.
}

// SectionRegistry is only referenced as a `use` import in site-md.php for
// datamachine_register_core_sections(), which this smoke never calls.
eval( 'namespace DataMachine\Engine\AI { final class SectionRegistry { public static function register( ...$args ): void {} } }' );

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
	fwrite( STDOUT, "PASS: {$message}\n" );
}

require_once dirname( __DIR__ ) . '/inc/setup/site-md.php';

// --- SITE.md "## Active Plugins" -------------------------------------------

$site_plugins_output = datamachine_site_section_plugins();

assert_true(
	str_contains( $site_plugins_output, '**Present Plugin**' ),
	'plugin present on disk is listed by name'
);
assert_true(
	! str_contains( $site_plugins_output, 'ghost-plugin' ),
	'plugin missing from disk (deleted without deactivation) is not listed — #3521'
);
assert_true(
	! str_contains( $site_plugins_output, 'Data Machine' ),
	"Data Machine's own entry is still suppressed"
);
assert_true(
	! str_contains( $site_plugins_output, 'This plugin still exists on disk.' ),
	'description text is no longer emitted for Active Plugins (byte-size scope reduction)'
);
assert_true(
	! str_contains( $site_plugins_output, ' — ' ),
	'no description separator appears anywhere in the Active Plugins block'
);

// --- NETWORK.md "## Network Plugins" ----------------------------------------

$network_plugins_output = datamachine_network_section_plugins();

assert_true(
	str_contains( $network_plugins_output, '- Present Plugin' ),
	'network-active plugin present on disk is listed by name'
);
assert_true(
	! str_contains( $network_plugins_output, 'ghost-plugin' ),
	'network-active plugin missing from disk is not listed — shares the #3521 defect, fixed identically'
);
assert_true(
	! str_contains( $network_plugins_output, 'Data Machine' ),
	"Data Machine's own network entry is still suppressed"
);

fwrite( STDOUT, "SITE.md / NETWORK.md plugin ghost-entry smoke passed.\n" );
