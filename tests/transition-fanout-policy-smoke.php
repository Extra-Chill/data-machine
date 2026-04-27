<?php
/**
 * Pure-PHP smoke test for ExecuteStepAbility transition fan-out policy.
 *
 * Run with: php tests/transition-fanout-policy-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function apply_filters( string $hook, $value ) {
	return $value;
}

function do_action( string $hook, ...$args ): void {}

require_once __DIR__ . '/../inc/Core/DataPacket.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Abilities/Engine/EngineHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php';

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Core\DataPacket;

$failures = array();
$passes   = 0;

function transition_fanout_packet( string $type, array $metadata = array() ): array {
	$packet = new DataPacket(
		array(
			'title' => 'Test',
			'body'  => 'Test body',
		),
		$metadata,
		$type
	);

	$result = $packet->addTo( array() );
	return $result[0];
}

function transition_fanout_assert_same( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

$route = ExecuteStepAbility::resolveTransitionRoute(
	array( 'step_type' => 'ai' ),
	array(
		'step_type'     => 'publish',
		'handler_slugs' => array( 'publish_post' ),
	),
	array(
		transition_fanout_packet( 'ai_handler_complete', array( 'tool_name' => 'publish_post', 'handler_tool' => 'publish_post' ) ),
		transition_fanout_packet( 'tool_result', array( 'tool_name' => 'search' ) ),
	)
);

transition_fanout_assert_same( 'inline', $route['mode'], 'ai to handler step continues inline', $failures, $passes );
transition_fanout_assert_same( 1, count( $route['packets'] ), 'ai to handler step keeps only handler packet', $failures, $passes );
transition_fanout_assert_same( 'publish_post', $route['packets'][0]['metadata']['handler_tool'], 'handler packet metadata preserved', $failures, $passes );

$route = ExecuteStepAbility::resolveTransitionRoute(
	array( 'step_type' => 'ai' ),
	array(
		'step_type'     => 'upsert',
		'handler_slugs' => array( 'upsert_event' ),
	),
	array(
		transition_fanout_packet( 'tool_result', array( 'tool_name' => 'search' ) ),
		transition_fanout_packet( 'ai_response', array( 'source_type' => 'custom' ) ),
	)
);

transition_fanout_assert_same( 'fail', $route['mode'], 'ai non-handler packets fail before handler step', $failures, $passes );
transition_fanout_assert_same( 'handler_requiring_step_missing_handler_packets', $route['reason'], 'failure reason is explicit', $failures, $passes );

$route = ExecuteStepAbility::resolveTransitionRoute(
	array( 'step_type' => 'ai' ),
	array(
		'step_type'     => 'publish',
		'handler_slugs' => array( 'publish_post', 'publish_pin' ),
	),
	array(
		transition_fanout_packet( 'ai_handler_complete', array( 'tool_name' => 'publish_post', 'handler_tool' => 'publish_post' ) ),
		transition_fanout_packet( 'ai_handler_complete', array( 'tool_name' => 'publish_pin', 'handler_tool' => 'publish_pin' ) ),
	)
);

transition_fanout_assert_same( 'inline', $route['mode'], 'multi-handler completions stay in one job', $failures, $passes );
transition_fanout_assert_same( 2, count( $route['packets'] ), 'multi-handler completions are both available to next step', $failures, $passes );

$route = ExecuteStepAbility::resolveTransitionRoute(
	array( 'step_type' => 'fetch' ),
	array( 'step_type' => 'ai' ),
	array(
		transition_fanout_packet( 'fetch', array( 'item_identifier' => 'one' ) ),
		transition_fanout_packet( 'fetch', array( 'item_identifier' => 'two' ) ),
	)
);

transition_fanout_assert_same( 'fanout', $route['mode'], 'non-ai source-item transitions still fan out', $failures, $passes );
transition_fanout_assert_same( 2, count( $route['packets'] ), 'source-item packet count preserved', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures: " . implode( ', ', $failures ) . "\n";
	exit( 1 );
}

echo "\nAll {$passes} transition fan-out policy assertions passed.\n";
