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
	 * @param string $mode Agent mode slug.
	 * @return array Tools keyed by tool name.
	 */
	public function __invoke( string $mode, array $args = array() ): array {
		$available_tools = array();
		$all_tools       = $this->tool_manager->get_all_tools();
		$mode_tools      = $this->filterByMode( $all_tools, $mode, $args );

		foreach ( $mode_tools as $tool_name => $tool_config ) {
			if ( ! is_array( $tool_config ) || empty( $tool_config ) ) {
				continue;
			}

			if ( ToolPolicyResolver::MODE_PIPELINE === $mode && ToolPolicyResolver::isPipelinePolicyToolAllowed( $tool_config, $tool_name, $args ) ) {
				$tool_config['modes'] = array_values( array_unique( array_merge( $tool_config['modes'], array( ToolPolicyResolver::MODE_PIPELINE ) ) ) );
			}

			if ( ToolPolicyResolver::MODE_PIPELINE === $mode ) {
				if ( isset( $tool_config['_handler_callable'] ) ) {
					continue;
				}

				if ( $this->tool_manager->is_tool_available( $tool_name, null ) ) {
					$available_tools[ $tool_name ] = $tool_config;
				}

				continue;
			}

			// Tools with requires_config go through availability checks.
			// Tools without it are always available unless globally disabled.
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
	 * Filter resolved tools by agent mode.
	 *
	 * @param array  $tools Resolved tools array.
	 * @param string $mode  Mode slug to filter by.
	 * @return array Filtered tools.
	 */
	private function filterByMode( array $tools, string $mode, array $args = array() ): array {
		return array_filter(
			$tools,
			static function ( $tool, string $tool_name ) use ( $mode, $args ) {
				$modes = $tool['modes'] ?? array();
				if ( ToolPolicyResolver::MODE_PIPELINE === $mode
					&& ToolPolicyResolver::isPipelinePolicyToolAllowed( $tool, $tool_name, $args ) ) {
					return true;
				}

				return in_array( $mode, $modes, true );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}
}
