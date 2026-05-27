<?php
/**
 * Pure-PHP smoke test for job status accounting repairs.
 *
 * Run with: php tests/job-status-accounting-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$datamachine_status_accounting_logs = array();

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['datamachine_status_accounting_logs'][] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		$key = strtolower( $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/StepExecutionResult.php';
require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';
require_once __DIR__ . '/../inc/Abilities/Engine/EngineHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php';

use DataMachine\Abilities\Engine\ExecuteStepAbility;

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}

	++$failures;
	echo "  [FAIL] {$label}\n";
};

$evaluate = function ( array $packets ): bool {
	$reflection = new ReflectionClass( ExecuteStepAbility::class );
	$ability    = $reflection->newInstanceWithoutConstructor();
	$method     = $reflection->getMethod( 'evaluateStepSuccess' );

	return $method->invoke( $ability, $packets, 123, 'ai_step' );
};

echo "=== job-status-accounting-smoke ===\n";

echo "\n[1] later successful handler result wins over earlier failed attempt\n";
$packets = array(
	array(
		'type'     => 'tool_result',
		'data'     => array( 'body' => 'first call failed' ),
		'metadata' => array(
			'handler_tool' => 'wiki_upsert',
			'tool_success' => false,
		),
	),
	array(
		'type'     => 'ai_handler_complete',
		'data'     => array( 'body' => 'later call succeeded' ),
		'metadata' => array(
			'handler_tool' => 'wiki_upsert',
		),
	),
);

$assert( 'step succeeds when the same handler later succeeds', true === $evaluate( $packets ) );

echo "\n[2] standalone failed tool result still fails the step\n";
$packets = array(
	array(
		'type'     => 'tool_result',
		'data'     => array( 'body' => 'tool failed' ),
		'metadata' => array(
			'handler_tool' => 'wiki_upsert',
			'tool_success' => false,
		),
	),
);

$assert( 'step fails when no successful handler result exists', false === $evaluate( $packets ) );

echo "\n[3] fallback AI response packet is not execution success\n";
$packets = array(
	array(
		'type'     => 'ai_response',
		'data'     => array( 'body' => 'summarized but did not execute a handler' ),
		'metadata' => array(
			'step_execution_success' => false,
			'failure_reason'          => 'ai_response_without_tool_result',
		),
	),
);

$assert( 'step fails when only an AI fallback packet exists', false === $evaluate( $packets ) );

echo "\n[4] reconcile-status command exists with repair markers\n";
$jobs_command = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' );
$assert( 'CLI exposes reconcile-status subcommand', str_contains( $jobs_command, '@subcommand reconcile-status' ) );
$assert( 'CLI detects successful wiki update artifacts', str_contains( $jobs_command, 'Updated wiki article:' ) );
$assert( 'CLI detects source rejection artifacts', str_contains( $jobs_command, 'Source rejected:' ) );
$assert( 'CLI inspects processing rows with terminal artifacts', str_contains( $jobs_command, "status = 'processing'" ) );
$assert( 'CLI requires successful runtime provenance', str_contains( $jobs_command, 'engine_data_has_successful_runtime' ) );
$assert( 'CLI requires successful handler tool summary', str_contains( $jobs_command, 'engine_data_has_successful_handler_tool' ) );
$assert( 'CLI supports dry-run output', str_contains( $jobs_command, "'dry_run' => $" ) || str_contains( $jobs_command, "'dry_run' => \$dry_run" ) );

echo "\n[5] compact job summary avoids heavyweight breakdowns\n";
$jobs_db = file_get_contents( __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php' );
$summary_ability = file_get_contents( __DIR__ . '/../inc/Abilities/Job/JobsSummaryAbility.php' );
$assert( 'jobs summary accepts compact input', str_contains( $summary_ability, "'compact'" ) );
$assert( 'jobs database returns early for compact summaries', str_contains( $jobs_db, 'if ( ! empty( $args[\'compact\'] ) )' ) );
$assert( 'compact summaries keep status buckets', str_contains( $jobs_db, "'status'                 => $" ) || str_contains( $jobs_db, "'status'                 => \$this->get_status_summary_rows" ) );

if ( $failures > 0 ) {
	echo "\n=== job-status-accounting-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== job-status-accounting-smoke: ALL PASS ({$total} assertions) ===\n";
