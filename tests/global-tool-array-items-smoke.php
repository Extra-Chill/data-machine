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
require_once $root . '/inc/Engine/AI/Tools/ToolPolicyResolver.php';

$tools_dir = $root . '/inc/Engine/AI/Tools/Global';
$tool_files = glob( $tools_dir . '/*.php' ) ?: array();

$schemas_checked = 0;
$array_params_checked = 0;

$assert_schema_shape = function ( array $schema, string $path ) use ( &$assert, &$assert_schema_shape, &$array_params_checked ): void {
	if ( array_key_exists( 'required', $schema ) ) {
		$assert(
			is_array( $schema['required'] ),
			"{$path}: required is an object-level JSON Schema array"
		);
	}

	if ( 'array' === ( $schema['type'] ?? null ) ) {
		++$array_params_checked;
		$assert(
			isset( $schema['items'] ) && is_array( $schema['items'] ),
			"{$path}: array schema declares 'items' (JSON Schema requirement; OpenAI strict mode rejects without)"
		);

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$assert(
				isset( $schema['items']['type'] ),
				"{$path}.items: items declares a 'type'"
			);
			$assert_schema_shape( $schema['items'], "{$path}.items" );
		}
	}

	foreach ( $schema['properties'] ?? array() as $property_name => $property_schema ) {
		if ( ! is_array( $property_schema ) ) {
			$assert( false, "{$path}.properties.{$property_name}: property schema is not an array" );
			continue;
		}

		$assert(
			! array_key_exists( 'required', $property_schema ) || is_array( $property_schema['required'] ),
			"{$path}.properties.{$property_name}: does not use property-level required flags"
		);
		$assert_schema_shape( $property_schema, "{$path}.properties.{$property_name}" );
	}
};

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
	$assert( 'object' === ( $definition['parameters']['type'] ?? null ), "{$class_short}: parameters is a canonical object schema" );
	$assert( isset( $definition['parameters']['properties'] ) && is_array( $definition['parameters']['properties'] ), "{$class_short}: parameters declares properties" );
	$assert_schema_shape( $definition['parameters'], $class_short . '.parameters' );
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
