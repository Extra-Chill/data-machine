<?php
/**
 * Canonical workflow ability registrations.
 *
 * Three abilities, all dispatchers — agents-api itself ships no runner;
 * consumers register a runtime via the `wp_agent_workflow_handler` filter:
 *
 *   - `agents/run-workflow`      — execute a workflow by id (or inline spec).
 *   - `agents/validate-workflow` — structural validate (no DB / runtime touch).
 *   - `agents/describe-workflow` — return the registered spec + input schema.
 *
 * The dispatcher mirrors `agents/chat` (#100) so the two contracts are
 * familiar to consumers: validate input, look up a registered handler,
 * call it, fire observability hooks on failure.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

const AGENTS_RUN_WORKFLOW_ABILITY      = 'agents/run-workflow';
const AGENTS_VALIDATE_WORKFLOW_ABILITY = 'agents/validate-workflow';
const AGENTS_DESCRIBE_WORKFLOW_ABILITY = 'agents/describe-workflow';
const AGENTS_GET_WORKFLOW_RUN_ABILITY  = 'agents/get-workflow-run';
const AGENTS_CANCEL_WORKFLOW_RUN_ABILITY = 'agents/cancel-workflow-run';
const AGENTS_LIST_WORKFLOW_RUN_EVENTS_ABILITY = 'agents/list-workflow-run-events';

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( wp_has_ability_category( 'agents-api' ) ) {
			return;
		}

		wp_register_ability_category(
			'agents-api',
			array(
				'label'       => 'Agents API',
				'description' => 'Cross-cutting abilities provided by the Agents API substrate (channel dispatch, canonical chat contract, and workflow dispatch).',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! wp_has_ability( AGENTS_RUN_WORKFLOW_ABILITY ) ) {
			wp_register_ability(
				AGENTS_RUN_WORKFLOW_ABILITY,
				array(
					'label'               => 'Run Workflow',
					'description'         => 'Canonical entry point for running a registered workflow. Dispatches to whichever runtime is registered via the wp_agent_workflow_handler filter.',
					'category'            => 'agents-api',
					'input_schema'        => agents_run_workflow_input_schema(),
					'output_schema'       => agents_run_workflow_output_schema(),
					'execute_callback'    => __NAMESPACE__ . '\\agents_run_workflow_dispatch',
					'permission_callback' => __NAMESPACE__ . '\\agents_run_workflow_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'destructive' => true,
							'idempotent'  => false,
						),
					),
				)
			);
		}

		if ( ! wp_has_ability( AGENTS_VALIDATE_WORKFLOW_ABILITY ) ) {
			wp_register_ability(
				AGENTS_VALIDATE_WORKFLOW_ABILITY,
				array(
					'label'               => 'Validate Workflow Spec',
					'description'         => 'Structural validation of a workflow spec. Returns a list of structured errors (or an empty list when valid). Does not touch any runtime or storage.',
					'category'            => 'agents-api',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'spec' ),
						'properties' => array(
							'spec' => array(
								'type'        => 'object',
								'description' => 'Raw workflow spec to validate.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'required'   => array( 'valid', 'errors' ),
						'properties' => array(
							'valid'  => array( 'type' => 'boolean' ),
							'errors' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'path'    => array( 'type' => 'string' ),
										'code'    => array( 'type' => 'string' ),
										'message' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => __NAMESPACE__ . '\\agents_validate_workflow',
					'permission_callback' => __NAMESPACE__ . '\\agents_validate_workflow_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'idempotent' => true ),
					),
				)
			);
		}

		if ( ! wp_has_ability( AGENTS_DESCRIBE_WORKFLOW_ABILITY ) ) {
			wp_register_ability(
				AGENTS_DESCRIBE_WORKFLOW_ABILITY,
				array(
					'label'               => 'Describe Workflow',
					'description'         => 'Return a registered workflow spec along with its input declarations. Useful for callers that want to enumerate or render workflows without executing them.',
					'category'            => 'agents-api',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'workflow_id' ),
						'properties' => array(
							'workflow_id' => array( 'type' => 'string' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'spec'   => array( 'type' => array( 'object', 'null' ) ),
							'inputs' => array( 'type' => array( 'object', 'null' ) ),
						),
					),
					'execute_callback'    => __NAMESPACE__ . '\\agents_describe_workflow',
					'permission_callback' => __NAMESPACE__ . '\\agents_run_workflow_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'idempotent' => true ),
					),
				)
			);
		}

		$run_control_abilities = array(
			AGENTS_GET_WORKFLOW_RUN_ABILITY         => array(
				'label'            => 'Get Workflow Run',
				'description'      => 'Read the canonical status envelope for an addressable workflow run.',
				'input_schema'     => agents_workflow_run_id_input_schema(),
				'output_schema'    => agents_workflow_run_control_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_get_workflow_run',
				'permission'       => __NAMESPACE__ . '\\agents_workflow_run_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_CANCEL_WORKFLOW_RUN_ABILITY      => array(
				'label'            => 'Cancel Workflow Run',
				'description'      => 'Request best-effort cancellation for an addressable workflow run.',
				'input_schema'     => agents_workflow_run_id_input_schema(),
				'output_schema'    => agents_workflow_run_control_output_schema( true ),
				'execute_callback' => __NAMESPACE__ . '\\agents_cancel_workflow_run',
				'permission'       => __NAMESPACE__ . '\\agents_workflow_run_cancel_permission',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			AGENTS_LIST_WORKFLOW_RUN_EVENTS_ABILITY => array(
				'label'            => 'List Workflow Run Events',
				'description'      => 'List canonical lifecycle events for an addressable workflow run.',
				'input_schema'     => agents_workflow_run_events_input_schema(),
				'output_schema'    => agents_workflow_run_events_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_list_workflow_run_events',
				'permission'       => __NAMESPACE__ . '\\agents_workflow_run_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
		);

		foreach ( $run_control_abilities as $ability => $args ) {
			if ( wp_has_ability( $ability ) ) {
				continue;
			}

			wp_register_ability(
				$ability,
				array(
					'label'               => $args['label'],
					'description'         => $args['description'],
					'category'            => 'agents-api',
					'input_schema'        => $args['input_schema'],
					'output_schema'       => $args['output_schema'],
					'execute_callback'    => $args['execute_callback'],
					'permission_callback' => $args['permission'],
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => $args['annotations'],
					),
				)
			);
		}
	}
);

/**
 * Dispatch a workflow run to the registered runtime.
 *
 * @since  0.103.0
 *
 * @param  array<mixed> $input Canonical run-workflow input.
 * @return array<mixed>|\WP_Error Canonical output, or WP_Error if no runtime is registered.
 */
