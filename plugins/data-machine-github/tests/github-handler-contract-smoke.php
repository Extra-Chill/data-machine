<?php
/**
 * Contract smoke test against the real WordPress + Data Machine runtime.
 *
 * github-files-smoke.php covers behavior with stubbed database classes; this
 * checks that the core APIs the handler relies on exist with those shapes.
 *
 * @package DataMachineGitHub
 */

$wp_path = getenv( 'WP_PATH' );
if ( ! defined( 'ABSPATH' ) ) {
	if ( empty( $wp_path ) || ! file_exists( $wp_path . '/wp-load.php' ) ) {
		fwrite( STDERR, "WP_PATH must point to a WordPress installation.\n" );
		exit( 2 );
	}
	require_once $wp_path . '/wp-load.php';
}

// The runtime may not activate this plugin; load it and register its handler directly.
if ( ! function_exists( 'datamachine_github_load_handlers' ) ) {
	require_once dirname( __DIR__ ) . '/data-machine-github.php';
}
if ( ! isset( apply_filters( 'datamachine_handlers', array(), 'fetch' )['github'] ) ) {
	datamachine_github_load_handlers();
}

$failures = array();
$check    = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$handlers = apply_filters( 'datamachine_handlers', array(), 'fetch' );
$github   = $handlers['github'] ?? null;
$check( is_array( $github ), 'The github fetch handler is not registered.' );
$check( ( $github['class'] ?? '' ) === \DataMachineGitHub\Handlers\GitHub\GitHubFetch::class, 'The github handler class is wrong.' );
$check( ( $github['meta']['data_source'] ?? '' ) === 'files', 'The github handler does not declare the files data source.' );

$methods = array(
	\DataMachine\Core\Database\TrackedItems\TrackedItems::class => array( 'get', 'upsert' ),
	\DataMachine\Core\ExecutionContext::class => array( 'isFlow', 'getFlowStepId', 'isItemProcessed', 'classifySourceItems' ),
	\DataMachine\Core\Steps\Fetch\Handlers\FetchHandler::class => array( 'getAuthProvider' ),
);
foreach ( $methods as $class => $names ) {
	foreach ( $names as $name ) {
		$check( method_exists( $class, $name ), "{$class}::{$name}() is missing." );
	}
}

$upsert = new ReflectionMethod( \DataMachine\Core\Database\TrackedItems\TrackedItems::class, 'upsert' );
$check( 1 === $upsert->getNumberOfRequiredParameters(), 'TrackedItems::upsert() no longer takes a single row array.' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "GitHub handler contract smoke passed.\n";
