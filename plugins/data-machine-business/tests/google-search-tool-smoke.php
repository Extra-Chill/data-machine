<?php
/**
 * Static smoke checks for the Google Search tool extraction.
 *
 * @package DataMachineBusiness\Tests
 */

$root = dirname( __DIR__ );

$providers_file = file_get_contents( $root . '/inc/Bootstrap/ProviderModules.php' );
$tool_file      = file_get_contents( $root . '/inc/Tools/GoogleSearch.php' );

$checks = array(
	'provider module instantiates Google Search tool' => str_contains( $providers_file, 'new \\DataMachineBusiness\\Tools\\GoogleSearch()' ),
	'tool registers google_search slug'      => str_contains( $tool_file, "registerTool( 'google_search'" ),
	'tool keeps existing config option'      => str_contains( $tool_file, "get_site_option( 'datamachine_search_config'" ),
	'tool uses Custom Search API endpoint'   => str_contains( $tool_file, 'https://www.googleapis.com/customsearch/v1' ),
);

$failures = array();

foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		$failures[] = $label;
	}
}

if ( $failures ) {
	fwrite( STDERR, "Google Search tool smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Google Search tool smoke passed (" . count( $checks ) . " assertions).\n";
