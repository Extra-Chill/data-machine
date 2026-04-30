<?php
/**
 * Data Machine tool access policy.
 *
 * Adapts Data Machine permission helpers and legacy chat gating into the
 * generic tool policy filter's access-level callback shape.
 *
 * @package DataMachine\Engine\AI\Tools\Policy
 */

namespace DataMachine\Engine\AI\Tools\Policy;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

final class DataMachineToolAccessPolicy {

	/**
	 * Return whether chat tools require the legacy coarse permission gate.
	 *
	 * @param array $args Resolution arguments.
	 * @return bool Whether the caller can proceed past the legacy gate.
	 */
	public function passesLegacyChatGate( array $args ): bool {
		// @phpstan-ignore-next-line WordPress apply_filters accepts additional hook arguments.
		$require_use_tools_for_chat = apply_filters( 'datamachine_require_use_tools_for_chat_tools', false, $args );

		return ! $require_use_tools_for_chat || PermissionHelper::can( 'use_tools' );
	}

	/**
	 * Check if the current user meets an access level requirement.
	 *
	 * @param string $access_level One of: public, authenticated, author, editor, admin.
	 * @return bool Whether the current user has sufficient capabilities.
	 */
	public function canAccessLevel( string $access_level ): bool {
		if ( 'public' === $access_level ) {
			return true;
		}

		$action_map = array(
			'authenticated' => 'chat',
			'author'        => 'use_tools',
			'editor'        => 'view_logs',
			'admin'         => 'manage_settings',
		);

		$action = $action_map[ $access_level ] ?? 'manage_settings';
		return PermissionHelper::can( $action );
	}
}
