<?php
/**
 * Tool source registry.
 *
 * Composes tool definitions from named sources before policy filtering.
 *
 * @package DataMachine\Engine\AI\Tools
 */

namespace DataMachine\Engine\AI\Tools;

use DataMachine\Engine\AI\Tools\Sources\AdjacentHandlerToolSource;
use DataMachine\Engine\AI\Tools\Sources\DataMachineToolRegistrySource;

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
		// @phpstan-ignore-next-line WordPress apply_filters accepts additional hook arguments.
		$sources = apply_filters(
			'datamachine_tool_sources',
			array(
				self::SOURCE_STATIC_REGISTRY   => new DataMachineToolRegistrySource( $this->tool_manager ),
				self::SOURCE_ADJACENT_HANDLERS => new AdjacentHandlerToolSource(),
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

		// @phpstan-ignore-next-line WordPress apply_filters accepts additional hook arguments.
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

}
