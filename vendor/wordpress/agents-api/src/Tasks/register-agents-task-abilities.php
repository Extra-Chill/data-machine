<?php
/**
 * Canonical task execution ability registration.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tasks;

defined( 'ABSPATH' ) || exit;

const AGENTS_RUN_TASK_ABILITY               = 'agents/run-task';
const AGENTS_LIST_EXECUTION_TARGETS_ABILITY = 'agents/list-execution-targets';
const AGENTS_GET_TASK_RUN_ABILITY           = 'agents/get-task-run';
const AGENTS_CANCEL_TASK_RUN_ABILITY        = 'agents/cancel-task-run';

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
				'description' => 'Cross-cutting abilities provided by the Agents API substrate.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		$abilities = array(
			AGENTS_RUN_TASK_ABILITY               => array(
				'label'            => 'Run Task',
				'description'      => 'Dispatch a product-neutral task request to a registered executor target.',
				'input_schema'     => agents_task_input_schema(),
				'output_schema'    => agents_task_result_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_run_task',
				'permission'       => __NAMESPACE__ . '\\agents_run_task_permission',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			AGENTS_LIST_EXECUTION_TARGETS_ABILITY => array(
				'label'            => 'List Execution Targets',
				'description'      => 'Discover registered product-neutral executor targets and their capabilities.',
				'input_schema'     => agents_list_execution_targets_input_schema(),
				'output_schema'    => agents_list_execution_targets_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_list_execution_targets',
				'permission'       => __NAMESPACE__ . '\\agents_task_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_GET_TASK_RUN_ABILITY           => array(
				'label'            => 'Get Task Run',
				'description'      => 'Read the canonical status/result envelope for an addressable task run.',
				'input_schema'     => agents_task_run_id_input_schema(),
				'output_schema'    => agents_task_result_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_get_task_run',
				'permission'       => __NAMESPACE__ . '\\agents_task_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_CANCEL_TASK_RUN_ABILITY        => array(
				'label'            => 'Cancel Task Run',
				'description'      => 'Request best-effort cancellation for an addressable task run.',
				'input_schema'     => agents_task_run_id_input_schema(),
				'output_schema'    => agents_cancel_task_run_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_cancel_task_run',
				'permission'       => __NAMESPACE__ . '\\agents_cancel_task_run_permission',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		);

		foreach ( $abilities as $ability => $args ) {
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
 * Dispatch a task request to the selected executor target.
 *
 * @param array<string,mixed> $input Canonical task input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_run_task( array $input ) {
	$input = agents_task_normalize_input( $input );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$targets = agents_execution_targets();
	if ( is_wp_error( $targets ) ) {
		return $targets;
	}
	$placement  = is_array( $input['placement'] ?? null ) ? agents_task_string_keyed_array( $input['placement'] ) : array();
	$run_id     = agents_task_string( $input['run_id'] ?? '' );
	$session_id = agents_task_string( $input['session_id'] ?? '' );

	$target = agents_task_select_target( $targets, $placement );
	if ( null === $target ) {
		do_action( 'agents_task_dispatch_failed', 'no_handler', $input );
		return new \WP_Error( 'agents_task_no_handler', 'No agents/run-task executor target is registered for the requested placement.' );
	}

	$handler = apply_filters( 'wp_agent_task_handler', null, $input, $target );
	if ( ! is_callable( $handler ) ) {
		do_action( 'agents_task_dispatch_failed', 'no_handler', $input );
		return new \WP_Error( 'agents_task_no_handler', 'No agents/run-task handler is registered for the selected executor target.' );
	}

	$executor_id          = agents_task_string( $target['id'] ?? '' );
	$input['executor_id'] = $executor_id;
	$metadata             = array(
		'executor_id'    => $executor_id,
		'target_kind'    => $target['kind'],
		'resource_class' => $placement['resource_class'] ?? '',
	);
	WP_Agent_Task_Run_Control::start_run( $run_id, $session_id, $executor_id, $metadata );

	$result = call_user_func( $handler, $input, $target );
	if ( is_wp_error( $result ) ) {
		WP_Agent_Task_Run_Control::save_run(
			array(
				'run_id'      => $run_id,
				'session_id'  => $session_id,
				'executor_id' => $executor_id,
				'status'      => WP_Agent_Task_Run_Control::STATUS_FAILED,
				'diagnostics' => array(
					'error_code'    => $result->get_error_code(),
					'error_message' => $result->get_error_message(),
				),
			)
		);
		do_action( 'agents_task_dispatch_failed', $result->get_error_code(), $input );
		return $result;
	}

	if ( ! is_array( $result ) ) {
		WP_Agent_Task_Run_Control::save_run(
			array(
				'run_id'      => $run_id,
				'session_id'  => $session_id,
				'executor_id' => $executor_id,
				'status'      => WP_Agent_Task_Run_Control::STATUS_FAILED,
			)
		);
		do_action( 'agents_task_dispatch_failed', 'invalid_result', $input );
		return new \WP_Error( 'agents_task_invalid_result', 'agents/run-task handlers must return an array matching agents-api/task-result/v1 or WP_Error.' );
	}

	$result = agents_task_string_keyed_array( $result );
	$result = array_merge(
		array(
			'schema'      => 'agents-api/task-result/v1',
			'run_id'      => $run_id,
			'session_id'  => $session_id,
			'executor_id' => $executor_id,
		),
		$result
	);

	try {
		return WP_Agent_Task_Run_Control::save_run( $result );
	} catch ( \InvalidArgumentException $error ) {
		do_action( 'agents_task_dispatch_failed', 'invalid_result', $input );
		return new \WP_Error( 'agents_task_invalid_result', $error->getMessage() );
	}
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_list_execution_targets( array $input ) {
	$targets = agents_execution_targets();
	if ( is_wp_error( $targets ) ) {
		return $targets;
	}

	$required_capabilities = agents_task_string_list( $input['required_capabilities'] ?? array() );
	$allowed_targets       = agents_task_string_list( $input['allowed_targets'] ?? array() );
	$resource_class        = agents_task_optional_string( $input['resource_class'] ?? null );

	$filtered = array();
	foreach ( $targets as $target ) {
		$target_id        = agents_task_string( $target['id'] ?? '' );
		$resource_classes = agents_task_string_list( $target['resource_classes'] ?? array() );
		$capabilities     = agents_task_string_list( $target['capabilities'] ?? array() );

		if ( array() !== $allowed_targets && ! in_array( $target_id, $allowed_targets, true ) ) {
			continue;
		}
		if ( null !== $resource_class && array() !== $resource_classes && ! in_array( $resource_class, $resource_classes, true ) ) {
			continue;
		}
		if ( array() !== array_diff( $required_capabilities, $capabilities ) ) {
			continue;
		}
		$filtered[] = $target;
	}

	return array(
		'schema'  => 'agents-api/execution-target-list/v1',
		'targets' => $filtered,
	);
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_get_task_run( array $input ) {
	$handler = apply_filters( 'wp_agent_task_run_status_handler', null, $input );
	if ( is_callable( $handler ) ) {
		return agents_task_normalize_run_control_result( call_user_func( $handler, $input ), 'agents_task_run_invalid_status' );
	}

	$run                  = WP_Agent_Task_Run_Control::get_run( agents_task_string( $input['run_id'] ?? '' ) );
	$requested_session_id = agents_task_string( $input['session_id'] ?? '' );
	if ( null !== $run && agents_task_string( $run['session_id'] ?? '' ) !== $requested_session_id ) {
		return new \WP_Error( 'agents_task_run_not_found', 'No task run was found for the requested session_id and run_id.' );
	}
	if ( null !== $run ) {
		return $run;
	}

	return new \WP_Error( 'agents_task_run_not_found', 'No task run was found for the requested run_id.' );
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_cancel_task_run( array $input ) {
	$handler = apply_filters( 'wp_agent_task_run_cancel_handler', null, $input );
	if ( is_callable( $handler ) ) {
		$result = agents_task_normalize_run_control_result( call_user_func( $handler, $input ), 'agents_task_run_invalid_cancel_result' );
	} else {
		$run                  = WP_Agent_Task_Run_Control::get_run( agents_task_string( $input['run_id'] ?? '' ) );
		$requested_session_id = agents_task_string( $input['session_id'] ?? '' );
		if ( null === $run || agents_task_string( $run['session_id'] ?? '' ) !== $requested_session_id ) {
			return new \WP_Error( 'agents_task_run_not_found', 'No task run was found for the requested session_id and run_id.' );
		}

		$result = WP_Agent_Task_Run_Control::request_cancel( agents_task_string( $input['run_id'] ?? '' ) );
		if ( null === $result ) {
			return new \WP_Error( 'agents_task_run_not_found', 'No task run was found for the requested run_id.' );
		}
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$status              = WP_Agent_Task_Run_Control::normalize_status( $result['status'] ?? WP_Agent_Task_Run_Control::STATUS_RUNNING );
	$result['status']    = $status;
	$result['cancelled'] = (bool) ( $result['cancelled'] ?? in_array( $status, array( WP_Agent_Task_Run_Control::STATUS_CANCELLING, WP_Agent_Task_Run_Control::STATUS_CANCELLED ), true ) );

	return $result;
}

/**
 * @return array<int,array<string,mixed>>|\WP_Error
 */
