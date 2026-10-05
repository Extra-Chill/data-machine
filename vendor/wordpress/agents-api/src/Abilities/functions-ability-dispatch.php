<?php
/**
 * Public ability dispatch helpers.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_agent_dispatch_ability' ) ) {
	/**
	 * Dispatch a registered WordPress ability through the stable Agents API facade.
	 *
	 * Runtime bundles and generated host code call this helper for ability
	 * invocation from in-process PHP.
	 *
	 * @param string       $ability_name Registered ability name.
	 * @param array<mixed> $parameters   Ability input parameters.
	 * @return mixed|WP_Error Ability result, or an error before dispatch.
	 */
	function wp_agent_dispatch_ability( string $ability_name, array $parameters = array() ) {
		if ( ! class_exists( 'AgentsAPI\\AI\\Abilities\\WP_Agent_Ability_Dispatcher' ) ) {
			return new WP_Error( 'agents_api_ability_dispatcher_unavailable', 'The Agents API ability dispatcher is unavailable.' );
		}

		return AgentsAPI\AI\Abilities\WP_Agent_Ability_Dispatcher::dispatch( $ability_name, $parameters );
	}
}

if ( ! function_exists( 'wp_agent_run_runtime_package' ) ) {
	/**
	 * Run a portable runtime package through the canonical runtime package ability.
	 *
	 * This is the stable PHP import boundary for hosts that invoke a
	 * generated runtime bundle from in-process code. The helper prefers the
	 * Abilities API registry and only falls back to the dispatcher when the
	 * registry is not loaded, which keeps normal WordPress requests on the
	 * canonical `agents/run-runtime-package` path.
	 *
	 * @param array<mixed> $input Canonical runtime package run input.
	 * @return array<string,mixed>|WP_Error Runtime package result or dispatch error.
	 */
	function wp_agent_run_runtime_package( array $input ) {
		$ability_name     = defined( 'AgentsAPI\\AI\\AGENTS_RUN_RUNTIME_PACKAGE_ABILITY' )
			? constant( 'AgentsAPI\\AI\\AGENTS_RUN_RUNTIME_PACKAGE_ABILITY' )
			: 'agents/run-runtime-package';
		if ( function_exists( 'AgentsAPI\\AI\\agents_register_runtime_package_run_abilities' ) ) {
			AgentsAPI\AI\agents_register_runtime_package_run_abilities();
		}

		$normalize_result = static function ( mixed $result ) {
			if ( $result instanceof WP_Error ) {
				return $result;
			}

			if ( ! is_array( $result ) ) {
				return new WP_Error(
					'agents_runtime_package_invalid_result',
					'The canonical agents/run-runtime-package ability returned an invalid result.'
				);
			}

			$normalized = array();
			foreach ( $result as $key => $value ) {
				if ( is_string( $key ) ) {
					$normalized[ $key ] = $value;
				}
			}

			return $normalized;
		};

		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( $ability_name );
			if ( $ability instanceof WP_Ability ) {
				return $normalize_result( $ability->execute( $input ) );
			}

			return new WP_Error(
				'agents_runtime_package_ability_unavailable',
				'The canonical agents/run-runtime-package ability is not registered.'
			);
		}

		if ( function_exists( 'AgentsAPI\\AI\\agents_runtime_package_run_dispatch' ) ) {
			return $normalize_result( AgentsAPI\AI\agents_runtime_package_run_dispatch( $input ) );
		}

		return new WP_Error(
			'agents_runtime_package_dispatcher_unavailable',
			'The Agents API runtime package dispatcher is unavailable.'
		);
	}
}
