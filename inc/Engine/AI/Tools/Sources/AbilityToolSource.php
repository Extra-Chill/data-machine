<?php
/**
 * Ability-backed tool source.
 *
 * Exposes selected registered WordPress abilities as model-facing tools without
 * requiring hand-written BaseTool wrappers. Execution remains ability-native via
 * ToolExecutionCore because generated declarations include the explicit
 * `execution_ability` marker.
 *
 * @package DataMachine\Engine\AI\Tools\Sources
 */

namespace DataMachine\Engine\AI\Tools\Sources;

use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

final class AbilityToolSource {

	private ToolManager $tool_manager;

	/**
	 * Metadata keys that tool authors may override on generated declarations.
	 *
	 * @var string[]
	 */
	private const OVERRIDE_KEYS = array(
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
	);

	public function __construct( ToolManager $tool_manager ) {
		$this->tool_manager = $tool_manager;
	}

	/**
	 * Gather selected abilities as Data Machine tool declarations.
	 *
	 * Plugins opt in through `datamachine_ability_tool_projections` or the
	 * `datamachine_register_ability_tool()` helper:
	 *
	 *     $tools['my_tool'] = array(
	 *         'ability' => 'my-plugin/my-ability',
	 *         'modes'   => array( 'chat', 'pipeline' ),
	 *     );
	 *
	 * @param array $modes Active agent modes.
	 * @param array $args  Resolution args.
	 * @return array<string,array<string,mixed>> Tool declarations keyed by tool name.
	 */
	public function __invoke( array $modes, array $args = array() ): array {
		if ( ! class_exists( '\WP_Abilities_Registry' ) ) {
			return array();
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( ! $registry || ! method_exists( $registry, 'get_registered' ) ) {
			return array();
		}

		$declared = $this->declarationsFromContext( $args );
		$declared = apply_filters( 'datamachine_ability_tool_projections', $declared, $args );
		$declared = apply_filters( 'datamachine_ability_tools', $declared, $args );
		if ( ! is_array( $declared ) ) {
			return array();
		}

		$tools = array();
		foreach ( $declared as $tool_name => $declaration ) {
			if ( ! is_string( $tool_name ) || '' === $tool_name || ! is_array( $declaration ) ) {
				continue;
			}

			$tool = $this->buildToolDefinition( $tool_name, $declaration, $registry, $args );
			if ( empty( $tool ) ) {
				continue;
			}

			if ( ! $this->matchesModes( $tool, $modes ) ) {
				continue;
			}

			$include_unavailable = ! empty( $args['include_unavailable'] );

			if ( ! $include_unavailable && ! ToolPolicyResolver::isOptInToolAllowed( $tool, $tool_name, $args ) ) {
				continue;
			}

			if ( ! $include_unavailable && ! $this->isToolAvailable( $tool_name, $tool ) ) {
				continue;
			}

			$tools[ $tool_name ] = $tool;
		}

		return $tools;
	}

	/**
	 * Extract run-scoped ability tool declarations from resolver context.
	 *
	 * Accepts keyed maps and list entries with a `name` field:
	 *
	 *     array( 'my_tool' => array( 'ability' => 'plugin/ability' ) )
	 *     array( array( 'name' => 'my_tool', 'ability' => 'plugin/ability' ) )
	 *
	 * @param array $args Full resolution arguments.
	 * @return array<string,array<string,mixed>> Declarations keyed by model-facing tool name.
	 */
	private function declarationsFromContext( array $args ): array {
		$sets = array( $args['ability_tools'] ?? null );

		$engine_data = is_array( $args['engine_data'] ?? null ) ? $args['engine_data'] : array();
		$job_snapshot = is_array( $engine_data['job'] ?? null ) ? $engine_data['job'] : array();
		$sets[] = $job_snapshot['ability_tools'] ?? null;

		$client_context = is_array( $args['client_context'] ?? null ) ? $args['client_context'] : array();
		$sets[] = $client_context['ability_tools'] ?? null;

		$declared = array();
		foreach ( $sets as $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}

			foreach ( $set as $name => $declaration ) {
				if ( ! is_array( $declaration ) ) {
					continue;
				}

				$tool_name = is_string( $name ) && '' !== $name ? $name : (string) ( $declaration['name'] ?? '' );
				if ( '' === $tool_name ) {
					continue;
				}

				unset( $declaration['name'] );
				$declared[ $tool_name ] = $declaration;
			}
		}

		return $declared;
	}