function agents_execution_targets() {
	$targets = apply_filters( 'wp_agent_execution_targets', array() );
	if ( ! is_array( $targets ) ) {
		return new \WP_Error( 'agents_execution_targets_invalid', 'Execution target registration must return an array.' );
	}

	$normalized = array();
	foreach ( $targets as $target ) {
		if ( ! is_array( $target ) ) {
			return new \WP_Error( 'agents_execution_targets_invalid', 'Execution target declarations must be arrays.' );
		}

		$target = agents_execution_target_normalize( agents_task_string_keyed_array( $target ) );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$normalized[] = $target;
	}

	return $normalized;
}

/**
 * @param array<string,mixed> $target Raw target declaration.
 * @return array<string,mixed>|\WP_Error
 */
function agents_execution_target_normalize( array $target ) {
	$id = agents_task_optional_string( $target['id'] ?? null );
	if ( null === $id ) {
		return new \WP_Error( 'agents_execution_target_invalid', 'Execution target declarations must include id.' );
	}

	return array(
		'schema'           => agents_task_optional_string( $target['schema'] ?? null ) ?? 'agents-api/executor-target/v1',
		'id'               => $id,
		'label'            => agents_task_optional_string( $target['label'] ?? null ) ?? $id,
		'kind'             => agents_task_optional_string( $target['kind'] ?? null ) ?? 'executor',
		'description'      => agents_task_optional_string( $target['description'] ?? null ) ?? '',
		'capabilities'     => agents_task_string_list( $target['capabilities'] ?? array() ),
		'resource_classes' => agents_task_string_list( $target['resource_classes'] ?? array() ),
		'metadata'         => is_array( $target['metadata'] ?? null ) ? agents_task_string_keyed_array( $target['metadata'] ) : array(),
	);
}

