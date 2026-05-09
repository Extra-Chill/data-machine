<?php
/**
 * Source smoke coverage for job artifacts and daily memory exports.
 *
 * Run with: php tests/job-artifacts-daily-memory-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$root          = dirname( __DIR__ );
$job_artifacts = file_get_contents( $root . '/inc/Core/JobArtifacts.php' );
$jobs_cli      = file_get_contents( $root . '/inc/Cli/Commands/JobsCommand.php' );
$ai_step       = file_get_contents( $root . '/inc/Core/Steps/AI/AIStep.php' );
$loop          = file_get_contents( $root . '/inc/Engine/AI/conversation-loop.php' );

$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}

	echo "FAIL: {$message}\n";
	$failures[] = $message;
};

$assert(
	false !== strpos( $jobs_cli, '@subcommand artifacts' )
		&& false !== strpos( $jobs_cli, 'new JobArtifacts()' ),
	'jobs artifacts CLI subcommand delegates to the core artifact builder'
);

$assert(
	false !== strpos( $ai_step, "'tool_execution_summary'" )
		&& false !== strpos( $ai_step, 'summarizeToolExecutions' ),
	'AI step persists sanitized tool execution summaries into engine_data'
);

$assert(
	false !== strpos( $loop, "'completion_assertions_required'  =" )
		&& false !== strpos( $loop, "'completion_assertions_satisfied' =" ),
	'conversation loop returns final completion assertion diagnostics even without a nudge'
);

$assert(
	false !== strpos( $job_artifacts, "'required_tool_names'")
		&& false !== strpos( $job_artifacts, "'satisfied_tool_names'" )
		&& false !== strpos( $job_artifacts, "'successful_tool_calls'" ),
	'artifact payload includes required/satisfied tools and successful tool summaries'
);

$assert(
	false !== strpos( $job_artifacts, 'additional_tool_summaries' )
		&& false !== strpos( $job_artifacts, 'successful_tool_summaries_from_list' ),
	'job artifact builder accepts in-flight tool summaries before engine_data persistence'
);

$assert(
	false !== strpos( $loop, 'datamachine_payload_with_inflight_run_artifacts' )
		&& false !== strpos( $loop, 'datamachine_summarize_tool_execution_results' ),
	'conversation loop passes in-flight run artifacts to artifact-aware tools'
);

$assert(
	false !== strpos( $job_artifacts, "'type'                 => 'agent_daily_memory'" )
		&& false !== strpos( $job_artifacts, "memory/agent/daily/%s/%s/%s.md" )
		&& false !== strpos( $job_artifacts, "'content'              =>" ),
	'daily memory artifacts include type, canonical bundle-relative path, and content'
);

$assert(
	false !== strpos( $job_artifacts, 'ConversationStoreFactory::get()->get_session' )
		&& false !== strpos( $job_artifacts, "'message_count'" ),
	'artifact payload includes transcript session metadata without raw messages'
);

echo "\n";
if ( empty( $failures ) ) {
	echo "OK: job artifacts daily memory smoke assertions passed.\n";
	exit( 0 );
}

echo sprintf( "FAILED: %d assertion(s) failed.\n", count( $failures ) );
exit( 1 );
