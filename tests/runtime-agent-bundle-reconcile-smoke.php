<?php
/**
 * Smoke checks for runtime agent bundle reconciliation.
 *
 * @package DataMachine
 */

$root   = dirname( __DIR__ );
$source = file_get_contents( $root . '/inc/Engine/Agents/datamachine-register-agents.php' );

$assert = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$label}\n" );
		exit( 1 );
	}

	fwrite( fopen( 'php://stdout', 'w' ), "PASS: {$label}\n" );
};

$assert( false !== $source, 'agent-registration-source-readable' );
$assert( str_contains( $source, 'function datamachine_reconcile_runtime_agent_bundle_import' ), 'runtime-import-reconcile-function-exists' );
$assert( str_contains( $source, "add_filter( 'wp_agent_runtime_import_bundle', 'datamachine_reconcile_runtime_agent_bundle_import', 20, 1 )" ), 'runtime-import-reconcile-filter-registered-after-importer' );
$assert( str_contains( $source, "! empty( \$result['success'] )" ), 'runtime-import-reconcile-requires-success' );
$assert( str_contains( $source, "! empty( \$result['agent_slug'] )" ), 'runtime-import-reconcile-requires-agent-slug' );
$assert( str_contains( $source, 'AgentRegistry::reconcile();' ), 'runtime-import-reconcile-materializes-agent' );

fwrite( fopen( 'php://stdout', 'w' ), "Runtime agent bundle reconcile smoke passed.\n" );
