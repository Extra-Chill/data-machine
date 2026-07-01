<?php
/**
 * Smoke test for the bounded synchronous debug runner source contract.
 *
 * Run with: php tests/sync-runner-bounds-smoke.php
 *
 * @package DataMachine\Tests
 */

$root   = dirname( __DIR__ );
$source = (string) file_get_contents( $root . '/inc/Engine/Debug/SyncRunner.php' );
$base   = (string) file_get_contents( $root . '/inc/Cli/BaseCommand.php' );
$flows  = (string) file_get_contents( $root . '/inc/Cli/Commands/Flows/FlowsCommand.php' );
$pipes  = (string) file_get_contents( $root . '/inc/Cli/Commands/PipelinesCommand.php' );

$assertions = 0;

$assert = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "sync-runner smoke failed: {$message}\n" );
		exit( 1 );
	}
};

$assert( str_contains( $source, "'mode'" ) && str_contains( $source, "'sync_debug'" ), 'diagnostics packet identifies sync debug mode' );
$assert( str_contains( $source, "'max_steps'       => max( 1, min( 100" ), 'max_steps is bounded' );
$assert( str_contains( $source, "'max_items'       => max( 1, min( 1000" ), 'max_items is bounded' );
$assert( str_contains( $source, "'timeout_seconds' => max( 1, min( 900" ), 'timeout is bounded' );
$assert( str_contains( $source, "'stopped_reason'] = 'timeout'" ), 'timeout stop is reported' );
$assert( str_contains( $source, "'stopped_reason'] = 'max_steps'" ), 'max step stop is reported' );
$assert( str_contains( $source, "'sync_runner_max_items'" ), 'max item stop is recorded' );
$assert( str_contains( $source, "'stopped_reason' => 'error'" ), 'step errors produce error stop diagnostics' );
$assert( str_contains( $source, '\\remove_all_actions( \'datamachine_schedule_next_step\'' ), 'sync runner captures schedule-next-step hook' );
$assert( str_contains( $source, 'restoreProductionScheduleNextStepHook' ), 'sync runner restores production schedule hook' );
$assert( ! str_contains( $source, 'as_schedule_single_action' ), 'sync runner does not call Action Scheduler directly' );
$assert( str_contains( $base, 'read_sync_input_packets' ), 'CLI can load input packet JSON' );
$assert( str_contains( $flows, "'run-sync' === \$args[0]" ), 'flow CLI exposes run-sync' );
$assert( str_contains( $pipes, "'run-sync' === \$args[0]" ), 'pipeline CLI exposes run-sync' );

echo "sync-runner bounds smoke passed ({$assertions} assertions)\n";
