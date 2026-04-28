<?php
/**
 * PendingAction handler registration for bundle upgrades.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

add_filter(
	'datamachine_pending_action_handlers',
	static function ( $handlers ) {
		if ( ! is_array( $handlers ) ) {
			$handlers = array();
		}

		$handlers[ AgentBundleUpgradePendingAction::KIND ] = array(
			'apply'       => static function ( array $apply_input ) {
				return AgentBundleUpgradePendingAction::apply( $apply_input );
			},
			'can_resolve' => static function () {
				return current_user_can( 'manage_options' );
			},
		);

		return $handlers;
	}
);
