<?php
/**
 * Backward-compatible include path for the Agents API transcript contract.
 *
 * @package DataMachine\Core\Database\Chat
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__, 4 ) . '/agents-api/inc/Core/Database/Chat/ConversationTranscriptStoreInterface.php';
