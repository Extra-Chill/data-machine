<?php
/**
 * Memory Policy Resolver
 *
 * Single entry point for determining which agent memory files inject
 * into an AI call. Aggregates the existing memory sources
 * (MemoryFileRegistry for core files, pipeline_config.memory_files,
 * flow_config.memory_files) and applies a per-agent MemoryPolicy on
 * top.
 *
 * This is the memory-side parallel to ToolPolicyResolver. It does NOT
 * replace the registry or the additive pipeline/flow config; it
 * sits after them as a subtractive agent-scoped filter.
 *
 * Resolution precedence (highest to lowest):
 * 1. Explicit deny list passed in context (always wins)
 * 2. Per-agent policy deny list (from agent_config.memory_policy)
 * 3. Per-agent policy allow-only (narrows to subset)
 * 4. Context-level allow_only (narrows to subset)
 * 5. Context preset (registry's get_for_context)
 *
 * @package DataMachine\Engine\AI\Memory
 * @since   0.67.0
 */

namespace DataMachine\Engine\AI\Memory;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class MemoryPolicyResolver {

	/**
	 * Context presets, aligned with ToolPolicyResolver for consistency.
	 */
	public const CONTEXT_PIPELINE = 'pipeline';
	public const CONTEXT_CHAT     = 'chat';
	public const CONTEXT_SYSTEM   = 'system';

	/**
	 * Resolve the set of registered memory files applicable to a context
	 * after agent policy is applied.
	 *
	 * Used by CoreMemoryFilesDirective to replace its direct
	 * MemoryFileRegistry::get_for_context() call. The registry remains
	 * the source of truth for which files exist and where they live;
	 * this method is a per-agent filter on top.
	 *
	 * @param array $context {
	 *     Execution context describing the request.
	 *
	 *     @type string   $context    Required. Execution context slug (e.g. 'chat', 'pipeline').
	 *     @type int|null $agent_id   Optional agent ID for per-agent policy filtering.
	 *     @type array    $deny       Filenames to explicitly deny (highest precedence).
	 *     @type array    $allow_only Context-level allowlist narrowing.
	 * }
	 * @return array<string, array> Filename => metadata map, sorted by priority ascending.
	 */
	public function resolveRegistered( array $context ): array {
		$context_type = $context['context'] ?? '';
		$agent_id     = isset( $context['agent_id'] ) ? (int) $context['agent_id'] : 0;

		// 1. Start with registry output filtered by context (already priority-sorted).
		$files = ! empty( $context_type )
			? MemoryFileRegistry::get_for_context( $context_type )
			: MemoryFileRegistry::get_all();

		// 2. Apply per-agent policy.
		if ( $agent_id > 0 ) {
			$policy = $this->getAgentMemoryPolicy( $agent_id );
			$files  = $this->applyAgentPolicyToRegistered( $files, $policy );
		}

		// 3. Context-level allow_only (narrows to explicit subset).
		$allow_only = $context['allow_only'] ?? array();
		if ( ! empty( $allow_only ) ) {
			$files = array_intersect_key( $files, array_flip( array_map( 'sanitize_file_name', $allow_only ) ) );
		}

		// 4. Explicit deny list (always wins).
		$deny = $context['deny'] ?? array();
		if ( ! empty( $deny ) ) {
			$files = array_diff_key( $files, array_flip( array_map( 'sanitize_file_name', $deny ) ) );
		}

		// 5. Allow external filtering of the resolved registered set.
		$files = apply_filters( 'datamachine_resolved_memory_files', $files, $context_type, $context );

		return $files;
	}

	/**
	 * Filter an explicit list of filenames (e.g. from pipeline_config or
	 * flow_config) through the per-agent memory policy.
	 *
	 * Used by PipelineMemoryFilesDirective and FlowMemoryFilesDirective
	 * so that per-agent deny/allow applies consistently to all three
	 * memory injection surfaces, not just core registered files.
	 *
	 * @param array $filenames List of memory filenames from scope config.
	 * @param array $context   {
	 *     Execution context describing the request.
	 *
	 *     @type int|null $agent_id Agent ID for per-agent policy filtering.
	 *     @type array    $deny    Filenames to explicitly deny.
	 *     @type string   $scope   Optional scope label for logging ('pipeline', 'flow').
	 * }
	 * @return array Filtered list of filenames (preserves input order).
	 */
	public function filter( array $filenames, array $context ): array {
		if ( empty( $filenames ) ) {
			return $filenames;
		}

		// Normalize input.
		$filenames = array_values( array_map( 'sanitize_file_name', $filenames ) );

		$agent_id = isset( $context['agent_id'] ) ? (int) $context['agent_id'] : 0;

		// 1. Per-agent policy.
		if ( $agent_id > 0 ) {
			$policy    = $this->getAgentMemoryPolicy( $agent_id );
			$filenames = $this->applyAgentPolicyToList( $filenames, $policy );
		}

		// 2. Explicit deny (always wins).
		$deny = $context['deny'] ?? array();
		if ( ! empty( $deny ) ) {
			$deny_set  = array_flip( array_map( 'sanitize_file_name', $deny ) );
			$filenames = array_values( array_filter(
				$filenames,
				function ( $name ) use ( $deny_set ) {
					return ! isset( $deny_set[ $name ] );
				}
			) );
		}

		/**
		 * Filter the filtered explicit-list memory filenames.
		 *
		 * Fires for pipeline- and flow-scoped memory file lists after
		 * the per-agent MemoryPolicy is applied. The registered-file
		 * path fires `datamachine_resolved_memory_files` instead.
		 *
		 * @since 0.67.0
		 *
		 * @param string[] $filenames Filtered filenames.
		 * @param array    $context   Resolution context.
		 */
		return apply_filters( 'datamachine_resolved_scoped_memory_files', $filenames, $context );
	}

