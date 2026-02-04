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
use DataMachine\Abilities\PermissionHelper;

use DataMachine\Abilities\AgentPing\SendPingAbility;

defined( 'ABSPATH' ) || exit;

class AgentPingAbilities {

	private static bool $registered = false;

	private SendPingAbility $send_ping;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->send_ping = new SendPingAbility();

		self::$registered = true;
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute send-ping ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success status.
	 */
	public function executeSendPing( array $input ): array {
		if ( ! isset( $this->send_ping ) ) {
			$this->send_ping = new SendPingAbility();
		}
		return $this->send_ping->execute( $input );
	}
}
