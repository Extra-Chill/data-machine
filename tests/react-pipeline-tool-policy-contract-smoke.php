<?php
/**
 * Pure-PHP smoke test for the React pipeline AI tool policy summary (#1450).
 *
 * Run with: php tests/react-pipeline-tool-policy-contract-smoke.php
 *
 * #1448 has not landed yet, so the admin UI must not invent editable
 * writes for tool policy fields. This smoke locks the first acceptable
 * slice: PipelineStepCard.jsx renders a read-only summary for the
 * canonical runtime fields already present on stepConfig.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

/**
 * Assert helper.
 *
 * @param string $name      Test case name.
 * @param bool   $condition Pass/fail.
 */
function assert_pipeline_tool_policy_contract( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

/**
 * Read a React source file relative to the pipelines React root.
 *
 * @param string $relative Path under `inc/Core/Admin/Pages/Pipelines/assets/react`.
 * @return string File contents.
 */
function read_pipeline_react_file( string $relative ): string {
	$base = dirname( __DIR__ ) . '/inc/Core/Admin/Pages/Pipelines/assets/react';
	$path = $base . '/' . ltrim( $relative, '/' );
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Missing React source: {$path}\n" );
		exit( 2 );
	}
	return (string) file_get_contents( $path );
}

echo "=== React Pipeline Tool Policy Contract Smoke (#1450) ===\n";

$card = read_pipeline_react_file( 'components/pipelines/PipelineStepCard.jsx' );
$api  = read_pipeline_react_file( 'utils/api.js' );

echo "\n[PipelineStepCard.jsx:1] Canonical policy fields are read from stepConfig\n";
assert_pipeline_tool_policy_contract(
	'reads stepConfig.enabled_tools',
	false !== strpos( $card, 'stepConfig?.enabled_tools' )
);
assert_pipeline_tool_policy_contract(
	'reads stepConfig.disabled_tools',
	false !== strpos( $card, 'stepConfig?.disabled_tools' )
);
assert_pipeline_tool_policy_contract(
	'reads stepConfig.tool_categories',
	false !== strpos( $card, 'stepConfig?.tool_categories' )
);

echo "\n[PipelineStepCard.jsx:2] Summary labels match runtime policy vocabulary\n";
assert_pipeline_tool_policy_contract(
	'allowlist label exists',
	false !== strpos( $card, "__( 'Allowlist', 'data-machine' )" )
);
assert_pipeline_tool_policy_contract(
	'denylist label exists',
	false !== strpos( $card, "__( 'Denylist', 'data-machine' )" )
);
assert_pipeline_tool_policy_contract(
	'categories label exists',
	false !== strpos( $card, "__( 'Categories', 'data-machine' )" )
);

echo "\n[PipelineStepCard.jsx:3] UI is explicitly read-only and handler tools stay separate\n";
assert_pipeline_tool_policy_contract(
	'read-only summary copy exists',
	false !== strpos( $card, 'Read-only summary of the pipeline AI step policy' )
);
assert_pipeline_tool_policy_contract(
	'handler tools are described as resolved separately',
	false !== strpos( $card, 'Handler tools required by adjacent steps are resolved separately at runtime' )
);
assert_pipeline_tool_policy_contract(
	'policy summary class exists',
	false !== strpos( $card, 'datamachine-ai-tool-policy-summary' )
);

echo "\n[PipelineStepCard.jsx:4] No edit controls or mutation helpers claim policy write support\n";
assert_pipeline_tool_policy_contract(
	'no updateToolPolicy helper in component',
	false === strpos( $card, 'updateToolPolicy' )
);
assert_pipeline_tool_policy_contract(
	'no ToolPolicyField edit component in component',
	false === strpos( $card, 'ToolPolicyField' )
);
assert_pipeline_tool_policy_contract(
	'policy fields are not added to updateSystemPrompt payload',
	false === strpos( $api, 'enabled_tools:' )
		&& false === strpos( $api, 'disabled_tools:' )
		&& false === strpos( $api, 'tool_categories:' )
);

echo "\n[PipelineStepCard.jsx:5] Display helper accepts existing array/string/object shapes\n";
assert_pipeline_tool_policy_contract(
	'normalizeToolPolicyList helper exists',
	false !== strpos( $card, 'const normalizeToolPolicyList' )
);
assert_pipeline_tool_policy_contract(
	'array values are filtered and stringified',
	false !== strpos( $card, 'value.filter( Boolean ).map( String )' )
);
assert_pipeline_tool_policy_contract(
	'object values render truthy keys',
	false !== strpos( $card, 'Object.entries( value )' )
		&& false !== strpos( $card, '.filter( ( [ , enabled ] ) => Boolean( enabled ) )' )
);

echo "\n=== Summary ===\n";
if ( $failed > 0 ) {
	echo "FAILED: {$failed}/{$total} assertions failed\n";
	exit( 1 );
}

echo "PASSED: {$total} assertions\n";
