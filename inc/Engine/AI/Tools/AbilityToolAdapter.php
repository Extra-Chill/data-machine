<?php
/**
 * Ability-backed AI tool adapter.
 *
 * @package DataMachine\Engine\AI\Tools
 */

namespace DataMachine\Engine\AI\Tools;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Result;
use DataMachine\Core\AbilityResult;
use DataMachine\Engine\AI\ToolSchemaNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes WordPress Ability projection and execution for AI tools.
 */
final class AbilityToolAdapter {

	/**
	 * Metadata keys that callers may override on generated declarations.
	 *
	 * @var string[]
	 */
	public const OVERRIDE_KEYS = array(
		'access_level',
		'action_kind',
		'action_policy',
		'action_policy_chat',
		'action_policy_pipeline',
		'action_policy_system',
		'action_preview_redact',
		'build_action_preview',
		'build_action_summary',
		'client_context_bindings',
		'description',
		'label',
		'mandatory',
		'modes',
		'parameters',
		'requires_config',
		'requires_opt_in',
		'runtime',
		'strip_action_parameter',
		'strip_internal_result_keys',
	);

	/**
	 * Build a Data Machine tool declaration from a registered ability.
	 *
	 * @param string $tool_name   Model-facing tool name.
	 * @param array  $declaration Ability projection declaration.
	 * @param object $registry    WP_Abilities_Registry instance.
	 * @return array<string,mixed>
	 */
	public static function declaration( string $tool_name, array $declaration, object $registry ): array {
		$ability_slug = self::primaryAbilitySlug( $declaration );
		if ( '' === $ability_slug ) {
			return array();
		}

		if ( method_exists( $registry, 'is_registered' ) && ! $registry->is_registered( $ability_slug ) ) {
			return array();
		}

		$ability = $registry->get_registered( $ability_slug );
		if ( ! is_object( $ability ) ) {
			return array();
		}

		$meta        = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
		$meta        = is_array( $meta ) ? $meta : array();
		$annotations = is_array( $meta['annotations'] ?? null ) ? $meta['annotations'] : array();

		$tool = array(
			'name'              => $tool_name,
			'ability'           => $ability_slug,
			'execution_ability' => $ability_slug,
			'ability_category'  => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'annotations'       => $annotations,
			'description'       => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'label'             => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $tool_name,
			'modes'             => array( ToolPolicyResolver::MODE_CHAT ),
			'parameters'        => self::normalizeParameters( method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : array() ),
		);

		if ( isset( $declaration['ability_map'] ) && is_array( $declaration['ability_map'] ) ) {
			$tool['ability_map'] = self::normalizeAbilityMap( $declaration['ability_map'] );
			unset( $tool['execution_ability'] );
		}

		foreach ( self::OVERRIDE_KEYS as $key ) {
			if ( array_key_exists( $key, $declaration ) ) {
				$tool[ $key ] = $declaration[ $key ];
			}
		}

		$tool['parameters'] = self::normalizeParameters( $tool['parameters'] ?? array() );
		$tool['modes']      = ToolPolicyResolver::normalizeModes( $tool['modes'] ?? array( ToolPolicyResolver::MODE_CHAT ) );

		try {
			$canonical = WP_Agent_Tool_Declaration::normalizeForServer(
				array(
					'name'        => $tool_name,
					'description' => $tool['description'] ?? '',
					'parameters'  => $tool['parameters'],
					'runtime'     => $tool['runtime'] ?? array(),
				)
			);
			$tool['description'] = $canonical['description'];
			$tool['parameters']  = is_array( $canonical['parameters'] ?? null ) ? $canonical['parameters'] : $tool['parameters'];
			if ( isset( $canonical['runtime'] ) ) {
				$tool['runtime'] = $canonical['runtime'];
			}
		} catch ( \InvalidArgumentException $error ) {
			unset( $error );
		}

		unset( $tool['name'] );

		return $tool;
	}

	/**
	 * Execute an ability-backed tool declaration.
	 *
	 * @param string $tool_name       Tool name.
	 * @param array  $parameters      Complete tool parameters.
	 * @param array  $tool_definition Tool definition.
	 * @return array<string,mixed>
	 */
	public static function execute( string $tool_name, array $parameters, array $tool_definition ): array {
		$ability_slug = self::resolveExecutionAbilitySlug( $parameters, $tool_definition );
		if ( '' === $ability_slug ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				sprintf( "Tool '%s' does not declare an executable ability.", $tool_name ),
				array( 'error_type' => 'missing_execution_ability' )
			);
		}

		if ( ! class_exists( '\\WP_Abilities_Registry' ) ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				sprintf( "Tool '%s' references ability '%s', but the WordPress Abilities API is not available.", $tool_name, $ability_slug ),
				array( 'ability' => $ability_slug )
			);
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( method_exists( $registry, 'is_registered' ) && ! $registry->is_registered( $ability_slug ) ) {
			return self::missingAbilityResult( $tool_name, $ability_slug );
		}

