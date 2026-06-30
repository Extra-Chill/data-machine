<?php
/**
 * Smoke test for late plugin inclusion after plugins_loaded has fired.
 *
 * Run with: php tests/late-plugins-loaded-runtime-smoke.php
 */

$source = file_get_contents( dirname( __DIR__ ) . '/data-machine.php' );
if ( false === $source ) {
	fwrite( fopen( 'php://stderr', 'w' ), "FAIL: unable to read data-machine.php\n" );
	exit( 1 );
}

$assertions = 0;
$assert     = function ( string $label, bool $condition ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$label}\n" );
		exit( 1 );
	}

	echo "ok - {$label}\n";
};

echo "=== Late Plugins Loaded Runtime Smoke ===\n";

$assert( 'runtime boot checks plugins_loaded state', str_contains( $source, "did_action( 'plugins_loaded' )" ) );
$assert( 'runtime boots immediately after late inclusion', str_contains( $source, "datamachine_run_datamachine_plugin();\n} else {" ) );
$assert( 'runtime still hooks normal plugin load', str_contains( $source, "add_action( 'plugins_loaded', 'datamachine_run_datamachine_plugin', 20 );" ) );
$assert( 'runtime boot is idempotent', str_contains( $source, 'static $runtime_loaded = false;' ) );

echo "All {$assertions} late plugins_loaded runtime assertions passed.\n";
