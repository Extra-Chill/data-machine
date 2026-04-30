<?php
/**
 * Pure-PHP smoke test for direct wp-ai-client dispatch and tool schema normalization.
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
require_once $root . '/agents-api/agents-api.php';
require_once $root . '/inc/Engine/AI/Directives/DirectiveInterface.php';
require_once $root . '/inc/Engine/AI/Directives/DirectiveOutputValidator.php';
require_once $root . '/inc/Engine/AI/Directives/DirectiveRenderer.php';
require_once $root . '/inc/Engine/AI/Directives/DirectivePolicyResolver.php';
require_once $root . '/inc/Engine/AI/PromptBuilder.php';
require_once $root . '/inc/Engine/AI/ProviderRequestAssembler.php';
require_once $root . '/inc/Engine/AI/RequestMetadata.php';
require_once $root . '/inc/Engine/AI/WpAiClientProviderAdmin.php';
require_once $root . '/inc/Engine/AI/RequestBuilder.php';

function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $tag, $callback, $priority, $accepted_args );
}

function apply_filters( string $tag, $value, ...$args ) {
	unset( $tag, $args );
	return $value;
}

function do_action( string $tag, ...$args ): void {
	unset( $tag, $args );
}

function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags );
}

function size_format( $bytes ): string {
	return $bytes . ' B';
}

function sanitize_key( $key ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) ?? '' );
}

function get_option( string $option, $default = false ) {
	unset( $option );
	return $default;
}

$captured_request = array();
\DataMachine\Tests\Unit\Support\WpAiClientTestDouble::reset();
\DataMachine\Tests\Unit\Support\WpAiClientTestDouble::set_response_callback(
	function ( array $request ) use ( &$captured_request ): array {
		$captured_request = $request;
		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'Tool call follows.',
				'tool_calls' => array(
					array(
						'name'       => 'client/test_tool',
						'parameters' => array( 'reason' => 'covered' ),
						'id'         => 'call-1',
					),
				),
				'usage'      => array(
					'prompt_tokens'     => 3,
					'completion_tokens' => 4,
					'total_tokens'      => 7,
				),
			),
		);
	},
);

$response = \DataMachine\Engine\AI\RequestBuilder::build(
	array(
		array(
			'role'    => 'system',
			'content' => 'System directive.',
		),
		array(
			'role'    => 'user',
			'content' => 'Run tool.',
		),
		array(
			'role'    => 'user',
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'Describe this file.',
				),
				array(
					'type' => 'file',
					'url'  => 'https://example.com/image.png',
				),
			),
		),
	),
	'openai',
	'gpt-smoke',
	array(
		'client/test_tool' => array(
			'name'        => 'client/test_tool',
			'description' => 'Test tool.',
			'parameters'  => array(
				'reason' => array(
					'type'        => 'string',
					'description' => 'Skip reason.',
					'required'    => true,
				),
				'note'   => array(
					'type'     => 'string',
					'required' => false,
				),
			),
		),
	),
	'pipeline',
	array( 'job_id' => 1684 )
);

$schema = $captured_request['tools']['client/test_tool']['parameters'] ?? null;

$assert( $response instanceof \WordPress\AiClient\Results\DTO\GenerativeAiResult, 'RequestBuilder returns a wp-ai-client result DTO' );
$assert( ! is_array( $response ), 'RequestBuilder does not return the legacy provider envelope' );
$assert( 'Tool call follows.' === \DataMachine\Engine\AI\RequestBuilder::resultText( $response ), 'text content is read from the result DTO' );
$function_call = $response->getCandidates()[0]->getMessage()->getParts()[1]->getFunctionCall();
$assert( null !== $function_call, 'tool-call result remains a wp-ai-client FunctionCall DTO' );
$assert( 'client/test_tool' === $function_call->getName(), 'tool-call name is read from FunctionCall DTO' );
$assert( array( 'reason' => 'covered' ) === $function_call->getArgs(), 'tool-call args are read from FunctionCall DTO' );
$assert( 7 === $response->getTokenUsage()->getTotalTokens(), 'token usage is read from token usage DTO' );
$assert( 'System directive.' === ( $captured_request['messages'][0]['content'] ?? null ), 'system instruction reaches wp-ai-client builder' );
$assert( 'Run tool.' === ( $captured_request['messages'][1]['content'] ?? null ), 'user message reaches wp-ai-client builder' );
$assert( is_array( $captured_request['messages'][2]['content'] ?? null ), 'multimodal message content passes through to wp-ai-client builder' );
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
