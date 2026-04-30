<?php
/**
 * Pure-PHP smoke test for the wp-ai-client runtime gate.
 *
 * Run with: php tests/wp-ai-client-runtime-gate-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['datamachine_test_filters'] = array();
$GLOBALS['datamachine_test_logs']    = array();

function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['datamachine_test_filters'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( string $tag, $value, ...$args ) {
	$callbacks = $GLOBALS['datamachine_test_filters'][ $tag ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $entry ) {
			$callback      = $entry[0];
			$accepted_args = $entry[1];
			$value         = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}
	return $value;
}

function do_action( string $tag, ...$args ): void {
	if ( 'datamachine_log' === $tag ) {
		$GLOBALS['datamachine_test_logs'][] = $args;
	}
}

function did_action( string $hook = '' ): int {
	return 0;
}

function doing_action( string $hook = '' ): bool {
	return false;
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	// no-op
}

function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags );
}

function size_format( $bytes ): string {
	return $bytes . ' B';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/agents-api/agents-api.php';

use DataMachine\Engine\AI\RequestBuilder;
use DataMachine\Engine\AI\WpAiClientCapability;

$failures = array();

function assert_smoke( bool $condition, string $label, string $detail = '' ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo 'FAIL: ' . $label . ( '' !== $detail ? ' - ' . $detail : '' ) . "\n";
	$failures[] = $label;
}

function smoke_failure_count(): int {
	global $failures;
	return count( $failures );
}

$dispatches = 0;
add_filter(
	'chubes_ai_request',
	function ( array $request ) use ( &$dispatches ): array {
		++$dispatches;
		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'legacy fallback should not run',
				'tool_calls' => array(),
			),
		);
	},
	10,
	1
);

$reason = WpAiClientCapability::unavailableReason( 'openai' );
assert_smoke( is_string( $reason ) && str_contains( $reason, 'wp-ai-client is unavailable' ), 'missing wp-ai-client produces a capability-gate reason', (string) $reason );
assert_smoke( is_string( WpAiClientCapability::unavailableReason( 'openai' ) ), 'gate reason remains stable on repeated calls' );

$response = RequestBuilder::build(
	array(
		array(
			'role'    => 'user',
			'content' => 'hello',
		),
	),
	'openai',
	'gpt-smoke',
	array(),
	'pipeline',
	array( 'job_id' => 1633 )
);

assert_smoke( 0 === $dispatches, 'RequestBuilder does not dispatch chubes_ai_request fallback' );
assert_smoke( false === ( $response['success'] ?? true ), 'RequestBuilder returns structured failure when wp-ai-client is missing' );
assert_smoke( isset( $response['request_metadata']['request_json_bytes'] ), 'blocked request still carries request metadata' );
assert_smoke( str_contains( (string) ( $response['error'] ?? '' ), 'wp-ai-client is unavailable' ), 'blocked request error names wp-ai-client gate', (string) ( $response['error'] ?? '' ) );

$request_builder_source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Engine/AI/RequestBuilder.php' );
$capability_source      = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Engine/AI/WpAiClientCapability.php' );

assert_smoke( false === str_contains( $request_builder_source, "'chubes_ai_request'" ), 'RequestBuilder source has no chubes_ai_request dispatch' );
assert_smoke( false === str_contains( $request_builder_source, 'Legacy path: ai-http-client' ), 'RequestBuilder source has no legacy provider path comment' );
assert_smoke( str_contains( $request_builder_source, 'WpAiClientCapability::unavailableReason' ), 'RequestBuilder uses the capability-gate helper' );
assert_smoke( str_contains( $capability_source, 'public static function unavailableReason' ), 'single capability-gate helper exists' );

echo "\n" . smoke_failure_count() . " failures\n";
if ( smoke_failure_count() > 0 ) {
	exit( 1 );
}