		$ability = $registry->get_registered( $ability_slug );
		if ( ! $ability ) {
			return self::missingAbilityResult( $tool_name, $ability_slug );
		}

		$input      = self::buildAbilityInput( $parameters, $tool_definition );
		$permission = $ability->check_permissions( $input );
		if ( is_wp_error( $permission ) ) {
			return WP_Agent_Tool_Result::error( $tool_name, $permission->get_error_message(), array( 'ability' => $ability_slug ) );
		}

		if ( true !== $permission ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				sprintf( "Tool '%s' is not permitted by ability '%s'.", $tool_name, $ability_slug ),
				array( 'ability' => $ability_slug )
			);
		}

		$result = AbilityResult::normalize_tool_result( $ability->execute( $input ), $tool_name, $ability_slug );
		if ( ! is_array( $result ) || empty( $result['success'] ) ) {
			return AbilityResult::normalize_tool_envelope( $result, $tool_name, array( 'ability' => $ability_slug ) );
		}

		$payload = array_key_exists( 'result', $result ) ? $result['result'] : ( $result['data'] ?? $result );
		if ( ! empty( $tool_definition['strip_internal_result_keys'] ) && is_array( $payload ) ) {
			$payload = array_filter(
				$payload,
				static fn( $key ): bool => is_string( $key ) && 0 !== strpos( $key, '_' ),
				ARRAY_FILTER_USE_KEY
			);
		}

		return WP_Agent_Tool_Result::success( $tool_name, $payload, array( 'ability' => $ability_slug ) );
	}

	/**
	 * Return the canonical ability slug used for projection-time metadata.
	 *
	 * @param array $declaration Ability projection declaration.
	 * @return string Ability slug.
	 */
	public static function primaryAbilitySlug( array $declaration ): string {
		if ( isset( $declaration['ability'] ) && is_string( $declaration['ability'] ) ) {
			return $declaration['ability'];
		}

		$map = isset( $declaration['ability_map'] ) && is_array( $declaration['ability_map'] ) ? self::normalizeAbilityMap( $declaration['ability_map'] ) : array();
		foreach ( $map as $ability_slug ) {
			return $ability_slug;
		}

		return '';
	}

	/**
	 * Resolve the concrete ability slug for this tool call.
	 *
	 * @param array $parameters      Tool parameters.
	 * @param array $tool_definition Tool definition.
	 * @return string Ability slug.
	 */
	private static function resolveExecutionAbilitySlug( array $parameters, array $tool_definition ): string {
		$map = isset( $tool_definition['ability_map'] ) && is_array( $tool_definition['ability_map'] ) ? self::normalizeAbilityMap( $tool_definition['ability_map'] ) : array();
		if ( ! empty( $map ) ) {
			$action = isset( $parameters['action'] ) && is_scalar( $parameters['action'] ) ? (string) $parameters['action'] : '';
			return isset( $map[ $action ] ) ? $map[ $action ] : '';
		}

		return isset( $tool_definition['execution_ability'] ) && is_string( $tool_definition['execution_ability'] ) ? $tool_definition['execution_ability'] : '';
	}

	/**
	 * Build ability input from tool parameters.
	 *
	 * @param array $parameters      Tool parameters.
	 * @param array $tool_definition Tool definition.
	 * @return array<string,mixed>
	 */
	private static function buildAbilityInput( array $parameters, array $tool_definition ): array {
		$input = $parameters;

		if ( ! empty( $tool_definition['ability_map'] ) && ! empty( $tool_definition['strip_action_parameter'] ) ) {
			unset( $input['action'] );
		}

		return $input;
	}

	/**
	 * Normalize an action => ability map.
	 *
	 * @param array $map Raw ability map.
	 * @return array<string,string>
	 */
	private static function normalizeAbilityMap( array $map ): array {
		$normalized = array();
		foreach ( $map as $action => $ability_slug ) {
			if ( is_string( $action ) && '' !== $action && is_string( $ability_slug ) && '' !== $ability_slug ) {
				$normalized[ $action ] = $ability_slug;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize parameters to the canonical model-facing schema shape.
	 *
	 * @param mixed $parameters Raw parameters.
	 * @return array<string,mixed>
	 */
	private static function normalizeParameters( mixed $parameters ): array {
		return ToolSchemaNormalizer::normalize( $parameters );
	}

	/**
	 * Build a missing-ability error result.
	 *
	 * @param string $tool_name    Tool name.
	 * @param string $ability_slug Ability slug.
	 * @return array<string,mixed>
	 */
	private static function missingAbilityResult( string $tool_name, string $ability_slug ): array {
		return WP_Agent_Tool_Result::error(
			$tool_name,
			sprintf( "Tool '%s' references missing ability '%s'.", $tool_name, $ability_slug ),
			array( 'ability' => $ability_slug )
		);
	}
}
