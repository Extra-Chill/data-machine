<?php
/**
 * Pure-PHP smoke test for AI request inspection (#1423).
 *
 * Run with: php tests/ai-request-inspector-smoke.php
 *
 * Covers the provider-request assembly seam used by the CLI inspector without
 * booting WordPress or dispatching to a provider.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$test_filters = array();

function add_filter( string $hook, callable $callback, int $priority = 10, int $_accepted_args = 1 ): void {
	global $test_filters;
	$test_filters[ $hook ][ $priority ][] = $callback;
}

function apply_filters( string $hook, $value, ...$args ) {
	global $test_filters;
	if ( empty( $test_filters[ $hook ] ) ) {
		return $value;
	}
	ksort( $test_filters[ $hook ] );
	foreach ( $test_filters[ $hook ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}
	return $value;
}

function do_action( string $_hook, ...$args ): void {}

function wp_json_encode( $value, int $flags = 0 ) {
	return json_encode( $value, $flags );
}

require_once __DIR__ . '/../inc/Engine/AI/Directives/DirectiveInterface.php';
require_once __DIR__ . '/../inc/Engine/AI/Directives/DirectivePolicyResolver.php';
require_once __DIR__ . '/../inc/Engine/AI/Directives/DirectiveOutputValidator.php';
require_once __DIR__ . '/../inc/Engine/AI/Directives/DirectiveRenderer.php';
require_once __DIR__ . '/../inc/Engine/AI/PromptBuilder.php';
require_once __DIR__ . '/../inc/Engine/AI/RequestBuilder.php';

class Test_Request_Inspector_Directive implements \DataMachine\Engine\AI\Directives\DirectiveInterface {
	public static function get_outputs( string $_provider_name, array $_tools, ?string $_step_id = null, array $payload = array() ): array {
		return array(
			array(
				'type'    => 'system_text',
				'content' => 'Inspect directive for job ' . ( $payload['job_id'] ?? 'none' ),
			),
		);
	}
}

$failed = 0;
$total  = 0;

function assert_test( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
	} else {
		echo "  [FAIL] $name" . ( $detail ? " — $detail" : '' ) . "\n";
		++$failed;
	}
}

echo "Case 1: RequestBuilder::assemble builds the request without provider dispatch\n";

$dispatch_count = 0;
add_filter(
	'chubes_ai_request',
	function ( $request ) use ( &$dispatch_count ) {
		++$dispatch_count;
		return array( 'success' => false, 'error' => 'should not dispatch' );
	}
);

add_filter(
	'datamachine_directives',
	function ( array $directives ): array {
		$directives[] = array(
			'class'    => Test_Request_Inspector_Directive::class,
			'priority' => 20,
			'modes'    => array( 'pipeline' ),
		);
		return $directives;
	}
);

$messages = array(
	array(
		'role'    => 'user',
		'content' => 'Original user packet',
	),
);
$tools = array(
	'inspect_tool' => array(
		'description' => 'Small fake tool',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'name' => array( 'type' => 'string' ),
			),
		),
	),
);

$assembled = \DataMachine\Engine\AI\RequestBuilder::assemble(
	$messages,
	'openai',
	'gpt-test',
	$tools,
	'pipeline',
	array(
		'job_id'       => 1423,
		'flow_step_id' => 'ai_step_1',
		'step_id'      => 'pipeline_ai_1',
	)
);

assert_test( 'assemble did not call chubes_ai_request', 0 === $dispatch_count );
assert_test( 'request model set', 'gpt-test' === ( $assembled['request']['model'] ?? '' ) );
assert_test( 'directive prepended a system message', 'system' === ( $assembled['request']['messages'][0]['role'] ?? '' ) );
assert_test( 'original user message preserved', 'Original user packet' === ( $assembled['request']['messages'][1]['content'] ?? '' ) );
assert_test( 'tool restructured with explicit name', 'inspect_tool' === ( $assembled['structured_tools']['inspect_tool']['name'] ?? '' ) );

echo "\nCase 2: directive breakdown and byte counts are deterministic\n";

$breakdown = $assembled['directive_breakdown'][0] ?? array();
assert_test( 'fake directive appears in breakdown', Test_Request_Inspector_Directive::class === ( $breakdown['class'] ?? '' ) );
assert_test( 'directive priority recorded', 20 === ( $breakdown['priority'] ?? null ) );
assert_test( 'directive rendered one message', 1 === ( $breakdown['rendered_message_count'] ?? null ) );
assert_test( 'directive content byte count is exact', strlen( 'Inspect directive for job 1423' ) === ( $breakdown['content_bytes'] ?? null ) );

$request_json_bytes = strlen( wp_json_encode( $assembled['request'], JSON_UNESCAPED_UNICODE ) );
$messages_json_bytes = strlen( wp_json_encode( $assembled['request']['messages'], JSON_UNESCAPED_UNICODE ) );
$tools_json_bytes = strlen( wp_json_encode( $assembled['request']['tools'], JSON_UNESCAPED_UNICODE ) );

assert_test( 'request JSON byte count stable', 330 === $request_json_bytes, 'got ' . $request_json_bytes );
assert_test( 'messages JSON byte count stable', 111 === $messages_json_bytes, 'got ' . $messages_json_bytes );
assert_test( 'tools JSON byte count stable', 178 === $tools_json_bytes, 'got ' . $tools_json_bytes );

echo "\nCase 3: CLI command surface is registered and documented\n";

$bootstrap = file_get_contents( __DIR__ . '/../inc/Cli/Bootstrap.php' );
$command   = file_get_contents( __DIR__ . '/../inc/Cli/Commands/AICommand.php' );

assert_test( 'datamachine ai namespace registered', false !== strpos( $bootstrap, "datamachine ai" ) );
assert_test( 'inspect-request subcommand declared', false !== strpos( $command, '@subcommand inspect-request' ) );
assert_test( 'CLI routes through inspect request ability', false !== strpos( $command, 'InspectRequestAbility' ) );
assert_test( '--job option documented', false !== strpos( $command, '--job=<job_id>' ) );
assert_test( '--step option documented', false !== strpos( $command, '--step=<flow_step_id>' ) );
assert_test( 'json output path exists', false !== strpos( $command, "'json' === \$format" ) );
assert_test( 'table output includes directive section', false !== strpos( $command, "Directives" ) );
assert_test( 'table output includes largest tools section', false !== strpos( $command, "Largest tools" ) );

$plugin = file_get_contents( __DIR__ . '/../data-machine.php' );
$ability = file_get_contents( __DIR__ . '/../inc/Abilities/AI/InspectRequestAbility.php' );

assert_test( 'inspect request ability loaded by plugin bootstrap', false !== strpos( $plugin, 'InspectRequestAbility.php' ) );
assert_test( 'inspect request ability instantiated by plugin bootstrap', false !== strpos( $plugin, 'new \\DataMachine\\Abilities\\AI\\InspectRequestAbility()' ) );
assert_test( 'ability registers datamachine/inspect-ai-request', false !== strpos( $ability, 'datamachine/inspect-ai-request' ) );

echo "\n$total assertions, $failed failures\n";
if ( $failed > 0 ) {
	exit( 1 );
}
