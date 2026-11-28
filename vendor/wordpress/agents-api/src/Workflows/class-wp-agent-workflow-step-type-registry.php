<?php
/**
 * Single source of truth for workflow step types: handler + field validation.
 *
 * A workflow step type used to be defined in up to four places that could
 * drift apart — the validator's known-type list, the runner's default
 * handler map (declared twice), and the reconcile branch resolver's copy of
 * that same map. A type could validate and then fail at run time with no
 * handler, or have a handler but fail validation, and consumer-registered
 * types got no field validation at all.
 *
 * This registry pairs each step type with its handler and an optional field
 * validation contract in one place. {@see WP_Agent_Workflow_Spec_Validator}
 * pulls known types and per-type validation from here; the runner and the
 * reconcile branch resolver pull the handler map from here. Built-in types
 * (`ability`, `agent`, `foreach`, `parallel`) are registered lazily on first
 * access so a consumer's own `register()` calls, run before or after
 * agents-api's own boot, always land the same result.
 *
 * @package AgentsAPI
 * @since   0.14.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Step_Type_Registry {

	/**
	 * Registered step types.
	 *
	 * @var array<string,array{handler:callable,validate:?callable,required:array<int,string>}>
	 */
	private static array $types = array();

	/**
	 * Whether the built-in step types have been registered for this request.
	 */
	private static bool $booted = false;

	/**
	 * Register a step type's handler and field validation contract.
	 *
	 * Registering an already-registered type keeps the first registration
	 * and triggers `_doing_it_wrong` — no silent overwrite. This mirrors the
	 * duplicate-registration guard other agents-api registries use.
	 *
	 * @since 0.14.0
	 *
	 * @param string $type Step type name (`ability`, `agent`, `foreach`, `parallel`, or a consumer type).
	 * @param array{
	 *     handler?:  mixed,
	 *     validate?: mixed,
	 *     required?: mixed,
	 * } $args Registration arguments:
	 *     - `handler` (callable, required): receives ( array $resolved_step, array $context ),
	 *       returns array|WP_Error.
	 *     - `validate` (callable, optional): receives ( array $step, string $path ), returns
	 *       a list of `{path,code,message}` structured errors.
	 *     - `required` (list<string>, optional): field names checked for a non-empty string value.
	 *     Types are declared as `mixed` here (rather than the documented shapes above) because this
	 *     is a public registration boundary — a caller can pass the wrong shape at runtime and this
	 *     method is exactly what validates and rejects that, rather than trusting the PHPDoc contract.
	 * @return bool True when registered, false when the type name was invalid, the handler
	 *              wasn't callable, or the type was already registered.
	 */
	public static function register( string $type, array $args ): bool {
		self::ensure_booted();

		if ( '' === $type ) {
			return false;
		}

		if ( isset( self::$types[ $type ] ) ) {
			if ( function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'Workflow step type `%s` is already registered. The existing registration is kept.',
						function_exists( 'esc_html' ) ? esc_html( $type ) : $type
					),
					'0.14.0'
				);
			}
			return false;
		}

		$handler = $args['handler'] ?? null;
		if ( ! self::is_callable_shape( $handler ) ) {
			if ( function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'Workflow step type `%s` was not registered: a callable `handler` is required.',
						function_exists( 'esc_html' ) ? esc_html( $type ) : $type
					),
					'0.14.0'
				);
			}
			return false;
		}

		$validate = $args['validate'] ?? null;
		$required = array();
		if ( isset( $args['required'] ) && is_array( $args['required'] ) ) {
			foreach ( $args['required'] as $field ) {
				if ( is_string( $field ) && '' !== $field ) {
					$required[] = $field;
				}
			}
		}

		self::$types[ $type ] = array(
			'handler'  => $handler,
			'validate' => self::is_callable_shape( $validate ) ? $validate : null,
			'required' => $required,
		);

		return true;
	}

	/**
	 * Look up a registered step type's raw registration.
	 *
	 * @since 0.14.0
	 *
	 * @param string $type Step type name.
	 * @return array{handler:callable,validate:?callable,required:array<int,string>}|null
	 */
	public static function get( string $type ): ?array {
		self::ensure_booted();
		return self::$types[ $type ] ?? null;
	}

	/**
	 * All registered step types, keyed by type name.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string,array{handler:callable,validate:?callable,required:array<int,string>}>
	 */
	public static function all(): array {
		self::ensure_booted();
		return self::$types;
	}

	/**
	 * Registered step type names, in registration order.
	 *
	 * @since 0.14.0
	 *
	 * @return array<int,string>
	 */
	public static function types(): array {
		self::ensure_booted();
		return array_keys( self::$types );
	}

	/**
	 * Registered step type → handler map, for the runner and reconcile resolver.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string,callable>
	 */
	public static function handlers(): array {
		self::ensure_booted();
		$handlers = array();
		foreach ( self::$types as $type => $entry ) {
			$handlers[ $type ] = $entry['handler'];
		}
		return $handlers;
	}

	/**
	 * Validate one resolved step against its registered type's contract:
	 * the generic `required` field check first, then the type's `validate`
	 * callback. Unregistered types produce no errors here — the caller
	 * (the spec validator) is responsible for rejecting unknown types
	 * before reaching this point.
	 *
	 * @since 0.14.0
	 *
	 * @param array<mixed> $step Raw step, including its `type`.
	 * @param string       $path Error path prefix for this step (e.g. `steps.0`).
	 * @return array<int,array{path:string,code:string,message:string}>
	 */
	public static function validate_step( array $step, string $path ): array {
		self::ensure_booted();

		$type = is_string( $step['type'] ?? null ) ? $step['type'] : '';
		if ( '' === $type || ! isset( self::$types[ $type ] ) ) {
			return array();
		}

		$entry  = self::$types[ $type ];
		$errors = array();

		foreach ( $entry['required'] as $field ) {
			if ( empty( $step[ $field ] ) || ! is_string( $step[ $field ] ) ) {
				$errors[] = array(
					'path'    => "{$path}.{$field}",
					'code'    => 'missing_required',
					'message' => sprintf( '%s step is missing a non-empty `%s`', $type, $field ),
				);
			}
		}

		if ( is_callable( $entry['validate'] ) ) {
			$callback_result = call_user_func( $entry['validate'], $step, $path );
			$errors           = array_merge( $errors, self::normalize_errors( is_array( $callback_result ) ? $callback_result : array() ) );
		}

		return $errors;
	}

	/**
	 * Filter a `validate` callback's raw return value down to well-formed
	 * `{path,code,message}` error tuples, dropping anything malformed
	 * instead of trusting a consumer-supplied callback's shape.
	 *
	 * @param array<mixed> $raw Raw return value from a `validate` callback.
	 * @return array<int,array{path:string,code:string,message:string}>
	 */
	private static function normalize_errors( array $raw ): array {
		$errors = array();
		foreach ( $raw as $error ) {
			if (
				is_array( $error )
				&& isset( $error['path'], $error['code'], $error['message'] )
				&& is_string( $error['path'] )
				&& is_string( $error['code'] )
				&& is_string( $error['message'] )
			) {
				$errors[] = array(
					'path'    => $error['path'],
					'code'    => $error['code'],
					'message' => $error['message'],
				);
			}
		}
		return $errors;
	}

	/**
	 * Test-only: clear the registry, including the built-in registrations,
	 * so the next access re-boots from a clean slate.
	 *
	 * @since 0.14.0
	 */
	public static function reset(): void {
		self::$types  = array();
		self::$booted = false;
	}

	/**
	 * Structural callable check that never triggers autoloading.
	 *
	 * `is_callable()` on a `[class-string, method]` pair resolves the class
	 * through the autoloader to verify the method exists — which is exactly
	 * the wrong thing to do at registration time. Agents-api's own built-ins
	 * point at `WP_Agent_Workflow_Runner`, and consumers register their own
	 * handlers, often before that class (or the consumer's own class) has
	 * been loaded yet in the current request. This only checks the shape —
	 * a `Closure`, non-empty string, `[target, method]` pair, or invokable
	 * object — and defers real callability to `call_user_func()` at
	 * dispatch time, same as the pre-registry code that stored these
	 * handlers as plain array values without ever validating them.
	 *
	 * @param mixed $value Candidate handler or validate callback.
	 * @phpstan-assert-if-true callable $value
	 */
	private static function is_callable_shape( $value ): bool {
		if ( $value instanceof \Closure ) {
			return true;
		}

		if ( is_string( $value ) ) {
			return '' !== $value;
		}

		if ( is_array( $value ) ) {
			if ( 2 !== count( $value ) ) {
				return false;
			}
			$target = $value[0] ?? null;
			$method = $value[1] ?? null;
			return ( is_string( $target ) || is_object( $target ) ) && is_string( $method ) && '' !== $method;
		}

		return is_object( $value ) && method_exists( $value, '__invoke' );
	}

	/**
	 * Lazily register the built-in step types on first access. Lazy
	 * (rather than hooked to a WordPress action) so the registry behaves
	 * identically in and out of a full WordPress bootstrap, including in
	 * plain-PHP smoke tests that never fire `init`.
	 */
	private static function ensure_booted(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		self::register_builtin_types();
	}

	/**
	 * Register agents-api's four built-in step types. Field checks mirror
	 * the checks {@see WP_Agent_Workflow_Spec_Validator} performed inline
	 * before this registry existed — moved here so the validator, runner,
	 * and reconcile resolver read one contract instead of three.
	 */
	private static function register_builtin_types(): void {
		self::register(
			'ability',
			array(
				'handler'  => array( WP_Agent_Workflow_Runner::class, 'default_ability_handler' ),
				'required' => array( 'ability' ),
			)
		);

		self::register(
			'agent',
			array(
				'handler'  => array( WP_Agent_Workflow_Runner::class, 'default_agent_handler' ),
				'required' => array( 'agent', 'message' ),
			)
		);

		self::register(
			'foreach',
			array(
				'handler'  => array( WP_Agent_Workflow_Runner::class, 'default_foreach_handler' ),
				'validate' => array( __CLASS__, 'validate_foreach_step' ),
			)
		);

		self::register(
			'parallel',
			array(
				'handler'  => array( WP_Agent_Workflow_Runner::class, 'default_parallel_handler' ),
				'validate' => array( __CLASS__, 'validate_parallel_step' ),
			)
		);
	}

	/**
	 * `foreach` step field validation: requires an `items` key (any value,
	 * including a falsy one, is fine structurally — the runner resolves and
	 * type-checks it at run time) and a non-empty, list-shaped nested
	 * `steps`, recursively validated.
	 *
	 * @param array<mixed> $step Raw `foreach` step.
	 * @param string       $path Error path prefix for this step.
	 * @return array<int,array{path:string,code:string,message:string}>
	 */
	private static function validate_foreach_step( array $step, string $path ): array {
		$errors = array();

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
			foreach ( WP_Agent_Workflow_Spec_Validator::validate_steps( $step['steps'] ) as $inner_error ) {
				$inner_path          = (string) preg_replace( '/^steps\./', '', $inner_error['path'] );
				$inner_error['path'] = "{$path}.steps." . $inner_path;
				$errors[]            = $inner_error;
			}
		}

		return $errors;
	}

	/**
	 * `parallel` step field validation. The one step type expresses two
	 * shapes; exactly one must be present:
	 *
	 *   - parallel-map: `items` + a non-empty nested `steps` list.
	 *   - parallel-roles: a non-empty `branches` list, each branch a role
	 *     contract with a `role` + nested `steps`. At most one branch may be
	 *     flagged `is_aggregator` (the optional aggregator); zero is valid.
	 *
	 * @param array<mixed> $step Raw `parallel` step.
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
				foreach ( WP_Agent_Workflow_Spec_Validator::validate_steps( $step['steps'] ) as $inner_error ) {
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
					foreach ( WP_Agent_Workflow_Spec_Validator::validate_steps( $branch['steps'] ) as $inner_error ) {
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
}
