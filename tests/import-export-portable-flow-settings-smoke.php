<?php
/**
 * Pure-PHP smoke test for portable flow-step settings in CSV import/export.
 *
 * Run with: php tests/import-export-portable-flow-settings-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities\Flow {
	if ( ! class_exists( QueueAbility::class, false ) ) {
		class QueueAbility {
			const SLOT_PROMPT_QUEUE       = 'prompt_queue';
			const SLOT_CONFIG_PATCH_QUEUE = 'config_patch_queue';
		}
	}
}

namespace {

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', __DIR__ );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( 'datamachine_step_types' === $hook ) {
			return array(
				'ai'      => array( 'uses_handler' => false, 'multi_handler' => false ),
				'fetch'   => array( 'uses_handler' => true, 'multi_handler' => false ),
				'publish' => array( 'uses_handler' => true, 'multi_handler' => true ),
				'upsert'  => array( 'uses_handler' => true, 'multi_handler' => true ),
			);
		}

		return $value;
	}
}

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfigFactory.php';
require_once __DIR__ . '/../inc/Engine/Actions/ImportExport.php';

use DataMachine\Engine\Actions\ImportExport;

$failures = array();
$passes   = 0;

function assert_csv_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

function call_import_export_private( ImportExport $import_export, string $method, array $arg ): array {
	$reflection = new ReflectionMethod( ImportExport::class, $method );
	$result = $reflection->invoke( $import_export, $arg );
	return is_array( $result ) ? $result : array();
}

echo "import-export-portable-flow-settings-smoke\n";

$import_export = new ImportExport();

$ai_settings = call_import_export_private(
	$import_export,
	'export_flow_step_settings',
	array(
		'step_type'     => 'ai',
		'enabled_tools' => array( 'datamachine/get-github-pull-review-context', 'datamachine/upsert-github-pull-review-comment' ),
		'prompt_queue'  => array(
			array(
				'prompt'   => 'Review this PR.',
				'added_at' => '2026-04-27T00:00:00Z',
			),
		),
		'queue_mode'    => 'loop',
	)
);

assert_csv_equals(
	array( 'datamachine/get-github-pull-review-context', 'datamachine/upsert-github-pull-review-comment' ),
	$ai_settings['enabled_tools'] ?? null,
	'AI enabled_tools export as portable settings',
	$failures,
	$passes
);
assert_csv_equals( 'Review this PR.', $ai_settings['prompt_queue'][0]['prompt'] ?? null, 'AI prompt_queue exports as portable settings', $failures, $passes );
assert_csv_equals( 'loop', $ai_settings['queue_mode'] ?? null, 'AI queue_mode exports as portable settings', $failures, $passes );
assert_csv_equals( false, array_key_exists( 'handler_slug', $ai_settings ), 'AI settings stay handler-free', $failures, $passes );

$fetch_settings = call_import_export_private(
	$import_export,
	'export_flow_step_settings',
	array(
		'step_type'          => 'fetch',
		'handler_slug'       => 'webhook_payload',
		'handler_config'     => array( 'payload_path' => 'pull_request' ),
		'config_patch_queue' => array( array( 'after' => '2026-04-01' ) ),
		'queue_mode'         => 'drain',
	)
);

assert_csv_equals( 'webhook_payload', $fetch_settings['handler_slug'] ?? null, 'fetch handler_slug still exports', $failures, $passes );
assert_csv_equals( array( 'payload_path' => 'pull_request' ), $fetch_settings['handler_config'] ?? null, 'fetch handler_config still exports', $failures, $passes );
assert_csv_equals( array( array( 'after' => '2026-04-01' ) ), $fetch_settings['config_patch_queue'] ?? null, 'fetch config_patch_queue exports as portable settings', $failures, $passes );
assert_csv_equals( 'drain', $fetch_settings['queue_mode'] ?? null, 'fetch queue_mode exports as portable settings', $failures, $passes );

$normalized = call_import_export_private(
	$import_export,
	'normalize_portable_flow_step_settings',
	array(
		'enabled_tools' => array( 'datamachine/read-github-file' ),
		'queue_mode'    => 'static',
		'prompt_queue'  => array( array( 'prompt' => 'Pinned prompt.' ) ),
		'handler_slug'  => 'ignored_here',
	)
);

assert_csv_equals( array( 'datamachine/read-github-file' ), $normalized['enabled_tools'] ?? null, 'import normalization keeps enabled_tools', $failures, $passes );
assert_csv_equals( array( array( 'prompt' => 'Pinned prompt.' ) ), $normalized['prompt_queue'] ?? null, 'import normalization keeps prompt_queue', $failures, $passes );
assert_csv_equals( 'static', $normalized['queue_mode'] ?? null, 'import normalization keeps queue_mode', $failures, $passes );
assert_csv_equals( false, array_key_exists( 'handler_slug', $normalized ), 'portable normalization does not duplicate handler fields', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " portable flow settings assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} portable flow settings assertions passed.\n";
}
