<?php
/**
 * Pure-PHP smoke test for handler result projection into Upsert/Publish.
 *
 * Run with: php tests/tool-result-consumer-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		unset( $type, $gmt );
		return '2026-05-26 00:00:00';
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		unset( $hook, $args );
	}
}

if ( ! function_exists( 'datamachine_get_engine_data' ) ) {
	function datamachine_get_engine_data( int $job_id ): array {
		unset( $job_id );
		return array();
	}
}

require_once __DIR__ . '/../inc/Core/DataPacket.php';
require_once __DIR__ . '/../inc/Core/EngineData.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/Step.php';
require_once __DIR__ . '/../inc/Core/Steps/StepTypeRegistrationTrait.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolResultFinder.php';
require_once __DIR__ . '/../inc/Core/Steps/Publish/PublishStep.php';
require_once __DIR__ . '/../inc/Core/Steps/Upsert/UpsertStep.php';

use DataMachine\Core\Steps\Publish\PublishStep;
use DataMachine\Core\Steps\Upsert\UpsertStep;

$failures = array();
$passes   = 0;

function assert_tool_consumer_contract( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  [PASS] {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "  [FAIL] {$message}\n";
}

function invoke_tool_consumer_contract_private_method( string $class_name, string $method_name, array $entry ): array {
	$reflection = new ReflectionClass( $class_name );
	$instance   = $reflection->newInstanceWithoutConstructor();
	$method     = $reflection->getMethod( $method_name );

	return $method->invoke( $instance, $entry, array(), 'demo_handler', 'flow_step_1' );
}

function handler_tool_result_entry( array $envelope ): array {
	return array(
		'type'     => 'ai_handler_complete',
		'data'     => array( 'body' => 'handler completed' ),
		'metadata' => array(
			'tool_name'            => 'demo_tool',
			'handler_tool'         => 'demo_handler',
			'source_type'          => 'demo_source',
			'tool_result_envelope' => $envelope,
			'tool_result_data'     => DataMachine\Engine\AI\Tools\ToolResultFinder::projectEnvelopeData( $envelope ),
		),
	);
}

echo "=== tool-result-consumer-contract-smoke ===\n";

$cases = array(
	'success + result'           => array(
		'envelope' => array(
			'success' => true,
			'result'  => array( 'post_id' => 123 ),
		),
		'payload'  => array( 'post_id' => 123 ),
	),
	'legacy success + top-level' => array(
		'envelope' => array(
			'success' => true,
			'post_id' => 456,
		),
		'payload'  => array( 'post_id' => 456 ),
	),
);

foreach ( $cases as $label => $case ) {
	$entry          = handler_tool_result_entry( $case['envelope'] );
	$upsert_packets = invoke_tool_consumer_contract_private_method( UpsertStep::class, 'create_update_entry_from_tool_result', $entry );
	$publish_packets = invoke_tool_consumer_contract_private_method( PublishStep::class, 'create_publish_entry_from_tool_result', $entry );
	$upsert_packet  = $upsert_packets[0] ?? array();
	$publish_packet = $publish_packets[0] ?? array();

	assert_tool_consumer_contract( true === ( $upsert_packet['metadata']['success'] ?? false ), "{$label}: Upsert preserves semantic success", $failures, $passes );
	assert_tool_consumer_contract( $case['payload'] === ( $upsert_packet['data']['update_result'] ?? array() ), "{$label}: Upsert exposes payload data", $failures, $passes );
	assert_tool_consumer_contract( $case['envelope'] === ( $upsert_packet['metadata']['tool_execution_data'] ?? array() ), "{$label}: Upsert preserves execution envelope", $failures, $passes );
	assert_tool_consumer_contract( $case['payload'] === ( $upsert_packet['metadata']['tool_result_data'] ?? array() ), "{$label}: Upsert stores projected payload", $failures, $passes );

	assert_tool_consumer_contract( true === ( $publish_packet['metadata']['publish_success'] ?? false ), "{$label}: Publish preserves semantic success", $failures, $passes );
	assert_tool_consumer_contract( $case['payload'] === ( $publish_packet['metadata']['result'] ?? array() ), "{$label}: Publish exposes payload result", $failures, $passes );
	assert_tool_consumer_contract( $case['envelope'] === ( $publish_packet['metadata']['tool_execution_data'] ?? array() ), "{$label}: Publish preserves execution envelope", $failures, $passes );
	assert_tool_consumer_contract( $case['payload'] === ( $publish_packet['metadata']['tool_result_data'] ?? array() ), "{$label}: Publish stores projected payload", $failures, $passes );
}

if ( ! empty( $failures ) ) {
	echo "\n=== tool-result-consumer-contract-smoke: " . count( $failures ) . " FAILURE(S) ===\n";
	exit( 1 );
}

echo "\n=== tool-result-consumer-contract-smoke: ALL PASS ({$passes} assertions) ===\n";
