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
$projection_filters = array();

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $projection_filters;
		unset( $accepted_args );
		$projection_filters[ $hook ][ $priority ][] = $callback;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		global $projection_filters;
		ksort( $projection_filters[ $hook ] );
		foreach ( $projection_filters[ $hook ] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback( $value );
			}
		}
		return $value;
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

require_once $root . '/inc/Engine/AI/Tools/ability-tool-projections.php';
$projections = apply_filters( 'datamachine_ability_tool_projections', array() );

$migrated = array(
	'local_search'         => 'datamachine/local-search',
	'image_generation'     => 'datamachine/generate-image',
	'internal_link_audit'  => 'datamachine/audit-internal-links',
	'wordpress_post_reader' => 'datamachine/get-wordpress-post',
);

foreach ( $migrated as $tool_name => $ability_slug ) {
	$projection = $projections[ $tool_name ] ?? array();
	$linked     = $projection['ability'] ?? reset( $projection['ability_map'] );

	assert_global_projection( ! empty( $projection ), "{$tool_name} registers an ability projection", $failures, $passes );
	assert_global_projection( $ability_slug === $linked, "{$tool_name} projection links {$ability_slug}", $failures, $passes );
	assert_global_projection( array( 'chat', 'pipeline' ) === ( $projection['modes'] ?? array() ), "{$tool_name} preserves chat and pipeline modes", $failures, $passes );
	assert_global_projection( ! isset( $projection['class'] ), "{$tool_name} no longer declares a class handler", $failures, $passes );
	assert_global_projection( ! isset( $projection['method'] ), "{$tool_name} no longer declares a method handler", $failures, $passes );
	assert_global_projection( 'object' === ( $projection['parameters']['type'] ?? '' ), "{$tool_name} preserves its object parameter schema", $failures, $passes );
}

foreach ( array( 'InternalLinkAudit.php', 'LocalSearch.php', 'WordPressPostReader.php' ) as $deleted_shell ) {
	assert_global_projection( ! file_exists( $root . '/inc/Engine/AI/Tools/Global/' . $deleted_shell ), "{$deleted_shell} declaration shell is deleted", $failures, $passes );
}

$image_source = read_global_tool_source( 'inc/Engine/AI/Tools/Global/ImageGeneration.php', $root );
assert_global_projection( false === strpos( $image_source, 'datamachine_register_ability_tool' ), 'image_generation projection is separated from its bounded configuration adapter', $failures, $passes );
assert_global_projection( false !== strpos( $image_source, "registerConfigurationHandlers( 'image_generation' )" ), 'image_generation retains its bounded configuration adapter', $failures, $passes );

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
