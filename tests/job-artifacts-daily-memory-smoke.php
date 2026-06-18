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
$engine_data   = file_get_contents( $root . '/inc/Engine/Filters/EngineData.php' );
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
	false !== strpos( $loop, "\$datamachine_metadata['completion_assertions_required']" )
		&& false !== strpos( $loop, "\$datamachine_metadata['completion_assertions_satisfied']" ),
	'conversation loop returns final completion assertion diagnostics under Data Machine metadata even without a nudge'
);

$assert(
	false !== strpos( $engine_data, 'write_artifact_files' )
		&& false !== strpos( $engine_data, "'artifact_files' =>" )
		&& false !== strpos( $job_artifacts, 'datamachine-artifacts/jobs/' ),
	'engine data persistence writes first-class transcript/tool-trace artifact files and stores refs in engine_data'
);

$assert(
	false !== strpos( $job_artifacts, 'public function resolve_artifact_ref' )
		&& false !== strpos( $job_artifacts, "'artifact_ref'" )
		&& false !== strpos( $job_artifacts, "'type'" )
		&& false !== strpos( $job_artifacts, "'schema_version'" )
		&& false !== strpos( $job_artifacts, "'sha256'" )
		&& false !== strpos( $job_artifacts, "'bytes'" )
		&& false !== strpos( $job_artifacts, "'relative_path'" ),
	'job artifact files expose a portable ArtifactRef resolver contract'
);

$assert(
	false !== strpos( $job_artifacts, "'local_debug'" )
		&& false !== strpos( $job_artifacts, 'datamachine_job_artifact_ref_export_url' )
		&& false !== strpos( $job_artifacts, 'datamachine_job_artifact_ref_signed_url' )
		&& false === strpos( $job_artifacts, "\t\t\t\t\t'path'            =>" )
		&& false === strpos( $job_artifacts, "\t\t\t\t\t'url'             =>" ),
	'absolute filesystem paths and local URLs stay in local_debug while export/signed URLs are explicit'
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
	false !== strpos( $loop, 'datamachine_persist_inflight_tool_summary' )
		&& false !== strpos( $loop, "'tool_execution_summary' => datamachine_summarize_tool_execution_results( \$tool_execution_results, true )" ),
	'conversation loop persists in-flight tool summaries before later artifact-aware tools run'
);

$assert(
	false !== strpos( $loop, "\$summary['user_id']" )
		&& false !== strpos( $loop, "\$summary['agent_id']" )
		&& false !== strpos( $job_artifacts, "\$tool_call['user_id']" )
		&& false !== strpos( $job_artifacts, "\$tool_call['agent_id']" ),
	'daily memory artifact export preserves the exact write scope from tool summaries'
);

$assert(
	false !== strpos( $loop, 'datamachine_summarize_tool_execution_results( $tool_execution_results, true )' )
		&& false !== strpos( $loop, "\$summary['content']" )
		&& false !== strpos( $job_artifacts, 'daily_memory_fallback_content' ),
	'in-flight daily memory artifacts can fall back to the successful write content'
);

$assert(
	false !== strpos( $loop, "'agent_memory' === \$tool_name" )
		&& false !== strpos( $loop, "\$tool_result['scope']" )
		&& false !== strpos( $loop, "\$summary['file']" )
		&& false !== strpos( $loop, "\$summary['section']" )
		&& false !== strpos( $loop, "'update' === \$summary['action']" ),
	'agent memory update summaries preserve resolved scope, file, section, action, and content for artifact export'
);

$assert(
	false !== strpos( $job_artifacts, "'agent_memory_artifacts'" )
		&& false !== strpos( $job_artifacts, 'private function agent_memory_artifacts' )
		&& false !== strpos( $job_artifacts, "'type'                 => 'agent_memory'" ),
	'artifact payload includes durable agent memory artifacts'
);

$assert(
	false !== strpos( $job_artifacts, "memory/agent/' . \$filename" )
		&& false !== strpos( $job_artifacts, "'memory/USER.md'" )
		&& false !== strpos( $job_artifacts, 'AgentMemoryFile::resolve_layer_for' ),
	'agent memory artifacts map memory layers to canonical bundle-relative paths'
);

$assert(
	false !== strpos( $job_artifacts, 'new AgentMemoryFile' )
		&& false !== strpos( $job_artifacts, 'get_all()' )
		&& false !== strpos( $job_artifacts, '$agent_id <= 0 && $default_agent_id > 0' )
		&& false !== strpos( $job_artifacts, 'agent_memory_fallback_content' ),
	'agent memory artifacts export the full updated file content using the resolved job agent when needed'
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

$assert(
	false !== strpos( $job_artifacts, "'disposition_diagnostic'" )
		&& false !== strpos( $job_artifacts, 'private function disposition_diagnostic' )
		&& false !== strpos( $job_artifacts, "'packet_count'" )
		&& false !== strpos( $job_artifacts, "'excerpt_limit'" ),
	'job artifacts expose bounded source disposition diagnostics for skipped/deferred jobs'
);

echo "\n";
if ( empty( $failures ) ) {
	echo "OK: job artifacts daily memory smoke assertions passed.\n";
	exit( 0 );
}

echo sprintf( "FAILED: %d assertion(s) failed.\n", count( $failures ) );
exit( 1 );
