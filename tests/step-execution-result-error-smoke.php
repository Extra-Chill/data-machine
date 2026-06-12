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
				'tool_name'            => 'create_github_pull_request',
				'tool_success'         => false,
				'failure_reason'       => 'handler_failed',
				'tool_result_envelope' => array(
					'success' => false,
					'code'    => 'not_found',
					'message' => 'GitHub App installation token exchange failed.',
				),
			),
		),
	),
	'fetch'
);

assert_step_execution_result_error(
	'packet metadata errors propagate to execution result',
	'GitHub App installation token exchange failed.' === $metadata_error_result['error']
);

assert_step_execution_result_error(
	'failed tool diagnostic preserves tool name',
	'create_github_pull_request' === ( $metadata_error_result['diagnostics']['tool_name'] ?? '' )
);

assert_step_execution_result_error(
	'failed tool diagnostic preserves envelope code',
	'not_found' === ( $metadata_error_result['diagnostics']['tool_result']['code'] ?? '' )
);

assert_step_execution_result_error(
	'failed tool diagnostic preserves envelope message',
	'GitHub App installation token exchange failed.' === ( $metadata_error_result['diagnostics']['tool_result']['message'] ?? '' )
);

if ( $failed > 0 ) {
	echo "\nstep-execution-result-error-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\nstep-execution-result-error-smoke passed: {$total} assertions.\n";
