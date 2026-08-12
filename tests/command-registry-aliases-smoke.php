<?php
/**
 * Smoke coverage for canonical WP-CLI commands and compatibility aliases.
 *
 * Run with: php tests/command-registry-aliases-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/inc/Cli/CommandRegistry.php';

use DataMachine\Cli\CommandRegistry;

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		fwrite( STDOUT, "PASS: {$message}\n" );
		return;
	}

	$failures[] = $message;
	fwrite( STDERR, "FAIL: {$message}\n" );
};

$aliases = array(
	'datamachine settings'        => array( 'datamachine setting' ),
	'datamachine flows'           => array( 'datamachine flow' ),
	'datamachine jobs'            => array( 'datamachine job' ),
	'datamachine cycle'           => array( 'datamachine cycles' ),
	'datamachine pipelines'       => array( 'datamachine pipeline' ),
	'datamachine posts'           => array( 'datamachine post' ),
	'datamachine logs'            => array( 'datamachine log' ),
	'datamachine agents'          => array( 'datamachine agent' ),
	'datamachine pending-actions' => array( 'datamachine pending-action' ),
	'datamachine handlers'        => array( 'datamachine handler' ),
	'datamachine step-types'      => array( 'datamachine step-type' ),
	'datamachine processed-items' => array( 'datamachine processed-item' ),
	'datamachine tracked-items'   => array( 'datamachine tracked-item' ),
	'datamachine test'            => array( 'datamachine fetch test' ),
	'datamachine links'           => array( 'datamachine link' ),
	'datamachine blocks'          => array( 'datamachine block' ),
);

$declarations = CommandRegistry::declarations();
$map          = CommandRegistry::map();

$assert( 32 === count( $declarations ), 'Each implementation has one canonical declaration' );
$assert( 48 === count( $map ), 'Every existing command string remains registered' );
$assert( count( $declarations ) === count( array_unique( array_column( $declarations, 'class' ) ) ), 'Implementations are not declared more than once' );

foreach ( $aliases as $canonical => $compatibility_aliases ) {
	$assert( isset( $declarations[ $canonical ] ), "Canonical command {$canonical} is declared" );
	$assert( $compatibility_aliases === ( $declarations[ $canonical ]['aliases'] ?? array() ), "Aliases for {$canonical} are explicit" );

	foreach ( $compatibility_aliases as $alias ) {
		$assert( isset( $map[ $alias ] ), "Compatibility alias {$alias} remains registered" );
		$assert( $map[ $canonical ] === $map[ $alias ], "{$alias} resolves to the {$canonical} implementation" );
	}
}

if ( $failures ) {
	fwrite( STDERR, sprintf( "\n%d assertion(s) failed.\n", count( $failures ) ) );
	exit( 1 );
}

fwrite( STDOUT, "\nAll assertions passed.\n" );
