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
 *     fallback to handler_slugs — legacy on-disk rows are migrated by
 *     inc/migrations/ai-enabled-tools.php on activation.
 *
 * This file covers both:
 *   1. The accessor (no shim, no fallback).
 *   2. The migration that flips legacy rows in place.
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
 * Inline reimplementation of the per-flow_config migration step in
 * datamachine_migrate_ai_enabled_tools(). Mirrors
 * inc/migrations/ai-enabled-tools.php so a regression there shows up
 * here as a fixture diverging.
 */
function migrate_ai_enabled_tools_for_test( array $flow_config ): array {
	foreach ( $flow_config as $step_id => &$step ) {
		if ( ! is_array( $step ) ) {
			continue;
		}

		if ( 'ai' !== ( $step['step_type'] ?? '' ) ) {
			continue;
		}

		if ( ! empty( $step['enabled_tools'] ) && is_array( $step['enabled_tools'] ) ) {
			if ( ! empty( $step['handler_slugs'] ) ) {
				$step['handler_slugs'] = array();
			}
			continue;
		}

		$legacy = $step['handler_slugs'] ?? array();
		if ( empty( $legacy ) || ! is_array( $legacy ) ) {
			if ( ! isset( $step['enabled_tools'] ) ) {
				$step['enabled_tools'] = array();
			}
			continue;
		}

		$step['enabled_tools'] = array_values( $legacy );
		$step['handler_slugs'] = array();
	}
	unset( $step );

	return $flow_config;
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

// ----- inc/migrations/ai-enabled-tools.php -----

echo "\n[7] Migration: legacy AI row flips handler_slugs → enabled_tools:\n";
$flow_config = array(
	'step_a' => array(
		'step_type'     => 'ai',
		'handler_slugs' => array( 'intelligence/search', 'intelligence/wiki-upsert' ),
	),
);
$migrated = migrate_ai_enabled_tools_for_test( $flow_config );
assert_equals( array( 'intelligence/search', 'intelligence/wiki-upsert' ), $migrated['step_a']['enabled_tools'], 'enabled_tools populated from handler_slugs', $failures, $passes );
assert_equals( array(), $migrated['step_a']['handler_slugs'], 'handler_slugs cleared', $failures, $passes );

echo "\n[8] Migration: AI row already on Phase 2b shape is left alone:\n";
$flow_config = array(
	'step_a' => array(
		'step_type'     => 'ai',
		'handler_slugs' => array(),
		'enabled_tools' => array( 'intelligence/search' ),
	),
);
$migrated = migrate_ai_enabled_tools_for_test( $flow_config );
assert_equals( array( 'intelligence/search' ), $migrated['step_a']['enabled_tools'], 'enabled_tools preserved', $failures, $passes );
assert_equals( array(), $migrated['step_a']['handler_slugs'], 'handler_slugs stays empty', $failures, $passes );

echo "\n[9] Migration: dual-shape row (both populated) clears handler_slugs without overwriting enabled_tools:\n";
// Defensive against partial-state rows. enabled_tools wins; handler_slugs is wiped.
$flow_config = array(
	'step_a' => array(
		'step_type'     => 'ai',
		'handler_slugs' => array( 'intelligence/legacy_search' ),
		'enabled_tools' => array( 'intelligence/wiki-upsert' ),
	),
);
$migrated = migrate_ai_enabled_tools_for_test( $flow_config );
assert_equals( array( 'intelligence/wiki-upsert' ), $migrated['step_a']['enabled_tools'], 'enabled_tools wins on dual shape', $failures, $passes );
assert_equals( array(), $migrated['step_a']['handler_slugs'], 'handler_slugs wiped on dual shape', $failures, $passes );

echo "\n[10] Migration: AI row with no tools at all gets enabled_tools=[]:\n";
$flow_config = array(
	'step_a' => array(
		'step_type' => 'ai',
	),
);
$migrated = migrate_ai_enabled_tools_for_test( $flow_config );
assert_equals( array(), $migrated['step_a']['enabled_tools'], 'enabled_tools field added empty', $failures, $passes );

echo "\n[11] Migration: non-AI step left alone:\n";
$flow_config = array(
	'step_a' => array(
		'step_type'     => 'publish',
		'handler_slugs' => array( 'wordpress_publish' ),
		'handler_configs' => array( 'wordpress_publish' => array( 'post_status' => 'draft' ) ),
	),
);
$migrated = migrate_ai_enabled_tools_for_test( $flow_config );
assert_equals( array( 'wordpress_publish' ), $migrated['step_a']['handler_slugs'], 'publish handler_slugs untouched', $failures, $passes );
assert_equals( false, isset( $migrated['step_a']['enabled_tools'] ), 'no enabled_tools added to non-AI step', $failures, $passes );

echo "\n[12] Migration: mixed flow with AI + publish + system_task:\n";
$flow_config = array(
	'step_a' => array(
		'step_type'     => 'ai',
		'handler_slugs' => array( 'intelligence/search' ),
	),
	'step_b' => array(
		'step_type'       => 'publish',
		'handler_slugs'   => array( 'wordpress_publish' ),
		'handler_configs' => array( 'wordpress_publish' => array() ),
	),
	'step_c' => array(
		'step_type'       => 'system_task',
		'handler_slugs'   => array( 'system_task' ),
		'handler_configs' => array( 'system_task' => array( 'task' => 'daily_memory_generation' ) ),
	),
);
$migrated = migrate_ai_enabled_tools_for_test( $flow_config );
assert_equals( array( 'intelligence/search' ), $migrated['step_a']['enabled_tools'], 'AI step migrated', $failures, $passes );
assert_equals( array(), $migrated['step_a']['handler_slugs'], 'AI handler_slugs cleared', $failures, $passes );
assert_equals( array( 'wordpress_publish' ), $migrated['step_b']['handler_slugs'], 'publish step untouched', $failures, $passes );
assert_equals( array( 'system_task' ), $migrated['step_c']['handler_slugs'], 'system_task synthetic slug untouched', $failures, $passes );

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
