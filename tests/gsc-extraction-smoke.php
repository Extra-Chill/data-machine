<?php
/**
 * Pure-PHP smoke test for Google Search Console extraction (#2139).
 *
 * Run with: php tests/gsc-extraction-smoke.php
 *
 * @package DataMachine\Tests
 */

$root     = dirname( __DIR__ );
$failures = array();
$passes   = 0;

function assert_gsc_extraction( bool $condition, string $name, array &$failures, int &$passes ): void {
	if ( $condition ) {
		$passes++;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
}

function gsc_file_contains( string $path, string $needle ): bool {
	$contents = file_get_contents( $path );
	return false !== $contents && false !== strpos( $contents, $needle );
}

echo "GSC extraction smoke (#2139)\n";
echo "----------------------------\n";

assert_gsc_extraction(
	! file_exists( $root . '/inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php' ),
	'core no longer ships GoogleSearchConsoleAbilities',
	$failures,
	$passes
);

assert_gsc_extraction(
	! file_exists( $root . '/inc/Engine/AI/Tools/Global/GoogleSearchConsole.php' ),
	'core no longer ships google_search_console tool wrapper',
	$failures,
	$passes
);

assert_gsc_extraction(
	! gsc_file_contains( $root . '/inc/Engine/AI/Tools/ToolServiceProvider.php', 'GoogleSearchConsole' ),
	'core tool service provider does not register GSC',
	$failures,
	$passes
);

assert_gsc_extraction(
	! gsc_file_contains( $root . '/inc/Api/Analytics.php', 'datamachine/google-search-console' ),
	'core analytics REST map does not register GSC',
	$failures,
	$passes
);

assert_gsc_extraction(
	! gsc_file_contains( $root . '/inc/Cli/Commands/AnalyticsCommand.php', 'datamachine/google-search-console' ),
	'core analytics CLI command does not register GSC',
	$failures,
	$passes
);

if ( ! empty( $failures ) ) {
	echo "\nFAILURES:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "\n{$passes} assertions passed.\n";
