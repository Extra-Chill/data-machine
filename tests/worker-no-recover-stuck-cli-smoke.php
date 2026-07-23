<?php
/**
 * Worker negatable recovery flag contract smoke test.
 */

$source = (string) file_get_contents( __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php' );
$passes = 0;
$failures = 0;

$assert = static function ( string $name, bool $condition ) use ( &$passes, &$failures ): void {
	if ( $condition ) {
		++$passes;
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failures;
	echo "  [FAIL] {$name}\n";
};

echo "=== worker-no-recover-stuck-cli-smoke ===\n";

$assert( 'worker declares canonical negatable recovery flag', str_contains( $source, '[--[no-]recover-stuck]' ) );
$assert( 'worker reads normalized positive recovery key', str_contains( $source, "get_flag_value( \$assoc_args, 'recover-stuck', true )" ) );
$assert( 'worker does not inspect an impossible negative key', ! str_contains( $source, "\$assoc_args['no-recover-stuck']" ) );
$assert( 'help documents recovery as enabled by default', str_contains( $source, 'Enabled by default; use --no-recover-stuck to skip it.' ) );

echo "\nWorker recovery flag contract: {$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
