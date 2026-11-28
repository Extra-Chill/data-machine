<?php
/**
 * Contract smoke test against the real WordPress + Data Machine runtime.
 *
 * github-files-smoke.php covers behavior with stubbed database classes; this
 * checks that the core APIs the handler relies on exist with those shapes.
 *
 * @package DataMachineBusiness
 */

$wp_path = getenv( 'WP_PATH' );
if ( ! defined( 'ABSPATH' ) ) {
	if ( empty( $wp_path ) || ! file_exists( $wp_path . '/wp-load.php' ) ) {
		fwrite( STDERR, "WP_PATH must point to a WordPress installation.\n" );
		exit( 2 );
	}
	require_once $wp_path . '/wp-load.php';
}

// The runtime mounts core as a dependency without activating it.
if ( ! class_exists( \DataMachine\Core\Steps\Fetch\Handlers\FetchHandler::class ) ) {
	$datamachine_core = WP_PLUGIN_DIR . '/data-machine/data-machine.php';
	if ( ! is_readable( $datamachine_core ) ) {
		fwrite( STDERR, "Data Machine core is not available at {$datamachine_core}.\n" );
		exit( 1 );
	}
	require_once $datamachine_core;
}

// The runtime may not activate this plugin; register its handler directly.
if ( ! isset( apply_filters( 'datamachine_handlers', array(), 'fetch' )['github'] ) ) {
	if ( ! defined( 'DATAMACHINE_BUSINESS_VERSION' ) ) {
		require_once dirname( __DIR__ ) . '/data-machine-business.php';
	}
	new \DataMachineBusiness\Handlers\GitHub\GitHubFetch();
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
$check( ( $github['class'] ?? '' ) === \DataMachineBusiness\Handlers\GitHub\GitHubFetch::class, 'The github handler class is wrong.' );
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

$upsert = ! class_exists( \DataMachine\Core\Database\TrackedItems\TrackedItems::class ) ? null : new ReflectionMethod( \DataMachine\Core\Database\TrackedItems\TrackedItems::class, 'upsert' );
$check( $upsert && 1 === $upsert->getNumberOfRequiredParameters(), 'TrackedItems::upsert() no longer takes a single row array.' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "GitHub handler contract smoke passed.\n";