	/**
	 * Build one tool declaration from a registered ability and optional overrides.
	 *
	 * @param string $tool_name   Tool name.
	 * @param array  $declaration Ability tool declaration.
	 * @param object $registry    WP_Abilities_Registry instance.
	 * @param array  $args        Resolution args.
	 * @return array<string,mixed>
	 */
	private function buildToolDefinition( string $tool_name, array $declaration, object $registry, array $args ): array {
		$ability_slug = isset( $declaration['ability'] ) && is_string( $declaration['ability'] ) ? $declaration['ability'] : '';
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
			'ability'           => $ability_slug,
			'execution_ability' => $ability_slug,
			'ability_category'  => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'annotations'       => $annotations,
			'description'       => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'label'             => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $tool_name,
			'modes'             => array( ToolPolicyResolver::MODE_CHAT ),
			'parameters'        => method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : array(),
		);

		foreach ( self::OVERRIDE_KEYS as $key ) {
			if ( array_key_exists( $key, $declaration ) ) {
				$tool[ $key ] = $declaration[ $key ];
			}
		}

		if ( ! is_array( $tool['parameters'] ?? null ) ) {
			$tool['parameters'] = array();
		}

		$tool['modes'] = ToolPolicyResolver::normalizeModes( $tool['modes'] ?? array( ToolPolicyResolver::MODE_CHAT ) );

		/**
		 * Filter a generated ability-backed tool declaration before policy handling.
		 *
		 * @param array  $tool         Generated tool declaration.
		 * @param string $tool_name    Model-facing tool name.
		 * @param string $ability_slug Registered ability slug.
		 * @param object $ability      Registered WP_Ability instance.
		 * @param array  $declaration  Raw `datamachine_ability_tools` declaration.
		 * @param array  $args         Tool resolution args.
		 */
		$filtered = apply_filters( 'datamachine_ability_tool_definition', $tool, $tool_name, $ability_slug, $ability, $declaration, $args );
		$tool     = is_array( $filtered ) ? $filtered : $tool;

		if ( ! is_array( $tool['parameters'] ?? null ) ) {
			$tool['parameters'] = array();
		}

		$tool['modes'] = ToolPolicyResolver::normalizeModes( $tool['modes'] ?? array( ToolPolicyResolver::MODE_CHAT ) );

		return $tool;
	}

	/**
	 * Whether a tool declaration matches any active mode.
	 *
	 * @param array $tool  Tool declaration.
	 * @param array $modes Active modes.
	 * @return bool Whether the tool should be considered for the run.
	 */
	private function matchesModes( array $tool, array $modes ): bool {
		$tool_modes = $tool['modes'] ?? array();
		$tool_modes = is_array( $tool_modes ) ? $tool_modes : array( $tool_modes );

		return ! empty( array_intersect( ToolPolicyResolver::normalizeModes( $tool_modes ), $modes ) );
	}

	/**
	 * Whether a generated ability projection is enabled and configured.
	 *
	 * Ability-native tools are not necessarily present in ToolManager's static
	 * `datamachine_tools` registry, so source-level availability must evaluate
	 * the generated declaration directly instead of asking ToolManager to look the
	 * tool up by name.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $tool      Generated tool declaration.
	 * @return bool Whether the tool is available.
	 */
	private function isToolAvailable( string $tool_name, array $tool ): bool {
		$disabled_tools = PluginSettings::get( 'disabled_tools', array() );
		if ( isset( $disabled_tools[ $tool_name ] ) ) {
			return false;
		}

		if ( empty( $tool['requires_config'] ) ) {
			return true;
		}

		return (bool) apply_filters( 'datamachine_tool_configured', false, $tool_name );
	}

}
