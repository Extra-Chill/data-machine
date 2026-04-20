<?php
/**
 * Agent Remote Call Abilities
 *
 * Facade that loads and registers cross-site agent call abilities.
 *
 * @package DataMachine\Abilities
 * @since 0.71.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\AgentRemoteCall\AgentRemoteCallAbility;

defined( 'ABSPATH' ) || exit;

class AgentRemoteCallAbilities {

	private static bool $registered = false;

	private AgentRemoteCallAbility $remote_call;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->remote_call = new AgentRemoteCallAbility();

		self::$registered = true;
	}
}
