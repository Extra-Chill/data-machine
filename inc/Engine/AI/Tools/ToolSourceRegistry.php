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

		$order_callback       = array( $this, 'orderSourcesForContext' );
		$host_policy_callback = array( $this, 'filterSourceToolsForHostPolicy' );
		if ( function_exists( 'add_filter' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical Agents API tool-source compatibility hook.
			add_filter( 'agents_api_tool_source_order', $order_callback, 5, 3 );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical Agents API tool-source compatibility hook.
			add_filter( 'agents_api_tool_source_tools', $host_policy_callback, PHP_INT_MAX, 4 );
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
				remove_filter( 'agents_api_tool_source_order', $order_callback, 5 );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical Agents API tool-source compatibility hook.
				remove_filter( 'agents_api_tool_source_tools', $host_policy_callback, PHP_INT_MAX );
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
				$source_tools      = $this->applyHostToolPolicy( $source_tools, $source_slug, $context );
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
			function ( array $context ) use ( $runtime_source ): array {
				$modes = ToolPolicyResolver::normalizeModes( $context['modes'] ?? array() );
				return $this->applyHostToolPolicy( $runtime_source( $modes, $context ), self::SOURCE_RUNTIME_TOOLS, $context );
			}
		);

		$this->registry->registerSource(
			self::SOURCE_ADJACENT_HANDLERS,
			function ( array $context ) use ( $handler_source ): array {
				$modes = ToolPolicyResolver::normalizeModes( $context['modes'] ?? array() );
				return $this->applyHostToolPolicy( $handler_source( $modes, $context, $this->tool_manager ), self::SOURCE_ADJACENT_HANDLERS, $context );
			}
		);

		$this->registry->registerSource(
			self::SOURCE_STATIC_REGISTRY,
			function ( array $context ) use ( $static_source ): array {
				$modes = ToolPolicyResolver::normalizeModes( $context['modes'] ?? array() );
				return $this->applyHostToolPolicy( $static_source( $modes, $context ), self::SOURCE_STATIC_REGISTRY, $context );
			}
		);

		$this->registry->registerSource(
			self::SOURCE_ABILITY_TOOLS,
			function ( array $context ) use ( $ability_source ): array {
				$modes = ToolPolicyResolver::normalizeModes( $context['modes'] ?? array() );
				return $this->applyHostToolPolicy( $ability_source( $modes, $context ), self::SOURCE_ABILITY_TOOLS, $context );
			}
		);
	}

	/**
	 * Apply host-owned execution policy to one source's locally runnable tools.
	 *
	 * @param array<string,mixed> $source_tools Tools keyed by name, optionally with source rejections.
	 * @param string              $source_slug  Source slug.
	 * @param array<string,mixed> $context      Resolver context.
	 * @return array<string,mixed> Filtered tools with source-level rejection metadata.
	 */
	private function applyHostToolPolicy( array $source_tools, string $source_slug, array $context ): array {
		$policy = HostToolPolicy::fromContext( $context );
		if ( null === $policy ) {
			return $source_tools;
		}

		$rejections = array();
		if ( isset( $source_tools[ AbilityToolSource::REJECTION_METADATA_KEY ] ) && is_array( $source_tools[ AbilityToolSource::REJECTION_METADATA_KEY ] ) ) {
			$rejections = $source_tools[ AbilityToolSource::REJECTION_METADATA_KEY ];
		}

		foreach ( $source_tools as $tool_name => $tool_definition ) {
			if ( AbilityToolSource::REJECTION_METADATA_KEY === $tool_name || ! is_string( $tool_name ) || ! is_array( $tool_definition ) ) {
				continue;
			}

			if ( $policy->isLocallyRunnable( $tool_name ) ) {
				continue;
			}

			$rejections[ $tool_name ] = array(
				'tool_name'           => $tool_name,
				'reason'              => 'host_tool_policy',
				'execution_location'  => $policy->executionLocation( $tool_name ),
				'source'              => $source_slug,
			);
			unset( $source_tools[ $tool_name ] );
		}

		if ( ! empty( $context['include_source_rejection_metadata'] ) && ! empty( $rejections ) ) {
			$source_tools[ AbilityToolSource::REJECTION_METADATA_KEY ] = $rejections;
		} else {
			unset( $source_tools[ AbilityToolSource::REJECTION_METADATA_KEY ] );
		}

		return $source_tools;
	}

	/**
	 * Agents API source-tools filter that enforces host policy after source filters.
	 *
	 * @param mixed                         $source_tools Source tool declarations.
	 * @param string                        $source_slug  Source slug.
	 * @param array<string,mixed>           $context      Resolver context.
	 * @param WP_Agent_Tool_Source_Registry $registry     Source registry instance.
	 * @return mixed Filtered source tools.
	 */
	public function filterSourceToolsForHostPolicy( $source_tools, string $source_slug, array $context, WP_Agent_Tool_Source_Registry $registry ) {
		if ( $registry !== $this->registry || ! is_array( $source_tools ) ) {
			return $source_tools;
		}

		return $this->applyHostToolPolicy( $source_tools, $source_slug, $context );
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
