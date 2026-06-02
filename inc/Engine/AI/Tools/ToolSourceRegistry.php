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
	/**
	 * Data Machine-owned Agents API registries that should use DM source ordering.
	 *
	 * @var \WeakMap<WP_Agent_Tool_Source_Registry, bool>|null
	 */
	private static ?\WeakMap $ordered_registries = null;

	public function __construct( ToolManager $tool_manager ) {
		$this->tool_manager = $tool_manager;
		$this->registry     = new WP_Agent_Tool_Source_Registry();

		$this->registerDataMachineSources();
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'agents_api_tool_source_order', array( self::class, 'orderSourcesForContext' ), 5, 3 );
		}
		$this->registerOrderedRegistry( $this->registry );
	}

	/**
	 * Gather tools from the sources configured for active modes.
	 *
	 * @param array $modes Agent mode slugs.
	 * @param array  $args Full resolution arguments.
	 * @return array Tools keyed by tool name.
	 */
	public function gather( array $modes, array $args ): array {
		return $this->registry->gather(
			array_merge(
				$args,
				array(
					'modes'        => $modes,
					'tool_manager' => $this->tool_manager,
				)
			)
		);
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
	public static function orderSourcesForContext( array $order, array $context, WP_Agent_Tool_Source_Registry $registry ): array {
		if ( null === self::$ordered_registries || ! isset( self::$ordered_registries[ $registry ] ) ) {
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

	/**
	 * Mark an Agents API registry as Data Machine-owned for the shared order hook.
	 *
	 * @param WP_Agent_Tool_Source_Registry $registry Source registry instance.
	 * @return void
	 */
	private function registerOrderedRegistry( WP_Agent_Tool_Source_Registry $registry ): void {
		self::$ordered_registries ??= new \WeakMap();
		self::$ordered_registries[ $registry ] = true;
	}

}