function agents_run_workflow_dispatch( array $input ) {
	/**
	 * Filter the workflow runtime handler.
	 *
	 * Consumers register a callable that accepts the canonical input array
	 * and returns either the canonical output or WP_Error. The first hook
	 * to return a callable wins.
	 *
	 * @since 0.103.0
	 *
	 * @param callable|null $handler Currently registered handler. Null when
	 *                               no runtime has registered.
	 * @param array<mixed>         $input   The canonical input being dispatched.
	 */
	$handler = apply_filters( 'wp_agent_workflow_handler', null, $input );

	if ( ! is_callable( $handler ) ) {
		/**
		 * Fires when agents/run-workflow dispatched but no handler was
		 * registered. Use for sysadmin-side observability.
		 *
		 * @since 0.103.0
		 *
		 * @param string $reason Always `'no_handler'` for this branch.
		 * @param array<mixed>  $input  The canonical input that was rejected.
		 */
		do_action( 'agents_run_workflow_dispatch_failed', 'no_handler', $input );

		return new \WP_Error(
			'agents_run_workflow_no_handler',
			'No agents/run-workflow handler is registered. Install a consumer plugin that registers a runtime, or add a callable to the wp_agent_workflow_handler filter.'
		);
	}

	$result = call_user_func( $handler, $input );

	if ( is_wp_error( $result ) ) {
		/** This action is documented above. */
		do_action( 'agents_run_workflow_dispatch_failed', $result->get_error_code(), $input );
		return $result;
	}

	if ( ! is_array( $result ) ) {
		/** This action is documented above. */
		do_action( 'agents_run_workflow_dispatch_failed', 'invalid_result', $input );
		return new \WP_Error(
			'agents_run_workflow_invalid_result',
			'agents/run-workflow handler returned an unexpected result type. Handlers must return an array matching the canonical output shape or a WP_Error.'
		);
	}

	return $result;
}

