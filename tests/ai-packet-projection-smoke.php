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

$test_filters = array();

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook, callable $callback, int $priority = 10, int $_accepted_args = 1 ): void {
    	global $test_filters;
    	$test_filters[ $hook ][ $priority ][] = array(
    		'callback'      => $callback,
    		'accepted_args' => $_accepted_args,
    	);
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook, $value, ...$args ) {
    	global $test_filters;
    	if ( empty( $test_filters[ $hook ] ) ) {
    		return $value;
    	}

    	ksort( $test_filters[ $hook ] );
    	foreach ( $test_filters[ $hook ] as $callbacks ) {
    		foreach ( $callbacks as $filter ) {
    			$accepted_args = max( 1, (int) $filter['accepted_args'] );
    			$filter_args   = array_slice( array_merge( array( $value ), $args ), 0, $accepted_args );
    			$value         = $filter['callback']( ...$filter_args );
    		}
    	}

    	return $value;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value, int $flags = 0 ) {
    	return json_encode( $value, $flags );
    }
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

$canonical = array(
	array(
		'type'      => 'fetch',
		'timestamp' => 1770000000,
		'data'      => array(
			'title'     => 'Generic packet',
			'body'      => 'Plain source text',
			'file_info' => array(
				'file_path' => '/tmp/runtime-only.jpg',
				'mime_type' => 'image/jpeg',
			),
		),
		'metadata'  => array(
			'source_type' => 'generic_source',
			'custom_key'   => 'custom value',
		),
	),
);

$canonical_before = $canonical;
$projected        = \DataMachine\Engine\AI\DataPacketPromptProjector::project( $canonical );

assert_projection( 'canonical packet unchanged after generic projection', $canonical_before === $canonical );
assert_projection( 'generic title preserved', 'Generic packet' === ( $projected[0]['data']['title'] ?? '' ) );
assert_projection( 'generic body preserved', 'Plain source text' === ( $projected[0]['data']['body'] ?? '' ) );
assert_projection( 'generic metadata preserved', 'custom value' === ( $projected[0]['metadata']['custom_key'] ?? '' ) );
assert_projection( 'runtime file_path stripped from prompt data', ! array_key_exists( 'file_path', $projected[0]['data']['file_info'] ?? array() ) );

add_filter(
	'datamachine_ai_project_data_packet',
	static function ( array $projected_packet, array $canonical_packet ): array {
		if ( 'integration_owned_source' !== ( $canonical_packet['metadata']['source_type'] ?? '' ) ) {
			return $projected_packet;
		}

		return array(
			'type'     => $canonical_packet['type'],
			'data'     => array(
				'title'   => $canonical_packet['data']['title'],
				'snippet' => 'Source-specific compact projection',
			),
			'metadata' => array( 'source_type' => $canonical_packet['metadata']['source_type'] ),
		);
	},
	10,
	2
);

$source_specific = array(
	array(
		'type'     => 'fetch',
		'data'     => array(
			'title' => 'Verbose integration packet',
			'body'  => str_repeat( 'Long duplicated source text. ', 20 ),
		),
		'metadata' => array(
			'source_type' => 'integration_owned_source',
			'raw_payload'  => array( 'duplicated' => true ),
		),
	),
);
$source_specific_before = $source_specific;
$context                = array(
	'job_id'           => 1799,
	'pipeline_id'      => 3,
	'flow_id'          => 2,
	'flow_step_id'     => 'flow_step_ai',
	'pipeline_step_id' => 'pipeline_step_ai',
);
$received_context       = array();

add_filter(
	'datamachine_ai_project_data_packet',
	static function ( array $projected_packet, array $_canonical_packet, array $filter_context ) use ( &$received_context ): array {
		$received_context = $filter_context;
		return $projected_packet;
	},
	20,
	3
);

$compact = \DataMachine\Engine\AI\DataPacketPromptProjector::project( $source_specific, $context );

assert_projection( 'filter projection leaves canonical source packet unchanged', $source_specific_before === $source_specific );
assert_projection( 'filter projection can remove verbose body', ! array_key_exists( 'body', $compact[0]['data'] ?? array() ) );
assert_projection( 'filter projection can remove source-specific raw metadata', ! array_key_exists( 'raw_payload', $compact[0]['metadata'] ?? array() ) );
assert_projection( 'filter projection keeps compact source text', 'Source-specific compact projection' === ( $compact[0]['data']['snippet'] ?? '' ) );
assert_projection( 'three-argument filter receives projection context', $context === $received_context );

$canonical_bytes = strlen( wp_json_encode( $source_specific, JSON_UNESCAPED_UNICODE ) );
$projected_bytes = strlen( wp_json_encode( $compact, JSON_UNESCAPED_UNICODE ) );

assert_projection( 'filter-projected packet JSON is smaller than canonical JSON', $projected_bytes < $canonical_bytes, "canonical=$canonical_bytes projected=$projected_bytes" );

$prompt_json = wp_json_encode( array( 'data_packets' => $compact ), JSON_UNESCAPED_UNICODE );
assert_projection( 'prompt JSON is compact by default', ! str_contains( $prompt_json, "\n" ) );

echo "\n$total assertions, $failed failures\n";
if ( $failed > 0 ) {
	exit( 1 );
}
