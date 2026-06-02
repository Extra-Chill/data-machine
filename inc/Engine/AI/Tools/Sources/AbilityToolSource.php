<?php
/**
 * Ability-backed tool source.
 *
 * Exposes selected registered WordPress abilities as model-facing tools without
 * requiring hand-written BaseTool wrappers. Execution remains ability-native via
 * ToolExecutionCore because generated declarations include the `ability` key.
 *
 * @package DataMachine\Engine\AI\Tools\Sources
 */

namespace DataMachine\Engine\AI\Tools\Sources;

use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

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
	 * Plugins opt in through `datamachine_ability_tools`:
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

		$declared = apply_filters( 'datamachine_ability_tools', array(), $args );
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

			if ( ! ToolPolicyResolver::isOptInToolAllowed( $tool, $tool_name, $args ) ) {
				continue;
			}

			if ( ! empty( $tool['requires_config'] ) ) {
				if ( ! $this->tool_manager->is_tool_available( $tool_name, null ) ) {
					continue;
				}
			} elseif ( ! $this->tool_manager->is_globally_enabled( $tool_name ) ) {
				continue;
			}

			$tools[ $tool_name ] = $tool;
		}

		return $tools;
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
			'ability'          => $ability_slug,
			'ability_category' => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'annotations'      => $annotations,
			'description'      => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'label'            => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $tool_name,
			'modes'            => array( ToolPolicyResolver::MODE_CHAT ),
			'parameters'       => method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : array(),
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

}
