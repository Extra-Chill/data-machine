<?php
/**
 * Tool source registry.
 *
 * Composes tool definitions from named sources before policy filtering.
 *
 * @package DataMachine\Engine\AI\Tools
 */

namespace DataMachine\Engine\AI\Tools;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry;
use DataMachine\Engine\AI\Tools\Sources\AdjacentHandlerToolSource;
use DataMachine\Engine\AI\Tools\Sources\AbilityToolSource;
use DataMachine\Engine\AI\Tools\Sources\DataMachineToolRegistrySource;
use DataMachine\Engine\AI\Tools\Sources\RuntimeToolSource;

defined( 'ABSPATH' ) || exit;

class ToolSourceRegistry {

	public const SOURCE_STATIC_REGISTRY   = 'static_registry';
	public const SOURCE_ADJACENT_HANDLERS = 'adjacent_handlers';
	public const SOURCE_RUNTIME_TOOLS     = 'runtime_tools';
	public const SOURCE_ABILITY_TOOLS     = 'ability_tools';

	private ToolManager $tool_manager;
	private WP_Agent_Tool_Source_Registry $registry;

	public function __construct( ToolManager $tool_manager ) {
		$this->tool_manager = $tool_manager;
		$this->registry     = new WP_Agent_Tool_Source_Registry();

		$this->registerDataMachineSources();
	}

