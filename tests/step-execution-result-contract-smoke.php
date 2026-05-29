<?php
/**
 * Pure-PHP smoke test for StepExecutionResult status/packet separation.
 *
 * Run with: php tests/step-execution-result-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		$key = strtolower( $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/StepExecutionResult.php';

use DataMachine\Core\JobStatus;
use DataMachine\Core\StepExecutionResult;

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

echo "=== step-execution-result-contract-smoke ===\n";

echo "\n[1] explicit status succeeds independently from packet count\n";
$result = StepExecutionResult::fromStepOutput(
	array(
		'status'  => 'succeeded',
		'packets' => array(),
	),
	'system_task'
);

$assert( 'explicit status is preserved', 'succeeded' === $result['status'] );
$assert( 'empty explicit packet list can still be a successful execution', true === $result['success'] );
$assert( 'packet count remains transport-only', 0 === $result['packet_count'] );

echo "\n[2] explicit failure wins over successful-looking packets\n";
$result = StepExecutionResult::fromStepOutput(
	array(
		'status'  => 'failed',
		'reason'  => 'handler refused item',
		'packets' => array(
			array(
				'type'     => 'source_item',
				'metadata' => array(),
			),
		),
	),
	'fetch'
);

$assert( 'explicit failed status is authoritative', 'failed' === $result['status'] );
$assert( 'explicit failed result is not successful', false === $result['success'] );
$assert( 'explicit failure reason is sanitized', 'handler_refused_item' === $result['reason'] );

echo "\n[3] legacy fetch empty output maps to completed_no_items\n";
$result = StepExecutionResult::classify( array(), 'fetch' );

$assert( 'fetch empty output has completed_no_items status', 'completed_no_items' === $result['status'] );
$assert( 'fetch empty output exposes terminal status', JobStatus::COMPLETED_NO_ITEMS === $result['terminal_status'] );
$assert( 'fetch empty output is not downstream success', false === $result['success'] );

echo "\n[4] legacy AI fallback packets do not prove execution success\n";
$result = StepExecutionResult::classify(
	array(
		array(
			'type'     => 'ai_response',
			'data'     => array( 'body' => 'I summarized instead of calling a handler.' ),
			'metadata' => array(),
		),
	),
	'ai'
);

$assert( 'AI response fallback fails without explicit success', 'failed' === $result['status'] );
$assert( 'AI response fallback uses actionable failure reason', 'ai_response_without_tool_result' === $result['reason'] );

echo "\n[5] non-fatal failed tool packets do not override later successful tool output\n";
$result = StepExecutionResult::classify(
	array(
		array(
			'type'     => 'tool_result',
			'metadata' => array(
				'tool_name'              => 'wiki_read',
				'tool_success'           => false,
				'tool_failure_non_fatal' => true,
			),
		),
		array(
			'type'     => 'tool_result',
			'metadata' => array(
				'tool_name'    => 'wiki_upsert',
				'tool_success' => true,
			),
		),
	),
	'ai'
);

$assert( 'non-fatal failed tool packet is retained without failing step', 'succeeded' === $result['status'] );
$assert( 'successful later tool packet determines AI success', true === $result['success'] );

if ( $failures > 0 ) {
	echo "\n=== step-execution-result-contract-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== step-execution-result-contract-smoke: ALL PASS ({$total} assertions) ===\n";
