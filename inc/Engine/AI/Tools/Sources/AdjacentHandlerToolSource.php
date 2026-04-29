<?php
/**
 * Adjacent handler tool source.
 *
 * Pipeline AI steps expose required handler tools from their neighboring steps.
 *
 * @package DataMachine\Engine\AI\Tools\Sources
 */

namespace DataMachine\Engine\AI\Tools\Sources;

use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

defined( 'ABSPATH' ) || exit;

final class AdjacentHandlerToolSource {

	/**
	 * Gather handler tools from adjacent pipeline steps.
	 *
	 * @param string      $mode         Agent mode slug.
	 * @param array       $args         Full resolution arguments.
	 * @param ToolManager $tool_manager Tool registry/handler resolver.
	 * @return array Tools keyed by tool name.
	 */
	public function __invoke( string $mode, array $args, ToolManager $tool_manager ): array {
		if ( ToolPolicyResolver::MODE_PIPELINE !== $mode ) {
			return array();
		}

		$available_tools = array();
		$engine_data     = $args['engine_data'] ?? array();

		foreach ( array( $args['previous_step_config'] ?? null, $args['next_step_config'] ?? null ) as $step_config ) {
			if ( ! $step_config ) {
				continue;
			}

			$handler_slugs = FlowStepConfig::getConfiguredHandlerSlugs( $step_config );
			$cache_scope   = $step_config['flow_step_id'] ?? ( $args['cache_scope'] ?? '' );

			foreach ( $handler_slugs as $slug ) {
				$handler_config = FlowStepConfig::getHandlerConfigForSlug( $step_config, $slug );
				$tools          = $tool_manager->resolveHandlerTools(
					$slug,
					$handler_config,
					$engine_data,
					$cache_scope
				);

				foreach ( $tools as $tool_name => $tool_config ) {
					$available_tools[ $tool_name ] = $tool_config;
				}
			}
		}

		return $available_tools;
	}
}
