<?php
/**
 * Pure-PHP smoke test for shared execution-order planning.
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
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			$GLOBALS['datamachine_step_navigator_actions'][] = array(
				'hook' => $hook,
				'args' => $args,
			);
		}
	}

	require_once __DIR__ . '/../inc/Engine/Filters/EngineData.php';
	require_once __DIR__ . '/../inc/Engine/ExecutionPlan.php';
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

	function set_step_navigator_flow_config( int $job_id, array $flow_config ): void {
		\DataMachine\Core\EngineData::$snapshots[ $job_id ] = array(
			'flow_config' => $flow_config,
		);
	}

	function step_navigator_flow_config( array $orders ): array {
		$flow_config = array();
		foreach ( $orders as $step_id => $order ) {
			$flow_config[ $step_id ] = array(
				'flow_step_id'    => $step_id,
				'execution_order' => $order,
			);
		}

		return $flow_config;
	}

	function latest_step_navigator_log_context(): array {
		$actions = $GLOBALS['datamachine_step_navigator_actions'] ?? array();
		$latest  = end( $actions );
		$args    = is_array( $latest ) ? ( $latest['args'] ?? array() ) : array();

		return is_array( $args[2] ?? null ) ? $args[2] : array();
	}

	set_step_navigator_flow_config( 123, array(
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
	) );

	$navigator = new \DataMachine\Engine\StepNavigator();
	$context   = array( 'job_id' => 123 );

	assert_step_navigator_order(
		'execution plan first step reads sorted order instead of strict zero scan',
		'fetch_step' === \DataMachine\Engine\ExecutionPlan::from_flow_config(
			step_navigator_flow_config(
				array(
					'publish_step' => 20,
					'fetch_step'   => 10,
					'ai_step'      => 15,
				)
			)
		)->first_step_id()
	);

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

	set_step_navigator_flow_config( 124, step_navigator_flow_config(
		array(
			'fetch_step'   => 0,
			'ai_step'      => 10,
			'publish_step' => 20,
		)
	) );
	$gapped_context = array( 'job_id' => 124 );
	assert_step_navigator_order(
		'gapped orders advance by sorted position',
		'ai_step' === $navigator->get_next_flow_step_id( 'fetch_step', $gapped_context )
	);
	assert_step_navigator_order(
		'gapped orders move back by sorted position',
		'ai_step' === $navigator->get_previous_flow_step_id( 'publish_step', $gapped_context )
	);

	set_step_navigator_flow_config( 125, step_navigator_flow_config(
		array(
			'fetch_step'   => '0',
			'ai_step'      => '10',
			'publish_step' => '20',
		)
	) );
	$string_context = array( 'job_id' => 125 );
	assert_step_navigator_order(
		'numeric-string orders are normalized for next navigation',
		'ai_step' === $navigator->get_next_flow_step_id( 'fetch_step', $string_context )
	);
	assert_step_navigator_order(
		'numeric-string orders are normalized for previous navigation',
		'ai_step' === $navigator->get_previous_flow_step_id( 'publish_step', $string_context )
	);

	$GLOBALS['datamachine_step_navigator_actions'] = array();
	set_step_navigator_flow_config( 126, step_navigator_flow_config(
		array(
			'fetch_step'   => 0,
			'ai_step'      => '0',
			'publish_step' => 20,
		)
	) );
	$duplicate_context = array( 'job_id' => 126 );
	assert_step_navigator_order(
		'duplicate execution orders stop next navigation',
		null === $navigator->get_next_flow_step_id( 'fetch_step', $duplicate_context )
	);
	assert_step_navigator_order(
		'duplicate execution orders log an explicit error',
		str_contains( latest_step_navigator_log_context()['error'] ?? '', 'Duplicate execution_order 0' )
	);

	if ( $failed > 0 ) {
		echo "\n{$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$total} assertions passed.\n";
}
