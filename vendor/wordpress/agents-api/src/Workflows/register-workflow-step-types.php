<?php
/**
 * Public registration helper for {@see WP_Agent_Workflow_Step_Type_Registry}.
 *
 * The class file contains the class; this helper lives alongside it as a
 * plain function so a file is either OO or procedural, never both
 * (PHPCS: Universal.Files.SeparateFunctionsFromOO).
 *
 * @package AgentsAPI
 * @since   0.14.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * Register a workflow step type's handler and field validation contract.
 *
 * Consumers that add a step type (`branch`, a nested `workflow`, a
 * product-specific fanout, …) call this once instead of hooking two
 * unrelated filters. One registration gets the type recognized by
 * {@see WP_Agent_Workflow_Spec_Validator}, validated per its own
 * `required` / `validate` contract, and dispatched by the runner.
 *
 * Registering an already-registered type keeps the first registration and
 * triggers `_doing_it_wrong` — no silent overwrite.
 *
 * @since 0.14.0
 *
 * @param string $type Step type name.
 * @param array{
 *     handler:  callable,
 *     validate?: callable(array<mixed> $step, string $path): array<int,array{path:string,code:string,message:string}>,
 *     required?: array<int,string>,
 * } $args Registration arguments:
 *     - `handler` (callable, required): receives ( array $resolved_step, array $context ),
 *       returns array|WP_Error. Dispatched by the runner exactly like a built-in step type.
 *     - `validate` (callable, optional): receives ( array $step, string $path ), returns a list of
 *       `{path,code,message}` structured errors (empty when valid). Runs during structural
 *       validation, before any handler executes.
 *     - `required` (list<string>, optional): field names that must be present as non-empty
 *       strings. Checked by the registry itself before `validate` runs; use this for simple
 *       required-field contracts instead of writing a `validate` callback for them.
 * @return bool True when registered, false when the handler wasn't callable or the type was
 *              already registered.
 */
function register_workflow_step_type( string $type, array $args ): bool {
	return WP_Agent_Workflow_Step_Type_Registry::register( $type, $args );
}
