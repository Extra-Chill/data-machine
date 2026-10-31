<?php
/**
 * Smoke tests for generic tool trace metadata preservation.
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}

require_once __DIR__ . '/../inc/Engine/AI/RuntimeProvenance.php';
require_once __DIR__ . '/../inc/Engine/AI/conversation-loop.php';

use DataMachine\Engine\AI\RuntimeProvenance;

function assert_tool_trace_metadata( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( ! $condition ) {
		$failures[] = $message;
		return;
	}
	++$passes;
}

$failures = array();
$passes   = 0;

$trace = DataMachine\Engine\AI\datamachine_build_tool_trace(
	'terminal_grader',
	array( 'id' => 'call_123' ),
	array(
		'command' => 'php grader.php',
		'token'   => 'secret-token',
	),
	array(
		'success'            => true,
		'message'            => 'Grader completed.',
		'trace_metadata'     => array(
			'actor'             => 'system',
			'source'            => 'terminal',
			'structured_output' => array( 'passed' => true, 'score' => 1 ),
			'artifact_refs'     => array( 'stdout' => 'artifacts/stdout.txt' ),
		),
		'execution_metadata' => array( 'runner' => 'terminal' ),
	),
	array(),
	2,
	1000.0,
	1000.250
);

assert_tool_trace_metadata( 'terminal_grader' === $trace['tool_name'], 'trace keeps tool name', $failures, $passes );
assert_tool_trace_metadata( 'call_123' === $trace['tool_call_id'], 'trace keeps provider tool call id', $failures, $passes );
assert_tool_trace_metadata( 'system' === $trace['actor'], 'trace preserves system actor distinction', $failures, $passes );
assert_tool_trace_metadata( 'terminal' === $trace['source'], 'trace preserves generic terminal source', $failures, $passes );
assert_tool_trace_metadata( 'success' === $trace['status'], 'trace records result status', $failures, $passes );
assert_tool_trace_metadata( 250 === $trace['duration_ms'], 'trace records duration in milliseconds', $failures, $passes );
assert_tool_trace_metadata( '[redacted]' === $trace['arguments_redacted']['token'], 'trace redacts secret-looking argument keys', $failures, $passes );
assert_tool_trace_metadata( true === $trace['metadata']['structured_output']['passed'], 'trace preserves structured execution output generically', $failures, $passes );
assert_tool_trace_metadata( 'terminal' === $trace['metadata']['runner'], 'trace preserves generic execution metadata', $failures, $passes );
assert_tool_trace_metadata( 'artifacts/stdout.txt' === $trace['artifact_refs']['stdout'], 'trace preserves artifact refs', $failures, $passes );

$large_trace = DataMachine\Engine\AI\datamachine_build_tool_trace(
	'manage_github_issue',
	array( 'id' => 'call_large' ),
	array(
		'action'       => 'comment',
		'repo'         => 'owner/repo',
		'issue_number' => 123,
		'body'         => str_repeat( 'Large body. ', 400 ),
	),
	array( 'success' => false, 'error' => 'GitHub API error (403): Resource not accessible by integration' ),
	array(),
	1,
	1000.0,
	1000.1
);

assert_tool_trace_metadata( 'redacted_arguments_too_large' === $large_trace['arguments_omitted'], 'large trace marks omitted full arguments', $failures, $passes );
assert_tool_trace_metadata( 'comment' === $large_trace['arguments_redacted']['action'], 'large trace keeps bounded action argument', $failures, $passes );
assert_tool_trace_metadata( 'owner/repo' === $large_trace['arguments_redacted']['repo'], 'large trace keeps bounded repo argument', $failures, $passes );
assert_tool_trace_metadata( 123 === $large_trace['arguments_redacted']['issue_number'], 'large trace keeps bounded issue number argument', $failures, $passes );
assert_tool_trace_metadata( strlen( $large_trace['arguments_redacted']['body'] ) <= 240, 'large trace truncates long string arguments', $failures, $passes );

$summary = DataMachine\Engine\AI\datamachine_summarize_tool_execution_results(
	array(
		array(
			'tool_name'  => 'terminal_grader',
			'result'     => array( 'success' => true, 'message' => 'Grader completed.' ),
			'parameters' => array(),
			'trace'      => $trace,
			'turn_count' => 2,
		),
	)
);

assert_tool_trace_metadata( $trace === $summary[0]['trace'], 'tool execution summary preserves trace metadata', $failures, $passes );

$provenance = RuntimeProvenance::fromConversationResult(
	array(
		'messages'               => array(),
		'completed'              => true,
		'tool_execution_results' => array(
			array(
				'tool_name'  => 'terminal_grader',
				'result'     => array( 'success' => true ),
				'parameters' => array(),
				'trace'      => $trace,
				'turn_count' => 2,
			),
		),
		'request_metadata'       => array( 'tool_policy_sha256' => 'abc123' ),
	),
	array(),
	'openai',
	'gpt-5.5',
	array( 'pipeline' )
);

assert_tool_trace_metadata( $trace === $provenance['tool_trace'][0], 'runtime provenance exports tool trace', $failures, $passes );
assert_tool_trace_metadata( 'abc123' === $provenance['tools']['policy_sha256'], 'runtime provenance still exposes tool policy fingerprint', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "FAILED: " . count( $failures ) . " tool trace metadata assertions failed.\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "Tool trace metadata smoke passed ({$passes} assertions).\n";