/**
 * @param array<string,mixed> $input Raw input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_task_normalize_input( array $input ) {
	$input = agents_task_string_keyed_array( $input );
	$task  = is_array( $input['task'] ?? null ) ? agents_task_string_keyed_array( $input['task'] ) : array();

	$task_id = agents_task_optional_string( $task['id'] ?? null ) ?? agents_task_optional_string( $input['task_id'] ?? null );
	if ( null === $task_id ) {
		return new \WP_Error( 'agents_task_invalid_input', 'agents/run-task input must include task.id or task_id.' );
	}

	$session_id = agents_task_optional_string( $input['session_id'] ?? null );
	if ( null === $session_id ) {
		$session_id = 'task_session_' . str_replace( 'task_run_', '', WP_Agent_Task_Run_Control::generate_run_id() );
	}

	$run_id = agents_task_optional_string( $input['run_id'] ?? null ) ?? WP_Agent_Task_Run_Control::generate_run_id();

	$task['schema']       = agents_task_optional_string( $task['schema'] ?? null ) ?? 'agents-api/task-input/v1';
	$task['id']           = $task_id;
	$task['instructions'] = agents_task_optional_string( $task['instructions'] ?? null ) ?? agents_task_optional_string( $input['instructions'] ?? null ) ?? '';
	$task['input']        = is_array( $task['input'] ?? null ) ? agents_task_string_keyed_array( $task['input'] ) : ( is_array( $input['input'] ?? null ) ? agents_task_string_keyed_array( $input['input'] ) : array() );
	$task['attachments']  = is_array( $task['attachments'] ?? null ) ? array_values( $task['attachments'] ) : ( is_array( $input['attachments'] ?? null ) ? array_values( $input['attachments'] ) : array() );
	$task['metadata']     = is_array( $task['metadata'] ?? null ) ? agents_task_string_keyed_array( $task['metadata'] ) : array();

	$input['schema']         = agents_task_optional_string( $input['schema'] ?? null ) ?? 'agents-api/task-input/v1';
	$input['task']           = $task;
	$input['task_id']        = $task_id;
	$input['session_id']     = $session_id;
	$input['run_id']         = $run_id;
	$input['placement']      = agents_task_normalize_placement( is_array( $input['placement'] ?? null ) ? agents_task_string_keyed_array( $input['placement'] ) : $input );
	$input['client_context'] = is_array( $input['client_context'] ?? null ) ? agents_task_string_keyed_array( $input['client_context'] ) : array();
	$input['metadata']       = is_array( $input['metadata'] ?? null ) ? agents_task_string_keyed_array( $input['metadata'] ) : array();

	return $input;
}

/**
 * @param array<string,mixed> $placement Raw placement hints.
 * @return array<string,mixed>
 */