/**
 * `agents/validate-workflow` execute callback. Pure substrate — no
 * runtime hookup needed.
 *
 * @since  0.103.0
 *
 * @param  array<mixed> $input
 * @return array<mixed>
 */
function agents_validate_workflow( array $input ): array {
	$errors = WP_Agent_Workflow_Spec_Validator::validate( (array) ( $input['spec'] ?? array() ) );
	return array(
		'valid'  => empty( $errors ),
		'errors' => $errors,
	);
}

/**
 * `agents/describe-workflow` execute callback. Reads the in-memory
 * registry only — Store-backed workflows are described by their consumer.
 *
 * @since  0.103.0
 *
 * @param  array<mixed> $input
 * @return array<mixed>
 */
function agents_describe_workflow( array $input ): array {
	$workflow_id = is_string( $input['workflow_id'] ?? null ) ? $input['workflow_id'] : '';
	$spec        = WP_Agent_Workflow_Registry::find( $workflow_id );
	if ( null === $spec ) {
		return array(
			'spec'   => null,
			'inputs' => null,
		);
	}
	return array(
		'spec'   => $spec->to_array(),
		'inputs' => $spec->get_inputs(),
	);
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_get_workflow_run( array $input ) {
	$handler = apply_filters( 'wp_agent_workflow_run_status_handler', null, $input );
	if ( is_callable( $handler ) ) {
		return \AgentsAPI\AI\WP_Agent_Run_Control::normalize_run_result( call_user_func( $handler, $input ), 'agents_workflow_run_invalid_status' );
	}

	$run = \AgentsAPI\AI\WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, agents_workflow_string( $input['run_id'] ?? '' ) );
	if ( null === $run ) {
		return new \WP_Error( 'agents_workflow_run_not_found', 'No workflow run was found for the requested run_id.' );
	}

	return $run;
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_cancel_workflow_run( array $input ) {
	$handler = apply_filters( 'wp_agent_workflow_run_cancel_handler', null, $input );
	if ( is_callable( $handler ) ) {
		$result = \AgentsAPI\AI\WP_Agent_Run_Control::normalize_cancel_result( call_user_func( $handler, $input ), 'agents_workflow_run_invalid_cancel_result' );
	} else {
		$result = \AgentsAPI\AI\WP_Agent_Run_Control::request_cancel( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, agents_workflow_string( $input['run_id'] ?? '' ) );
		if ( null === $result ) {
			return new \WP_Error( 'agents_workflow_run_not_found', 'No workflow run was found for the requested run_id.' );
		}
		$result = \AgentsAPI\AI\WP_Agent_Run_Control::normalize_cancel_result( $result, 'agents_workflow_run_invalid_cancel_result' );
	}

	return $result;
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_list_workflow_run_events( array $input ) {
	$handler = apply_filters( 'wp_agent_workflow_run_events_handler', null, $input );
	if ( is_callable( $handler ) ) {
		return \AgentsAPI\AI\WP_Agent_Run_Control::normalize_events_result( call_user_func( $handler, $input ), 'agents_workflow_run_invalid_events_result' );
	}

	$result = \AgentsAPI\AI\WP_Agent_Run_Control::list_events(
		WP_Agent_Workflow_Runner::RUN_CONTROL_STORE,
		agents_workflow_string( $input['run_id'] ?? '' ),
		agents_workflow_string( $input['cursor'] ?? '' ),
		'' !== agents_workflow_string( $input['limit'] ?? '' ) ? (int) agents_workflow_string( $input['limit'] ?? '' ) : 100
	);

	if ( null === $result ) {
		return new \WP_Error( 'agents_workflow_run_not_found', 'No workflow run was found for the requested run_id.' );
	}

	return $result;
}

/**
 * Permission gate for the workflow abilities. Same default as
 * `agents/chat`: `manage_options`. Consumers with their own auth model
 * (HMAC-signed webhook, OAuth bearer, scheduled action) widen via the
 * `agents_run_workflow_permission` filter.
 *
 * @since 0.103.0
 *
 * @param array<mixed> $input Canonical input.
 * @return bool
 */
function agents_run_workflow_permission( array $input ): bool {
	/**
	 * Filter the permission decision for the canonical workflow abilities.
	 *
	 * @since 0.103.0
	 *
	 * @param bool  $allowed Default: current_user_can( 'manage_options' ).
	 * @param array<mixed> $input   The canonical input being authorized.
	 */
	return (bool) apply_filters(
		'agents_run_workflow_permission',
		current_user_can( 'manage_options' ),
		$input
	);
}

/**
 * Permission gate for `agents/validate-workflow`. Validation has no side
 * effects and exposes no information beyond what the caller already
 * supplied, so the default is more permissive than `agents/run-workflow`:
 * any logged-in user can lint a spec they're authoring. Anonymous callers
 * are still rejected unless the filter widens the gate.
 *
 * @since 0.103.0
 *
 * @param array<mixed> $input
 * @return bool
 */
function agents_validate_workflow_permission( array $input ): bool {
	/**
	 * Filter the permission decision for `agents/validate-workflow`.
	 *
	 * @since 0.103.0
	 *
	 * @param bool  $allowed Default: any logged-in user.
	 * @param array<mixed> $input
	 */
	return (bool) apply_filters(
		'agents_validate_workflow_permission',
		is_user_logged_in(),
		$input
	);
}

/** @param array<string,mixed> $input Ability input. */
function agents_workflow_run_read_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'read' ) : false;
	return (bool) apply_filters( 'agents_workflow_run_read_permission', $allowed, $input );
}

