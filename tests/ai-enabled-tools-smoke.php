<?php
/**
 * Pure-PHP smoke test for the AI enabled_tools split (#1205 Phase 2b).
 *
 * Run with: php tests/ai-enabled-tools-shim-smoke.php
 *
 * Phase 2b drops the field overload where flow_step_config['handler_slugs']
 * carried both the step's handler (length 0..1) and the AI's tool list
 * (length 0..N). After this:
 *
 *   - handler_slugs is single-purpose: [handler_slug] or [].
 *   - AI tools live in flow_step_config['enabled_tools'].
 *   - FlowStepConfig::getEnabledTools() reads the new field. No runtime
 *     fallback to handler_slugs.
 *
 * This file covers the accessor contract: no shim, no fallback.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Inline reimplementation of FlowStepConfig::getEnabledTools().
 *
 * Mirrors inc/Core/Steps/FlowStepConfig.php post-Phase 2b. Diverging
 * here means the real file regressed.
 */
function get_enabled_tools_for_test( array $step_config ): array {
	if ( 'ai' !== ( $step_config['step_type'] ?? '' ) ) {
		return array();
	}

	$enabled = $step_config['enabled_tools'] ?? array();
	if ( ! is_array( $enabled ) ) {
		return array();
	}

	return array_values( $enabled );
}

/**
 * Inline reimplementation of FlowStepConfig::isEnabledToolsExplicit().
 *
 * Mirrors inc/Core/Steps/FlowStepConfig.php. Diverging here means the real
 * file regressed.
 */
function is_enabled_tools_explicit_for_test( array $step_config ): bool {
	if ( 'ai' !== ( $step_config['step_type'] ?? '' ) ) {
		return false;
	}

	return array_key_exists( 'enabled_tools', $step_config )
		&& is_array( $step_config['enabled_tools'] );
}

$failures = array();
$passes   = 0;

function assert_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		$passes++;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo "    expected: " . var_export( $expected, true ) . "\n";
	echo "    actual:   " . var_export( $actual, true ) . "\n";
}

echo "AI enabled_tools split smoke (Phase 2b)\n";
echo "---------------------------------------\n";

// ----- FlowStepConfig::getEnabledTools() -----

echo "\n[1] AI step with enabled_tools populated:\n";
$config = array(
	'flow_step_id'  => 'flow_ai_1',
	'step_type'     => 'ai',
	'handler_slugs' => array(),
	'enabled_tools' => array( 'intelligence/search', 'intelligence/wiki-upsert' ),
);
assert_equals(
	array( 'intelligence/search', 'intelligence/wiki-upsert' ),
	get_enabled_tools_for_test( $config ),
	'returns enabled_tools verbatim',
	$failures,
	$passes
);

echo "\n[2] AI step with no tools:\n";
$config = array(
	'flow_step_id' => 'flow_ai_empty',
	'step_type'    => 'ai',
);
assert_equals( array(), get_enabled_tools_for_test( $config ), 'empty config → empty', $failures, $passes );

echo "\n[3] AI step with handler_slugs populated but enabled_tools empty (post-migration impossible state):\n";
// The accessor does NOT fall back. handler_slugs is for handlers; AI
// has none. Anything stored there is dead data after the migration.
$config = array(
	'flow_step_id'  => 'flow_ai_legacy',
	'step_type'     => 'ai',
	'handler_slugs' => array( 'intelligence/search' ),
);
assert_equals( array(), get_enabled_tools_for_test( $config ), 'no fallback to handler_slugs', $failures, $passes );

echo "\n[4] Non-AI step (publish) returns empty:\n";
$config = array(
	'step_type'     => 'publish',
	'handler_slugs' => array( 'wordpress_publish' ),
	'enabled_tools' => array( 'intelligence/search' ),
);
assert_equals( array(), get_enabled_tools_for_test( $config ), 'publish step has no AI tools', $failures, $passes );

echo "\n[5] Step config without step_type:\n";
$config = array(
	'enabled_tools' => array( 'intelligence/search' ),
);
assert_equals( array(), get_enabled_tools_for_test( $config ), 'no step_type → empty', $failures, $passes );

echo "\n[6] AI step with enabled_tools that is not an array:\n";
$config = array(
	'step_type'     => 'ai',
	'enabled_tools' => 'not_an_array',
);
assert_equals( array(), get_enabled_tools_for_test( $config ), 'non-array enabled_tools → empty (no fatal)', $failures, $passes );

// ----- FlowStepConfig::isEnabledToolsExplicit() -----

echo "\n[7] explicit detection — populated enabled_tools is explicit:\n";
assert_equals(
	true,
	is_enabled_tools_explicit_for_test( array( 'step_type' => 'ai', 'enabled_tools' => array( 'intelligence/search' ) ) ),
	'populated array => explicit',
	$failures,
	$passes
);

echo "\n[8] explicit detection — present-but-EMPTY enabled_tools is explicit:\n";
// This is the footgun case: the operator deselected every tool. It must be
// distinguishable from an absent key so the runtime can deny all optional tools.
assert_equals(
	true,
	is_enabled_tools_explicit_for_test( array( 'step_type' => 'ai', 'enabled_tools' => array() ) ),
	'present empty array => explicit (deny all optional)',
	$failures,
	$passes
);

echo "\n[9] explicit detection — absent key is NOT explicit (legacy preset):\n";
assert_equals(
	false,
	is_enabled_tools_explicit_for_test( array( 'step_type' => 'ai' ) ),
	'absent key => not explicit (preset applies)',
	$failures,
	$passes
);

echo "\n[10] explicit detection — non-array enabled_tools is NOT explicit:\n";
assert_equals(
	false,
	is_enabled_tools_explicit_for_test( array( 'step_type' => 'ai', 'enabled_tools' => 'nope' ) ),
	'non-array => not explicit',
	$failures,
	$passes
);

echo "\n[11] explicit detection — non-AI step is never explicit:\n";
assert_equals(
	false,
	is_enabled_tools_explicit_for_test( array( 'step_type' => 'publish', 'enabled_tools' => array() ) ),
	'publish step => not explicit',
	$failures,
	$passes
);

echo "\n---------------------------------------\n";
$total = $passes + count( $failures );
echo "{$passes} / {$total} passed\n";

if ( ! empty( $failures ) ) {
	echo "Failures:\n";
	foreach ( $failures as $name ) {
		echo "  - {$name}\n";
	}
	exit( 1 );
}

echo "All checks passed.\n";
exit( 0 );