function agents_task_normalize_placement( array $placement ): array {
	return array(
		'schema'                => 'agents-api/execution-placement/v1',
		'preferred_target'      => agents_task_optional_string( $placement['preferred_target'] ?? null ),
		'allowed_targets'       => agents_task_string_list( $placement['allowed_targets'] ?? array() ),
		'resource_class'        => agents_task_optional_string( $placement['resource_class'] ?? null ) ?? 'generic',
		'required_capabilities' => agents_task_string_list( $placement['required_capabilities'] ?? array() ),
		'metadata'              => is_array( $placement['metadata'] ?? null ) ? agents_task_string_keyed_array( $placement['metadata'] ) : array(),
	);
}

/**
 * @param array<int,array<string,mixed>> $targets Registered targets.
 * @param array<string,mixed>            $placement Placement hints.
 * @return array<string,mixed>|null
 */
function agents_task_select_target( array $targets, array $placement ): ?array {
	$allowed      = agents_task_string_list( $placement['allowed_targets'] ?? array() );
	$required     = agents_task_string_list( $placement['required_capabilities'] ?? array() );
	$resource     = agents_task_optional_string( $placement['resource_class'] ?? null );
	$preferred_id = agents_task_optional_string( $placement['preferred_target'] ?? null );
	$candidates   = array();

	foreach ( $targets as $target ) {
		$target_id        = agents_task_string( $target['id'] ?? '' );
		$resource_classes = agents_task_string_list( $target['resource_classes'] ?? array() );
		$capabilities     = agents_task_string_list( $target['capabilities'] ?? array() );

		if ( array() !== $allowed && ! in_array( $target_id, $allowed, true ) ) {
			continue;
		}
		if ( null !== $resource && array() !== $resource_classes && ! in_array( $resource, $resource_classes, true ) ) {
			continue;
		}
		if ( array() !== array_diff( $required, $capabilities ) ) {
			continue;
		}
		if ( null !== $preferred_id && $preferred_id === $target_id ) {
			return $target;
		}
		$candidates[] = $target;
	}

	return $candidates[0] ?? null;
}

/**
 * @param mixed  $result Handler result.
 * @param string $error_code Error code for invalid results.
 * @return array<string,mixed>|\WP_Error
 */
function agents_task_normalize_run_control_result( $result, string $error_code ) {
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) ) {
		return new \WP_Error( $error_code, 'Task run-control handlers must return an array or WP_Error.' );
	}

	try {
		return WP_Agent_Task_Run_Control::normalize_run( agents_task_string_keyed_array( $result ) );
	} catch ( \InvalidArgumentException $error ) {
		return new \WP_Error( $error_code, $error->getMessage() );
	}
}

