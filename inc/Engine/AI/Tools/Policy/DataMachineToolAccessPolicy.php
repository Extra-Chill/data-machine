<?php
/**
 * Data Machine tool access policy.
 *
 * Adapts Data Machine permission helpers and chat gating into the
 * generic tool policy filter's access-level callback shape.
 *
 * @package DataMachine\Engine\AI\Tools\Policy
 */

namespace DataMachine\Engine\AI\Tools\Policy;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

final class DataMachineToolAccessPolicy {

	/**
	 * Return whether chat tools pass the coarse permission gate.
	 *
	 * @param array $args Resolution arguments.
	 * @return bool Whether the caller can proceed past the chat gate.
	 */
	public function passesChatGate( array $args ): bool {
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

	/**
	 * Check if the current user can access a tool declaration.
	 *
	 * @param array  $tool Tool definition.
	 * @param string $name Tool name.
	 * @return bool Whether the current request can access the tool.
	 */
	public function canAccessTool( array $tool, string $name ): bool {
		unset( $name );

		$ability_slugs = $this->getAbilitySlugs( $tool );
		if ( ! empty( $ability_slugs ) ) {
			$registry = \WP_Abilities_Registry::get_instance();
			foreach ( $ability_slugs as $slug ) {
				if ( method_exists( $registry, 'is_registered' ) && ! $registry->is_registered( $slug ) ) {
					return false;
				}

				$ability = $registry->get_registered( $slug );
				$category = is_object( $ability ) && method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
				if ( ! PermissionHelper::can_use_ability( $slug, $category ) ) {
					return false;
				}

				if ( ! $ability || true !== $ability->check_permissions() ) {
					return false;
				}
			}

			return true;
		}

		$access_level = $tool['access_level'] ?? 'admin';
		return is_string( $access_level ) && $this->canAccessLevel( $access_level );
	}

	/**
	 * Return linked ability slugs from a tool declaration.
	 *
	 * @param array $tool Tool definition.
	 * @return string[] Ability slugs.
	 */
	private function getAbilitySlugs( array $tool ): array {
		$ability_slugs = array();

		if ( ! empty( $tool['ability'] ) && is_string( $tool['ability'] ) ) {
			$ability_slugs[] = $tool['ability'];
		}

		if ( ! empty( $tool['abilities'] ) && is_array( $tool['abilities'] ) ) {
			foreach ( $tool['abilities'] as $slug ) {
				if ( is_string( $slug ) && '' !== $slug ) {
					$ability_slugs[] = $slug;
				}
			}
		}

		return array_values( array_unique( $ability_slugs ) );
	}
}
