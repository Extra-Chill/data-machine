<?php
/**
 * Pure-PHP smoke test for step execution result error propagation.
 *
 * Run with: php tests/step-execution-result-error-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wordpress/' );
}

require_once dirname( __DIR__ ) . '/inc/Core/StepExecutionResult.php';

use DataMachine\Core\StepExecutionResult;

$failed = 0;
$total  = 0;

function assert_step_execution_result_error( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}\n";
}

echo "=== step-execution-result-error-smoke ===\n";

$explicit_error_result = StepExecutionResult::fromStepOutput(
	array(
		'status' => 'failed',
		'reason' => 'handler_failed',
		'error'  => 'GitHub API request failed: 404 Not Found',
	),
	'fetch'
);

assert_step_execution_result_error(
	'explicit result preserves human error message',
	'GitHub API request failed: 404 Not Found' === $explicit_error_result['error']
);

assert_step_execution_result_error(
	'explicit result keeps sanitized reason separate from error',
	'handler_failed' === $explicit_error_result['reason']
);

$metadata_error_result = StepExecutionResult::classify(
	array(
		array(
			'type'     => 'handler_result',
			'metadata' => array(
				'tool_success'   => false,
				'failure_reason' => 'handler_failed',
				'error_message'  => 'GitHub App installation token exchange failed.',
			),
		),
	),
	'fetch'
);

assert_step_execution_result_error(
	'packet metadata errors propagate to execution result',
	'GitHub App installation token exchange failed.' === $metadata_error_result['error']
);

if ( $failed > 0 ) {
	echo "\nstep-execution-result-error-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\nstep-execution-result-error-smoke passed: {$total} assertions.\n";
