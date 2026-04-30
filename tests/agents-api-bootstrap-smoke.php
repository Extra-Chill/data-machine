<?php
/**
 * Pure-PHP smoke test for the in-repo Agents API module boundary (#1631/#1639).
 *
 * Run with: php tests/agents-api-bootstrap-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['__agents_api_smoke_actions'] = array();

function sanitize_title( string $value ): string {
	$value = strtolower( $value );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
	return trim( (string) $value, '-' );
}

function sanitize_file_name( string $value ): string {
	return basename( $value );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $accepted_args );
	$GLOBALS['__agents_api_smoke_actions'][ $hook ][ $priority ][] = $callback;
}

function do_action( string $hook, ...$args ): void {
	$callbacks = $GLOBALS['__agents_api_smoke_actions'][ $hook ] ?? array();
	ksort( $callbacks );

	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}
}

$failures = array();
$passes   = 0;

function assert_agents_api_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

echo "agents-api-bootstrap-smoke\n";

require_once __DIR__ . '/../agents-api/agents-api.php';

echo "\n[1] Module bootstrap exposes registration facade without Data Machine product code:\n";
assert_agents_api_equals( true, defined( 'AGENTS_API_LOADED' ), 'module marks itself loaded', $failures, $passes );
assert_agents_api_equals( true, function_exists( 'wp_register_agent' ), 'wp_register_agent helper is available', $failures, $passes );
assert_agents_api_equals( true, class_exists( 'WP_Agent' ), 'WP_Agent value object is available', $failures, $passes );
assert_agents_api_equals( true, class_exists( 'WP_Agents_Registry' ), 'WP_Agents_Registry facade is available', $failures, $passes );
assert_agents_api_equals( true, interface_exists( 'DataMachine\\Core\\Database\\Chat\\ConversationTranscriptStoreInterface' ), 'ConversationTranscriptStoreInterface contract is available', $failures, $passes );
assert_agents_api_equals( true, class_exists( 'DataMachine\\Engine\\AI\\AgentMessageEnvelope' ), 'AgentMessageEnvelope contract is available', $failures, $passes );
assert_agents_api_equals( true, class_exists( 'DataMachine\\Engine\\AI\\AgentConversationResult' ), 'AgentConversationResult contract is available', $failures, $passes );
assert_agents_api_equals( true, class_exists( 'DataMachine\\Engine\\AI\\Tools\\RuntimeToolDeclaration' ), 'RuntimeToolDeclaration contract is available', $failures, $passes );
assert_agents_api_equals( true, interface_exists( 'DataMachine\\Core\\FilesRepository\\AgentMemoryStoreInterface' ), 'AgentMemoryStoreInterface contract is available', $failures, $passes );
assert_agents_api_equals( true, class_exists( 'DataMachine\\Core\\FilesRepository\\AgentMemoryScope' ), 'AgentMemoryScope contract is available', $failures, $passes );
assert_agents_api_equals( false, class_exists( 'DataMachine\\Engine\\Agents\\AgentRegistry', false ), 'Data Machine registry is not loaded by module bootstrap', $failures, $passes );
assert_agents_api_equals( false, class_exists( 'DataMachine\\Engine\\AI\\AIConversationLoop', false ), 'Data Machine compatibility loop is not loaded by module bootstrap', $failures, $passes );
assert_agents_api_equals( false, class_exists( 'DataMachine\\Engine\\AI\\BuiltInAgentConversationRunner', false ), 'Data Machine built-in runner is not loaded by module bootstrap', $failures, $passes );
assert_agents_api_equals( false, class_exists( 'DataMachine\\Core\\FilesRepository\\DiskAgentMemoryStore', false ), 'Data Machine disk memory store is not loaded by module bootstrap', $failures, $passes );

echo "\n[2] Public registration hook collects definitions once and stays side-effect free:\n";
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			new WP_Agent(
				'Example Agent!',
				array(
					'label'          => 'Example Agent',
					'description'    => 'Standalone module smoke',
					'memory_seeds'   => array( '../SOUL.md' => '/tmp/seed-soul.md' ),
					'owner_resolver' => static fn() => 7,
					'default_config' => array( 'default_provider' => 'openai' ),
				)
			)
		);
	}
);

$definitions = WP_Agents_Registry::get_all();
assert_agents_api_equals( array( 'example-agent' ), array_keys( $definitions ), 'definition slug is normalized', $failures, $passes );
assert_agents_api_equals( 'Example Agent', $definitions['example-agent']['label'] ?? '', 'definition label is preserved', $failures, $passes );
assert_agents_api_equals( array( 'SOUL.md' => '/tmp/seed-soul.md' ), $definitions['example-agent']['memory_seeds'] ?? array(), 'memory seed filenames are sanitized', $failures, $passes );
assert_agents_api_equals( array( 'example-agent' ), array_keys( WP_Agents_Registry::get_all() ), 'registration hook fires only once', $failures, $passes );

echo "\n[3] agents-api files do not import Data Machine product namespaces:\n";
$forbidden_import = false;
$forbidden_loop   = false;
$iterator         = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../agents-api' ) );
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$source = (string) file_get_contents( $file->getPathname() );
	if ( preg_match( '/(?:use\s+|new\s+|extends\s+|implements\s+|::|instanceof\s+)\\?DataMachine\\\\/', $source ) ) {
		$forbidden_import = true;
	}

	if ( preg_match( '/\\b(?:AIConversationLoop|BuiltInAgentConversationRunner)\\b/', $source ) ) {
		$forbidden_loop = true;
	}

	if ( $forbidden_import || $forbidden_loop ) {
		break;
	}
}
assert_agents_api_equals( false, $forbidden_import, 'agents-api has no DataMachine namespace imports', $failures, $passes );
assert_agents_api_equals( false, $forbidden_loop, 'agents-api does not contain Data Machine loop implementation classes', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " Agents API bootstrap assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} Agents API bootstrap assertions passed.\n";