/** @param array<string,mixed> $input Ability input. */
function agents_workflow_run_cancel_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;
	return (bool) apply_filters( 'agents_workflow_run_cancel_permission', $allowed, $input );
}

/**
 * Canonical input schema for `agents/run-workflow`.
 *
 * @since  0.103.0
 *
 * @return array<mixed>
 * @return array<string, mixed>
 */
function agents_run_workflow_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'workflow_id' => array(
				'type'        => array( 'string', 'null' ),
				'description' => 'Id of a registered or stored workflow to run. Pass `null` to run an inline `spec` instead.',
			),
			'spec'        => array(
				'type'        => array( 'object', 'null' ),
				'description' => 'Inline workflow spec to run. Use when the workflow is not (yet) persisted. Either `workflow_id` or `spec` must be provided.',
			),
			'inputs'      => array(
				'type'        => 'object',
				'description' => 'Map of input_name => value supplied to the workflow. Required inputs missing here cause an early failure.',
				'default'     => array(),
			),
			'options'     => array(
				'type'        => 'object',
				'description' => 'Runtime options forwarded to the runner. Recognized keys: run_id, continue_on_error, metadata, evidence_refs.',
				'default'     => array(),
			),
		),
	);
}

/**
 * Canonical output schema for `agents/run-workflow`.
 *
 * @since  0.103.0
 *
 * @return array<mixed>
 * @return array<string, mixed>
 */
