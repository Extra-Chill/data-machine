<?php
/**
 * Pure-PHP smoke test for global AI tools migrated to ability projections.
 *
 * Run with: php tests/global-ability-projections-smoke.php
 *
 * @package DataMachine\Tests
 */

$root     = dirname( __DIR__ );
$failures = array();
$passes   = 0;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $hook, $callback, $priority, $accepted_args );
	}
}

function assert_global_projection( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  ✓ {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "  ✗ {$message}\n";
}

function read_global_tool_source( string $relative_path, string $root ): string {
	$source = file_get_contents( $root . '/' . $relative_path );
	return is_string( $source ) ? $source : '';
}

echo "global-ability-projections-smoke\n";

require_once $root . '/inc/Engine/AI/Tools/BaseTool.php';
require_once $root . '/inc/Engine/AI/Tools/ability-tool-projections.php';
require_once $root . '/inc/Engine/AI/Tools/Global/InternalLinkAudit.php';
new \DataMachine\Engine\AI\Tools\Global\InternalLinkAudit();
assert_global_projection( true, 'internal_link_audit class loads and registers without parse errors', $failures, $passes );

$migrated = array(
	'inc/Engine/AI/Tools/Global/LocalSearch.php'         => array( 'local_search', 'datamachine/local-search' ),
	'inc/Engine/AI/Tools/Global/ImageGeneration.php'     => array( 'image_generation', 'datamachine/generate-image' ),
	'inc/Engine/AI/Tools/Global/InternalLinkAudit.php'   => array( 'internal_link_audit', 'datamachine/audit-internal-links' ),
	'inc/Engine/AI/Tools/Global/WordPressPostReader.php' => array( 'wordpress_post_reader', 'datamachine/get-wordpress-post' ),
);

foreach ( $migrated as $path => $expectations ) {
	$source       = read_global_tool_source( $path, $root );
	$tool_name    = $expectations[0];
	$ability_slug = $expectations[1];

	assert_global_projection( false !== strpos( $source, 'datamachine_register_ability_tool' ), "{$tool_name} registers an ability projection", $failures, $passes );
	assert_global_projection( false !== strpos( $source, "'{$ability_slug}'" ), "{$tool_name} projection links {$ability_slug}", $failures, $passes );
	assert_global_projection( false === strpos( $source, "'class'           => __CLASS__" ), "{$tool_name} no longer declares a class handler", $failures, $passes );
	assert_global_projection( false === strpos( $source, "'method'          => 'handle_tool_call'" ), "{$tool_name} no longer declares a method handler", $failures, $passes );
}

$exceptions = array(
	'inc/Engine/AI/Tools/Global/AgentMemory.php'       => 'agent_memory',
	'inc/Engine/AI/Tools/Global/AgentDailyMemory.php'  => 'agent_daily_memory',
	'inc/Engine/AI/Tools/Global/QueueValidator.php'    => 'queue_validator',
	'inc/Engine/AI/Tools/Global/WebFetch.php'          => 'web_fetch',
);

foreach ( $exceptions as $path => $tool_name ) {
	$source = read_global_tool_source( $path, $root );
	assert_global_projection( false !== strpos( $source, "'method'" ), "{$tool_name} remains an explicit class/method exception", $failures, $passes );
}

$docs = read_global_tool_source( 'docs/ai-tools/tools-overview.md', $root );
foreach ( $exceptions as $tool_name ) {
	assert_global_projection( false !== strpos( $docs, "`{$tool_name}`" ), "{$tool_name} exception is documented", $failures, $passes );
}

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " global projection assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} global projection assertions passed.\n";
