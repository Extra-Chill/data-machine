<?php
/**
 * Pure-PHP behavioral tests for bounded raw test-handler output (#2946).
 *
 * Run with: php tests/test-handler-raw-output-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/fixtures/test-handler-wp-fn-stubs.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityRegistration.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/HandlerAbilities.php';
require_once dirname( __DIR__ ) . '/inc/Core/DataPacket.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/Handler/TestHandlerAbility.php';

use DataMachine\Abilities\Handler\TestHandlerAbility;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\DataPacket;

class TestHandlerRawFailureStub {
	public static array $received_config = array();

	public function get_fetch_data( $pipeline_id, array $config, $job_id ): array {
		self::$received_config = $config;
		$credential_values     = array_intersect_key( $config, array_flip( array( 'imap_password', 'api_secret', 'access_token_v2' ) ) );
		throw new RuntimeException( 'Request failed with credentials ' . implode( ' ', $credential_values ) );
	}
}

$failed = 0;
$total  = 0;

function assert_test_handler_raw( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}\n";
}

function invoke_test_handler_private( object $ability, string $method, array $args ) {
	$reflection = new ReflectionMethod( $ability, $method );
	return $reflection->invokeArgs( $ability, $args );
}

function sanitize_test_handler_value( object $ability, $value, int $budget = 65536 ): array {
	$report = invoke_test_handler_private( $ability, 'newSanitizationReport', array() );
	$output = null;
	$status = invoke_test_handler_private( $ability, 'sanitizeBoundedValue', array( $value, 'value', &$budget, &$report, &$output ) );
	return compact( 'status', 'output', 'report', 'budget' );
}

function build_test_handler_raw( object $ability, array $packets, int $packet_limit, int $byte_limit, array $config = array() ): array {
	$sanitized = sanitize_test_handler_value( $ability, $config, min( 65536, intdiv( $byte_limit, 4 ) ) );
	if ( 'ok' !== $sanitized['status'] ) {
		$sanitized['output'] = array( '_omitted' => 'byte_limit' );
		invoke_test_handler_private( $ability, 'recordOmission', array( &$sanitized['report'], 'config', 'config_limit' ) );
	}
	$base = array(
		'success'           => true,
		'handler_slug'      => 'events-shaped',
		'handler_label'     => 'Events Shaped',
		'config_used'       => $sanitized['output'],
		'execution_time_ms' => 1.2,
		'output_mode'       => 'raw',
	);
	return invoke_test_handler_private( $ability, 'buildRawResponse', array( $packets, $packet_limit, $byte_limit, $base, $sanitized['report'], true ) );
}

function test_handler_transport_size( array $response ): int {
	$rest = json_encode( $response );
	$cli  = json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	return max( strlen( $rest ), strlen( $cli ) + 1 );
}

echo "=== test-handler-raw-output-smoke ===\n";

$reflection = new ReflectionClass( TestHandlerAbility::class );
$ability    = $reflection->newInstanceWithoutConstructor();
$summarize  = $reflection->getMethod( 'summarizePacket' );

$long_body = str_repeat( 'a', 220 );
$packet    = new DataPacket(
	array(
		'title' => 'Preview',
		'body'  => $long_body,
	),
	array( 'source_url' => 'https://example.com/item' ),
	'fetch'
);
$summary   = $summarize->invoke( $ability, $packet );
assert_test_handler_raw( 'default summary retains compact keys', array( 'title', 'content_preview', 'metadata', 'source_url' ) === array_keys( $summary ) );
assert_test_handler_raw( 'default summary retains 200-character preview behavior', str_repeat( 'a', 200 ) . '...' === $summary['content_preview'] );

$event_json = '{"title":"Token Tuesday","venue":{"name":"The Secretary"},"author":"Token Adams"}';
$event_html = '<article data-token="ordinary prose"><h1>Secretary Live</h1></article>';
$packets    = array(
	new DataPacket(
		array(
			'title' => 'Events-shaped JSON',
			'body'  => $event_json,
			'venue' => array(
				'name' => 'The Royal American',
				'city' => 'Charleston',
			),
		),
		array(
			'source_url' => 'https://events.example/show',
			'event_id'   => 42,
		),
		'fetch'
	),
	new DataPacket(
		array(
			'title' => 'HTML',
			'body'  => $event_html,
		),
		array(),
		'fetch'
	),
	new DataPacket(
		array(
			'title'           => 'Production credentials',
			'imap_password'   => 'packet-imap-secret',
			'api_secret'      => 'packet-api-secret',
			'access_token_v2' => 'packet-access-token',
			'clientSecret'    => 'packet-client-secret',
		),
		array( 'authorization_header' => 'packet-authorization' ),
		'fetch'
	),
);
$raw        = build_test_handler_raw( $ability, $packets, 5, 12000 );

assert_test_handler_raw( 'events-shaped JSON body round-trips byte-for-byte', $event_json === $raw['packets'][0]['data']['body'] );
assert_test_handler_raw( 'raw HTML and ordinary Token prose remain unchanged', $event_html === $raw['packets'][1]['data']['body'] );
assert_test_handler_raw( 'events-shaped structured venue metadata is retained', 'Charleston' === $raw['packets'][0]['data']['venue']['city'] );
assert_test_handler_raw( 'production packet credential keys are redacted', '[redacted]' === $raw['packets'][2]['data']['imap_password'] && '[redacted]' === $raw['packets'][2]['data']['api_secret'] && '[redacted]' === $raw['packets'][2]['data']['access_token_v2'] && '[redacted]' === $raw['packets'][2]['data']['clientSecret'] && '[redacted]' === $raw['packets'][2]['metadata']['authorization_header'] );
assert_test_handler_raw( 'production packet credential values never serialize', ! str_contains( json_encode( $raw ), 'packet-imap-secret' ) && ! str_contains( json_encode( $raw ), 'packet-api-secret' ) && ! str_contains( json_encode( $raw ), 'packet-access-token' ) && ! str_contains( json_encode( $raw ), 'packet-client-secret' ) && ! str_contains( json_encode( $raw ), 'packet-authorization' ) );
assert_test_handler_raw( 'complete response byte count is exact and within limit', test_handler_transport_size( $raw ) === $raw['truncation']['returned_bytes'] && $raw['truncation']['returned_bytes'] <= 12000 );

$nested = array();
for ( $index = 0; $index < 35; ++$index ) {
	$nested[ 'item_' . $index ] = array(
		'venue' => 'Royal American',
		'city'  => 'Charleston',
		'date'  => '2026-08-01',
	);
}
$nested_packet = new DataPacket(
	array(
		'title' => 'Nested expansion',
		'body'  => $nested,
	),
	array(),
	'fetch'
);
$nested_probe  = build_test_handler_raw( $ability, array( $nested_packet ), 5, 65536 );
$nested_min    = strlen( json_encode( $nested_probe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
$nested_pretty = strlen( json_encode( $nested_probe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) + 1;
assert_test_handler_raw( 'nested probe reproduces minified-under/pretty-over transport expansion', $nested_min < 4096 && $nested_pretty > 4096 );
$nested_bounded = build_test_handler_raw( $ability, array( $nested_packet ), 5, 4096 );
assert_test_handler_raw( 'nested probe emitted response fits both actual transports', test_handler_transport_size( $nested_bounded ) <= 4096 && array() === $nested_bounded['packets'] );

$emoji_packet = new DataPacket(
	array(
		'title' => 'Emoji expansion',
		'body'  => str_repeat( '🥶', 300 ),
	),
	array(),
	'fetch'
);
$emoji_probe  = build_test_handler_raw( $ability, array( $emoji_packet ), 5, 12000 );
$emoji_min    = strlen( json_encode( $emoji_probe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
$emoji_rest   = strlen( json_encode( $emoji_probe ) );
assert_test_handler_raw( '300-emoji probe reproduces canonical-under/REST-over expansion', $emoji_min < 4096 && $emoji_rest > 4096 );
$emoji_bounded = build_test_handler_raw( $ability, array( $emoji_packet ), 5, 4096 );
assert_test_handler_raw( '300-emoji probe emitted response fits REST and CLI transports', test_handler_transport_size( $emoji_bounded ) <= 4096 && array() === $emoji_bounded['packets'] );

$structured = sanitize_test_handler_value(
	$ability,
	array(
		'api_key'         => 'credential-value',
		'imap_password'   => 'imap-value',
		'api_secret'      => 'secret-value',
		'access_token_v2' => 'token-value',
		'clientSecret'    => 'client-secret-value',
		'author'          => 'Token Adams',
		'secretary'       => 'The Secretary',
		'note'            => 'Token prose is legitimate event content.',
	)
);
assert_test_handler_raw( 'exact credential key is redacted', '[redacted]' === $structured['output']['api_key'] );
assert_test_handler_raw( 'compound and camel-case credential keys are redacted', '[redacted]' === $structured['output']['imap_password'] && '[redacted]' === $structured['output']['api_secret'] && '[redacted]' === $structured['output']['access_token_v2'] && '[redacted]' === $structured['output']['clientSecret'] );
assert_test_handler_raw( 'author and secretary false positives are retained', 'Token Adams' === $structured['output']['author'] && 'The Secretary' === $structured['output']['secretary'] );
assert_test_handler_raw( 'ordinary Token prose is never rewritten', 'Token prose is legitimate event content.' === $structured['output']['note'] );

$config_raw = build_test_handler_raw(
	$ability,
	array(),
	5,
	12000,
	array(
		'imap_password'   => 'config-imap-secret',
		'api_secret'      => 'config-api-secret',
		'access_token_v2' => 'config-access-token',
	)
);
assert_test_handler_raw( 'successful raw config redacts production credential keys', '[redacted]' === $config_raw['config_used']['imap_password'] && '[redacted]' === $config_raw['config_used']['api_secret'] && '[redacted]' === $config_raw['config_used']['access_token_v2'] );
assert_test_handler_raw( 'successful raw config never serializes production credential values', ! str_contains( json_encode( $config_raw ), 'config-imap-secret' ) && ! str_contains( json_encode( $config_raw ), 'config-api-secret' ) && ! str_contains( json_encode( $config_raw ), 'config-access-token' ) );

$resource = fopen( 'php://memory', 'r' );
$unsafe   = sanitize_test_handler_value(
	$ability,
	array(
		'binary'       => "image\0bytes",
		'invalid'      => "bad\xB1utf8",
		'resource'     => $resource,
		'object'       => new stdClass(),
		'not_a_number' => INF,
		"bad\xB1key"   => 'value',
	)
);
fclose( $resource );
assert_test_handler_raw( 'binary, invalid UTF-8, resources, and objects are omitted', ! isset( $unsafe['output']['binary'], $unsafe['output']['invalid'], $unsafe['output']['resource'], $unsafe['output']['object'] ) );
assert_test_handler_raw( 'invalid keys and json_encode failures are omitted', ! isset( $unsafe['output']['not_a_number'] ) && 0 === count( array_filter( array_keys( $unsafe['output'] ), static fn ( $key ) => ! mb_check_encoding( (string) $key, 'UTF-8' ) ) ) );
assert_test_handler_raw( 'all unsafe omissions are explicitly reported', $unsafe['report']['omitted_field_count'] >= 6 && in_array( 'binary_content', $unsafe['report']['reasons'], true ) && in_array( 'invalid_utf8', $unsafe['report']['reasons'], true ) && in_array( 'unsupported_type', $unsafe['report']['reasons'], true ) && in_array( 'json_encode_failure', $unsafe['report']['reasons'], true ) );
assert_test_handler_raw( 'sanitized unsafe output always serializes', false !== json_encode( $unsafe['output'] ) );

$multibyte = new DataPacket(
	array(
		'title' => 'Emoji',
		'body'  => str_repeat( '🥶', 900 ),
	),
	array(),
	'fetch'
);
$raw       = build_test_handler_raw( $ability, array( $multibyte ), 5, 4096 );
assert_test_handler_raw( 'multibyte packet is omitted whole at the boundary', array() === $raw['packets'] && in_array( 'byte_limit', $raw['truncation']['reasons'], true ) );
assert_test_handler_raw( 'multibyte truncation response remains valid UTF-8 JSON under the complete-response cap', false !== json_encode( $raw, JSON_UNESCAPED_UNICODE ) && test_handler_transport_size( $raw ) <= 4096 );

$oversized = new DataPacket( array( 'body' => str_repeat( 'x', 20000 ) ), array(), 'fetch' );
$raw       = build_test_handler_raw( $ability, array( $oversized ), 5, 4096, array( 'description' => str_repeat( 'c', 5000 ) ) );
assert_test_handler_raw( 'oversized packet and config are omitted rather than partially copied', array() === $raw['packets'] && isset( $raw['config_used']['_omitted'] ) );
assert_test_handler_raw( 'full response cap reports config and packet omissions', in_array( 'config_limit', $raw['truncation']['reasons'], true ) && in_array( 'byte_limit', $raw['truncation']['reasons'], true ) );

$huge_body = str_repeat( 'z', 8 * 1024 * 1024 );
$huge      = new DataPacket( array( 'body' => $huge_body ), array(), 'fetch' );
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$memory_before = memory_get_usage( true );
$raw           = build_test_handler_raw( $ability, array( $huge ), 5, 4096 );
$peak_delta    = memory_get_peak_usage( true ) - $memory_before;
assert_test_handler_raw( 'oversized body is rejected before full encoding or copying', array() === $raw['packets'] && $peak_delta < 4 * 1024 * 1024 );
unset( $huge_body, $huge );

$raw = build_test_handler_raw( $ability, $packets, 1, 12000 );
assert_test_handler_raw( 'packet cap returns only complete packets', 1 === count( $raw['packets'] ) );
assert_test_handler_raw( 'packet cap and materialization bound are explicit', in_array( 'packet_limit', $raw['truncation']['reasons'], true ) && true === $raw['truncation']['materialization_limited'] );

$failure_handlers       = array(
	'failure-stub' => array(
		'label' => 'Failure Stub',
		'class' => TestHandlerRawFailureStub::class,
	),
);
$failure_handler_filter = static function ( array $handlers ) use ( $failure_handlers ): array {
	return array_merge( $handlers, $failure_handlers );
};
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'datamachine_handlers', $failure_handler_filter, 10, 2 );
	HandlerAbilities::clearCache();
} else {
	$GLOBALS['datamachine_test_handlers'] = $failure_handlers;
}
$failure = $ability->execute(
	array(
		'handler_slug' => 'failure-stub',
		'config'       => array(
			'imap_password'   => 'failure-imap-secret',
			'api_secret'      => 'failure-api-secret',
			'access_token_v2' => 'failure-access-token',
		),
		'output_mode'  => 'raw',
	)
);
assert_test_handler_raw( 'handler receives real production credentials required for execution', 'failure-imap-secret' === TestHandlerRawFailureStub::$received_config['imap_password'] && 'failure-api-secret' === TestHandlerRawFailureStub::$received_config['api_secret'] && 'failure-access-token' === TestHandlerRawFailureStub::$received_config['access_token_v2'] );
assert_test_handler_raw( 'raw execution bounds handler materialization through max_items', 5 === TestHandlerRawFailureStub::$received_config['max_items'] );
assert_test_handler_raw( 'failure config compound credentials are sanitized before output is built', '[redacted]' === $failure['config_used']['imap_password'] && '[redacted]' === $failure['config_used']['api_secret'] && '[redacted]' === $failure['config_used']['access_token_v2'] );
assert_test_handler_raw( 'failure message cannot echo applied credentials', 'Handler execution failed.' === $failure['error'] && ! str_contains( json_encode( $failure ), 'failure-imap-secret' ) && ! str_contains( json_encode( $failure ), 'failure-api-secret' ) && ! str_contains( json_encode( $failure ), 'failure-access-token' ) );

$bounded_failure = $ability->execute(
	array(
		'handler_slug' => 'failure-stub',
		'config'       => array(
			'description' => str_repeat( 'x', 5000 ),
			'api_key'     => 'later-credential',
		),
		'output_mode'  => 'raw',
		'byte_limit'   => 4096,
	)
);
assert_test_handler_raw( 'oversized failure config is replaced before handler execution returns', 'byte_limit' === $bounded_failure['config_used']['_omitted'] );
assert_test_handler_raw( 'failure stays credential-safe when a secret follows oversized config', 'Handler execution failed.' === $bounded_failure['error'] && ! str_contains( json_encode( $bounded_failure ), 'later-credential' ) );

if ( function_exists( 'remove_filter' ) ) {
	remove_filter( 'datamachine_handlers', $failure_handler_filter, 10 );
	HandlerAbilities::clearCache();
}

$command = file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/TestCommand.php' ) ?: '';
assert_test_handler_raw( 'CLI adapter maps --raw and --byte-limit', str_contains( $command, "\$input['output_mode'] = 'raw';" ) && str_contains( $command, "\$input['byte_limit'] = \$byte_limit;" ) );
assert_test_handler_raw( 'every explicit raw CLI format emits JSON', str_contains( $command, "if ( \$raw ) {\n\t\t\t\$format = 'json';" ) );

$ability_source = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Handler/TestHandlerAbility.php' ) ?: '';
assert_test_handler_raw( 'schema discriminates failure, compact, and raw output', str_contains( $ability_source, "'oneOf'" ) && str_contains( $ability_source, "array( 'compact' )" ) && str_contains( $ability_source, "array( 'raw' )" ) );
assert_test_handler_raw( 'input schema remains extensible', ! str_contains( substr( $ability_source, strpos( $ability_source, "'input_schema'" ), 500 ), "'additionalProperties' => false" ) );
assert_test_handler_raw( 'direct execution remains lifecycle-read-only', str_contains( $ability_source, "get_fetch_data( 'direct', \$config, null )" ) );

if ( $failed > 0 ) {
	echo "\ntest-handler-raw-output-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\ntest-handler-raw-output-smoke passed: {$total} assertions.\n";
