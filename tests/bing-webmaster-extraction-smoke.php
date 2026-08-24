<?php
/**
 * Smoke test for Bing Webmaster extraction from Data Machine core.
 *
 * Run with: php tests/bing-webmaster-extraction-smoke.php
 */

$root     = dirname( __DIR__ );
$failures = array();

$must_not_contain = array(
	'data-machine.php'                         => 'BingWebmasterAbilities',
	'inc/Engine/AI/Tools/ToolServiceProvider.php' => 'BingWebmaster',
	'inc/Cli/Commands/AnalyticsCommand.php'    => 'datamachine/bing-webmaster',
	'uninstall.php'                            => 'datamachine_bing_webmaster_config',
);

foreach ( $must_not_contain as $relative_path => $needle ) {
	$contents = file_get_contents( $root . '/' . $relative_path );
	if ( false !== strpos( $contents, $needle ) ) {
		$failures[] = "{$relative_path} still contains {$needle}.";
	}
}

$analytics = file_get_contents( $root . '/inc/Api/Analytics.php' );
if ( false === strpos( $analytics, 'datamachine_analytics_ability_map' ) ) {
	$failures[] = 'Analytics REST API does not expose the extension ability-map filter.';
}

if ( file_exists( $root . '/inc/Abilities/Analytics/BingWebmasterAbilities.php' ) ) {
	$failures[] = 'Core BingWebmasterAbilities file still exists.';
}

if ( file_exists( $root . '/inc/Engine/AI/Tools/Global/BingWebmaster.php' ) ) {
	$failures[] = 'Core BingWebmaster tool file still exists.';
}

if ( $failures ) {
	fwrite( fopen( 'php://stderr', 'w' ), "FAILED: " . count( $failures ) . " Bing extraction assertion(s) failed.\n" );
	foreach ( $failures as $failure ) {
		fwrite( fopen( 'php://stderr', 'w' ), "- {$failure}\n" );
	}
	exit( 1 );
}

echo "All Bing Webmaster extraction smoke assertions passed.\n";
