<?php
/**
 * Standalone AGENTS.md section contract smoke test.
 *
 * @package DataMachineEvents\Tests
 */

define( 'ABSPATH', '/var/www/extrachill.com/' );

require_once dirname( __DIR__ ) . '/inc/Cli/AgentsMdSection.php';

use DataMachineEvents\Cli\AgentsMdSection;

$wp      = 'wp --url=https://events.extrachill.com --allow-root --path=/var/www/extrachill.com/';
$section = AgentsMdSection::render( $wp );

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$routes = array(
	'data-machine-events check quality',
	'data-machine-events update-event',
	'data-machine-events audit-venues',
	'data-machine-events test-event-scraper',
	'data-machine-events backfill-event-dates',
);

foreach ( $routes as $route ) {
	$assert( false !== strpos( $section, $wp . ' ' . $route ), "missing default route: {$route}" );
}

$assert( false !== strpos( $section, 'nested help to select a targeted repair' ), 'missing targeted repair discovery' );
$assert( false !== strpos( $section, $wp . ' data-machine-events --help' ), 'missing top-level discovery' );
$assert( false !== strpos( $section, $wp . ' data-machine-events <command> --help' ), 'missing nested discovery' );
$assert( false !== strpos( $section, 'Live `--help` is authoritative.' ), 'missing live-help authority' );

preg_match_all( '/`(wp [^`]+)`/', $section, $commands );
$assert( ! empty( $commands[1] ), 'no copyable commands rendered' );
foreach ( $commands[1] as $command ) {
	$assert( 0 === strpos( $command, $wp ), "command does not target Events site: {$command}" );
}

$assert( substr_count( $section, "\n" ) + 1 <= 16, 'section exceeds bounded line contract' );
$assert( false === strpos( $section, 'data-machine-events settings' ), 'section exhaustively lists settings' );
$assert( false === strpos( $section, 'data-machine-events test-ticketmaster' ), 'section exhaustively lists provider diagnostics' );
$assert( false === strpos( $section, 'data-machine-events resync-ticket-urls' ), 'section exhaustively lists maintenance commands' );

printf(
	"PASS: concise AGENTS.md routing (%d lines / %d bytes)\n",
	substr_count( $section, "\n" ) + 1,
	strlen( $section )
);
