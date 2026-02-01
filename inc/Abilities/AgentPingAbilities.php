<?php
/**
 * Agent Ping Abilities
 *
 * Facade that loads and registers all Agent Ping ability classes.
 *
 * @package DataMachine\Abilities
 * @since 0.18.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\AgentPing\TriggerAgentAbility;

defined( 'ABSPATH' ) || exit;

class AgentPingAbilities {

	private static bool $registered = false;

	private TriggerAgentAbility $trigger_agent;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->trigger_agent = new TriggerAgentAbility();

		self::$registered = true;
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Execute trigger-agent ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success status.
	 */
	public function executeTriggerAgent( array $input ): array {
		if ( ! isset( $this->trigger_agent ) ) {
			$this->trigger_agent = new TriggerAgentAbility();
		}
		return $this->trigger_agent->execute( $input );
	}
}
