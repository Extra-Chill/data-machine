<?php
/**
 * Pure-PHP contract tests for bounded raw test-handler output (#2946).
 *
 * Run with: php tests/test-handler-raw-output-smoke.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/fixtures/namespaced-wp-fn-stubs.php';
require_once dirname( __DIR__ ) . '/inc/Core/DataPacket.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/Handler/TestHandlerAbility.php';

use DataMachine\Abilities\Handler\TestHandlerAbility;
use DataMachine\Core\DataPacket;

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

echo "=== test-handler-raw-output-smoke ===\n";

$reflection = new ReflectionClass( TestHandlerAbility::class );
$ability    = $reflection->newInstanceWithoutConstructor();
$summarize  = $reflection->getMethod( 'summarizePacket' );
$format_raw = $reflection->getMethod( 'formatRawPackets' );

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

$json_body = '{"name":"Venue","nested":{"doors":"19:00"}}';
$html_body = '<article><h1>Show</h1><p>Doors at 7 &amp; music at 8.</p></article>';
$packets   = array(
	new DataPacket( array( 'title' => 'JSON', 'body' => $json_body ), array(), 'fetch' ),
	new DataPacket( array( 'title' => 'HTML', 'body' => $html_body ), array(), 'fetch' ),
);
$raw       = $format_raw->invoke( $ability, $packets, 5, 10000 );

assert_test_handler_raw( 'valid JSON body round-trips as the original string', $json_body === $raw['packets'][0]['data']['body'] );
assert_test_handler_raw( 'raw HTML round-trips as the original string', $html_body === $raw['packets'][1]['data']['body'] );
assert_test_handler_raw( 'unbounded result reports no truncation', false === $raw['truncation']['truncated'] );

$binary = new DataPacket( array( 'body' => "image\0bytes" ), array( 'api_token' => 'secret-value' ), 'fetch' );
$raw    = $format_raw->invoke( $ability, array( $binary ), 5, 10000 );

assert_test_handler_raw( 'binary content follows omission policy', '[binary omitted]' === $raw['packets'][0]['data']['body'] );
assert_test_handler_raw( 'secret-bearing fields are redacted', '[redacted]' === $raw['packets'][0]['metadata']['api_token'] );
assert_test_handler_raw( 'binary and redacted paths are declared', ! empty( $raw['truncation']['binary_fields'] ) && ! empty( $raw['truncation']['redacted_fields'] ) );

$secret_json = new DataPacket( array( 'body' => '{"event":"Show","api_token":"secret-value"}' ), array(), 'fetch' );
$raw         = $format_raw->invoke( $ability, array( $secret_json ), 5, 10000 );
$decoded     = json_decode( $raw['packets'][0]['data']['body'], true );
assert_test_handler_raw( 'redacted JSON remains complete valid JSON', 'Show' === $decoded['event'] && '[redacted]' === $decoded['api_token'] );

$oversized = new DataPacket( array( 'body' => str_repeat( 'x', 500 ) ), array(), 'fetch' );
$raw       = $format_raw->invoke( $ability, array( $oversized ), 5, 100 );

assert_test_handler_raw( 'oversized packet is omitted rather than cut', array() === $raw['packets'] );
assert_test_handler_raw( 'byte cap reports explicit truncation', in_array( 'byte_limit', $raw['truncation']['reasons'], true ) && 1 === $raw['truncation']['omitted_packet_count'] );

$raw = $format_raw->invoke( $ability, $packets, 1, 10000 );
assert_test_handler_raw( 'packet cap returns only complete packets', 1 === count( $raw['packets'] ) );
assert_test_handler_raw( 'packet cap reports explicit truncation', in_array( 'packet_limit', $raw['truncation']['reasons'], true ) && 1 === $raw['truncation']['omitted_packet_count'] );

$command = file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/TestCommand.php' ) ?: '';
assert_test_handler_raw( 'CLI adapter maps --raw to output_mode', str_contains( $command, "\$input['output_mode'] = 'raw';" ) );
assert_test_handler_raw( 'CLI adapter forwards --byte-limit', str_contains( $command, "\$input['byte_limit'] = \$byte_limit;" ) );
assert_test_handler_raw( 'CLI adapter renders raw mode as JSON', str_contains( $command, "\$raw && 'table' === \$format" ) );

$ability_source = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Handler/TestHandlerAbility.php' ) ?: '';
assert_test_handler_raw( 'ability schema declares raw mode and truncation contract', str_contains( $ability_source, "'output_mode'" ) && str_contains( $ability_source, "'truncation'" ) && str_contains( $ability_source, "'byte_limit'" ) );
assert_test_handler_raw( 'direct execution remains lifecycle-read-only', str_contains( $ability_source, "get_fetch_data( 'direct', \$config, null )" ) );

if ( $failed > 0 ) {
	echo "\ntest-handler-raw-output-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\ntest-handler-raw-output-smoke passed: {$total} assertions.\n";
