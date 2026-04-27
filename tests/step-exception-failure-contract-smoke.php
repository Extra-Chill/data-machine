<?php
/**
 * Pure-PHP smoke test for explicit step exception failure packets.
 *
 * Run with: php tests/step-exception-failure-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$datamachine_action_log = array();

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		global $datamachine_action_log;
		$datamachine_action_log[] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		$key = strtolower( $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'datamachine_get_engine_data' ) ) {
	function datamachine_get_engine_data( int $job_id ): array {
		return array(
			'flow_config' => array(
				'throwing_step' => array(
					'flow_step_id'     => 'throwing_step',
					'flow_id'          => 9,
					'pipeline_id'      => 3,
					'pipeline_step_id' => 'pipeline_step_1',
					'step_type'        => 'throwing',
					'handler_slug'     => 'test_handler',
				),
			),
			'job'         => array(
				'job_id'      => $job_id,
				'flow_id'     => 9,
				'pipeline_id' => 3,
			),
		);
	}
}

if ( ! function_exists( 'datamachine_get_file_context' ) ) {
	function datamachine_get_file_context( $flow_id ): array {
		return array();
	}
}

require_once __DIR__ . '/../inc/Core/DataPacket.php';
require_once __DIR__ . '/../inc/Core/EngineData.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/Step.php';
require_once __DIR__ . '/../inc/Abilities/Engine/EngineHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php';

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Step;

class DataMachine_Throwing_Test_Step extends Step {
	public function __construct() {
		parent::__construct( 'throwing' );
	}

	protected function executeStep(): array {
		throw new RuntimeException( 'boom from fake step' );
	}
}

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}

	++$failures;
	echo "  [FAIL] {$label}\n";
};

echo "=== step-exception-failure-contract-smoke ===\n";

$input_packets = array(
	array(
		'type'     => 'fetch',
		'data'     => array( 'body' => 'original packet' ),
		'metadata' => array( 'success' => true ),
	),
);

$engine = new EngineData( datamachine_get_engine_data( 100 ), 100 );
$step   = new DataMachine_Throwing_Test_Step();
$result = $step->execute(
	array(
		'job_id'       => 100,
		'flow_step_id' => 'throwing_step',
		'data'         => $input_packets,
		'engine'       => $engine,
	)
);

echo "\n[1] throwing step returns explicit failure packet\n";
$assert( 'exception adds a packet instead of returning only original input', 2 === count( $result ) );
$assert( 'failure packet is first', 'step_error' === ( $result[0]['type'] ?? '' ) );
$assert( 'failure packet is explicitly unsuccessful', false === ( $result[0]['metadata']['success'] ?? null ) );
$assert( 'failure reason is machine-readable', 'step_exception' === ( $result[0]['metadata']['failure_reason'] ?? '' ) );
$assert( 'exception message is preserved for logs', 'boom from fake step' === ( $result[0]['data']['body'] ?? '' ) );
$assert( 'original non-empty input packet is still present after failure packet', 'original packet' === ( $result[1]['data']['body'] ?? '' ) );

$ability_reflection = new ReflectionClass( ExecuteStepAbility::class );
$ability            = $ability_reflection->newInstanceWithoutConstructor();

$evaluate = $ability_reflection->getMethod( 'evaluateStepSuccess' );
$step_success = $evaluate->invoke( $ability, $result, 100, 'throwing_step' );

echo "\n[2] engine evaluates failure packet as step failure\n";
$assert( 'failure packet overrides non-empty packet list', false === $step_success );

$route = $ability_reflection->getMethod( 'routeAfterExecution' );
$route_result = $route->invoke(
	$ability,
	100,
	'throwing_step',
	9,
	datamachine_get_engine_data( 100 )['flow_config']['throwing_step'],
	'throwing',
	DataMachine_Throwing_Test_Step::class,
	$result,
	array( 'data' => $result ),
	$step_success,
	null
);

$scheduled_next = array_values( array_filter(
	$datamachine_action_log,
	fn( array $entry ): bool => 'datamachine_schedule_next_step' === $entry['hook']
) );
$failed_jobs    = array_values( array_filter(
	$datamachine_action_log,
	fn( array $entry ): bool => 'datamachine_fail_job' === $entry['hook']
) );
$last_failure   = end( $failed_jobs );
$failure_reason = $last_failure['args'][2]['reason'] ?? '';

echo "\n[3] failed step does not route as success\n";
$assert( 'route outcome is failed', 'failed' === ( $route_result['outcome'] ?? '' ) );
$assert( 'route reports step_success=false', false === ( $route_result['step_success'] ?? null ) );
$assert( 'next step is not scheduled', empty( $scheduled_next ) );
$assert( 'job failure action is emitted', ! empty( $failed_jobs ) );
$assert( 'job failure reason comes from packet metadata', 'step_exception' === $failure_reason );

echo "\n[4] UpsertStep override follows the explicit failure-packet contract\n";
$upsert_source = file_get_contents( __DIR__ . '/../inc/Core/Steps/Upsert/UpsertStep.php' );
$assert( 'UpsertStep does not return original packets from handleException', false === strpos( $upsert_source, 'return $this->dataPackets;' ) );
$assert( 'UpsertStep uses shared exception failure packet builder', false !== strpos( $upsert_source, 'buildExceptionFailurePackets( $e, $context, \'upsert_step_exception\' )' ) );

if ( $failures > 0 ) {
	echo "\n=== step-exception-failure-contract-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== step-exception-failure-contract-smoke: ALL PASS ({$total} assertions) ===\n";
