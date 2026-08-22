<?php
/**
 * Shared agent-side ability dispatcher.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Abilities;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Abilities API abilities and invokes core WP_Ability::execute().
 */
class WP_Agent_Ability_Dispatcher {

	/**
	 * Redacted placeholder used for sensitive parameter values.
	 */
	public const REDACTED_VALUE = WP_Agent_Tool_Parameters::REDACTED_VALUE;

	/**
	 * Dispatch an ability through WordPress core's WP_Ability::execute().
	 *
	 * @param string       $ability_name Registered ability name.
	 * @param array<mixed> $parameters   Ability parameters.
	 * @return mixed|\WP_Error Ability result, or a WP_Error before dispatch.
	 */
	public static function dispatch( string $ability_name, array $parameters = array() ) {
		$ability_name = trim( $ability_name );
		if ( '' === $ability_name ) {
			return new \WP_Error( 'ability_name_missing', 'Ability name is required.' );
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error( 'abilities_api_missing', 'Abilities API is not loaded; cannot dispatch ability.' );
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability instanceof \WP_Ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability is not registered.', array( 'ability_name' => $ability_name ) );
		}

		return $ability->execute( $parameters );
	}

	/**
	 * Return ability parameters with sensitive values redacted.
	 *
	 * Sensitivity is detected from explicit ability metadata and JSON-schema
	 * annotations, then backed by a conservative key-name fallback.
	 *
	 * @param string       $ability_name Registered ability name.
	 * @param array<mixed> $parameters   Raw ability parameters.
	 * @return array<string,mixed> Redacted parameters.
	 */
	public static function redacted_parameters( string $ability_name, array $parameters ): array {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( trim( $ability_name ) ) : null;
		$schema  = $ability instanceof \WP_Ability ? $ability->get_input_schema() : array();
		$meta    = array();

		if ( $ability instanceof \WP_Ability && method_exists( $ability, 'get_meta_item' ) ) {
			$meta['sensitive_parameters']  = $ability->get_meta_item( 'sensitive_parameters', array() );
			$meta['parameter_sensitivity'] = $ability->get_meta_item( 'parameter_sensitivity', array() );
		}

		return WP_Agent_Tool_Parameters::redactedParameters( $parameters, array_merge( $meta, $schema ) );
	}

	/**
	 * Resolve sensitive parameter paths declared by an ability.
	 *
	 * @param \WP_Ability $ability Ability object.
	 * @return array<string,bool> Dot paths keyed to true.
	 */
	public static function sensitive_parameter_paths( \WP_Ability $ability ): array {
		$definition = $ability->get_input_schema();
		if ( method_exists( $ability, 'get_meta_item' ) ) {
			$definition['sensitive_parameters']  = $ability->get_meta_item( 'sensitive_parameters', array() );
			$definition['parameter_sensitivity'] = $ability->get_meta_item( 'parameter_sensitivity', array() );
		}

		return WP_Agent_Tool_Parameters::sensitiveParameterPaths( $definition );
	}
}
