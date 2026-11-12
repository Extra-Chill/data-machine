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
	 * Validate a list of steps. Public because
	 * {@see WP_Agent_Workflow_Step_Type_Registry}'s built-in `foreach` and
	 * `parallel` validate callbacks recurse into a step's nested `steps`
	 * (or, for `parallel`, each branch's `steps`) through this same
	 * function — one recursive step-list validator instead of a copy
	 * living in the registry.
	 *
	 * @since 0.103.0
	 *
	 * @param array<int,mixed> $steps
	 * @return array<int,array{path:string,code:string,message:string}>
	 */
	public static function validate_steps( array $steps ): array {
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

			$known = self::known_step_types();
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

			$errors = array_merge( $errors, WP_Agent_Workflow_Step_Type_Registry::validate_step( $step, $path ) );
		}

		return $errors;
	}

	/**
	 * Known step types: {@see WP_Agent_Workflow_Step_Type_Registry}'s
	 * registered types. The registry is the sole source of truth; register a
	 * new type through {@see WP_Agent_Workflow_Step_Type_Registry::register()}
	 * or `register_workflow_step_type()`.
	 *
	 * @since 0.14.0
	 *
	 * @return array<int,string>
	 */
	public static function known_step_types(): array {
		return WP_Agent_Workflow_Step_Type_Registry::types();
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
