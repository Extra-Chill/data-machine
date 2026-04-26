<?php
/**
 * Pure-PHP smoke test for retention SystemTask migration (#1314).
 *
 * Run with: php tests/retention-system-tasks-smoke.php
 *
 * This pins the no-shim cutover shape: legacy Action Scheduler cleanup
 * files are gone, retention domains are registered as SystemTasks and
 * recurring schedules, and the CLI manual run path schedules tasks instead
 * of invoking legacy cleanup hooks directly.
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );

$sources = array(
	'bootstrap' => file_get_contents( $root . '/inc/bootstrap.php' ),
	'provider'  => file_get_contents( $root . '/inc/Engine/AI/System/SystemAgentServiceProvider.php' ),
	'command'   => file_get_contents( $root . '/inc/Cli/Commands/RetentionCommand.php' ),
	'files'     => file_get_contents( $root . '/inc/Core/FilesRepository/FileCleanup.php' ),
	'chat'      => file_get_contents( $root . '/inc/Core/Database/Chat/Chat.php' ),
	'cleanup'   => file_get_contents( $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php' ),
);

$failed = 0;
$total  = 0;

function assert_retention( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

echo "=== retention-system-tasks-smoke ===\n";

$legacy_files = array(
	'inc/Core/ActionScheduler/ClaimsCleanup.php',
	'inc/Core/ActionScheduler/JobsCleanup.php',
	'inc/Core/ActionScheduler/CompletedJobsCleanup.php',
	'inc/Core/ActionScheduler/LogCleanup.php',
	'inc/Core/ActionScheduler/ProcessedItemsCleanup.php',
	'inc/Core/ActionScheduler/ActionsCleanup.php',
);

foreach ( $legacy_files as $legacy_file ) {
	assert_retention( "legacy file deleted: {$legacy_file}", ! file_exists( $root . '/' . $legacy_file ) );
	assert_retention( "bootstrap no longer requires {$legacy_file}", ! str_contains( $sources['bootstrap'], $legacy_file ) );
}

$task_classes = array(
	'RetentionCompletedJobsTask',
	'RetentionFailedJobsTask',
	'RetentionLogsTask',
	'RetentionProcessedItemsTask',
	'RetentionActionSchedulerTask',
	'RetentionStaleClaimsTask',
	'RetentionFilesTask',
	'RetentionChatSessionsTask',
);

foreach ( $task_classes as $task_class ) {
	$path = $root . '/inc/Engine/AI/System/Tasks/Retention/' . $task_class . '.php';
	$task_source = file_exists( $path ) ? file_get_contents( $path ) : '';
	assert_retention( "task class file exists: {$task_class}", file_exists( $path ) );
	assert_retention( "provider imports {$task_class}", str_contains( $sources['provider'], $task_class . ';' ) );
	assert_retention( "provider registers {$task_class}", str_contains( $sources['provider'], '= ' . $task_class . '::class;' ) );
	assert_retention( "task supports manual system run: {$task_class}", str_contains( $task_source, "'supports_run'    => true" ) );
}

$task_constants = array(
	'TASK_COMPLETED_JOBS',
	'TASK_FAILED_JOBS',
	'TASK_LOGS',
	'TASK_PROCESSED_ITEMS',
	'TASK_AS_ACTIONS',
	'TASK_STALE_CLAIMS',
	'TASK_FILES',
	'TASK_CHAT_SESSIONS',
);

foreach ( $task_constants as $constant ) {
	assert_retention( "cleanup defines {$constant}", str_contains( $sources['cleanup'], 'const ' . $constant ) );
	assert_retention( "provider schedules {$constant}", substr_count( $sources['provider'], 'RetentionCleanup::' . $constant ) >= 2 );
}

assert_retention(
	'RetentionCommand routes execution through TaskScheduler',
	str_contains( $sources['command'], 'TaskScheduler::schedule( $domain[\'task_type\'], array() )' )
);
assert_retention(
	'RetentionCommand keeps dry-run local through count callbacks',
	str_contains( $sources['command'], "'count'     => array( RetentionCleanup::class, 'countCompletedJobs' )" )
);
assert_retention(
	'RetentionCommand no longer invokes legacy cleanup hooks',
	! str_contains( $sources['command'], "do_action( 'datamachine_cleanup_" )
);
assert_retention(
	'RetentionCommand no longer deletes jobs directly',
	! str_contains( $sources['command'], 'delete_old_jobs(' )
);
assert_retention(
	'FileCleanup no longer registers legacy cleanup hook',
	! str_contains( $sources['files'], 'datamachine_cleanup_old_files' )
);
assert_retention(
	'Chat store no longer registers legacy cleanup hook',
	! str_contains( $sources['chat'], 'datamachine_cleanup_chat_sessions' )
);
assert_retention(
	'Provider hard-unschedules old retention hooks on upgrade',
	str_contains( $sources['provider'], 'LEGACY_RETENTION_HOOKS' )
		&& str_contains( $sources['provider'], 'as_unschedule_all_actions( $legacy_hook[0], array(), $legacy_hook[1] );' )
);
assert_retention(
	'file dry-run counter includes old job directories',
	str_contains( $sources['cleanup'], '$jobs_dir = "{$flow_dir}/jobs";' )
		&& str_contains( $sources['cleanup'], '++$count;' )
);

if ( $failed > 0 ) {
	echo "\nretention-system-tasks-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\nretention-system-tasks-smoke passed: {$total} assertions.\n";
