<?php
/**
 * Source smoke coverage for pipeline transcript job linkage.
 *
 * Run with: php tests/pipeline-transcript-session-link-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$root      = dirname( __DIR__ );
$persister = file_get_contents( $root . '/inc/Engine/AI/DataMachinePipelineTranscriptPersister.php' );
$jobs_cli  = file_get_contents( $root . '/inc/Cli/Commands/JobsCommand.php' );

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
	false !== strpos( $persister, 'datamachine_merge_engine_data' )
		&& false !== strpos( $persister, '\'transcript_session_id\' => $session_id' ),
	'persister stores transcript_session_id on the originating job'
);

$assert(
	false !== strpos( $jobs_cli, 'findTranscriptSessionIdForJob' ),
	'jobs transcript command has metadata fallback for old transcript rows'
);

$assert(
	false !== strpos( $jobs_cli, 'metadata LIKE' ) && false !== strpos( $jobs_cli, 'datamachine_chat_sessions' ),
	'metadata fallback searches pipeline transcript rows by job_id'
);

echo "\n";
if ( empty( $failures ) ) {
	echo "OK: pipeline transcript session link smoke assertions passed.\n";
	exit( 0 );
}

echo sprintf( "FAILED: %d assertion(s) failed.\n", count( $failures ) );
exit( 1 );
