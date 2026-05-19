<?php
/**
 * Pure-PHP smoke test for `LegacyHandlerShapeMigrator`.
 *
 * Run with: php tests/legacy-handler-shape-migration-smoke.php
 *
 * Covers the production shapes observed before #2102 made the readers
 * canonical-only:
 *   - handler-backed fetch step with scalar handler_slug + handler_config
 *   - handler-backed event_import step with scalar shape (third-party step type)
 *   - handler-free system_task step with redundant scalar handler_config
 *   - already-canonical mixed flow that the migrator must leave untouched
 *   - flow-level metadata keys (e.g. memory_files) that must not be mutated
 *   - publish step in plural canonical shape (must pass through unchanged)
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		if ( 'datamachine_step_types' !== $hook ) {
			return $value;
		}

		return array(
			'ai'           => array( 'uses_handler' => false, 'multi_handler' => false ),
			'system_task'  => array( 'uses_handler' => false, 'multi_handler' => false ),
			'webhook_gate' => array( 'uses_handler' => false, 'multi_handler' => false ),
			'fetch'        => array( 'uses_handler' => true, 'multi_handler' => false ),
			'publish'      => array( 'uses_handler' => true, 'multi_handler' => true ),
			'upsert'       => array( 'uses_handler' => true, 'multi_handler' => true ),
			// Third-party step type registered by data-machine-events.
			'event_import' => array( 'uses_handler' => true, 'multi_handler' => false ),
		);
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['__legacy_handler_shape_migration_actions'][] = array( $hook, $args );
	}
}

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/LegacyHandlerShapeMigrator.php';

use DataMachine\Core\Steps\LegacyHandlerShapeMigrator;

$failures = array();
$passes   = 0;

function legacy_assert_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

function legacy_assert_absent( string $key, array $array_value, string $name, array &$failures, int &$passes ): void {
	legacy_assert_equals( false, array_key_exists( $key, $array_value ), $name, $failures, $passes );
}

echo "legacy handler shape migration smoke\n";
echo "-------------------------------------\n";

echo "\n[1] handler-backed fetch step: scalar reddit shape (wire.extrachill.com)\n";
$flow_config = array(
	'fetch_1' => array(
		'flow_step_id'     => 'fetch_1',
		'step_type'        => 'fetch',
		'pipeline_step_id' => 'fetch_p',
		'pipeline_id'      => 1,
		'flow_id'          => 1,
		'execution_order'  => 0,
		'enabled_tools'    => array(),
		'enabled'          => true,
		'handler_slug'     => 'reddit',
		'handler_config'   => array(
			'subreddit'         => 'bonnaroo',
			'sort_by'           => 'top',
			'min_upvotes'       => 30,
			'min_comment_count' => 10,
			'timeframe_limit'   => '72_hours',
		),
	),
);

$report = LegacyHandlerShapeMigrator::migrate_flow_config( $flow_config );
legacy_assert_equals( true, $report['changed'], 'fetch step: report flagged as changed', $failures, $passes );
legacy_assert_equals( 1, $report['steps_migrated'], 'fetch step: one step migrated', $failures, $passes );
legacy_assert_equals( array( 'fetch_1' ), $report['migrated_step_ids'], 'fetch step: step id surfaced in report', $failures, $passes );

$migrated_step = $report['config']['fetch_1'];
legacy_assert_equals( array( 'reddit' ), $migrated_step['handler_slugs'] ?? null, 'fetch step: scalar slug lifted into handler_slugs', $failures, $passes );
legacy_assert_equals(
	array(
		'subreddit'         => 'bonnaroo',
		'sort_by'           => 'top',
		'min_upvotes'       => 30,
		'min_comment_count' => 10,
		'timeframe_limit'   => '72_hours',
	),
	$migrated_step['handler_configs']['reddit'] ?? null,
	'fetch step: scalar config lifted into handler_configs[slug]',
	$failures,
	$passes
);
legacy_assert_absent( 'handler_slug', $migrated_step, 'fetch step: legacy handler_slug stripped', $failures, $passes );
legacy_assert_absent( 'handler_config', $migrated_step, 'fetch step: legacy handler_config stripped', $failures, $passes );
legacy_assert_absent( 'handler', $migrated_step, 'fetch step: legacy handler stripped', $failures, $passes );

echo "\n[2] handler-backed event_import step (events.extrachill.com)\n";
$flow_config = array(
	'event_1' => array(
		'flow_step_id'   => 'event_1',
		'step_type'      => 'event_import',
		'handler_slug'   => 'universal_web_scraper',
		'handler_config' => array(
			'source_url' => 'https://www.theroyalamerican.com/schedule',
			'venue'      => '2',
		),
	),
);

$report = LegacyHandlerShapeMigrator::migrate_flow_config( $flow_config );
$migrated_step = $report['config']['event_1'];
legacy_assert_equals( array( 'universal_web_scraper' ), $migrated_step['handler_slugs'] ?? null, 'event_import: third-party step type recognised as handler-backed', $failures, $passes );
legacy_assert_equals(
	array(
		'source_url' => 'https://www.theroyalamerican.com/schedule',
		'venue'      => '2',
	),
	$migrated_step['handler_configs']['universal_web_scraper'] ?? null,
	'event_import: scalar config lifted into handler_configs[slug]',
	$failures,
	$passes
);

echo "\n[3] handler-free system_task with redundant scalar handler_config + canonical flow_step_settings\n";
$flow_config = array(
	'task_1' => array(
		'flow_step_id'       => 'task_1',
		'step_type'          => 'system_task',
		'flow_step_settings' => array(
			'task'   => 'dispatch_message',
			'params' => array( 'channel' => 'kimaki' ),
		),
		'handler_config'     => array(
			'task'   => 'dispatch_message',
			'params' => array( 'channel' => 'kimaki' ),
		),
	),
);

$report = LegacyHandlerShapeMigrator::migrate_flow_config( $flow_config );
$migrated_step = $report['config']['task_1'];
legacy_assert_equals( true, $report['changed'], 'system_task: report flagged as changed (legacy keys dropped)', $failures, $passes );
legacy_assert_equals(
	array(
		'task'   => 'dispatch_message',
		'params' => array( 'channel' => 'kimaki' ),
	),
	$migrated_step['flow_step_settings'] ?? null,
	'system_task: canonical flow_step_settings preserved verbatim',
	$failures,
	$passes
);
legacy_assert_absent( 'handler_slug', $migrated_step, 'system_task: legacy handler_slug stripped', $failures, $passes );
legacy_assert_absent( 'handler_config', $migrated_step, 'system_task: legacy handler_config stripped', $failures, $passes );
legacy_assert_absent( 'handler_slugs', $migrated_step, 'system_task: no synthetic handler_slugs created for handler-free step', $failures, $passes );
legacy_assert_absent( 'handler_configs', $migrated_step, 'system_task: no synthetic handler_configs created for handler-free step', $failures, $passes );
legacy_assert_equals( 1, $report['dropped_orphan_legacy_config'], 'system_task: redundant legacy config recorded as dropped orphan', $failures, $passes );

echo "\n[4] handler-free system_task with scalar handler_config and no canonical settings yet (legacy-only)\n";
$flow_config = array(
	'task_2' => array(
		'flow_step_id'   => 'task_2',
		'step_type'      => 'system_task',
		'handler_config' => array(
			'task'   => 'dispatch_message',
			'params' => array( 'channel' => 'kimaki' ),
		),
	),
);

$report = LegacyHandlerShapeMigrator::migrate_flow_config( $flow_config );
$migrated_step = $report['config']['task_2'];
legacy_assert_equals(
	array(
		'task'   => 'dispatch_message',
		'params' => array( 'channel' => 'kimaki' ),
	),
	$migrated_step['flow_step_settings'] ?? null,
	'system_task (legacy-only): scalar handler_config lifted into flow_step_settings',
	$failures,
	$passes
);
legacy_assert_absent( 'handler_config', $migrated_step, 'system_task (legacy-only): scalar handler_config stripped after lift', $failures, $passes );

echo "\n[5] already-canonical mixed flow: fetch + ai + publish; only legacy-shape steps mutate\n";
$flow_config = array(
	'fetch_1' => array(
		'flow_step_id'    => 'fetch_1',
		'step_type'       => 'fetch',
		'handler_slug'    => 'reddit',
		'handler_config'  => array( 'subreddit' => 'festivals' ),
	),
	'ai_1'    => array(
		'flow_step_id' => 'ai_1',
		'step_type'    => 'ai',
		'enabled_tools' => array(),
		'prompt_queue' => array( array( 'prompt' => 'summarise' ) ),
	),
	'publish_1' => array(
		'flow_step_id'    => 'publish_1',
		'step_type'       => 'publish',
		'handler_slugs'   => array( 'wordpress_publish' ),
		'handler_configs' => array(
			'wordpress_publish' => array(
				'post_type'   => 'festival_wire',
				'post_status' => 'publish',
			),
		),
	),
);

$report = LegacyHandlerShapeMigrator::migrate_flow_config( $flow_config );
legacy_assert_equals( 1, $report['steps_migrated'], 'mixed flow: only the legacy fetch step migrates', $failures, $passes );
legacy_assert_equals( array( 'fetch_1' ), $report['migrated_step_ids'], 'mixed flow: report names the migrated step id', $failures, $passes );

$migrated_fetch = $report['config']['fetch_1'];
legacy_assert_equals( array( 'reddit' ), $migrated_fetch['handler_slugs'] ?? null, 'mixed flow: fetch slug lifted', $failures, $passes );
legacy_assert_equals( array( 'subreddit' => 'festivals' ), $migrated_fetch['handler_configs']['reddit'] ?? null, 'mixed flow: fetch config lifted', $failures, $passes );

$untouched_publish = $report['config']['publish_1'];
legacy_assert_equals( array( 'wordpress_publish' ), $untouched_publish['handler_slugs'] ?? null, 'mixed flow: canonical publish slugs untouched', $failures, $passes );
legacy_assert_equals(
	array(
		'post_type'   => 'festival_wire',
		'post_status' => 'publish',
	),
	$untouched_publish['handler_configs']['wordpress_publish'] ?? null,
	'mixed flow: canonical publish config untouched',
	$failures,
	$passes
);

$untouched_ai = $report['config']['ai_1'];
legacy_assert_equals( array( array( 'prompt' => 'summarise' ) ), $untouched_ai['prompt_queue'] ?? null, 'mixed flow: ai prompt_queue untouched', $failures, $passes );

echo "\n[6] flow with no legacy fields and no changes: report is_changed=false\n";
$flow_config = array(
	'task_1' => array(
		'flow_step_id'       => 'task_1',
		'step_type'          => 'system_task',
		'flow_step_settings' => array( 'task' => 'noop' ),
	),
);
$report = LegacyHandlerShapeMigrator::migrate_flow_config( $flow_config );
legacy_assert_equals( false, $report['changed'], 'clean flow: no changes reported', $failures, $passes );
legacy_assert_equals( 0, $report['steps_migrated'], 'clean flow: zero migrations', $failures, $passes );
legacy_assert_equals( 1, $report['steps_already_canonical'], 'clean flow: counted as already-canonical', $failures, $passes );

echo "\n[7] flow-level metadata keys are not treated as steps\n";
$flow_config = array(
	'memory_files' => array( 'briefing.md' ),
	'fetch_1'      => array(
		'flow_step_id'   => 'fetch_1',
		'step_type'      => 'fetch',
		'handler_slug'   => 'rss',
		'handler_config' => array( 'feed_url' => 'https://example.com/rss' ),
	),
);

$report = LegacyHandlerShapeMigrator::migrate_flow_config( $flow_config );
legacy_assert_equals( 1, $report['steps_migrated'], 'metadata keys: only the real step migrates', $failures, $passes );
legacy_assert_equals( array( 'briefing.md' ), $report['config']['memory_files'] ?? null, 'metadata keys: memory_files preserved untouched', $failures, $passes );
legacy_assert_equals( array( 'rss' ), $report['config']['fetch_1']['handler_slugs'] ?? null, 'metadata keys: fetch step still migrated', $failures, $passes );
legacy_assert_equals( 1, $report['steps_skipped_non_step'], 'metadata keys: counter incremented for non-step entry', $failures, $passes );

echo "\n[8] orphan legacy: handler-backed step with handler_config but no slug\n";
$flow_config = array(
	'fetch_x' => array(
		'flow_step_id'   => 'fetch_x',
		'step_type'      => 'fetch',
		'handler_config' => array( 'feed_url' => 'https://orphan.example.com/rss' ),
	),
);
$report = LegacyHandlerShapeMigrator::migrate_flow_config( $flow_config );
legacy_assert_equals( 1, $report['dropped_orphan_legacy_config'], 'orphan: counted as dropped', $failures, $passes );
$migrated_orphan = $report['config']['fetch_x'];
legacy_assert_absent( 'handler_config', $migrated_orphan, 'orphan: legacy handler_config stripped', $failures, $passes );
legacy_assert_absent( 'handler_slugs', $migrated_orphan, 'orphan: no synthetic slug list invented', $failures, $passes );

echo "\n[9] re-running migrator on already-migrated output is a no-op\n";
$flow_config = array(
	'fetch_1' => array(
		'flow_step_id'   => 'fetch_1',
		'step_type'      => 'fetch',
		'handler_slug'   => 'reddit',
		'handler_config' => array( 'subreddit' => 'idempotent' ),
	),
);
$first  = LegacyHandlerShapeMigrator::migrate_flow_config( $flow_config );
$second = LegacyHandlerShapeMigrator::migrate_flow_config( $first['config'] );
legacy_assert_equals( true, $first['changed'], 'idempotent: first pass changes', $failures, $passes );
legacy_assert_equals( false, $second['changed'], 'idempotent: second pass is a no-op', $failures, $passes );
legacy_assert_equals( $first['config'], $second['config'], 'idempotent: config stable across passes', $failures, $passes );

echo "\n-------------------------------------\n";
$total = $passes + count( $failures );
echo "{$passes} / {$total} passed\n";

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "\nAll assertions passed.\n";
