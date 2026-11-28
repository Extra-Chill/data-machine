<?php
/**
 * Focused contract test for the typed_artifact publish handler.
 *
 * Run with: php tests/typed-artifact-handler-smoke.php
 */

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action() {}
}
require_once $root . '/inc/Core/Steps/Handlers/HttpRequestHelpers.php';
require_once $root . '/inc/Core/Steps/Publish/Handlers/PublishHandler.php';
require_once $root . '/inc/Core/Steps/HandlerRegistrationTrait.php';
require_once $root . '/inc/Core/Steps/Publish/Handlers/TypedArtifact/TypedArtifact.php';

$failures = 0;
$passes   = 0;

function typed_artifact_handler_assert( bool $condition, string $message, int &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	++$failures;
	echo "FAIL: {$message}\n";
}

$tools = \DataMachine\Core\Steps\Publish\Handlers\TypedArtifact\TypedArtifact::registerAITool(
	array(),
	'typed_artifact',
	array(
		'output_key' => 'concept_packet',
		'schema'     => 'wp-site-generator/ConceptPacket/v1',
		'artifact'   => 'ConceptPacket',
	)
);

typed_artifact_handler_assert( isset( $tools['emit_typed_artifact'] ), 'typed_artifact exposes emit_typed_artifact tool', $failures, $passes );
typed_artifact_handler_assert( array( 'payload' ) === ( $tools['emit_typed_artifact']['parameters']['required'] ?? null ), 'tool requires payload object', $failures, $passes );
typed_artifact_handler_assert( 'concept_packet' === ( $tools['emit_typed_artifact']['output_key'] ?? null ), 'tool carries output key metadata', $failures, $passes );
typed_artifact_handler_assert( 'wp-site-generator/ConceptPacket/v1' === ( $tools['emit_typed_artifact']['artifact_schema'] ?? null ), 'tool carries artifact schema metadata', $failures, $passes );

if ( 0 !== $failures ) {
	echo "typed-artifact-handler-smoke failed: {$failures} failure(s), {$passes} pass(es).\n";
	exit( 1 );
}

echo "typed-artifact-handler-smoke passed: {$passes} assertion(s).\n";
