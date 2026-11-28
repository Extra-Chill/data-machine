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
	public function get( string $action_id, ?string $version = null ) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}\/[a-z0-9][a-z0-9_-]{0,63}$/', $action_id ) ) {
			return new \WP_Error( 'delegated_action_invalid', __( 'The delegated action identifier is invalid.', 'data-machine' ) );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant is the canonical Data Machine delegated-operation registry hook.
		$actions = apply_filters( self::FILTER, array() );
		$action  = is_array( $actions ) && is_array( $actions[ $action_id ] ?? null ) ? $actions[ $action_id ] : null;
		if ( null === $action ) {
			return new \WP_Error( 'delegated_action_unavailable', __( 'The delegated action is not registered.', 'data-machine' ) );
		}
		$current_version = is_string( $action['version'] ?? null ) || is_int( $action['version'] ?? null ) ? trim( (string) $action['version'] ) : '';
		if ( null !== $version && $version !== $current_version ) {
			$versions = is_array( $action['versions'] ?? null ) ? $action['versions'] : array();
			$action   = is_array( $versions[ $version ] ?? null ) ? $versions[ $version ] : null;
			if ( null === $action ) {
				return new \WP_Error( 'delegated_action_version_unavailable', __( 'The delegated action contract version is not registered.', 'data-machine' ) );
			}
			$action['version'] = $version;
		}

		foreach ( array( 'normalize_input', 'authorize', 'prepare', 'project' ) as $callback ) {
			if ( ! is_callable( $action[ $callback ] ?? null ) ) {
				return new \WP_Error( 'delegated_action_invalid', __( 'The delegated action registration is incomplete.', 'data-machine' ) );
			}
		}
		if ( array_key_exists( 'retry', $action ) && ! is_callable( $action['retry'] ) ) {
			return new \WP_Error( 'delegated_action_invalid', __( 'The delegated action retry callback is invalid.', 'data-machine' ) );
		}

		$raw_version = $action['version'] ?? null;
		if ( ! is_string( $raw_version ) && ! is_int( $raw_version ) ) {
			return new \WP_Error( 'delegated_action_invalid', __( 'The delegated action version is invalid.', 'data-machine' ) );
		}
		$version = trim( (string) $raw_version );
		if ( '' === $version || strlen( $version ) > 64 ) {
			return new \WP_Error( 'delegated_action_invalid', __( 'The delegated action version is invalid.', 'data-machine' ) );
		}

		$action['version'] = $version;
		return $action;
	}
}
