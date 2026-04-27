<?php
/**
 * Pure-PHP smoke test for StepNavigator's contiguous execution_order contract.
 *
 * Run with: php tests/step-navigator-execution-order-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core {
	class EngineData {
		public static array $snapshots = array();

		public static function retrieve( int $job_id ): array {
			return self::$snapshots[ $job_id ] ?? array();
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'WPINC' ) ) {
		define( 'WPINC', 'wp-includes' );
	}

	require_once __DIR__ . '/../inc/Engine/Filters/EngineData.php';
	require_once __DIR__ . '/../inc/Engine/StepNavigator.php';

	$failed = 0;
	$total  = 0;

	function assert_step_navigator_order( string $name, bool $condition, string $detail = '' ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$name}\n";
			return;
		}

		echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
		++$failed;
	}

	echo "=== step-navigator-execution-order-smoke ===\n";

	\DataMachine\Core\EngineData::$snapshots[123] = array(
		'flow_config' => array(
			'fetch_step'   => array(
				'flow_step_id'    => 'fetch_step',
				'execution_order' => 0,
			),
			'ai_step'      => array(
				'flow_step_id'    => 'ai_step',
				'execution_order' => 1,
			),
			'publish_step' => array(
				'flow_step_id'    => 'publish_step',
				'execution_order' => 2,
			),
		),
	);

	$navigator = new \DataMachine\Engine\StepNavigator();
	$context   = array( 'job_id' => 123 );

	assert_step_navigator_order(
		'fetch advances to ai when orders are contiguous',
		'ai_step' === $navigator->get_next_flow_step_id( 'fetch_step', $context )
	);
	assert_step_navigator_order(
		'ai advances to publish when orders are contiguous',
		'publish_step' === $navigator->get_next_flow_step_id( 'ai_step', $context )
	);
	assert_step_navigator_order(
		'publish has no next step',
		null === $navigator->get_next_flow_step_id( 'publish_step', $context )
	);
	assert_step_navigator_order(
		'publish moves back to ai when orders are contiguous',
		'ai_step' === $navigator->get_previous_flow_step_id( 'publish_step', $context )
	);
	assert_step_navigator_order(
		'ai moves back to fetch when orders are contiguous',
		'fetch_step' === $navigator->get_previous_flow_step_id( 'ai_step', $context )
	);
	assert_step_navigator_order(
		'fetch has no previous step',
		null === $navigator->get_previous_flow_step_id( 'fetch_step', $context )
	);

	if ( $failed > 0 ) {
		echo "\n{$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$total} assertions passed.\n";
}
