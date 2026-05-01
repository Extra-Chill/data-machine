<?php
/**
 * Agents API bootstrap.
 *
 * WordPress-shaped agent substrate.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'AGENTS_API_LOADED' ) ) {
	return;
}

define( 'AGENTS_API_LOADED', true );
define( 'AGENTS_API_PATH', __DIR__ . '/' );

require_once AGENTS_API_PATH . 'inc/class-wp-agent.php';
require_once AGENTS_API_PATH . 'inc/class-wp-agent-package-artifact.php';
require_once AGENTS_API_PATH . 'inc/class-wp-agent-package-artifact-type.php';
require_once AGENTS_API_PATH . 'inc/class-wp-agent-package-artifacts-registry.php';
require_once AGENTS_API_PATH . 'inc/class-wp-agent-package.php';
require_once AGENTS_API_PATH . 'inc/class-wp-agent-package-adoption-diff.php';
require_once AGENTS_API_PATH . 'inc/class-wp-agent-package-adoption-result.php';
require_once AGENTS_API_PATH . 'inc/class-wp-agent-package-adopter-interface.php';
require_once AGENTS_API_PATH . 'inc/class-wp-agents-registry.php';
require_once AGENTS_API_PATH . 'inc/register-agents.php';
require_once AGENTS_API_PATH . 'inc/register-agent-package-artifacts.php';
require_once AGENTS_API_PATH . 'inc/Core/Database/Chat/ConversationTranscriptStoreInterface.php';
require_once AGENTS_API_PATH . 'inc/AI/AgentMessageEnvelope.php';
require_once AGENTS_API_PATH . 'inc/AI/AgentConversationResult.php';
require_once AGENTS_API_PATH . 'inc/AI/Tools/RuntimeToolDeclaration.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryScope.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryListEntry.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryReadResult.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryWriteResult.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryStoreInterface.php';

add_action( 'init', array( 'WP_Agents_Registry', 'init' ), 10 );
