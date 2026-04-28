<?php
/**
 * Tool source registry.
 *
 * Composes tool definitions from named sources before policy filtering.
 *
 * @package DataMachine\Engine\AI\Tools
 */

namespace DataMachine\Engine\AI\Tools;

use DataMachine\Core\Steps\FlowStepConfig;

defined( 'ABSPATH' ) || exit;

class ToolSourceRegistry {

	public const SOURCE_STATIC_REGISTRY   = 'static_registry';
	public const SOURCE_ADJACENT_HANDLERS = 'adjacent_handlers';

	private ToolManager $tool_manager;

	public function __construct( ToolManager $tool_manager ) {
		$this->tool_manager = $tool_manager;
	}

	/**
	 * Gather tools from the sources configured for a mode.
	 *
	 * @param string $mode Agent mode slug.
	 * @param array  $args Full resolution arguments.
	 * @return array Tools keyed by tool name.
	 */
	public function gather( string $mode, array $args ): array {
		$tools   = array();
		$sources = $this->getRegisteredSources( $mode, $args );

		foreach ( $this->getSourcesForMode( $mode, $args ) as $source_slug ) {
			$source_tools = $this->gatherFromSource( $source_slug, $mode, $args, $sources );

			foreach ( $source_tools as $tool_name => $tool_config ) {
				if ( isset( $tools[ $tool_name ] ) ) {
					continue;
				}

				$tools[ $tool_name ] = $tool_config;
			}
		}

		return $tools;
	}

	/**
	 * Return registered tool sources.
	 *
	 * @param string $mode Agent mode slug.
	 * @param array  $args Full resolution arguments.
	 * @return array<string, callable> Source callbacks keyed by source slug.
	 */
	private function getRegisteredSources( string $mode, array $args ): array {
		$sources = apply_filters(
			'datamachine_tool_sources',
			array(
				self::SOURCE_STATIC_REGISTRY   => array( $this, 'gatherStaticRegistryTools' ),
				self::SOURCE_ADJACENT_HANDLERS => array( $this, 'gatherAdjacentHandlerTools' ),
			),
			$mode,
			$args,
			$this->tool_manager
		);

		return is_array( $sources ) ? $sources : array();
	}

	/**
	 * Return source slugs for a mode.
	 *
	 * @param string $mode Agent mode slug.
	 * @param array  $args Full resolution arguments.
	 * @return array<int, string> Source slugs in precedence order.
	 */
	private function getSourcesForMode( string $mode, array $args ): array {
		$sources = ToolPolicyResolver::MODE_PIPELINE === $mode
			? array( self::SOURCE_ADJACENT_HANDLERS, self::SOURCE_STATIC_REGISTRY )
			: array( self::SOURCE_STATIC_REGISTRY );

		$sources = apply_filters( 'datamachine_tool_sources_for_mode', $sources, $mode, $args );

		if ( ! is_array( $sources ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$sources,
				static fn( $source ) => is_string( $source ) && '' !== $source
			)
		);
	}

	/**
	 * Gather tools from one source.
	 *
	 * @param string $source_slug Source slug.
	 * @param string $mode        Agent mode slug.
	 * @param array  $args        Full resolution arguments.
	 * @param array  $sources     Registered source callbacks keyed by source slug.
	 * @return array Tools keyed by tool name.
	 */
	private function gatherFromSource( string $source_slug, string $mode, array $args, array $sources ): array {
		$source = $sources[ $source_slug ] ?? null;
		if ( ! is_callable( $source ) ) {
			return array();
		}

		$tools = call_user_func( $source, $mode, $args, $this->tool_manager );

		return is_array( $tools ) ? $tools : array();
	}

	/**
	 * Gather mode-filtered tools from the static registry.
	 *
	 * @param string $mode Agent mode slug.
	 * @return array Tools keyed by tool name.
	 */
	public function gatherStaticRegistryTools( string $mode ): array {
		$available_tools = array();
		$all_tools       = $this->tool_manager->get_all_tools();
		$mode_tools      = $this->filterByMode( $all_tools, $mode );

		foreach ( $mode_tools as $tool_name => $tool_config ) {
			if ( ! is_array( $tool_config ) || empty( $tool_config ) ) {
				continue;
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
	 * Gather handler tools from adjacent pipeline steps.
	 *
	 * @param string $mode Agent mode slug.
	 * @param array  $args Full resolution arguments.
	 * @return array Tools keyed by tool name.
	 */
	public function gatherAdjacentHandlerTools( string $mode, array $args ): array {
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
				$tools          = $this->tool_manager->resolveHandlerTools(
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

	/**
	 * Filter resolved tools by agent mode.
	 *
	 * @param array  $tools Resolved tools array.
	 * @param string $mode  Mode slug to filter by.
	 * @return array Filtered tools.
	 */
	private function filterByMode( array $tools, string $mode ): array {
		return array_filter(
			$tools,
			static function ( $tool ) use ( $mode ) {
				$modes = $tool['modes'] ?? array();
				return in_array( $mode, $modes, true );
			}
		);
	}
}
