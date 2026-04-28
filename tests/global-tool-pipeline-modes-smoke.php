<?php
/**
 * Pure-PHP smoke test for global tool pipeline-mode visibility (#1442).
 *
 * Run with: php tests/global-tool-pipeline-modes-smoke.php
 *
 * Static/global tools that declare `pipeline` are auto-injected into every
 * pipeline AI step. This smoke pins the audit boundary: source/search/content
 * helpers can remain pipeline-visible, while chat affordances and duplicate
 * validation tools stay chat-only.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$test_filters_for_pipeline_modes = array();

function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $test_filters_for_pipeline_modes;
	$test_filters_for_pipeline_modes[ $tag ][ $priority ][] = $callback;
}

function apply_filters( string $tag, $value ) {
	global $test_filters_for_pipeline_modes;
	if ( empty( $test_filters_for_pipeline_modes[ $tag ] ) ) {
		return $value;
	}

	ksort( $test_filters_for_pipeline_modes[ $tag ] );
	foreach ( $test_filters_for_pipeline_modes[ $tag ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value );
		}
	}

	return $value;
}

require_once __DIR__ . '/../inc/Engine/AI/Tools/BaseTool.php';

$failures = array();
$passes   = 0;

function assert_true_for_pipeline_modes( bool $condition, string $name, array &$failures, int &$passes ): void {
	if ( $condition ) {
		$passes++;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
}

function file_contains_for_pipeline_modes( string $relative_path, string $needle ): bool {
	$contents = file_get_contents( __DIR__ . '/../' . $relative_path );
	return false !== $contents && false !== strpos( $contents, $needle );
}

function load_global_tool_for_pipeline_modes( string $relative_path, string $class_name ): void {
	require_once __DIR__ . '/../' . $relative_path;
	new $class_name();
}

function resolve_tools_for_pipeline_modes( string $mode ): array {
	$tools = apply_filters( 'datamachine_tools', array() );

	return array_filter(
		$tools,
		function ( $tool ) use ( $mode ) {
			return is_array( $tool ) && in_array( $mode, $tool['modes'] ?? array(), true );
		}
	);
}

echo "Global tool pipeline-mode smoke (#1442)\n";
echo "---------------------------------------\n";

$chat_only = array(
	'queue_validator'    => array( 'inc/Engine/AI/Tools/Global/QueueValidator.php', 'DataMachine\Engine\AI\Tools\Global\QueueValidator' ),
	'agent_memory'       => array( 'inc/Engine/AI/Tools/Global/AgentMemory.php', 'DataMachine\Engine\AI\Tools\Global\AgentMemory' ),
	'agent_daily_memory' => array( 'inc/Engine/AI/Tools/Global/AgentDailyMemory.php', 'DataMachine\Engine\AI\Tools\Global\AgentDailyMemory' ),
);

foreach ( $chat_only as $tool => [ $path, $class_name ] ) {
	load_global_tool_for_pipeline_modes( $path, $class_name );

	$chat_tools     = resolve_tools_for_pipeline_modes( 'chat' );
	$pipeline_tools = resolve_tools_for_pipeline_modes( 'pipeline' );

	assert_true_for_pipeline_modes(
		isset( $chat_tools[ $tool ] ),
		"{$tool} resolves in chat mode",
		$failures,
		$passes
	);
	assert_true_for_pipeline_modes(
		! isset( $pipeline_tools[ $tool ] ),
		"{$tool} does not resolve in pipeline mode",
		$failures,
		$passes
	);
}

$pipeline_tools = array(
	'web_fetch'             => array( 'inc/Engine/AI/Tools/Global/WebFetch.php', 'DataMachine\Engine\AI\Tools\Global\WebFetch' ),
	'wordpress_post_reader' => array( 'inc/Engine/AI/Tools/Global/WordPressPostReader.php', 'DataMachine\Engine\AI\Tools\Global\WordPressPostReader' ),
	'image_generation'      => array( 'inc/Engine/AI/Tools/Global/ImageGeneration.php', 'DataMachine\Engine\AI\Tools\Global\ImageGeneration' ),
	'google_search'         => array( 'inc/Engine/AI/Tools/Global/GoogleSearch.php', 'DataMachine\Engine\AI\Tools\Global\GoogleSearch' ),
	'google_analytics'      => array( 'inc/Engine/AI/Tools/Global/GoogleAnalytics.php', 'DataMachine\Engine\AI\Tools\Global\GoogleAnalytics' ),
	'google_search_console' => array( 'inc/Engine/AI/Tools/Global/GoogleSearchConsole.php', 'DataMachine\Engine\AI\Tools\Global\GoogleSearchConsole' ),
	'bing_webmaster'        => array( 'inc/Engine/AI/Tools/Global/BingWebmaster.php', 'DataMachine\Engine\AI\Tools\Global\BingWebmaster' ),
	'pagespeed'             => array( 'inc/Engine/AI/Tools/Global/PageSpeed.php', 'DataMachine\Engine\AI\Tools\Global\PageSpeed' ),
	'local_search'          => array( 'inc/Engine/AI/Tools/Global/LocalSearch.php', 'DataMachine\Engine\AI\Tools\Global\LocalSearch' ),
	'internal_link_audit'   => array( 'inc/Engine/AI/Tools/Global/InternalLinkAudit.php', 'DataMachine\Engine\AI\Tools\Global\InternalLinkAudit' ),
	'amazon_affiliate_link' => array( 'inc/Engine/AI/Tools/Global/AmazonAffiliateLink.php', 'DataMachine\Engine\AI\Tools\Global\AmazonAffiliateLink' ),
);

foreach ( $pipeline_tools as $tool => [ $path, $class_name ] ) {
	load_global_tool_for_pipeline_modes( $path, $class_name );
}

$resolved_pipeline_tools = resolve_tools_for_pipeline_modes( 'pipeline' );
foreach ( $pipeline_tools as $tool => $unused ) {
	assert_true_for_pipeline_modes(
		isset( $resolved_pipeline_tools[ $tool ] ),
		"{$tool} remains pipeline-visible",
		$failures,
		$passes
	);
}

assert_true_for_pipeline_modes(
	file_contains_for_pipeline_modes( 'inc/Core/Steps/AI/Directives/PipelineMemoryFilesDirective.php', "'modes'    => array( 'pipeline' )" ),
	'pipeline memory directive remains pipeline-scoped context injection',
	$failures,
	$passes
);
assert_true_for_pipeline_modes(
	file_contains_for_pipeline_modes( 'inc/Core/Steps/AI/Directives/FlowMemoryFilesDirective.php', "'modes'    => array( 'pipeline' )" ),
	'flow memory directive remains pipeline-scoped context injection',
	$failures,
	$passes
);
assert_true_for_pipeline_modes(
	file_contains_for_pipeline_modes( 'inc/Engine/AI/Directives/AgentDailyMemoryDirective.php', "'modes'    => array( 'chat', 'pipeline' )" ),
	'daily memory directive remains opt-in pipeline context injection',
	$failures,
	$passes
);

if ( ! empty( $failures ) ) {
	echo "\nFAILURES:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "\n{$passes} assertions passed.\n";