/**
 * @param array<string,mixed> $input Ability input.
 */
function agents_task_read_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'read' ) : false;
	$allowed = (bool) apply_filters( 'agents_task_read_permission', $allowed, $input );
	return (bool) apply_filters( 'agents_task_permission', $allowed, $input );
}

/** @param array<string,mixed> $input Ability input. */
function agents_run_task_permission( array $input ): bool {
	$allowed = agents_task_write_permission( $input );
	$allowed = (bool) apply_filters( 'agents_run_task_permission', $allowed, $input );
	return (bool) apply_filters( 'agents_task_permission', $allowed, $input );
}

/** @param array<string,mixed> $input Ability input. */
function agents_cancel_task_run_permission( array $input ): bool {
	$allowed = agents_task_write_permission( $input );
	$allowed = (bool) apply_filters( 'agents_cancel_task_run_permission', $allowed, $input );
	return (bool) apply_filters( 'agents_task_permission', $allowed, $input );
}

/** @param array<string,mixed> $input Ability input. */
function agents_task_write_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;
	return $allowed || agents_task_current_user_owns_session( $input );
}

/** @param array<string,mixed> $input Ability input. */
function agents_task_current_user_owns_session( array $input ): bool {
	$owner = is_array( $input['session_owner'] ?? null ) ? agents_task_string_keyed_array( $input['session_owner'] ) : array();
	if ( 'user' !== agents_task_string( $owner['type'] ?? '' ) ) {
		return false;
	}

	$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	return 0 < $user_id && agents_task_string( $owner['key'] ?? '' ) === (string) $user_id;
}

function agents_task_optional_string( mixed $value ): ?string {
	if ( ! is_scalar( $value ) && ! $value instanceof \Stringable ) {
		return null;
	}

	$value = trim( (string) $value );
	return '' === $value ? null : $value;
}

function agents_task_string( mixed $value ): string {
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * @param mixed $value Raw list.
 * @return string[]
 */
function agents_task_string_list( mixed $value ): array {
	if ( null === agents_task_optional_string( $value ) && ! is_array( $value ) ) {
		return array();
	}

	$values = is_array( $value ) ? $value : array( $value );
	$list   = array();
	foreach ( $values as $item ) {
		$item = agents_task_optional_string( $item );
		if ( null !== $item ) {
			$list[] = $item;
		}
	}

	return array_values( array_unique( $list ) );
}

/**
 * @param array<array-key,mixed> $data Raw array.
 * @return array<string,mixed>
 */
function agents_task_string_keyed_array( array $data ): array {
	$result = array();
	foreach ( $data as $key => $value ) {
		if ( is_string( $key ) ) {
			$result[ $key ] = $value;
		}
	}
	return $result;
}

/** @return array<string,mixed> */
function agents_task_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'task' ),
		'properties' => array(
			'schema'         => array( 'type' => 'string' ),
			'task'           => agents_task_payload_schema(),
			'task_id'        => array( 'type' => 'string' ),
			'instructions'   => array( 'type' => 'string' ),
			'session_id'     => array( 'type' => array( 'string', 'null' ) ),
			'run_id'         => array( 'type' => array( 'string', 'null' ) ),
			'session_owner'  => agents_task_session_owner_schema(),
			'placement'      => agents_task_placement_schema(),
			'client_context' => array( 'type' => 'object' ),
			'metadata'       => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_task_payload_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'id' ),
		'properties' => array(
			'schema'       => array( 'type' => 'string' ),
			'id'           => array( 'type' => 'string' ),
			'instructions' => array( 'type' => 'string' ),
			'input'        => array( 'type' => 'object' ),
			'attachments'  => array( 'type' => 'array' ),
			'metadata'     => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_task_placement_schema(): array {
	return array(
		'type'       => array( 'object', 'null' ),
		'properties' => array(
			'schema'                => array( 'type' => 'string' ),
			'preferred_target'      => array( 'type' => array( 'string', 'null' ) ),
			'allowed_targets'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'resource_class'        => array( 'type' => 'string' ),
			'required_capabilities' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'metadata'              => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_executor_target_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'id', 'kind', 'capabilities' ),
		'properties' => array(
			'schema'           => array( 'type' => 'string' ),
			'id'               => array( 'type' => 'string' ),
			'label'            => array( 'type' => 'string' ),
			'kind'             => array( 'type' => 'string' ),
			'description'      => array( 'type' => 'string' ),
			'capabilities'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'resource_classes' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'metadata'         => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_task_result_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'schema', 'run_id', 'session_id', 'status', 'executor_id' ),
		'properties' => array(
			'schema'            => array( 'type' => 'string' ),
			'run_id'            => array( 'type' => 'string' ),
			'session_id'        => array( 'type' => 'string' ),
			'status'            => array(
				'type' => 'string',
				'enum' => WP_Agent_Task_Run_Control::statuses(),
			),
			'executor_id'       => array( 'type' => 'string' ),
			'execution_metrics' => agents_execution_metrics_schema(),
			'artifact_refs'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'diagnostics'       => array( 'type' => 'object' ),
			'events'            => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'provenance'        => array( 'type' => 'object' ),
			'output'            => array( 'type' => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ) ),
			'started_at'        => array( 'type' => 'string' ),
			'updated_at'        => array( 'type' => 'string' ),
			'metadata'          => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_execution_metrics_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'schema'              => array( 'type' => 'string' ),
			'environment'         => array( 'type' => 'string' ),
			'executor_id'         => array( 'type' => 'string' ),
			'wall_time_ms'        => array( 'type' => 'integer' ),
			'startup_time_ms'     => array( 'type' => 'integer' ),
			'tool_call_count'     => array( 'type' => 'integer' ),
			'per_tool_timings_ms' => array(
				'type'                 => 'object',
				'additionalProperties' => array( 'type' => 'integer' ),
			),
			'payload_bytes_in'    => array( 'type' => 'integer' ),
			'payload_bytes_out'   => array( 'type' => 'integer' ),
			'artifact_bytes'      => array( 'type' => 'integer' ),
			'failure_class'       => array( 'type' => 'string' ),
			'quality_signals'     => array( 'type' => 'object' ),
			'raw_refs'            => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
		),
	);
}

