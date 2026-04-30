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

$failures = array();
$passes   = 0;

echo "agents-api-bootstrap-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$namespace_map = array(
	'DataMachine\\Engine\\AI\\AgentMessageEnvelope'                         => 'AgentsAPI\\Engine\\AI\\AgentMessageEnvelope',
	'DataMachine\\Engine\\AI\\AgentConversationResult'                      => 'AgentsAPI\\Engine\\AI\\AgentConversationResult',
	'DataMachine\\Engine\\AI\\Tools\\RuntimeToolDeclaration'                => 'AgentsAPI\\Engine\\AI\\Tools\\RuntimeToolDeclaration',
	'DataMachine\\Core\\Database\\Chat\\ConversationTranscriptStoreInterface' => 'AgentsAPI\\Core\\Database\\Chat\\ConversationTranscriptStoreInterface',
	'DataMachine\\Core\\FilesRepository\\AgentMemoryStoreInterface'           => 'AgentsAPI\\Core\\FilesRepository\\AgentMemoryStoreInterface',
	'DataMachine\\Core\\FilesRepository\\AgentMemoryScope'                    => 'AgentsAPI\\Core\\FilesRepository\\AgentMemoryScope',
);

echo "\n[1] Module bootstrap exposes registration facade without Data Machine product code:\n";
agents_api_smoke_assert_equals( true, defined( 'AGENTS_API_LOADED' ), 'module marks itself loaded', $failures, $passes );
agents_api_smoke_assert_equals( true, defined( 'AGENTS_API_PATH' ), 'module path constant is available', $failures, $passes );
agents_api_smoke_assert_equals( realpath( __DIR__ . '/../agents-api' ) . '/', AGENTS_API_PATH, 'module path points at agents-api directory', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_register_agent' ), 'wp_register_agent helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_get_agent' ), 'wp_get_agent helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_get_agents' ), 'wp_get_agents helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_has_agent' ), 'wp_has_agent helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_unregister_agent' ), 'wp_unregister_agent helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent' ), 'WP_Agent value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agents_Registry' ), 'WP_Agents_Registry facade is available', $failures, $passes );
foreach ( $namespace_map as $legacy_class => $target_class ) {
	agents_api_smoke_assert_equals( true, class_exists( $target_class ) || interface_exists( $target_class ), $target_class . ' contract is available', $failures, $passes );
	agents_api_smoke_assert_equals( false, class_exists( $legacy_class, false ) || interface_exists( $legacy_class, false ), $legacy_class . ' compatibility alias is not loaded', $failures, $passes );
}
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Engine\\Agents\\AgentRegistry', false ), 'Data Machine registry is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Core\\Database\\Jobs\\Jobs', false ), 'Data Machine jobs repository is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Engine\\AI\\AIConversationLoop', false ), 'Data Machine compatibility loop is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Engine\\AI\\BuiltInAgentConversationRunner', false ), 'Data Machine built-in runner is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Core\\FilesRepository\\DiskAgentMemoryStore', false ), 'Data Machine disk memory store is not loaded by module bootstrap', $failures, $passes );

echo "\n[2] Module source keeps Data Machine vocabulary out of agents-api contracts:\n";
$agents_api_files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( AGENTS_API_PATH, FilesystemIterator::SKIP_DOTS )
);
foreach ( $agents_api_files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	$contents = file_get_contents( $file->getPathname() );
	agents_api_smoke_assert_equals(
		0,
		preg_match( '/^\s*(namespace|use)\s+DataMachine\\\\/m', is_string( $contents ) ? $contents : '' ),
		'agents-api source has no Data Machine namespace declaration/import: ' . str_replace( AGENTS_API_PATH, '', $file->getPathname() ),
		$failures,
		$passes
	);
	agents_api_smoke_assert_equals(
		false,
		false !== strpos( is_string( $contents ) ? $contents : '', 'Data Machine' ),
		'agents-api source has no Data Machine prose coupling: ' . str_replace( AGENTS_API_PATH, '', $file->getPathname() ),
		$failures,
		$passes
	);
}

agents_api_smoke_finish( 'Agents API bootstrap', $failures, $passes );
