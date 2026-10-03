<?php
/**
 * Principal-aware chat database store.
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

use AgentsAPI\Core\Database\Chat\WP_Agent_Principal_Conversation_Store;
use AgentsAPI\Core\Database\Chat\WP_Agent_Principal_Conversation_Session_Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Agents API principal-owner marker to the default store.
 *
 * Keep the base {@see Chat} class free of this optional interface so Data
 * Machine can activate cleanly when an older Agents API dependency has not yet
 * shipped the principal-owned session contract.
 */
class PrincipalChat extends Chat implements WP_Agent_Principal_Conversation_Store, WP_Agent_Principal_Conversation_Session_Reader {
}
