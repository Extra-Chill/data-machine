<?php
/**
 * Pure-PHP smoke test for wp-ai-client tool schema normalization.
 *
 * Run with: php tests/wp-ai-client-tool-schema-smoke.php
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

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

require_once $root . '/tests/Unit/Support/WpAiClientTestDoubles.php';
require_once $root . '/inc/Engine/AI/WpAiClientAdapter.php';

$method = new ReflectionMethod( DataMachine\Engine\AI\WpAiClientAdapter::class, 'ensureJsonSchema' );

$schema = $method->invoke(
	null,
	array(
		'reason' => array(
			'type'        => 'string',
			'description' => 'Skip reason.',
			'required'    => true,
		),
		'note'   => array(
			'type'     => 'string',
			'required' => false,
		),
	)
);

$assert( is_array( $schema ), 'legacy parameter map normalizes to a schema array' );
$assert( 'object' === ( $schema['type'] ?? null ), 'legacy parameter map is wrapped as an object schema' );
$assert( array( 'reason' ) === ( $schema['required'] ?? null ), 'property-level required=true is lifted to object-level required array' );
$assert( ! isset( $schema['properties']['reason']['required'] ), 'required flag is removed from required property schema' );
$assert( ! isset( $schema['properties']['note']['required'] ), 'required flag is removed from optional property schema' );
$assert( 'string' === ( $schema['properties']['reason']['type'] ?? null ), 'property schema fields are preserved' );

echo "\n{$assertions} assertions, " . count( $failures ) . " failures\n";

if ( ! empty( $failures ) ) {
	exit( 1 );
}
