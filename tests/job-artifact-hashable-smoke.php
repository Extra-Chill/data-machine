<?php
/**
 * Smoke tests for hashable job transcript and tool trace artifacts.
 *
 * Run with: php tests/job-artifact-hashable-smoke.php
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

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ): string {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		return trim( (string) $value, '-' );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $value ): string {
		return trim( preg_replace( '/[^A-Za-z0-9_.-]+/', '-', (string) $value ), '-' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ): string {
		return (string) $value;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ): string {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0775, true );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$base = sys_get_temp_dir() . '/datamachine-job-artifact-smoke';
		return array(
			'basedir' => $base,
			'baseurl' => 'https://example.test/uploads',
		);
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}

use DataMachine\Core\JobArtifacts;

$failures = array();
$passes   = 0;

$assert_true = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		return;
	}

	$failures[] = $label;
};

$invoke = static function ( JobArtifacts $artifacts, string $method, array $args ) {
	$reflection = new ReflectionMethod( $artifacts, $method );
	return $reflection->invokeArgs( $artifacts, $args );
};

$artifacts = new JobArtifacts();

$message = $invoke(
	$artifacts,
	'normalize_transcript_message',
	array(
		array(
			'role'       => 'assistant',
			'content'    => 'Use Bearer sk-secret and token=plain-secret while calling the tool.',
			'tool_calls' => array(
				array(
					'id'       => 'call_normal_1',
					'type'     => 'function',
					'function' => array(
						'name'      => 'normal_tool',
						'arguments' => '{"query":"status","password":"super-secret"}',
					),
				),
			),
			'metadata'   => array(
				'source' => 'provider-response',
				'token'  => 'metadata-secret',
			),
		),
		0,
	)
);

$message_json = wp_json_encode( $message );
$assert_true( 'assistant' === $message['role'], 'transcript artifact preserves role' );
$assert_true( 'call_normal_1' === $message['tool_calls'][0]['id'], 'transcript artifact preserves tool-call id' );
$assert_true( 'normal_tool' === $message['tool_calls'][0]['name'], 'transcript artifact preserves tool-call name' );
$assert_true( isset( $message['content_sha256'] ) && 64 === strlen( $message['content_sha256'] ), 'transcript artifact hashes raw content' );
$assert_true( '[redacted]' === $message['tool_calls'][0]['arguments_redacted']['password'], 'transcript tool-call arguments redact secret keys' );
$assert_true( false === str_contains( $message_json, 'sk-secret' ), 'transcript content redacts bearer secrets' );
$assert_true( false === str_contains( $message_json, 'plain-secret' ), 'transcript content redacts token assignments' );
$assert_true( false === str_contains( $message_json, 'metadata-secret' ), 'transcript metadata redacts secret keys' );

$transcript_artifact = $invoke(
	$artifacts,
	'with_payload_hash',
	array(
		array(
			'schema_version'   => 1,
			'artifact_type'    => 'transcript',
			'artifact_ref'     => 'datamachine://jobs/123/artifacts/transcript/session-abc',
			'message_count'    => 1,
			'messages_emitted' => 1,
			'entries'          => array( $message ),
			'bounded'          => true,
			'redacted'         => true,
		)
	)
);

$normal_trace = array(
	'schema_version'     => 1,
	'tool_name'          => 'normal_tool',
	'tool_call_id'       => 'call_normal_1',
	'turn_count'         => 1,
	'actor'              => 'agent',
	'source'             => 'data_machine',
	'status'             => 'success',
	'started_at'         => '2026-05-27T00:00:00+00:00',
	'ended_at'           => '2026-05-27T00:00:01+00:00',
	'duration_ms'        => 1000,
	'arguments_sha256'   => str_repeat( 'a', 64 ),
	'result_sha256'      => str_repeat( 'b', 64 ),
	'arguments_redacted' => array( 'query' => 'status' ),
	'metadata'           => array( 'request_id' => 'req-normal' ),
);
$runtime_trace = array(
	'schema_version'     => 1,
	'tool_name'          => 'runtime_tool',
	'tool_call_id'       => 'call_runtime_1',
	'turn_count'         => 2,
	'actor'              => 'system',
	'source'             => 'runtime_tool',
	'status'             => 'success',
	'started_at'         => '2026-05-27T00:00:02+00:00',
	'ended_at'           => '2026-05-27T00:00:03+00:00',
	'duration_ms'        => 1000,
	'arguments_sha256'   => str_repeat( 'c', 64 ),
	'result_sha256'      => str_repeat( 'd', 64 ),
	'arguments_redacted' => array( 'api_key' => '[redacted]' ),
	'artifact_refs'      => array( 'stdout' => 'artifacts/stdout.txt' ),
	'metadata'           => array(
		'runtime_request' => array( 'id' => 'runtime-req-1' ),
		'runtime_result'  => array( 'status' => 'ok' ),
		'token'           => 'runtime-secret',
	),
);

$tool_trace_artifact = $invoke(
	$artifacts,
	'tool_trace_artifact',
	array(
		123,
		array(
			'status'       => 'completed',
			'user_id'      => 7,
			'pipeline_id'  => 8,
			'flow_id'      => 9,
			'flow_step_id' => 10,
		),
		array(
			'agent_id'   => 11,
			'agent_slug' => 'hash-agent',
		),
		array(
			'tool_execution_summary' => array(
				array(
					'success' => true,
					'trace'   => $normal_trace,
				),
			),
		),
		array(
			array(
				'success' => true,
				'trace'   => $runtime_trace,
			),
		),
	)
);

$artifact_json = wp_json_encode( $tool_trace_artifact );
$assert_true( 'tool_trace' === $tool_trace_artifact['artifact_type'], 'tool trace artifact is typed' );
$assert_true( str_starts_with( $tool_trace_artifact['artifact_ref'], 'datamachine://jobs/123/artifacts/tool-trace' ), 'tool trace artifact has stable ref' );
$assert_true( 2 === $tool_trace_artifact['trace_count'], 'tool trace artifact covers normal and runtime traces' );
$assert_true( 'call_normal_1' === $tool_trace_artifact['entries'][0]['tool_call_id'], 'normal tool-call id is preserved' );
$assert_true( 'call_runtime_1' === $tool_trace_artifact['entries'][1]['tool_call_id'], 'runtime tool-call id is preserved' );
$assert_true( 'runtime_tool' === $tool_trace_artifact['entries'][1]['source'], 'runtime tool source is preserved' );
$assert_true( 'runtime-req-1' === $tool_trace_artifact['entries'][1]['metadata']['runtime_request']['id'], 'runtime request metadata is preserved' );
$assert_true( 'ok' === $tool_trace_artifact['entries'][1]['metadata']['runtime_result']['status'], 'runtime result metadata is preserved' );
$assert_true( isset( $tool_trace_artifact['sha256'] ) && 64 === strlen( $tool_trace_artifact['sha256'] ), 'tool trace artifact has stable payload hash' );
$assert_true( isset( $tool_trace_artifact['entries'][1]['entry_sha256'] ) && 64 === strlen( $tool_trace_artifact['entries'][1]['entry_sha256'] ), 'tool trace entries have stable hashes' );
$assert_true( false === str_contains( $artifact_json, 'runtime-secret' ), 'tool trace artifact redacts metadata secrets' );

$refs = $invoke( $artifacts, 'hashable_artifact_refs', array( null, $tool_trace_artifact ) );
$assert_true( $tool_trace_artifact['sha256'] === $refs['tool_trace']['sha256'], 'artifact refs expose tool trace hash' );
$assert_true( $tool_trace_artifact['artifact_ref'] === $refs['tool_trace']['artifact_ref'], 'artifact refs expose stable tool trace ref' );

$file_result = $invoke( $artifacts, 'write_artifact_file', array( 123, 'tool_trace', $tool_trace_artifact ) );
$assert_true( true === ( $file_result['success'] ?? false ), 'tool trace artifact file write succeeds' );
$assert_true( is_file( $file_result['file']['path'] ?? '' ), 'tool trace artifact file exists on disk' );
$assert_true( 'datamachine-artifacts/jobs/123/tool-trace.json' === ( $file_result['file']['relative_path'] ?? '' ), 'artifact file has stable relative path' );
$assert_true( $tool_trace_artifact['sha256'] === ( $file_result['file']['payload_sha256'] ?? '' ), 'artifact file references payload hash' );
$assert_true( hash_file( 'sha256', $file_result['file']['path'] ) === ( $file_result['file']['sha256'] ?? '' ), 'artifact file hash matches written bytes' );

$transcript_file_result = $invoke( $artifacts, 'write_artifact_file', array( 123, 'transcript', $transcript_artifact ) );
$assert_true( true === ( $transcript_file_result['success'] ?? false ), 'transcript artifact file write succeeds' );
$assert_true( is_file( $transcript_file_result['file']['path'] ?? '' ), 'transcript artifact file exists on disk' );
$assert_true( 'datamachine-artifacts/jobs/123/transcript.json' === ( $transcript_file_result['file']['relative_path'] ?? '' ), 'transcript artifact file has stable relative path' );
$assert_true( $transcript_artifact['sha256'] === ( $transcript_file_result['file']['payload_sha256'] ?? '' ), 'transcript artifact file references payload hash' );

$artifact_files = $invoke(
	$artifacts,
	'artifact_files_metadata',
	array(
		array(
			'artifact_files' => array(
				'transcript' => $transcript_file_result['file'],
				'tool_trace' => $file_result['file'],
			),
		)
	)
);
$assert_true( $transcript_file_result['file']['relative_path'] === ( $artifact_files['transcript']['relative_path'] ?? '' ), 'engine artifact file metadata round-trips transcript relative path' );
$assert_true( $file_result['file']['relative_path'] === ( $artifact_files['tool_trace']['relative_path'] ?? '' ), 'engine artifact file metadata round-trips relative path' );
$assert_true( $file_result['file']['sha256'] === ( $artifact_files['tool_trace']['sha256'] ?? '' ), 'engine artifact file metadata round-trips file hash' );

if ( $failures ) {
	echo "FAILED: " . count( $failures ) . " hashable artifact assertions failed.\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "Hashable job artifact smoke passed ({$passes} assertions).\n";
