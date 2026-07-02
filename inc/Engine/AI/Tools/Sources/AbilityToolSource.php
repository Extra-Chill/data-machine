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

use DataMachine\Engine\AI\Tools\AbilityToolAdapter;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

final class AbilityToolSource {

	public const REJECTION_METADATA_KEY = '__datamachine_source_rejections';

	private ToolManager $tool_manager;

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
		$result = $this->collect( $modes, $args );
		$tools  = $result['tools'];

		if ( ! empty( $args['include_source_rejection_metadata'] ) ) {
			$tools[ self::REJECTION_METADATA_KEY ] = $result['rejections'];
		}

		return $tools;
	}

	/**
	 * Gather ability tool declarations and source-level rejection diagnostics.
	 *
	 * @param array $modes Active agent modes.
	 * @param array $args  Resolution args.
	 * @return array{tools: array<string,array<string,mixed>>, rejections: array<string,array<string,mixed>>}
	 */
	private function collect( array $modes, array $args = array() ): array {
		$diagnostic_tool_names = $this->stringList( $args['diagnostic_tool_names'] ?? array() );
		$rejections            = array();

		if ( ! class_exists( '\WP_Abilities_Registry' ) ) {
			foreach ( $diagnostic_tool_names as $tool_name ) {
				$rejections[ $tool_name ] = $this->rejection( $tool_name, 'ability_registry_unavailable' );
			}
			return array(
				'tools'      => array(),
				'rejections' => $rejections,
			);
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( ! $registry || ! method_exists( $registry, 'get_registered' ) ) {
			foreach ( $diagnostic_tool_names as $tool_name ) {
				$rejections[ $tool_name ] = $this->rejection( $tool_name, 'ability_registry_unavailable' );
			}
			return array(
				'tools'      => array(),
				'rejections' => $rejections,
			);
		}

		$declared = $this->declarationsFromContext( $args );
		$declared = apply_filters( 'datamachine_ability_tool_projections', $declared, $args );
		$declared = apply_filters( 'datamachine_ability_tools', $declared, $args );
		if ( ! is_array( $declared ) ) {
			foreach ( $diagnostic_tool_names as $tool_name ) {
				$rejections[ $tool_name ] = $this->rejection( $tool_name, 'no_projection' );
			}
			return array(
				'tools'      => array(),
				'rejections' => $rejections,
			);
		}

		$declared_tool_names = array();
		$tools = array();
		foreach ( $declared as $tool_name => $declaration ) {
			if ( ! is_string( $tool_name ) || '' === $tool_name || ! is_array( $declaration ) ) {
				continue;
			}
			$declared_tool_names[] = $tool_name;

			$ability_slug = AbilityToolAdapter::primaryAbilitySlug( $declaration );
			if ( '' === $ability_slug ) {
				$rejections[ $tool_name ] = $this->rejection( $tool_name, 'invalid_projection' );
				continue;
			}

			if ( method_exists( $registry, 'is_registered' ) && ! $registry->is_registered( $ability_slug ) ) {
				$rejections[ $tool_name ] = $this->rejection( $tool_name, 'missing_ability', array( 'ability' => $ability_slug ) );
				continue;
			}

			$tool = $this->buildToolDefinition( $tool_name, $declaration, $registry, $args );
			if ( empty( $tool ) ) {
				$rejections[ $tool_name ] = $this->rejection( $tool_name, 'invalid_projection', array( 'ability' => $ability_slug ) );
				continue;
			}

			if ( ! $this->matchesModes( $tool, $modes ) ) {
				$rejections[ $tool_name ] = $this->rejection(
					$tool_name,
					'mode_mismatch',
					array(
						'ability'      => $ability_slug,
						'tool_modes'   => $tool['modes'] ?? array(),
						'active_modes' => $modes,
					)
				);
				continue;
			}

			$include_unavailable = ! empty( $args['include_unavailable'] );

			if ( ! ToolPolicyResolver::isOptInToolAllowed( $tool, $tool_name, $args ) ) {
				$rejections[ $tool_name ] = $this->rejection( $tool_name, 'policy_filtered', array( 'ability' => $ability_slug ) );
				if ( ! $include_unavailable ) {
					continue;
				}
			}

			$availability_reason = $this->availabilityFailureReason( $tool_name, $tool );
			if ( '' !== $availability_reason ) {
				$rejections[ $tool_name ] = $this->rejection( $tool_name, $availability_reason, array( 'ability' => $ability_slug ) );
				if ( ! $include_unavailable ) {
					continue;
				}
			}

			$tools[ $tool_name ] = $tool;
		}

		foreach ( array_diff( $diagnostic_tool_names, $declared_tool_names ) as $tool_name ) {
			$rejections[ $tool_name ] = $this->rejection( $tool_name, 'no_projection' );
		}

		return array(
			'tools'      => $tools,
			'rejections' => $rejections,
		);
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
		$tool = AbilityToolAdapter::declaration( $tool_name, $declaration, $registry );
		if ( empty( $tool ) ) {
			return array();
		}

		/**
		 * Filter a generated ability-backed tool declaration before policy handling.
		 *
		 * @param array  $tool         Generated tool declaration.
		 * @param string $tool_name    Model-facing tool name.
		 * @param string $ability_slug Registered primary ability slug.
		 * @param object $ability      Registered primary WP_Ability instance.
		 * @param array  $declaration  Raw `datamachine_ability_tools` declaration.
		 * @param array  $args         Tool resolution args.
		 */
		$ability_slug = (string) ( $tool['ability'] ?? '' );
		$ability      = '' !== $ability_slug ? $registry->get_registered( $ability_slug ) : null;
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
		return '' === $this->availabilityFailureReason( $tool_name, $tool );
	}

	/**
	 * Return the source-level availability rejection reason, or an empty string.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $tool      Generated tool declaration.
	 * @return string Rejection reason.
	 */
	private function availabilityFailureReason( string $tool_name, array $tool ): string {
		$disabled_tools = PluginSettings::get( 'disabled_tools', array() );
		if ( isset( $disabled_tools[ $tool_name ] ) ) {
			return 'disabled_tool_setting';
		}

		if ( empty( $tool['requires_config'] ) ) {
			return '';
		}

		return (bool) apply_filters( 'datamachine_tool_configured', false, $tool_name ) ? '' : 'config_missing';
	}

	/**
	 * Build a bounded source-level rejection record.
	 *
	 * @param string $tool_name Tool name.
	 * @param string $reason    Rejection reason.
	 * @param array  $extra     Additional metadata.
	 * @return array<string,mixed>
	 */
	private function rejection( string $tool_name, string $reason, array $extra = array() ): array {
		return array_filter(
			array_merge(
				array(
					'tool_name' => $tool_name,
					'reason'    => $reason,
				),
				$extra
			),
			static fn( $value ) => null !== $value && array() !== $value && '' !== $value
		);
	}

	/**
	 * Normalize a scalar/string list without relying on the policy filter.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private function stringList( $value ): array {
		$value = is_array( $value ) ? $value : array( $value );

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $item ): string => is_scalar( $item ) ? trim( (string) $item ) : '',
						$value
					),
					static fn( string $item ): bool => '' !== $item
				)
			)
		);
	}

}
