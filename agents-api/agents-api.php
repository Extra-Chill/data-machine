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
require_once AGENTS_API_PATH . 'inc/class-wp-agents-registry.php';
require_once AGENTS_API_PATH . 'inc/register-agents.php';
require_once AGENTS_API_PATH . 'inc/Core/Database/Chat/ConversationTranscriptStoreInterface.php';
require_once AGENTS_API_PATH . 'inc/Engine/AI/AgentMessageEnvelope.php';
require_once AGENTS_API_PATH . 'inc/Engine/AI/AgentConversationResult.php';
require_once AGENTS_API_PATH . 'inc/Engine/AI/Tools/RuntimeToolDeclaration.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryScope.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryListEntry.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryReadResult.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryWriteResult.php';
require_once AGENTS_API_PATH . 'inc/Core/FilesRepository/AgentMemoryStoreInterface.php';
