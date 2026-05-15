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

			// Handler-wrapper tools belong to the adjacent-handler source.
			// Skip them here regardless of mode so they cannot leak into
			// the static-registry surface — including via mode inheritance.
			if ( isset( $tool_config['_handler_callable'] ) ) {
				continue;
			}

			if ( ToolPolicyResolver::MODE_PIPELINE === $mode && ToolPolicyResolver::isPipelinePolicyToolAllowed( $tool_config, $tool_name, $args ) ) {
				$tool_config['modes'] = array_values( array_unique( array_merge( $tool_config['modes'], array( ToolPolicyResolver::MODE_PIPELINE ) ) ) );
			}

			// Tools admitted via mode inheritance get the current mode
			// stamped onto their `modes` array. Downstream policy filters
			// (notably Agents API's `filter_by_mode`) re-check tool modes
			// against the runtime mode; without the rewrite, inherited
			// tools would be filtered back out one layer up.
			$tool_config['modes'] = array_values(
				array_unique( array_merge( $tool_config['modes'] ?? array(), array( $mode ) ) )
			);

			if ( ToolPolicyResolver::MODE_PIPELINE === $mode ) {
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
	 * Tools declare which modes they belong to via the `modes` key on
	 * registration (e.g. `['chat', 'pipeline']`). By default a tool is
	 * available to a request only when the request's mode appears in
	 * that list verbatim.
	 *
	 * Extension plugins that register custom modes via `AgentModeRegistry`
	 * can opt their mode into another mode's tool surface by hooking the
	 * `datamachine_tool_mode_matchable_modes` filter. Returning
	 * `['world', 'pipeline']` for the `world` mode, for example, makes
	 * every tool registered for `pipeline` available to `world` runs as
	 * well — without re-registering each tool. The default value is
	 * `[$mode]` so existing behavior is unchanged for any mode that does
	 * not opt in.
	 *
	 * @param array  $tools Resolved tools array.
	 * @param string $mode  Mode slug to filter by.
	 * @param array  $args  Resolution context passed through from the source.
	 * @return array Filtered tools.
	 */
	private function filterByMode( array $tools, string $mode, array $args = array() ): array {
		/**
		 * Filter the modes a tool's `modes` array is matched against.
		 *
		 * Custom modes registered via `AgentModeRegistry` can declare
		 * inheritance from a built-in mode by appending its slug to the
		 * matchable list. Tools whose `modes` array intersects with the
		 * matchable list become available to the current mode.
		 *
		 * Defaults to `[$mode]`, which preserves the historical exact-
		 * match behavior. The filter is the seam custom modes use to
		 * inherit tool surfaces without DM having to know about them.
		 *
		 * @since 0.113.0
		 *
		 * @param string[] $matchable Mode slugs to match tool `modes` against.
		 * @param string   $mode      Current execution mode.
		 * @param array    $args      Tool resolution context.
		 */
		$matchable = apply_filters( 'datamachine_tool_mode_matchable_modes', array( $mode ), $mode, $args );
		if ( ! is_array( $matchable ) || empty( $matchable ) ) {
			$matchable = array( $mode );
		}

		return array_filter(
			$tools,
			static function ( $tool, string $tool_name ) use ( $mode, $matchable, $args ) {
				$modes = $tool['modes'] ?? array();
				if ( ToolPolicyResolver::MODE_PIPELINE === $mode
					&& ToolPolicyResolver::isPipelinePolicyToolAllowed( $tool, $tool_name, $args ) ) {
					return true;
				}

				return ! empty( array_intersect( $matchable, $modes ) );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}
}