	/**
	 * Read an agent's memory policy from agent_config.
	 *
	 * Returns null when the agent does not exist, has no policy, or the
	 * policy is structurally invalid. Returns null for effectively
	 * no-op policies (mode=deny with empty deny list and no allow_only)
	 * to avoid a wasted filter pass.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array|null { mode, deny?, allow_only? } or null.
	 */
	public function getAgentMemoryPolicy( int $agent_id ): ?array {
		if ( $agent_id <= 0 ) {
			return null;
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return null;
		}

		$config = $agent['agent_config'] ?? array();

		if ( empty( $config['memory_policy'] ) || ! is_array( $config['memory_policy'] ) ) {
			return null;
		}

		$policy = $config['memory_policy'];

		// Must have a recognizable mode.
		$mode = $policy['mode'] ?? 'default';
		if ( ! in_array( $mode, array( 'default', 'deny', 'allow_only' ), true ) ) {
			return null;
		}

		// Default mode is a no-op: behave as if no policy is set.
		if ( 'default' === $mode ) {
			return null;
		}

		$deny       = isset( $policy['deny'] ) && is_array( $policy['deny'] )
			? array_values( array_map( 'sanitize_file_name', $policy['deny'] ) )
			: array();
		$allow_only = isset( $policy['allow_only'] ) && is_array( $policy['allow_only'] )
			? array_values( array_map( 'sanitize_file_name', $policy['allow_only'] ) )
			: array();

		// Deny mode with empty deny list is a no-op.
		if ( 'deny' === $mode && empty( $deny ) ) {
			return null;
		}

		// allow_only mode is meaningful even with an empty list (means "nothing").

		return array(
			'mode'       => $mode,
			'deny'       => $deny,
			'allow_only' => $allow_only,
		);
	}

	/**
	 * Apply an agent policy to a registered-file metadata map.
	 *
	 * Preserves file metadata (layer, priority, etc.) so downstream
	 * readers (MemoryFilesReader, AgentMemory) continue to resolve
	 * files correctly.
	 *
	 * @param array      $files  Filename => metadata map.
	 * @param array|null $policy Policy from getAgentMemoryPolicy() or null.
	 * @return array Filtered map.
	 */
	private function applyAgentPolicyToRegistered( array $files, ?array $policy ): array {
		if ( null === $policy ) {
			return $files;
		}

		$mode = $policy['mode'];

		if ( 'deny' === $mode ) {
			if ( empty( $policy['deny'] ) ) {
				return $files;
			}
			return array_diff_key( $files, array_flip( $policy['deny'] ) );
		}

		if ( 'allow_only' === $mode ) {
			if ( empty( $policy['allow_only'] ) ) {
				return array();
			}
			return array_intersect_key( $files, array_flip( $policy['allow_only'] ) );
		}

		return $files;
	}

	/**
	 * Apply an agent policy to a plain filename list.
	 *
	 * @param string[]   $filenames List of filenames.
	 * @param array|null $policy    Policy from getAgentMemoryPolicy() or null.
	 * @return string[] Filtered filenames (indices reset).
	 */
	private function applyAgentPolicyToList( array $filenames, ?array $policy ): array {
		if ( null === $policy ) {
			return $filenames;
		}

		$mode = $policy['mode'];

		if ( 'deny' === $mode ) {
			if ( empty( $policy['deny'] ) ) {
				return $filenames;
			}
			$deny_set = array_flip( $policy['deny'] );
			return array_values( array_filter(
				$filenames,
				function ( $name ) use ( $deny_set ) {
					return ! isset( $deny_set[ $name ] );
				}
			) );
		}

		if ( 'allow_only' === $mode ) {
			if ( empty( $policy['allow_only'] ) ) {
				return array();
			}
			$allow_set = array_flip( $policy['allow_only'] );
			return array_values( array_filter(
				$filenames,
				function ( $name ) use ( $allow_set ) {
					return isset( $allow_set[ $name ] );
				}
			) );
		}

		return $filenames;
	}

	/**
	 * Get available context presets.
	 *
	 * @return array<string, string> Context key => description.
	 */
	public static function getContexts(): array {
		return array(
			self::CONTEXT_PIPELINE => 'Pipeline step AI execution',
			self::CONTEXT_CHAT     => 'Admin chat session',
			self::CONTEXT_SYSTEM   => 'System task execution',
		);
	}
}
