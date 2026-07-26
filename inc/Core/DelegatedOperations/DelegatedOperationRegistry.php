<?php
/**
 * Owner-registered delegated operation actions.
 *
 * @package DataMachine\Core\DelegatedOperations
 */

namespace DataMachine\Core\DelegatedOperations;

defined( 'ABSPATH' ) || exit;

final class DelegatedOperationRegistry {

	public const FILTER = 'datamachine_delegated_operation_actions';

	/**
	 * Resolve and validate one owner action.
	 *
	 * @return array|\WP_Error
	 */
	public function get( string $action_id ) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}\/[a-z0-9][a-z0-9_-]{0,63}$/', $action_id ) ) {
			return new \WP_Error( 'delegated_action_invalid', __( 'The delegated action identifier is invalid.', 'data-machine' ) );
		}

		$actions = apply_filters( self::FILTER, array() );
		$action  = is_array( $actions ) && is_array( $actions[ $action_id ] ?? null ) ? $actions[ $action_id ] : null;
		if ( null === $action ) {
			return new \WP_Error( 'delegated_action_unavailable', __( 'The delegated action is not registered.', 'data-machine' ) );
		}

		foreach ( array( 'normalize_input', 'authorize', 'prepare', 'project' ) as $callback ) {
			if ( ! is_callable( $action[ $callback ] ?? null ) ) {
				return new \WP_Error( 'delegated_action_invalid', __( 'The delegated action registration is incomplete.', 'data-machine' ) );
			}
		}

		$version = trim( (string) ( $action['version'] ?? '' ) );
		if ( '' === $version || strlen( $version ) > 64 ) {
			return new \WP_Error( 'delegated_action_invalid', __( 'The delegated action version is invalid.', 'data-machine' ) );
		}

		$action['version'] = $version;
		return $action;
	}
}
