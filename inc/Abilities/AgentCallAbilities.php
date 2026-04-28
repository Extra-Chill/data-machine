<?php
/**
 * Agent Call Abilities.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\AgentCall\AgentCallAbility;

defined( 'ABSPATH' ) || exit;

class AgentCallAbilities {

	private static bool $registered = false;

	private AgentCallAbility $agent_call;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->agent_call = new AgentCallAbility();

		self::$registered = true;
	}
}
