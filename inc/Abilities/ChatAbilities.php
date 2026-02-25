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

defined( 'ABSPATH' ) || exit;

class ChatAbilities {

	private static bool $registered = false;

	private ListChatSessionsAbility $list_sessions;
	private GetChatSessionAbility $get_session;
	private DeleteChatSessionAbility $delete_session;
	private CreateChatSessionAbility $create_session;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->list_sessions  = new ListChatSessionsAbility();
		$this->get_session    = new GetChatSessionAbility();
		$this->delete_session = new DeleteChatSessionAbility();
		$this->create_session = new CreateChatSessionAbility();

		self::$registered = true;
	}
}
