<?php
/**
 * Per-target tool executor registry.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves tool-call executors by `target_id`.
 *
 * Consumers register an executor for an execution environment (an ability-backed
 * executor, a sandboxed-filesystem executor, a remote-runner executor, etc.) by
 * keying a {@see WP_Agent_Tool_Executor} under a `target_id` through the
 * `agents_api_tool_executors` filter. A tool declaration selects its environment
 * with the `runtime.executor_target` key. The registry stays product-neutral: it
 * only maps target ids to the executor instances consumers register and never
 * knows which consumer registered which target.
 */
class WP_Agent_Tool_Executor_Registry {

	/**
	 * Canonical filter that maps `target_id` to a {@see WP_Agent_Tool_Executor}.
	 */
	public const EXECUTORS_FILTER = 'agents_api_tool_executors';

	/**
	 * Runtime metadata key on a tool declaration naming its execution target.
	 */
	public const RUNTIME_EXECUTOR_TARGET = 'executor_target';

	/**
	 * Executors keyed by target id.
	 *
	 * @var array<string, WP_Agent_Tool_Executor>
	 */
	private array $executors;

	/**
	 * @param array<mixed> $executors Raw executor map keyed by target id. Non-string keys
	 *        and non-executor values are dropped so a malformed registration cannot break
	 *        the default execution path.
	 */
	public function __construct( array $executors = array() ) {
		$this->executors = array();
		foreach ( $executors as $target_id => $executor ) {
			if ( is_string( $target_id ) && '' !== $target_id && $executor instanceof WP_Agent_Tool_Executor ) {
				$this->executors[ $target_id ] = $executor;
			}
		}
	}

	/**
	 * Build a registry from the `agents_api_tool_executors` filter.
	 *
	 * Consumers register `target_id => WP_Agent_Tool_Executor` entries through the
	 * filter. Non-string keys and non-executor values are ignored so a malformed
	 * registration cannot break the default execution path.
	 *
	 * @param array<mixed> $context Host runtime context for this invocation.
	 * @return self
	 */
	public static function fromFilters( array $context = array() ): self {
		$executors = array();
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( self::EXECUTORS_FILTER, $executors, $context );
			if ( is_array( $filtered ) ) {
				$executors = $filtered;
			}
		}

		return new self( $executors );
	}

	/**
	 * Whether any target-keyed executor is registered.
	 *
	 * @return bool
	 */
	public function hasExecutors(): bool {
		return array() !== $this->executors;
	}

	/**
	 * Resolve the registered executor for a target id.
	 *
	 * @param string $target_id Execution target id.
	 * @return WP_Agent_Tool_Executor|null Registered executor, or null when none is registered.
	 */
	public function executorForTarget( string $target_id ): ?WP_Agent_Tool_Executor {
		if ( '' === $target_id ) {
			return null;
		}

		return $this->executors[ $target_id ] ?? null;
	}

	/**
	 * Resolve the executor for a tool declaration, falling back to the default.
	 *
	 * The declaration's `runtime.executor_target` selects an execution environment.
	 * When that target has a registered executor, it is returned; otherwise the
	 * caller-provided default executor is returned unchanged so existing
	 * ability-backed tools and single-executor callers behave identically.
	 *
	 * @param array<mixed>           $tool_definition  Tool declaration selected for the call.
	 * @param WP_Agent_Tool_Executor $default_executor Caller-provided default executor.
	 * @return WP_Agent_Tool_Executor Executor to dispatch the tool call to.
	 */
	public function resolveForTool( array $tool_definition, WP_Agent_Tool_Executor $default_executor ): WP_Agent_Tool_Executor {
		$target_id = self::targetIdFromDeclaration( $tool_definition );
		if ( '' === $target_id ) {
			return $default_executor;
		}

		return $this->executorForTarget( $target_id ) ?? $default_executor;
	}

	/**
	 * Extract the execution target id from a tool declaration's runtime metadata.
	 *
	 * @param array<mixed> $tool_definition Tool declaration.
	 * @return string Target id, or empty string when unset.
	 */
	public static function targetIdFromDeclaration( array $tool_definition ): string {
		$runtime = isset( $tool_definition['runtime'] ) && is_array( $tool_definition['runtime'] )
			? $tool_definition['runtime']
			: array();

		$target_id = $runtime[ self::RUNTIME_EXECUTOR_TARGET ] ?? null;

		return is_string( $target_id ) ? trim( $target_id ) : '';
	}
}
