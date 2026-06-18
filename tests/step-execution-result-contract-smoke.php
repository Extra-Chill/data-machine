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
require_once __DIR__ . '/../inc/Core/StepResult.php';
require_once __DIR__ . '/../inc/Core/RunResult.php';
require_once __DIR__ . '/../inc/Core/StepExecutionResult.php';

use DataMachine\Core\JobStatus;
use DataMachine\Core\RunResult;
use DataMachine\Core\StepExecutionResult;
use DataMachine\Core\StepResult;

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

echo "\n[6] recovered failed runtime tool packet does not fail successful AI step\n";
$result = StepExecutionResult::classify(
	array(
		array(
			'type'     => 'tool_result',
			'metadata' => array(
				'tool_name'            => 'workspace_read',
				'tool_success'         => false,
				'tool_result_envelope' => array(
					'success' => false,
					'code'    => 'file_too_large',
					'message' => 'File exceeds max_size.',
				),
			),
		),
		array(
			'type'     => 'tool_result',
			'metadata' => array(
				'tool_name'    => 'workspace_read',
				'tool_success' => true,
			),
		),
		array(
			'type'     => 'tool_result',
			'metadata' => array(
				'tool_name'    => 'workspace_write',
				'tool_success' => true,
			),
		),
	),
	'ai'
);

$assert( 'recovered failed runtime tool does not fail final step', 'succeeded' === $result['status'] );
$assert( 'recovered failed runtime tool packet remains in audit packets', false === ( $result['packets'][0]['metadata']['tool_success'] ?? null ) );
$assert( 'recovered failed runtime tool diagnostics remain in packet history', 'file_too_large' === ( $result['packets'][0]['metadata']['tool_result_envelope']['code'] ?? '' ) );

echo "\n[7] step result envelope carries portable deterministic refs\n";
$result = StepExecutionResult::fromStepOutput(
	array(
		'status'        => 'succeeded',
		'outputs'       => array( 'post_id' => 123 ),
		'artifact_refs' => array(
			array(
				'type' => 'file',
				'uri'  => 'artifact://job/123/output.json',
			),
		),
		'packets'       => array(
			array(
				'type'     => 'source_item',
				'data'     => array( 'body' => 'ok' ),
				'metadata' => array(
					'source_type' => 'test',
					'source_id'   => 'abc',
				),
			),
		),
	),
	'publish'
);

$step_result = $result['step_result'];
$assert( 'step envelope schema version is canonical', StepResult::SCHEMA_VERSION === ( $step_result['schema_version'] ?? '' ) );
$assert( 'step envelope preserves non-packet outputs', 123 === ( $step_result['outputs']['post_id'] ?? null ) );
$assert( 'step envelope includes artifact refs', 'artifact://job/123/output.json' === ( $step_result['artifact_refs'][0]['uri'] ?? '' ) );
$assert( 'step envelope references packets by content hash', str_starts_with( (string) ( $step_result['packet_refs'][0]['content_hash'] ?? '' ), 'sha256:' ) );
$assert( 'step envelope records replay content hashes', str_starts_with( (string) ( $step_result['replay']['content_hashes']['packet_refs'] ?? '' ), 'sha256:' ) );

echo "\n[8] run result envelope aggregates step envelopes\n";
$run_result = RunResult::fromStepResults( array( $step_result ) );

$assert( 'run envelope schema version is canonical', RunResult::SCHEMA_VERSION === ( $run_result['schema_version'] ?? '' ) );
$assert( 'run envelope derives success from step envelopes', 'succeeded' === ( $run_result['status'] ?? '' ) );
$assert( 'run envelope aggregates packet refs', str_starts_with( (string) ( $run_result['packet_refs'][0]['content_hash'] ?? '' ), 'sha256:' ) );
$assert( 'run envelope records step replay hash', str_starts_with( (string) ( $run_result['replay']['content_hashes']['steps'] ?? '' ), 'sha256:' ) );

if ( $failures > 0 ) {
	echo "\n=== step-execution-result-contract-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== step-execution-result-contract-smoke: ALL PASS ({$total} assertions) ===\n";