function agents_run_workflow_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id', 'workflow_id', 'status' ),
		'properties' => array(
			'run_id'        => array( 'type' => 'string' ),
			'workflow_id'   => array( 'type' => 'string' ),
			'status'        => array(
				'type' => 'string',
				'enum' => array( 'pending', 'running', 'succeeded', 'failed', 'skipped' ),
			),
			'output'        => array( 'type' => 'object' ),
			'steps'         => array( 'type' => 'array' ),
			'error'         => array( 'type' => array( 'object', 'null' ) ),
			'started_at'    => array( 'type' => 'integer' ),
			'ended_at'      => array( 'type' => 'integer' ),
			'metadata'      => array( 'type' => 'object' ),
			'evidence_refs' => array(
				'type'        => 'array',
				'description' => 'Neutral JSON-serializable artifact/log references owned by the host runtime.',
			),
			'replay'        => array(
				'type'       => 'object',
				'properties' => array(
					'run_record_schema_version' => array( 'type' => 'integer' ),
					'workflow_spec_version'     => array( 'type' => 'string' ),
					'workflow_spec_hash'        => array( 'type' => 'string' ),
					'workflow_spec_snapshot'    => array( 'type' => 'object' ),
				),
			),
		),
	);
}

/** @return array<string,mixed> */
function agents_workflow_run_id_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id' ),
		'properties' => array(
			'run_id' => array( 'type' => 'string' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_workflow_run_events_input_schema(): array {
	$schema                 = agents_workflow_run_id_input_schema();
	$properties             = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
	$properties['cursor']   = array( 'type' => 'string' );
	$properties['limit']    = array( 'type' => 'integer' );
	$schema['properties']   = $properties;
	return $schema;
}

/** @return array<string,mixed> */
function agents_workflow_run_control_output_schema( bool $include_cancelled = false ): array {
	$properties = array(
		'run_id'      => array( 'type' => 'string' ),
		'status'      => array(
			'type' => 'string',
			'enum' => \AgentsAPI\AI\WP_Agent_Run_Control::statuses(),
		),
		'started_at'  => array( 'type' => 'string' ),
		'updated_at'  => array( 'type' => 'string' ),
		'workflow_id' => array( 'type' => 'string' ),
		'metadata'    => array( 'type' => 'object' ),
	);
	$required   = array( 'run_id', 'status', 'started_at', 'updated_at', 'metadata' );
	if ( $include_cancelled ) {
		$required[]              = 'cancelled';
		$properties['cancelled'] = array( 'type' => 'boolean' );
	}

	return array(
		'type'       => 'object',
		'required'   => $required,
		'properties' => $properties,
	);
}

/** @return array<string,mixed> */
function agents_workflow_run_events_output_schema(): array {
	$schema                 = agents_workflow_run_control_output_schema();
	$required               = is_array( $schema['required'] ?? null ) ? array_values( $schema['required'] ) : array();
	$properties             = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
	$required[]             = 'events';
	$required[]             = 'cursor';
	$required[]             = 'has_more';
	$properties['events']   = array(
		'type'  => 'array',
		'items' => array( 'type' => 'object' ),
	);
	$properties['cursor']   = array( 'type' => 'string' );
	$properties['has_more'] = array( 'type' => 'boolean' );
	$schema['required']     = $required;
	$schema['properties']   = $properties;
	return $schema;
}

function agents_workflow_string( mixed $value ): string {
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

/**
 * Convenience helper for consumers: register a callable as the workflow
 * runtime handler.
 *
 * @since 0.103.0
 *
 * @param callable $handler  Receives the canonical input array, returns the
 *                           canonical output array or WP_Error.
 * @param int      $priority Filter priority. Default 10.
 */
function register_workflow_handler( callable $handler, int $priority = 10 ): void {
	add_filter(
		'wp_agent_workflow_handler',
		static function ( $existing, array $input ) use ( $handler ) {
			unset( $input );
			if ( null !== $existing ) {
				return $existing;
			}
			return $handler;
		},
		$priority,
		2
	);
}
