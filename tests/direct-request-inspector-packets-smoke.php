<?php
/**
 * Pure-PHP smoke test for direct workflow AI packet inspection.
 *
 * Run with: php tests/direct-request-inspector-packets-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Core/EngineData.php';
require_once __DIR__ . '/../inc/Engine/AI/RequestInspector.php';

$failures = 0;
$passes   = 0;

$assert = static function ( $expected, $actual, string $message ) use ( &$failures, &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  [PASS] {$message}\n";
		return;
	}

	++$failures;
	echo "  [FAIL] {$message}\n";
	echo '    expected: ' . json_encode( $expected ) . "\n";
	echo '    actual:   ' . json_encode( $actual ) . "\n";
};

echo "Direct request inspector packet smoke\n";

$packet = array(
	'type'     => 'wiki_graph_exception_review',
	'data'     => array(
		'title' => 'Wiki graph exceptions needing AI review',
		'body'  => '{"items":[{"action_id":"pa-review","root_owner_agent":"matt-wiki"}]}',
	),
	'metadata' => array( 'source_type' => 'wiki_graph_maintain' ),
);

$engine = new \DataMachine\Core\EngineData(
	array(
		'job'                      => array(
			'flow_id'     => 'direct',
			'pipeline_id' => 'direct',
		),
		'direct_step_data_packets' => array(
			'ephemeral_step_1' => array( $packet ),
		),
	),
	87291
);

$method = new ReflectionMethod( \DataMachine\Engine\AI\RequestInspector::class, 'retrieveDataPackets' );
$result = $method->invoke( new \DataMachine\Engine\AI\RequestInspector(), 87291, $engine, 'ephemeral_step_1' );

$assert( array( $packet ), $result, 'direct workflow AI step receives its engine-backed packet slot' );
$assert( array(), $method->invoke( new \DataMachine\Engine\AI\RequestInspector(), 87291, $engine, 'ephemeral_step_2' ), 'other direct workflow steps do not receive adjacent packet slots' );

if ( $failures > 0 ) {
	echo "\nFAILED: {$failures} failures, {$passes} passes.\n";
	exit( 1 );
}

echo "\nAll {$passes} direct request inspector packet assertions passed.\n";
