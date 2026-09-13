<?php
/**
 * Structural validator for workflow specs.
 *
 * Walks a raw spec array and returns a list of structured errors:
 *
 *     [
 *       [
 *         'path'    => 'steps.1.ability',
 *         'code'    => 'missing_required',
 *         'message' => 'ability step is missing required `ability` field',
 *       ],
 *       ...
 *     ]
 *
 * An empty list means the spec is well-formed enough to construct a
 * {@see WP_Agent_Workflow_Spec}. Validators are deliberately separated
 * from the value object so consumers can use them on partial specs in
 * editor surfaces (linting in-progress JSON, REST validate endpoints,
 * `agents/validate-workflow` ability) without having to construct or
 * discard a Spec each call.
 *
 * The validator does NOT verify that referenced abilities or agents
 * actually exist — that's a runtime concern handled by the runner.
 * This pass is structural only.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Spec_Validator {

	/** @since 0.103.0 */
	public const KNOWN_STEP_TYPES = array( 'ability', 'agent', 'foreach', 'parallel' );

	/** @since 0.103.0 */
	public const KNOWN_TRIGGER_TYPES = array( 'on_demand', 'wp_action', 'cron' );

	/**
	 * Validate a raw workflow spec.
	 *
	 * @since 0.103.0
	 *
	 * @param array<mixed> $spec Raw spec.
	 * @return array<int,array{path:string,code:string,message:string}> Empty when valid.
	 */
	public static function validate( array $spec ): array {
		$errors = array();

		// id
		if ( empty( $spec['id'] ) || ! is_string( $spec['id'] ) ) {
			$errors[] = array(
				'path'    => 'id',
				'code'    => 'missing_required',
				'message' => 'workflow spec is missing a non-empty `id`',
			);
		}

		// inputs (optional but if present must be a map)
		if ( isset( $spec['inputs'] ) && ! is_array( $spec['inputs'] ) ) {
			$errors[] = array(
				'path'    => 'inputs',
				'code'    => 'invalid_type',
				'message' => '`inputs` must be a map of input_name => schema',
			);
		}

		// steps
		if ( ! isset( $spec['steps'] ) || ! is_array( $spec['steps'] ) || empty( $spec['steps'] ) ) {
			$errors[] = array(
				'path'    => 'steps',
				'code'    => 'missing_required',
				'message' => 'workflow spec must declare at least one step',
			);
		} else {
			$step_errors = self::validate_steps( array_values( $spec['steps'] ) );
			$errors      = array_merge( $errors, $step_errors );
		}

		// triggers (optional but if present must be a list)
		if ( isset( $spec['triggers'] ) ) {
			if ( ! is_array( $spec['triggers'] ) || array_values( $spec['triggers'] ) !== $spec['triggers'] ) {
				$errors[] = array(
					'path'    => 'triggers',
					'code'    => 'invalid_type',
					'message' => '`triggers` must be a list of trigger definitions',
				);
			} else {
				$trigger_errors = self::validate_triggers( $spec['triggers'] );
				$errors         = array_merge( $errors, $trigger_errors );
			}
		}

		// Forward / unknown step-id binding references. Cheap structural
		// pass: scan every `${steps.<id>.output.*}` token and verify <id>
		// exists earlier in the list. Catches typos and re-orderings that
		// would otherwise resolve to null silently at runtime.
		if ( isset( $spec['steps'] ) && is_array( $spec['steps'] ) ) {
			$binding_errors = self::validate_step_binding_references( array_values( $spec['steps'] ) );
			$errors         = array_merge( $errors, $binding_errors );
		}

		return $errors;
	}

	/**
	 * @param array<int,mixed> $steps
	 * @return array<int,array{path:string,code:string,message:string}>
	 */
	private static function validate_steps( array $steps ): array {
		$errors = array();
		$seen   = array();

		foreach ( $steps as $idx => $step ) {
			$path = "steps.{$idx}";

			if ( ! is_array( $step ) ) {
				$errors[] = array(
					'path'    => $path,
					'code'    => 'invalid_type',
					'message' => 'step entry must be an array',
				);
				continue;
			}

			if ( empty( $step['id'] ) || ! is_string( $step['id'] ) ) {
				$errors[] = array(
					'path'    => "{$path}.id",
					'code'    => 'missing_required',
					'message' => 'step is missing a non-empty `id`',
				);
			} elseif ( isset( $seen[ $step['id'] ] ) ) {
				$errors[] = array(
					'path'    => "{$path}.id",
					'code'    => 'duplicate_id',
					'message' => sprintf( 'step id `%s` is reused at index %d (first seen at index %d)', $step['id'], $idx, $seen[ $step['id'] ] ),
				);
			} else {
				$seen[ $step['id'] ] = $idx;
			}

			if ( empty( $step['type'] ) || ! is_string( $step['type'] ) ) {
				$errors[] = array(
					'path'    => "{$path}.type",
					'code'    => 'missing_required',
					'message' => 'step is missing a non-empty `type`',
				);
				continue; // type is needed for the per-type checks below
			}

			if ( ! in_array( $step['type'], self::KNOWN_STEP_TYPES, true ) ) {
				/**
				 * Allow consumer-extended step types. Agents-api ships only
				 * `ability` and `agent`; extra types (`branch`, `parallel`,
				 * `workflow`) get registered by consumers via a filter on
				 * `wp_agent_workflow_known_step_types`. Filtered list wins.
				 *
				 * @since 0.103.0
				 *
				 * @param array<string> $known_types Default v0 set.
				 */
				$known = (array) apply_filters( 'wp_agent_workflow_known_step_types', self::KNOWN_STEP_TYPES );
				if ( ! in_array( $step['type'], $known, true ) ) {
					$errors[] = array(
						'path'    => "{$path}.type",
						'code'    => 'unknown_step_type',
						'message' => sprintf(
							'unknown step type `%s` (known: %s)',
							$step['type'],
							implode( ', ', $known )
						),
					);
					continue;
				}
			}

			if ( 'ability' === $step['type'] ) {
				if ( empty( $step['ability'] ) || ! is_string( $step['ability'] ) ) {
					$errors[] = array(
						'path'    => "{$path}.ability",
						'code'    => 'missing_required',
						'message' => 'ability step is missing a non-empty `ability`',
					);
				}
			}

			if ( 'agent' === $step['type'] ) {
				if ( empty( $step['agent'] ) || ! is_string( $step['agent'] ) ) {
					$errors[] = array(
						'path'    => "{$path}.agent",
						'code'    => 'missing_required',
						'message' => 'agent step is missing a non-empty `agent`',
					);
				}
				if ( empty( $step['message'] ) || ! is_string( $step['message'] ) ) {
					$errors[] = array(
						'path'    => "{$path}.message",
						'code'    => 'missing_required',
						'message' => 'agent step is missing a non-empty `message`',
					);
				}
			}

			if ( 'foreach' === $step['type'] ) {
				if ( ! array_key_exists( 'items', $step ) ) {
					$errors[] = array(
						'path'    => "{$path}.items",
						'code'    => 'missing_required',
						'message' => 'foreach step is missing required `items` field',
					);
				}
				if ( empty( $step['steps'] ) || ! is_array( $step['steps'] ) || array_values( $step['steps'] ) !== $step['steps'] ) {
					$errors[] = array(
						'path'    => "{$path}.steps",
						'code'    => 'missing_required',
						'message' => 'foreach step must declare a non-empty `steps` list',
					);
				} else {
					foreach ( self::validate_steps( $step['steps'] ) as $inner_error ) {
						$inner_path          = (string) preg_replace( '/^steps\./', '', $inner_error['path'] );
						$inner_error['path'] = "{$path}.steps." . $inner_path;
						$errors[]            = $inner_error;
					}
				}
			}

			if ( 'parallel' === $step['type'] ) {
				$errors = array_merge( $errors, self::validate_parallel_step( $step, $path ) );
			}
		}

		return $errors;
	}

	/**
	 * Validate a `parallel` (agent fanout) step. The one step type expresses
	 * two shapes; exactly one must be present:
	 *
	 *   - parallel-map: `items` + a non-empty nested `steps` list.
	 *   - parallel-roles: a non-empty `branches` list, each branch a role
	 *     contract with a `role` + nested `steps`. At most one branch may be
	 *     flagged `is_aggregator` (the optional aggregator); zero is valid.
	 *
	 * @since 0.4.0
	 *
	 * @param array<mixed> $step Raw parallel step.
	 * @param string       $path Error path prefix for this step.
	 * @return array<int,array{path:string,code:string,message:string}>
	 */
	private static function validate_parallel_step( array $step, string $path ): array {
		$errors       = array();
		$has_branches = isset( $step['branches'] );
		$has_items    = array_key_exists( 'items', $step );

		if ( $has_branches === $has_items ) {
			$errors[] = array(
				'path'    => $path,
				'code'    => 'invalid_parallel_shape',
				'message' => 'parallel step must declare exactly one of `branches` (roles) or `items` (map)',
			);
			// Without a clear shape there's nothing further to validate.
			if ( ! $has_branches && ! $has_items ) {
				return $errors;
			}
		}

		// parallel-map shape.
		if ( $has_items ) {
			if ( empty( $step['steps'] ) || ! is_array( $step['steps'] ) || array_values( $step['steps'] ) !== $step['steps'] ) {
				$errors[] = array(
					'path'    => "{$path}.steps",
					'code'    => 'missing_required',
					'message' => 'parallel-map step must declare a non-empty `steps` list',
				);
			} else {
				foreach ( self::validate_steps( $step['steps'] ) as $inner_error ) {
					$inner_path          = (string) preg_replace( '/^steps\./', '', $inner_error['path'] );
					$inner_error['path'] = "{$path}.steps." . $inner_path;
					$errors[]            = $inner_error;
				}
			}
		}

		// parallel-roles shape.
		if ( $has_branches ) {
			if ( ! is_array( $step['branches'] ) || array_values( $step['branches'] ) !== $step['branches'] || empty( $step['branches'] ) ) {
				$errors[] = array(
					'path'    => "{$path}.branches",
					'code'    => 'missing_required',
					'message' => 'parallel-roles step must declare a non-empty list of `branches`',
				);
				return $errors;
			}

			$aggregator_count = 0;
			foreach ( $step['branches'] as $branch_idx => $branch ) {
				$branch_path = "{$path}.branches.{$branch_idx}";
				if ( ! is_array( $branch ) ) {
					$errors[] = array(
						'path'    => $branch_path,
						'code'    => 'invalid_type',
						'message' => 'parallel branch entry must be an array',
					);
					continue;
				}

				if ( empty( $branch['role'] ) || ! is_string( $branch['role'] ) ) {
					$errors[] = array(
						'path'    => "{$branch_path}.role",
						'code'    => 'missing_required',
						'message' => 'parallel branch is missing a non-empty `role`',
					);
				}

				if ( empty( $branch['steps'] ) || ! is_array( $branch['steps'] ) || array_values( $branch['steps'] ) !== $branch['steps'] ) {
					$errors[] = array(
						'path'    => "{$branch_path}.steps",
						'code'    => 'missing_required',
						'message' => 'parallel branch must declare a non-empty `steps` list',
					);
				} else {
					foreach ( self::validate_steps( $branch['steps'] ) as $inner_error ) {
						$inner_path          = (string) preg_replace( '/^steps\./', '', $inner_error['path'] );
						$inner_error['path'] = "{$branch_path}.steps." . $inner_path;
						$errors[]            = $inner_error;
					}
				}

				if ( ! empty( $branch['is_aggregator'] ) ) {
					++$aggregator_count;
				}
			}

			// The aggregator branch is OPTIONAL: zero or one is valid, more than
			// one is ambiguous (which output is the step's final?).
			if ( $aggregator_count > 1 ) {
				$errors[] = array(
					'path'    => "{$path}.branches",
					'code'    => 'invalid_parallel_aggregator',
					'message' => sprintf(
						'parallel-roles step may flag at most one branch with `is_aggregator` (the aggregator); found %d',
						$aggregator_count
					),
				);
			}
		}

		return $errors;
	}

	/**
	 * Scan every `${steps.<id>.output.*}` reference inside step args and
	 * fields, return errors for any id that's unknown or only appears
	 * later in the list. Bindings to `inputs.*` aren't checked here — the
	 * runner validates inputs against the spec at run time.
	 *
	 * @param array<int,mixed> $steps
	 * @return array<int,array{path:string,code:string,message:string}>
	 */
	private static function validate_step_binding_references( array $steps ): array {
		$errors = array();
		$seen   = array();

		foreach ( $steps as $idx => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$step_id = is_string( $step['id'] ?? null ) ? $step['id'] : '';
			$tokens  = self::extract_top_level_step_binding_ids( $step );

			foreach ( $tokens as $referenced_id ) {
				if ( ! isset( $seen[ $referenced_id ] ) ) {
					$errors[] = array(
						'path'    => "steps.{$idx}",
						'code'    => 'unknown_step_reference',
						'message' => sprintf(
							'step `%s` references `%s` which is not defined %s in the workflow',
							'' !== $step_id ? $step_id : (string) $idx,
							$referenced_id,
							isset( $seen[ $step_id ] ) ? 'before this step' : 'before this step'
						),
					);
				}
			}

			if ( '' !== $step_id ) {
				$seen[ $step_id ] = true;
			}
		}

		return $errors;
	}

	/**
	 * Pull step references from a top-level step without validating nested
	 * foreach step bodies against the outer step order.
	 *
	 * @param array<mixed> $step
	 * @return array<int,string>
	 */
	private static function extract_top_level_step_binding_ids( array $step ): array {
		$type = $step['type'] ?? '';
		$ids  = array();
		foreach ( $step as $key => $value ) {
			// foreach + parallel-map defer their nested `steps`, and
			// parallel-roles defers each branch's nested `steps`, to
			// branch-scoped resolution; those bodies don't reference the
			// outer step order so they're excluded from this cheap pass.
			if ( 'steps' === $key && in_array( $type, array( 'foreach', 'parallel' ), true ) ) {
				continue;
			}
			if ( 'branches' === $key && 'parallel' === $type ) {
				continue;
			}
			$ids = array_merge( $ids, self::extract_step_binding_ids( $value ) );
		}
		return $ids;
	}

	/**
	 * Walk a step array and pull out every `${steps.<id>.output.*}` token's
	 * id segment. Used by {@see validate_step_binding_references()}.
	 *
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private static function extract_step_binding_ids( $value ): array {
		$ids = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $inner ) {
				$ids = array_merge( $ids, self::extract_step_binding_ids( $inner ) );
			}
			return $ids;
		}

		if ( ! is_string( $value ) ) {
			return $ids;
		}

		if ( preg_match_all( '/\$\{\s*steps\.([a-zA-Z0-9_\-]+)\./', $value, $matches ) ) {
			foreach ( $matches[1] as $found ) {
				$ids[] = (string) $found;
			}
		}

		return $ids;
	}

	/**
	 * @param array<int,mixed> $triggers
	 * @return array<int,array{path:string,code:string,message:string}>
	 */
	private static function validate_triggers( array $triggers ): array {
		$errors = array();

		foreach ( $triggers as $idx => $trigger ) {
			$path = "triggers.{$idx}";

			if ( ! is_array( $trigger ) ) {
				$errors[] = array(
					'path'    => $path,
					'code'    => 'invalid_type',
					'message' => 'trigger entry must be an array',
				);
				continue;
			}

			if ( empty( $trigger['type'] ) || ! is_string( $trigger['type'] ) ) {
				$errors[] = array(
					'path'    => "{$path}.type",
					'code'    => 'missing_required',
					'message' => 'trigger is missing a non-empty `type`',
				);
				continue;
			}

			$known = self::string_list( apply_filters( 'wp_agent_workflow_known_trigger_types', self::KNOWN_TRIGGER_TYPES ) );
			if ( ! in_array( $trigger['type'], $known, true ) ) {
				$errors[] = array(
					'path'    => "{$path}.type",
					'code'    => 'unknown_trigger_type',
					'message' => sprintf(
						'unknown trigger type `%s` (known: %s)',
						$trigger['type'],
						implode( ', ', $known )
					),
				);
				continue;
			}

			if ( 'wp_action' === $trigger['type'] && empty( $trigger['hook'] ) ) {
				$errors[] = array(
					'path'    => "{$path}.hook",
					'code'    => 'missing_required',
					'message' => 'wp_action trigger is missing a non-empty `hook`',
				);
			}

			if ( 'cron' === $trigger['type'] && empty( $trigger['expression'] ) && empty( $trigger['interval'] ) ) {
				$errors[] = array(
					'path'    => $path,
					'code'    => 'missing_required',
					'message' => 'cron trigger needs either `expression` (cron string) or `interval` (seconds)',
				);
			}
		}

		return $errors;
	}

	/**
	 * @param mixed $values Raw values.
	 * @return array<int,string>
	 */
	private static function string_list( $values ): array {
		$values   = is_array( $values ) ? $values : array();
		$prepared = array();
		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$prepared[] = $value;
			}
		}

		return array_values( array_unique( $prepared ) );
	}
}
