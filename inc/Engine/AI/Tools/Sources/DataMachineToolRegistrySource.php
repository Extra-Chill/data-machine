<?php
/**
 * Data Machine tool registry source.
 *
 * Adapts Data Machine's legacy/product `datamachine_tools` registry into the
 * generic source-registry composition layer. The future Agents API direction is
 * Ability-native runtime declarations; this adapter keeps Data Machine's
 * curated class/method and handler-tool registry out of that public contract.
 *
 * @package DataMachine\Engine\AI\Tools\Sources
 */

namespace DataMachine\Engine\AI\Tools\Sources;

use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

defined( 'ABSPATH' ) || exit;

final class DataMachineToolRegistrySource {

	private ToolManager $tool_manager;

	public function __construct( ToolManager $tool_manager ) {
		$this->tool_manager = $tool_manager;
	}

	/**
	 * Gather mode-filtered tools from Data Machine's static registry.
	 *
	 * @param array $modes Agent mode slugs.
	 * @return array Tools keyed by tool name.
	 */
	public function __invoke( array $modes, array $args = array() ): array {
		$available_tools = array();
		$all_tools       = $this->tool_manager->get_all_tools();
		$mode_tools      = $this->filterByModes( $all_tools, $modes );

		foreach ( $mode_tools as $tool_name => $tool_config ) {
			if ( ! is_array( $tool_config ) || empty( $tool_config ) ) {
				continue;
			}

			$include_unavailable = ! empty( $args['include_unavailable'] );

			// Handler-wrapper tools belong to the adjacent-handler source.
			// Skip them here regardless of mode so they cannot leak into
			// the static-registry surface — including via mode inheritance.
			if ( isset( $tool_config['_handler_callable'] ) ) {
				continue;
			}

			if ( ! $include_unavailable && ! ToolPolicyResolver::isOptInToolAllowed( $tool_config, $tool_name, $args ) ) {
				continue;
			}

			// Tools with requires_config go through availability checks.
			// Tools without it are always available unless globally disabled.
			if ( $include_unavailable ) {
				$available_tools[ $tool_name ] = $tool_config;
				continue;
			}

			if ( ! empty( $tool_config['requires_config'] ) ) {
				if ( ! $this->tool_manager->is_tool_available( $tool_name, null ) ) {
					continue;
				}
			} elseif ( ! $this->tool_manager->is_globally_enabled( $tool_name ) ) {
				continue;
			}

			$available_tools[ $tool_name ] = $tool_config;
		}

		return $available_tools;
	}

	/**
	 * Filter resolved tools by active agent modes.
	 *
	 * @param array $tools Resolved tools array.
	 * @param array $modes Active mode slugs.
	 * @return array Filtered tools.
	 */
	private function filterByModes( array $tools, array $active_modes ): array {
		return array_filter(
			$tools,
			static function ( $tool ) use ( $active_modes ) {
				$tool_modes = $tool['modes'] ?? array();
				$tool_modes = is_array( $tool_modes ) ? $tool_modes : array( $tool_modes );
				return ! empty( array_intersect( $active_modes, $tool_modes ) );
			}
		);
	}
}