	/**
	 * Gather tools from the sources configured for active modes.
	 *
	 * @param array $modes Agent mode slugs.
	 * @param array  $args Full resolution arguments.
	 * @return array Tools keyed by tool name.
	 */
	public function gather( array $modes, array $args ): array {
		if ( ! empty( $args['capture_trace'] ) ) {
			$trace = $this->gatherTrace( $modes, $args );
			return $trace['tools'];
		}

		$callback = array( $this, 'orderSourcesForContext' );
		if ( function_exists( 'add_filter' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical Agents API tool-source compatibility hook.
			add_filter( 'agents_api_tool_source_order', $callback, 5, 3 );
		}

		try {
			return $this->registry->gather(
				array_merge(
					$args,
					array(
						'modes'        => $modes,
						'tool_manager' => $this->tool_manager,
					)
				)
			);
		} finally {
			if ( function_exists( 'remove_filter' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical Agents API tool-source compatibility hook.
				remove_filter( 'agents_api_tool_source_order', $callback, 5 );
			}
		}
	}

	/**
	 * Gather tools with bounded source-level metadata for diagnostics.
	 *
	 * @param array $modes Agent mode slugs.
	 * @param array $args  Full resolution arguments.
	 * @return array{tools: array<string,array<string,mixed>>, sources: array<int,array<string,mixed>>}
	 */
	public function gatherWithMetadata( array $modes, array $args ): array {
		return $this->gatherTrace( $modes, $args );
	}

	/**
	 * Gather tools and source trace metadata through the same source path.
	 *
	 * @param array $modes Agent mode slugs.
	 * @param array $args Full resolution arguments.
	 * @return array{tools: array<string,array<string,mixed>>, sources: array<int,array<string,mixed>>}
	 */
	public function gatherTrace( array $modes, array $args ): array {
		$context  = array_merge(
			$args,
			array(
				'modes'        => $modes,
				'tool_manager' => $this->tool_manager,
			)
		);
		$callback = array( $this, 'orderSourcesForContext' );
		if ( function_exists( 'add_filter' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical Agents API tool-source compatibility hook.
			add_filter( 'agents_api_tool_source_order', $callback, 5, 3 );
		}

		try {
			$sources = $this->registry->getSources( $context );
			$order   = array_keys( $sources );
			if ( function_exists( 'apply_filters' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical Agents API tool-source compatibility hook.
				$order = apply_filters( 'agents_api_tool_source_order', $order, $context, $this->registry, $sources );
			}
			$order = is_array( $order ) ? array_values( array_filter( $order, static fn( $source ): bool => is_string( $source ) && isset( $sources[ $source ] ) ) ) : array();

			$tools           = array();
			$source_metadata = array();
			foreach ( $order as $source_slug ) {
				$source_tools = call_user_func( $sources[ $source_slug ], $context, $this->registry );
				if ( function_exists( 'apply_filters' ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical Agents API tool-source compatibility hook.
					$source_tools = apply_filters( 'agents_api_tool_source_tools', $source_tools, $source_slug, $context, $this->registry );
				}

				$source_tools      = is_array( $source_tools ) ? $source_tools : array();
				$source_rejections = array();
				if ( isset( $source_tools[ AbilityToolSource::REJECTION_METADATA_KEY ] ) && is_array( $source_tools[ AbilityToolSource::REJECTION_METADATA_KEY ] ) ) {
					$source_rejections = $source_tools[ AbilityToolSource::REJECTION_METADATA_KEY ];
					unset( $source_tools[ AbilityToolSource::REJECTION_METADATA_KEY ] );
				}

				$produced_names = array_values( array_filter( array_keys( $source_tools ), 'is_string' ) );
				$accepted_names = array();
				foreach ( $source_tools as $tool_name => $tool_definition ) {
					if ( ! is_string( $tool_name ) || isset( $tools[ $tool_name ] ) || ! is_array( $tool_definition ) ) {
						continue;
					}

					$tools[ $tool_name ] = $tool_definition;
					$accepted_names[]    = $tool_name;
				}

				$source_metadata[] = array(
					'source'              => $source_slug,
					'consulted'           => true,
					'produced_tool_names'  => $produced_names,
					'accepted_tool_names'  => $accepted_names,
					'filtered_tool_names'  => array_values( array_diff( $produced_names, $accepted_names ) ),
					'rejected_tools'        => $source_rejections,
					'produced_tool_count'  => count( $produced_names ),
					'accepted_tool_count'  => count( $accepted_names ),
				);
			}

			return array(
				'tools'   => $tools,
				'sources' => $source_metadata,
			);
		} finally {
			if ( function_exists( 'remove_filter' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical Agents API tool-source compatibility hook.
				remove_filter( 'agents_api_tool_source_order', $callback, 5 );
			}
		}
	}

	/**
	 * Register Data Machine-owned sources with the Agents API source registry.
	 *
	 * @return void
	 */
	private function registerDataMachineSources(): void {
		$runtime_source = new RuntimeToolSource();
		$static_source  = new DataMachineToolRegistrySource( $this->tool_manager );
		$handler_source = new AdjacentHandlerToolSource();
		$ability_source = new AbilityToolSource( $this->tool_manager );

		$this->registry->registerSource(
			self::SOURCE_RUNTIME_TOOLS,
			static function ( array $context ) use ( $runtime_source ): array {
				$modes = ToolPolicyResolver::normalizeModes( $context['modes'] ?? array() );
				return $runtime_source( $modes, $context );
			}
		);

		$this->registry->registerSource(
			self::SOURCE_ADJACENT_HANDLERS,
			function ( array $context ) use ( $handler_source ): array {
				$modes = ToolPolicyResolver::normalizeModes( $context['modes'] ?? array() );
				return $handler_source( $modes, $context, $this->tool_manager );
			}
		);

		$this->registry->registerSource(
			self::SOURCE_STATIC_REGISTRY,
			static function ( array $context ) use ( $static_source ): array {
				$modes = ToolPolicyResolver::normalizeModes( $context['modes'] ?? array() );
				return $static_source( $modes, $context );
			}
		);

		$this->registry->registerSource(
			self::SOURCE_ABILITY_TOOLS,
			static function ( array $context ) use ( $ability_source ): array {
				$modes = ToolPolicyResolver::normalizeModes( $context['modes'] ?? array() );
				return $ability_source( $modes, $context );
			}
		);
	}

	/**
	 * Return source slugs for active modes through the Agents API order filter.
	 *
	 * @param array                         $order    Source slugs in upstream registry order.
	 * @param array                         $context  Runtime context.
	 * @param WP_Agent_Tool_Source_Registry $registry Source registry instance.
	 * @return array<int, string> Source slugs in precedence order.
	 */
	public function orderSourcesForContext( array $order, array $context, WP_Agent_Tool_Source_Registry $registry ): array {
		if ( $registry !== $this->registry ) {
			return $order;
		}

		$modes = ToolPolicyResolver::normalizeModes( $context['modes'] ?? array() );

		$sources = in_array( ToolPolicyResolver::MODE_PIPELINE, $modes, true )
			? array( self::SOURCE_RUNTIME_TOOLS, self::SOURCE_ADJACENT_HANDLERS, self::SOURCE_STATIC_REGISTRY, self::SOURCE_ABILITY_TOOLS )
			: array( self::SOURCE_RUNTIME_TOOLS, self::SOURCE_STATIC_REGISTRY, self::SOURCE_ABILITY_TOOLS );

		return array_values(
			array_filter(
				$sources,
				static fn( $source ) => is_string( $source ) && '' !== $source && in_array( $source, $order, true )
			)
		);
	}

}
