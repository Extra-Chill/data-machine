<?php
/**
 * Composer autoloading for standalone, pure-PHP smoke tests.
 *
 * Load after test doubles are declared so they take precedence over real classes.
 * WordPress function stubs remain the responsibility of each smoke test.
 * Agents API classes are provided by Composer's vendor classmap; its WordPress
 * plugin bootstrap is deliberately not loaded here, even though smokes define
 * ABSPATH to allow loading plugin source files.
 *
 * @package DataMachine\Tests
 */

// Composer's files autoloader would otherwise boot Agents API as a WP plugin.
// Skip only that entry, retaining Composer's generated classmap and PSR-4 maps.
$smoke_vendor = dirname( __DIR__ ) . '/vendor';
foreach ( require $smoke_vendor . '/composer/autoload_files.php' as $smoke_id => $smoke_file ) {
	if ( realpath( $smoke_file ) === realpath( $smoke_vendor . '/wordpress/agents-api/agents-api.php' ) ) {
		$GLOBALS['__composer_autoload_files'][ $smoke_id ] = true;
	}
}
require_once $smoke_vendor . '/autoload.php';
unset( $smoke_vendor, $smoke_id, $smoke_file );