/** @return array<string,mixed> */
function agents_task_run_id_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'session_id', 'run_id' ),
		'properties' => array(
			'session_id'    => array( 'type' => 'string' ),
			'run_id'        => array( 'type' => 'string' ),
			'session_owner' => agents_task_session_owner_schema(),
		),
	);
}

/** @return array<string,mixed> */
function agents_task_session_owner_schema(): array {
	return array(
		'type'       => array( 'object', 'null' ),
		'properties' => array(
			'type' => array( 'type' => 'string' ),
			'key'  => array( 'type' => 'string' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_cancel_task_run_output_schema(): array {
	$schema     = agents_task_result_schema();
	$required   = is_array( $schema['required'] ?? null ) ? array_values( $schema['required'] ) : array();
	$properties = is_array( $schema['properties'] ?? null ) ? agents_task_string_keyed_array( $schema['properties'] ) : array();

	$required[]              = 'cancelled';
	$properties['cancelled'] = array( 'type' => 'boolean' );
	$schema['required']      = $required;
	$schema['properties']    = $properties;

	return $schema;
}

/** @return array<string,mixed> */
function agents_list_execution_targets_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'allowed_targets'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'resource_class'        => array( 'type' => 'string' ),
			'required_capabilities' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
		),
	);
}

/** @return array<string,mixed> */
function agents_list_execution_targets_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'schema', 'targets' ),
		'properties' => array(
			'schema'  => array( 'type' => 'string' ),
			'targets' => array(
				'type'  => 'array',
				'items' => agents_executor_target_schema(),
			),
		),
	);
}

/**
 * Convenience helper for consumers: register a callable as the task handler.
 *
 * @param callable $handler Receives canonical input and selected target.
 * @param int      $priority Filter priority. Default 10.
 */
function register_task_handler( callable $handler, int $priority = 10 ): void {
	add_filter(
		'wp_agent_task_handler',
		static function ( $existing, array $input, array $target ) use ( $handler ) {
			unset( $input, $target );
			return null !== $existing ? $existing : $handler;
		},
		$priority,
		3
	);
}
