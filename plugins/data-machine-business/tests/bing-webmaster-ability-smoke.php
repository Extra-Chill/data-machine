<?php
/**
 * Smoke tests for the business-owned Bing Webmaster ability.
 *
 * Run with: php tests/bing-webmaster-ability-smoke.php
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/../inc/Abilities/Analytics/BingWebmasterAbilities.php';

use DataMachineBusiness\Abilities\Analytics\BingWebmasterAbilities;

$failures = array();

$parsed = BingWebmasterAbilities::parse_bing_date( '/Date(1316156400000-0700)/' );
if ( ! is_array( $parsed ) || 1316156400 !== $parsed['timestamp'] || '2011-09-16' !== $parsed['iso'] ) {
	$failures[] = 'parse_bing_date parses Bing WCF dates into UTC day metadata.';
}

if ( null !== BingWebmasterAbilities::parse_bing_date( '2011-09-16' ) ) {
	$failures[] = 'parse_bing_date rejects non-Bing date strings.';
}

if ( 'datamachine_bing_webmaster_config' !== BingWebmasterAbilities::CONFIG_OPTION ) {
	$failures[] = 'CONFIG_OPTION preserves the former Data Machine core option key for adoption.';
}

if ( ! isset( BingWebmasterAbilities::ACTION_ENDPOINTS['query_stats'], BingWebmasterAbilities::ACTION_ENDPOINTS['traffic_stats'], BingWebmasterAbilities::ACTION_ENDPOINTS['page_stats'], BingWebmasterAbilities::ACTION_ENDPOINTS['crawl_stats'] ) ) {
	$failures[] = 'ACTION_ENDPOINTS preserves the supported Bing actions.';
}

if ( $failures ) {
	fwrite( STDERR, "FAILED: " . count( $failures ) . " Bing Webmaster smoke assertion(s) failed.\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "- {$failure}\n" );
	}
	exit( 1 );
}

echo "All Bing Webmaster business smoke assertions passed.\n";
