<?php
/**
 * Pure-PHP smoke test for the first-party Step::execute result contract.
 *
 * Run with: php tests/step-base-explicit-result-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {
		unset( $args );
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
		unset( $job_id );
		return array();
	}
}

require_once __DIR__ . '/../inc/Core/DataPacket.php';
require_once __DIR__ . '/../inc/Core/EngineData.php';
require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/StepExecutionResult.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/Step.php';

use DataMachine\Core\DataPacket;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Step;

class Step_Base_Explicit_Result_Smoke_Step extends Step {
	private array $packets;

	public function __construct( string $step_type, array $packets ) {
		parent::__construct( $step_type );
		$this->packets = $packets;
	}

	protected function validateStepConfiguration(): bool {
		return true;
	}

	protected function executeStep(): array {
		return $this->packets;
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

$payload = function ( string $step_type ): array {
	return array(
		'job_id'       => 1,
		'flow_step_id' => 'step_1',
		'data'         => array(),
		'engine'       => new EngineData(
			array(
				'flow_config' => array(
					'step_1' => array(
						'step_type' => $step_type,
					),
				),
			),
			1
		),
	);
};

echo "=== step-base-explicit-result-smoke ===\n";

echo "\n[1] first-party step execution returns explicit result shape\n";
$packet = new DataPacket( array( 'body' => 'ok' ), array(), 'source_item' );
$step   = new Step_Base_Explicit_Result_Smoke_Step( 'custom', $packet->addTo( array() ) );
$result = $step->execute( $payload( 'custom' ) );

$assert( 'Step::execute returns status', 'succeeded' === ( $result['status'] ?? null ) );
$assert( 'Step::execute returns packets key', isset( $result['packets'] ) && 1 === count( $result['packets'] ) );
$assert( 'Step::execute exposes success boolean', true === ( $result['success'] ?? null ) );

echo "\n[2] fetch empty output is explicit completed_no_items\n";
$step   = new Step_Base_Explicit_Result_Smoke_Step( 'fetch', array() );
$result = $step->execute( $payload( 'fetch' ) );

$assert( 'empty fetch output is explicit completed_no_items', 'completed_no_items' === ( $result['status'] ?? null ) );
$assert( 'empty fetch output is not success for downstream routing', false === ( $result['success'] ?? null ) );

if ( $failures > 0 ) {
	echo "\n=== step-base-explicit-result-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== step-base-explicit-result-smoke: ALL PASS ({$total} assertions) ===\n";
