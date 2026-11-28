<?php
/**
 * Pure-PHP smoke test for flow step target resolution (#1346).
 *
 * Run with: php tests/flow-step-target-resolver-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Core/Steps/FlowStepTargetResolver.php';

use DataMachine\Core\Steps\FlowStepTargetResolver;

$failed = 0;
$total  = 0;

function assert_test( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
	} else {
		echo "  [FAIL] $name" . ( $detail ? " — $detail" : '' ) . "\n";
		++$failed;
	}
}

$single_ai_flow = array(
	'fetch_42' => array(
		'flow_step_id'     => 'fetch_42',
		'pipeline_step_id' => 'fetch',
		'step_type'        => 'fetch',
		'execution_order'  => 0,
	),
	'ai_42'    => array(
		'flow_step_id'     => 'ai_42',
		'pipeline_step_id' => 'ai',
		'step_type'        => 'ai',
		'execution_order'  => 1,
	),
);

echo "Case 1: single matching step_type keeps shorthand\n";
$single = FlowStepTargetResolver::resolve( $single_ai_flow, 'ai', array( 'user_message' => 'Summarize this.' ) );
assert_test( 'single step_type shorthand succeeds', true === ( $single['success'] ?? false ) );
assert_test( 'single step_type resolves the AI flow_step_id', 'ai_42' === ( $single['flow_step_id'] ?? null ) );
assert_test( 'single step_type carries resolved step_type', 'ai' === ( $single['step_type'] ?? null ) );

$duplicate_ai_flow = array(
	'fetch_77'   => array(
		'flow_step_id'     => 'fetch_77',
		'pipeline_step_id' => 'fetch',
		'step_type'        => 'fetch',
		'execution_order'  => 0,
	),
	'ai_intro_77' => array(
		'flow_step_id'     => 'ai_intro_77',
		'pipeline_step_id' => 'ai_intro',
		'step_type'        => 'ai',
		'execution_order'  => 1,
	),
	'ai_outro_77' => array(
		'flow_step_id'     => 'ai_outro_77',
		'pipeline_step_id' => 'ai_outro',
		'step_type'        => 'ai',
		'execution_order'  => 2,
	),
);

echo "\nCase 2: duplicate matching step_type rejects shorthand with candidates\n";
$ambiguous = FlowStepTargetResolver::resolve( $duplicate_ai_flow, 'ai', array( 'user_message' => 'Summarize this.' ) );
assert_test( 'duplicate step_type shorthand fails', false === ( $ambiguous['success'] ?? true ) );
assert_test( 'duplicate step_type returns ambiguity error type', 'ambiguous_step_target' === ( $ambiguous['error']['error_type'] ?? null ) );
assert_test( 'duplicate step_type lists both candidates', 2 === count( $ambiguous['error']['candidates'] ?? array() ) );
assert_test( 'first candidate exposes flow_step_id', 'ai_intro_77' === ( $ambiguous['error']['candidates'][0]['flow_step_id'] ?? null ) );
assert_test( 'first candidate exposes pipeline_step_id', 'ai_intro' === ( $ambiguous['error']['candidates'][0]['pipeline_step_id'] ?? null ) );
assert_test( 'first candidate exposes execution_order', 1 === ( $ambiguous['error']['candidates'][0]['execution_order'] ?? null ) );
assert_test( 'second candidate exposes flow_step_id', 'ai_outro_77' === ( $ambiguous['error']['candidates'][1]['flow_step_id'] ?? null ) );

echo "\nCase 3: explicit flow_step_id targeting works with duplicate step types\n";
$by_flow_step = FlowStepTargetResolver::resolve(
	$duplicate_ai_flow,
	'ai',
	array(
		'flow_step_id' => 'ai_outro_77',
		'user_message' => 'Write the outro.',
	)
);
assert_test( 'flow_step_id targeting succeeds', true === ( $by_flow_step['success'] ?? false ) );
assert_test( 'flow_step_id targets the requested duplicate', 'ai_outro_77' === ( $by_flow_step['flow_step_id'] ?? null ) );

echo "\nCase 4: explicit pipeline_step_id targeting works with duplicate step types\n";
$by_pipeline_step = FlowStepTargetResolver::resolve(
	$duplicate_ai_flow,
	'ai',
	array(
		'pipeline_step_id' => 'ai_intro',
		'user_message'     => 'Write the intro.',
	)
);
assert_test( 'pipeline_step_id targeting succeeds', true === ( $by_pipeline_step['success'] ?? false ) );
assert_test( 'pipeline_step_id targets the requested duplicate', 'ai_intro_77' === ( $by_pipeline_step['flow_step_id'] ?? null ) );

echo "\nCase 5: explicit execution_order targeting works with duplicate step types\n";
$by_order = FlowStepTargetResolver::resolve(
	$duplicate_ai_flow,
	'ai',
	array(
		'execution_order' => 2,
		'user_message'    => 'Write the outro.',
	)
);
assert_test( 'execution_order targeting succeeds', true === ( $by_order['success'] ?? false ) );
assert_test( 'execution_order targets the requested duplicate', 'ai_outro_77' === ( $by_order['flow_step_id'] ?? null ) );

echo "\nCase 6: production call sites use the shared resolver instead of last-write-wins maps\n";
$flow_helpers      = file_get_contents( __DIR__ . '/../inc/Abilities/Flow/FlowHelpers.php' );
$configure_ability = file_get_contents( __DIR__ . '/../inc/Abilities/FlowStep/ConfigureFlowStepsAbility.php' );

assert_test( 'FlowHelpers imports FlowStepTargetResolver', false !== strpos( $flow_helpers, 'FlowStepTargetResolver' ) );
assert_test( 'ConfigureFlowStepsAbility imports FlowStepTargetResolver', false !== strpos( $configure_ability, 'FlowStepTargetResolver' ) );
assert_test( 'FlowHelpers no longer builds step_type_to_flow_step map', false === strpos( $flow_helpers, '$step_type_to_flow_step' ) );
assert_test( 'ConfigureFlowStepsAbility no longer builds step_type_to_flow_step map', false === strpos( $configure_ability, '$step_type_to_flow_step' ) );

echo "\nAssertions: $total\n";
if ( $failed > 0 ) {
	echo "Failures: $failed\n";
	exit( 1 );
}

echo "All flow-step target resolver smoke tests passed.\n";
