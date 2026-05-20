<?php
/**
 * Static smoke checks for the PageSpeed integration ownership surface.
 */

$root     = dirname( __DIR__ );
$failures = array();

$required_files = array(
	'inc/Abilities/PageSpeed/PageSpeedAbility.php',
	'inc/Tools/PageSpeedTool.php',
	'inc/Api/PageSpeedAnalytics.php',
	'inc/Cli/PageSpeedCommand.php',
);

foreach ( $required_files as $relative_path ) {
	if ( ! file_exists( $root . '/' . $relative_path ) ) {
		$failures[] = "Missing {$relative_path}";
	}
}

$plugin_file = file_get_contents( $root . '/data-machine-business.php' );
$readme      = file_get_contents( $root . '/README.md' );

foreach ( array( 'PageSpeedAbility', 'PageSpeedTool', 'PageSpeedAnalytics', 'PageSpeedCommand' ) as $symbol ) {
	if ( false === strpos( $plugin_file, $symbol ) ) {
		$failures[] = "Plugin bootstrap does not reference {$symbol}";
	}
}

foreach ( array( 'datamachine/pagespeed', 'datamachine_pagespeed_config', 'wp datamachine analytics pagespeed' ) as $expected ) {
	if ( false === strpos( $readme, $expected ) ) {
		$failures[] = "README does not document {$expected}";
	}
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "PageSpeed ownership smoke checks passed.\n";
