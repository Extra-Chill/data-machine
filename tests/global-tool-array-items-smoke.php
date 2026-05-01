<?php
/**
 * Smoke tests for global-tool parameter schemas.
 *
 * Regression coverage for the LocalSearch `post_types` schema bug: every
 * global AI tool that declares an `array`-typed parameter MUST also declare
 * an `items` keyword. JSON Schema requires it, OpenAI's strict-mode tool
 * validation rejects requests that omit it, and wp-ai-client now forwards
 * those rejections instead of silently stripping them.
 *
 * Run with: php tests/global-tool-array-items-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$assertions = 0;
$failures   = array();

$assert = function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = $message;
		echo "FAIL: {$message}\n";
		return;
	}

	echo "PASS: {$message}\n";
};

// Bare-bones bootstrap so the global-tool classes load without WordPress.
$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

// Stub WP functions/constants the global-tool classes touch at construction
// time. None of these are exercised by getToolDefinition() itself; we only
// need them to satisfy guard clauses and `defined( 'ABSPATH' ) || exit` at
// the top of each tool file.
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $tag, $callback, $priority, $accepted_args );
	}
}

require_once $root . '/inc/Engine/AI/Tools/BaseTool.php';

$tools_dir = $root . '/inc/Engine/AI/Tools/Global';
$tool_files = glob( $tools_dir . '/*.php' ) ?: array();

$schemas_checked = 0;
$array_params_checked = 0;

foreach ( $tool_files as $file ) {
	require_once $file;

	$class_short = basename( $file, '.php' );
	$class_fqn   = 'DataMachine\\Engine\\AI\\Tools\\Global\\' . $class_short;

	if ( ! class_exists( $class_fqn ) ) {
		$assert( false, "{$class_short}: class not found at {$class_fqn}" );
		continue;
	}

	if ( ! method_exists( $class_fqn, 'getToolDefinition' ) ) {
		// Tool may not declare a definition (e.g. abstract). Skip without
		// counting against us — the schema check only applies to tools that
		// register parameters.
		continue;
	}

	try {
		$instance   = new $class_fqn();
		$definition = $instance->getToolDefinition();
	} catch ( \Throwable $e ) {
		$assert( false, "{$class_short}: getToolDefinition threw: " . $e->getMessage() );
		continue;
	}

	if ( ! is_array( $definition ) || empty( $definition['parameters'] ) || ! is_array( $definition['parameters'] ) ) {
		// No params to validate. Tool may legitimately take none.
		continue;
	}

	++$schemas_checked;

	foreach ( $definition['parameters'] as $param_name => $param_schema ) {
		if ( ! is_array( $param_schema ) ) {
			$assert(
				false,
				"{$class_short}.{$param_name}: parameter schema is not an array"
			);
			continue;
		}

		$type = $param_schema['type'] ?? null;

		if ( 'array' !== $type ) {
			continue;
		}

		++$array_params_checked;

		$assert(
			isset( $param_schema['items'] ) && is_array( $param_schema['items'] ),
			"{$class_short}.{$param_name}: array-typed parameter declares 'items' (JSON Schema requirement; OpenAI strict mode rejects without)"
		);

		if ( isset( $param_schema['items'] ) && is_array( $param_schema['items'] ) ) {
			$assert(
				isset( $param_schema['items']['type'] ),
				"{$class_short}.{$param_name}.items: items declares a 'type'"
			);
		}
	}
}

$assert(
	$schemas_checked > 0,
	"smoke covered at least one global tool definition (got {$schemas_checked})"
);

$assert(
	$array_params_checked > 0,
	"smoke exercised at least one array-typed parameter (got {$array_params_checked})"
);

echo "\n{$assertions} assertions, " . count( $failures ) . " failures\n";
echo "(Checked {$schemas_checked} global tools, {$array_params_checked} array-typed parameters)\n";

if ( ! empty( $failures ) ) {
	exit( 1 );
}
