<?php
/**
 * Pure-PHP smoke test for business-owned Google Search Console surfaces (#2139).
 *
 * Run with: php tests/gsc-business-registration-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

$root     = dirname( __DIR__ );
$failures = array();
$passes   = 0;

function assert_business_gsc( bool $condition, string $name, array &$failures, int &$passes ): void {
	if ( $condition ) {
		$passes++;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
}

function business_gsc_file_contains( string $relative_path, string $needle ): bool {
	$contents = file_get_contents( dirname( __DIR__ ) . '/' . $relative_path );
	return false !== $contents && false !== strpos( $contents, $needle );
}

echo "Business GSC registration smoke (#2139)\n";
echo "---------------------------------------\n";

assert_business_gsc(
	file_exists( $root . '/inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php' ),
	'business ships GoogleSearchConsoleAbilities',
	$failures,
	$passes
);

assert_business_gsc(
	file_exists( $root . '/inc/Tools/GoogleSearchConsole.php' ),
	'business ships google_search_console tool wrapper',
	$failures,
	$passes
);

assert_business_gsc(
	business_gsc_file_contains( 'inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php', "CONFIG_OPTION = 'datamachine_gsc_config'" ),
	'business preserves legacy GSC config option key',
	$failures,
	$passes
);

assert_business_gsc(
	business_gsc_file_contains( 'inc/Tools/GoogleSearchConsole.php', "registerTool( 'google_search_console'" ),
	'business registers google_search_console tool',
	$failures,
	$passes
);

assert_business_gsc(
	business_gsc_file_contains( 'inc/Api/GoogleSearchConsoleAnalytics.php', "'/analytics/gsc'" ),
	'business registers the GSC REST route',
	$failures,
	$passes
);

assert_business_gsc(
	business_gsc_file_contains( 'data-machine-business.php', "datamachine analytics gsc" ),
	'business registers the GSC WP-CLI command',
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
