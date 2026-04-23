<?php
/**
 * Chat Abilities
 *
 * Facade that loads and registers all modular Chat Session ability classes.
 *
 * @package DataMachine\Abilities
 * @since 0.31.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\Chat\ListChatSessionsAbility;
use DataMachine\Abilities\Chat\GetChatSessionAbility;
use DataMachine\Abilities\Chat\DeleteChatSessionAbility;
use DataMachine\Abilities\Chat\CreateChatSessionAbility;
use DataMachine\Abilities\Chat\MarkSessionReadAbility;
use DataMachine\Abilities\Chat\SendMessageAbility;

defined( 'ABSPATH' ) || exit;

class ChatAbilities {

	private static bool $registered = false;

	private ListChatSessionsAbility $list_sessions;
	private GetChatSessionAbility $get_session;
	private DeleteChatSessionAbility $delete_session;
	private CreateChatSessionAbility $create_session;
	private MarkSessionReadAbility $mark_session_read;
	private SendMessageAbility $send_message;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->list_sessions     = new ListChatSessionsAbility();
		$this->get_session       = new GetChatSessionAbility();
		$this->delete_session    = new DeleteChatSessionAbility();
		$this->create_session    = new CreateChatSessionAbility();
		$this->mark_session_read = new MarkSessionReadAbility();
		$this->send_message      = new SendMessageAbility();

		self::$registered = true;
	}
}
