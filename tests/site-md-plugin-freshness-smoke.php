<?php
/**
 * Smoke test for SITE.md active-plugin freshness signals.
 *
 * Run: php tests/site-md-plugin-freshness-smoke.php
 */

declare(strict_types=1);

$root                = dirname( __DIR__ );
$site_md_source      = file_get_contents( $root . '/inc/migrations/site-md.php' );
$invalidation_source = file_get_contents( $root . '/inc/Engine/AI/ComposableFileInvalidation.php' );

$failures = 0;
$passes   = 0;

$assert = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		$passes++;
		echo "PASS: {$message}\n";
		return;
	}

	$failures++;
	echo "FAIL: {$message}\n";
};

$assert( false !== strpos( $invalidation_source, "add_action( 'activated_plugin'" ), 'plugin activation regenerates composable files' );
$assert( false !== strpos( $invalidation_source, "add_action( 'deactivated_plugin'" ), 'plugin deactivation regenerates composable files' );
$assert( false !== strpos( $site_md_source, "'upgrader_process_complete'" ), 'plugin updates invalidate SITE.md composition' );
$assert( false !== strpos( $site_md_source, 'Generated:' ), 'SITE.md includes a visible generation timestamp' );
$assert( false !== strpos( $site_md_source, 'wp datamachine memory compose SITE.md' ), 'SITE.md includes refresh guidance' );
$assert( false !== strpos( $site_md_source, 'wp plugin list --status=active' ), 'SITE.md points to the active-plugin source of truth' );
$assert( false !== strpos( $site_md_source, "get_option( 'active_plugins'" ), 'active plugin section reads live site plugin state' );

printf( "Result: %d passed, %d failed\n", $passes, $failures );
exit( $failures > 0 ? 1 : 0 );
