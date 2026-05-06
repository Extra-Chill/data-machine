<?php
/**
 * Pure-PHP smoke coverage for AI DataPacket prompt projection (#1799).
 *
 * Run with: php tests/ai-packet-projection-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function wp_json_encode( $value, int $flags = 0 ) {
	return json_encode( $value, $flags );
}

require_once __DIR__ . '/../inc/Engine/AI/DataPacketPromptProjector.php';

$failed = 0;
$total  = 0;

function assert_projection( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] $name\n";
		return;
	}

	echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
	++$failed;
}

echo "AI DataPacket prompt projection smoke\n";

$raw_item = array(
	'id'               => 'mgs-624',
	'title'            => 'Data Download, April 14, 2026',
	'url'              => 'https://example.com/a8c/post',
	'date'             => '2026-04-14T12:00:00Z',
	'author'           => 'Chris',
	'matching_content' => 'Useful <em>highlight</em> for the model.',
	'tags'             => array( 'mgs', 'history' ),
);

$canonical = array(
	array(
		'type'      => 'fetch',
		'timestamp' => 1770000000,
		'data'      => array(
			'title' => 'Wrapped MGS item',
			'body'  => wp_json_encode( $raw_item, JSON_UNESCAPED_UNICODE ),
		),
		'metadata'  => array(
			'source_type'     => 'mcp',
			'pipeline_id'     => 3,
			'flow_id'         => 2,
			'handler'         => 'mcp_fetch',
			'mcp_provider'    => 'WordPress.com MGS',
			'mcp_server'      => 'wordpress-com',
			'mcp_tool'        => 'search',
			'mcp_url'         => 'https://example.com/a8c/post',
			'mcp_raw_item'    => $raw_item,
			'item_identifier' => 'mgs-624',
		),
	),
);

$canonical_before = $canonical;
$projected        = \DataMachine\Engine\AI\DataPacketPromptProjector::project( $canonical );

assert_projection( 'canonical packet unchanged after projection', $canonical_before === $canonical );
assert_projection( 'MGS title flattened from source body', 'Data Download, April 14, 2026' === ( $projected[0]['data']['title'] ?? '' ) );
assert_projection( 'snippet strips em highlight tags', 'Useful highlight for the model.' === ( $projected[0]['data']['matching_content'] ?? '' ) );
assert_projection( 'mcp_raw_item omitted from prompt metadata', ! array_key_exists( 'mcp_raw_item', $projected[0]['metadata'] ?? array() ) );
assert_projection( 'engine plumbing omitted from prompt metadata', ! array_key_exists( 'pipeline_id', $projected[0]['metadata'] ?? array() ) );
assert_projection( 'stable item identifier preserved', 'mgs-624' === ( $projected[0]['metadata']['item_identifier'] ?? '' ) );

$canonical_bytes = strlen( wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE ) );
$projected_bytes = strlen( wp_json_encode( $projected, JSON_UNESCAPED_UNICODE ) );

assert_projection( 'projected packet JSON is smaller than canonical JSON', $projected_bytes < $canonical_bytes, "canonical=$canonical_bytes projected=$projected_bytes" );

$prompt_json = wp_json_encode( array( 'data_packets' => $projected ), JSON_UNESCAPED_UNICODE );
assert_projection( 'prompt JSON is compact by default', ! str_contains( $prompt_json, "\n" ) );

$unknown_json_packet = array(
	array(
		'type'     => 'fetch',
		'data'     => array(
			'title' => 'Unknown JSON packet',
			'body'  => '{"title":"Nested title","custom":"important"}',
		),
		'metadata' => array( 'source_type' => 'custom_json_feed' ),
	),
);
$unknown_projected = \DataMachine\Engine\AI\DataPacketPromptProjector::project( $unknown_json_packet );
assert_projection( 'unknown JSON body packets use conservative fallback', $unknown_json_packet === $unknown_projected );

echo "\n$total assertions, $failed failures\n";
if ( $failed > 0 ) {
	exit( 1 );
}
