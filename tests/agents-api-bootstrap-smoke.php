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

echo "\n[1] Module bootstrap exposes registration facade without Data Machine product code:\n";
agents_api_smoke_assert_equals( true, defined( 'AGENTS_API_LOADED' ), 'module marks itself loaded', $failures, $passes );
agents_api_smoke_assert_equals( true, defined( 'AGENTS_API_PATH' ), 'module path constant is available', $failures, $passes );
agents_api_smoke_assert_equals( realpath( __DIR__ . '/../agents-api' ) . '/', AGENTS_API_PATH, 'module path points at agents-api directory', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_register_agent' ), 'wp_register_agent helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent' ), 'WP_Agent value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agents_Registry' ), 'WP_Agents_Registry facade is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'DataMachine\\Core\\Database\\Chat\\ConversationTranscriptStoreInterface' ), 'ConversationTranscriptStoreInterface contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'DataMachine\\Engine\\AI\\AgentMessageEnvelope' ), 'AgentMessageEnvelope contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'DataMachine\\Engine\\AI\\AgentConversationResult' ), 'AgentConversationResult contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'DataMachine\\Engine\\AI\\Tools\\RuntimeToolDeclaration' ), 'RuntimeToolDeclaration contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'DataMachine\\Core\\FilesRepository\\AgentMemoryStoreInterface' ), 'AgentMemoryStoreInterface contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'DataMachine\\Core\\FilesRepository\\AgentMemoryScope' ), 'AgentMemoryScope contract is available', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Engine\\Agents\\AgentRegistry', false ), 'Data Machine registry is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Core\\Database\\Jobs\\Jobs', false ), 'Data Machine jobs repository is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Engine\\AI\\AIConversationLoop', false ), 'Data Machine compatibility loop is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Engine\\AI\\BuiltInAgentConversationRunner', false ), 'Data Machine built-in runner is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Core\\FilesRepository\\DiskAgentMemoryStore', false ), 'Data Machine disk memory store is not loaded by module bootstrap', $failures, $passes );

agents_api_smoke_finish( 'Agents API bootstrap', $failures, $passes );
