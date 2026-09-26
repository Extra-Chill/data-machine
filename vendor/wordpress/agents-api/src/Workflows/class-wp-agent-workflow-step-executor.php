<?php
/**
 * Deterministic execution for a single workflow step.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

class WP_Agent_Workflow_Step_Executor {

	/**
	 * @param array<string,mixed> $handlers Step type handler candidates.
	 */
	public function __construct( private array $handlers ) {}

	/**
	 * Resolve, dispatch, normalize, and record one step.
	 *
	 * @param array<mixed> $step    Raw workflow step.
	 * @param WP_Agent_Workflow_Run_Context $context Mutable run context.
	 * @return array<mixed> Step record.
	 */
	public function execute( array $step, WP_Agent_Workflow_Run_Context $context ): array {
		$step_id  = self::string_value( $step['id'] ?? null );
		$type     = self::string_value( $step['type'] ?? null );
		$start_ts = time();
		$record   = array(
			'id'         => $step_id,
			'type'       => $type,
			'status'     => WP_Agent_Workflow_Run_Result::STATUS_RUNNING,
			'output'     => null,
			'started_at' => $start_ts,
			'ended_at'   => 0,
		);

		$handler           = $this->handlers[ $type ] ?? null;
		$record['handler'] = self::describe_handler( $handler );
		if ( ! is_callable( $handler ) ) {
			$record['status']   = WP_Agent_Workflow_Run_Result::STATUS_SKIPPED;
			$record['ended_at'] = time();
			$record['error']    = array(
				'code'    => 'no_step_handler',
				'message' => sprintf( 'no handler registered for step type `%s`', $type ),
			);

			return $record;
		}

		$context_array = $context->to_array();
		try {
			if ( 'foreach' === $type ) {
				$resolved = self::expand_foreach_outer_step( $step, $context_array );
			} elseif ( 'parallel' === $type ) {
				$resolved = self::expand_parallel_outer_step( $step, $context_array );
			} else {
				$resolved = WP_Agent_Workflow_Bindings::expand( $step, $context_array );
			}
			$record['resolved_step']                    = is_array( $resolved ) ? $resolved : array();
			$handler_context                            = $context_array;
			$handler_context['_workflow_step_handlers'] = $this->handlers;
			$step_output                                = call_user_func( $handler, $resolved, $handler_context );
		} catch ( \Throwable $throwable ) {
			$record['status']   = WP_Agent_Workflow_Run_Result::STATUS_FAILED;
			$record['ended_at'] = time();
			$record['error']    = array(
				'code'       => 'handler_exception',
				'error_type' => 'handler_exception',
				'message'    => $throwable->getMessage(),
				'data'       => array(
					'exception' => get_class( $throwable ),
				),
			);

			return $record;
		}

		if ( is_wp_error( $step_output ) ) {
			$record['status']   = WP_Agent_Workflow_Run_Result::STATUS_FAILED;
			$record['ended_at'] = time();
			$record['error']    = array(
				'code'    => $step_output->get_error_code(),
				'message' => $step_output->get_error_message(),
				'data'    => $step_output->get_error_data(),
			);

			return $record;
		}

		// Suspend directive: a successful handler MAY ask the run to park
		// mid-flight by returning an array carrying a reserved `_suspend`
		// envelope key (see WP_Agent_Workflow_Runner suspend/resume model).
		// This is invisible to existing handlers (none emit it), so it is
		// purely additive. The step is NOT ended and NOT marked succeeded;
		// it is parked. The runner recognizes the `'pending'` step status and
		// persists a suspension frame instead of advancing. We deliberately
		// skip set_step_output(): the step has no output yet — its real output
		// lands later, on resume, once the dispatched branches reconcile.
		if ( is_array( $step_output ) && isset( $step_output['_suspend'] ) && is_array( $step_output['_suspend'] ) ) {
			$record['status']   = 'pending';
			$record['suspend']  = $step_output['_suspend'];
			$record['output']   = null;
			$record['ended_at'] = 0;

			return $record;
		}

		$record['status']   = WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED;
		$record['output']   = is_array( $step_output ) ? $step_output : array( 'value' => $step_output );
		$record['ended_at'] = time();
		$context->set_step_output( $step_id, $record['output'] );

		return $record;
	}

	/**
	 * Expand a foreach step's outer fields while preserving nested step templates.
	 *
	 * @param array<mixed> $step
	 * @param array<mixed> $context
	 * @return array<mixed>
	 */
	private static function expand_foreach_outer_step( array $step, array $context ): array {
		$nested = $step['steps'] ?? array();
		unset( $step['steps'] );

		$expanded = WP_Agent_Workflow_Bindings::expand( $step, $context );
		if ( ! is_array( $expanded ) ) {
			$expanded = array();
		}
		$expanded['steps'] = $nested;

		return $expanded;
	}

	/**
	 * Expand a parallel step's outer fields while preserving nested step and
	 * branch templates. The `${...}` tokens inside nested `steps` (map shape)
	 * and `branches[].steps` (roles shape) are resolved later, per branch,
	 * against branch-scoped vars — exactly like foreach defers its nested
	 * `steps`. The outer `items`, `context`, and branch contract metadata are
	 * resolved now against the run context.
	 *
	 * @param array<mixed> $step
	 * @param array<mixed> $context
	 * @return array<mixed>
	 */
	private static function expand_parallel_outer_step( array $step, array $context ): array {
		$nested_steps    = $step['steps'] ?? null;
		$branch_step_map = array();
		if ( isset( $step['branches'] ) && is_array( $step['branches'] ) ) {
			$branches = $step['branches'];
			foreach ( $branches as $branch_index => $branch ) {
				if ( is_array( $branch ) && array_key_exists( 'steps', $branch ) ) {
					$branch_step_map[ $branch_index ] = $branch['steps'];
					unset( $branch['steps'] );
					$branches[ $branch_index ] = $branch;
				}
			}
			$step['branches'] = $branches;
		}
		unset( $step['steps'] );

		$expanded = WP_Agent_Workflow_Bindings::expand( $step, $context );
		if ( ! is_array( $expanded ) ) {
			$expanded = array();
		}

		if ( null !== $nested_steps ) {
			$expanded['steps'] = $nested_steps;
		}
		if ( $branch_step_map && isset( $expanded['branches'] ) && is_array( $expanded['branches'] ) ) {
			$expanded_branches = $expanded['branches'];
			foreach ( $branch_step_map as $branch_index => $branch_steps ) {
				if ( isset( $expanded_branches[ $branch_index ] ) && is_array( $expanded_branches[ $branch_index ] ) ) {
					$branch          = $expanded_branches[ $branch_index ];
					$branch['steps'] = $branch_steps;

					$expanded_branches[ $branch_index ] = $branch;
				}
			}
			$expanded['branches'] = $expanded_branches;
		}

		return $expanded;
	}

	/**
	 * Return a string only for values that can safely be represented as text.
	 *
	 * @param mixed $value Value to normalize.
	 */
	private static function string_value( $value ): string {
		if ( is_scalar( $value ) || $value instanceof \Stringable ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * @param mixed $handler Handler candidate.
	 * @return array<string, string>|null
	 */
	private static function describe_handler( $handler ): ?array {
		if ( is_string( $handler ) ) {
			return array(
				'type' => 'function',
				'name' => $handler,
			);
		}

		if ( is_array( $handler ) && isset( $handler[0], $handler[1] ) ) {
			$class_or_object = is_object( $handler[0] ) ? get_class( $handler[0] ) : self::string_value( $handler[0] );
			$method          = self::string_value( $handler[1] );

			return array(
				'type' => 'method',
				'name' => $class_or_object . '::' . $method,
			);
		}

		if ( $handler instanceof \Closure ) {
			return array(
				'type' => 'closure',
				'name' => 'Closure',
			);
		}

		if ( is_object( $handler ) && method_exists( $handler, '__invoke' ) ) {
			return array(
				'type' => 'invokable',
				'name' => get_class( $handler ),
			);
		}

		return null;
	}
}
