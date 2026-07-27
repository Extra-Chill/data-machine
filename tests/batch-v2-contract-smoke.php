<?php
/**
 * Pure-PHP source contract for durable batch worklists.
 *
 * Run: php tests/batch-v2-contract-smoke.php
 */

$root      = dirname( __DIR__ );
$batch     = file_get_contents( $root . '/inc/Core/ActionScheduler/BatchScheduler.php' ) ?: '';
$pipeline  = file_get_contents( $root . '/inc/Abilities/Engine/PipelineBatchScheduler.php' ) ?: '';
$tasks     = file_get_contents( $root . '/inc/Engine/Tasks/TaskScheduler.php' ) ?: '';
$database  = file_get_contents( $root . '/inc/Core/Database/BatchItems/BatchItems.php' ) ?: '';
$failures  = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( 1 === preg_match( '/public const STORAGE_VERSION\s+= 2;/', $batch ), 'new batches declare storage version 2' );
$assert( ! str_contains( substr( $batch, strpos( $batch, 'public static function start' ), strpos( $batch, 'public static function processChunk' ) - strpos( $batch, 'public static function start' ) ), "'items'            => \$items" ), 'v2 parent metadata omits work items' );
$assert( str_contains( $database, 'START TRANSACTION' ) && str_contains( $database, 'FOR UPDATE' ), 'repository claims transactionally' );
$assert( 1 === preg_match( '/private const INSERT_CHUNK_SIZE\s+= 100;/', $database ), 'worklist insertion is bounded to 100 rows' );
$assert( str_contains( $database, "'ownership_token'" ) && str_contains( $database, "'existing'" ), 'worklist insertion reports explicit ownership' );
$assert( str_contains( $batch, "empty( \$mutation['success'] )" ), 'initial scheduling checks parent metadata persistence' );
$assert( str_contains( $database, "'lease_token'  => \$token" ), 'terminal updates fence by lease token' );
$assert( str_contains( $pipeline, 'create_or_get_job' ) && str_contains( $pipeline, "'pipeline-batch:'" ), 'pipeline children use deterministic job identity' );
$assert( ! str_contains( substr( $pipeline, strpos( $pipeline, 'BatchScheduler::start' ), 500 ), "'engine_snapshot'" ), 'pipeline batch extra omits duplicate engine snapshot' );
$assert( str_contains( $tasks, "'task-batch:' . \$parent_id . ':' . \$item_index . ':' . \$payload_checksum" ), 'task children derive deterministic operation identity' );
$assert( str_contains( $tasks, "'' === \$payload_checksum ? ''" ), 'legacy v1 task batches do not collapse onto one synthetic operation key' );
$assert( str_contains( $batch, 'processLegacyChunk' ), 'legacy v1 processing path remains available' );
$assert( str_contains( $batch, "['worklist_complete'] = true" ) && str_contains( $batch, 'public static function finalize' ), 'terminal worklists remain replayable until consumer finalization' );
$assert( str_contains( $batch, 'wp_schedule_single_event' ) && str_contains( $batch, 'wp_next_scheduled( $hook, $wp_args )' ), 'recovery ownership falls back to positional WP-Cron scheduling' );
$assert( ! str_contains( $batch, 'as_has_scheduled_action( $hook, $args' ), 'the currently running Action Scheduler action is not mistaken for a future owner' );
$cancel = substr( $batch, strpos( $batch, 'public static function cancel' ) );
$assert( ! str_contains( substr( $cancel, 0, strpos( $cancel, '$remaining' ) ), "unset( \$engine['batch_state'] )" ), 'v2 cancellation preserves state until durable discard' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "batch v2 contract smoke passed\n";
