<?php
/**
 * Canonical runtime-tool lifecycle ability registration.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

const AGENTS_LIST_RUNTIME_TOOL_REQUESTS_ABILITY   = 'agents/list-runtime-tool-requests';
const AGENTS_GET_RUNTIME_TOOL_REQUEST_ABILITY     = 'agents/get-runtime-tool-request';
const AGENTS_SUBMIT_RUNTIME_TOOL_RESULT_ABILITY   = 'agents/submit-runtime-tool-result';
const AGENTS_TIMEOUT_RUNTIME_TOOL_REQUEST_ABILITY = 'agents/timeout-runtime-tool-request';
const AGENTS_CANCEL_RUNTIME_TOOL_REQUEST_ABILITY  = 'agents/cancel-runtime-tool-request';

add_action(
	'wp_abilities_api_init',
	static function (): void {
		$abilities = array(
			AGENTS_LIST_RUNTIME_TOOL_REQUESTS_ABILITY   => array(
				'label'            => 'List Runtime Tool Requests',
				'description'      => 'List recent pending runtime-tool requests from the host-provided request store.',
				'input_schema'     => agents_runtime_tool_list_requests_input_schema(),
				'output_schema'    => agents_runtime_tool_list_requests_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_runtime_tool_list_requests',
				'permission'       => __NAMESPACE__ . '\\agents_runtime_tool_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_GET_RUNTIME_TOOL_REQUEST_ABILITY     => array(
				'label'            => 'Get Runtime Tool Request',
				'description'      => 'Read status and retained result data for a runtime-tool request.',
				'input_schema'     => agents_runtime_tool_request_id_input_schema(),
				'output_schema'    => agents_runtime_tool_request_status_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_runtime_tool_get_request',
				'permission'       => __NAMESPACE__ . '\\agents_runtime_tool_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_SUBMIT_RUNTIME_TOOL_RESULT_ABILITY   => array(
				'label'            => 'Submit Runtime Tool Result',
				'description'      => 'Submit a runtime-tool result, complete the pending request, and optionally resume through the host continuation adapter.',
				'input_schema'     => agents_runtime_tool_submit_result_input_schema(),
				'output_schema'    => agents_runtime_tool_submission_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_runtime_tool_submit_result',
				'permission'       => __NAMESPACE__ . '\\agents_runtime_tool_write_permission',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			AGENTS_TIMEOUT_RUNTIME_TOOL_REQUEST_ABILITY => array(
				'label'            => 'Timeout Runtime Tool Request',
				'description'      => 'Mark a pending runtime-tool request timed out and optionally resume through the host continuation adapter.',
				'input_schema'     => agents_runtime_tool_terminal_request_input_schema(),
				'output_schema'    => agents_runtime_tool_terminal_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_runtime_tool_timeout_request',
				'permission'       => __NAMESPACE__ . '\\agents_runtime_tool_write_permission',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			AGENTS_CANCEL_RUNTIME_TOOL_REQUEST_ABILITY  => array(
				'label'            => 'Cancel Runtime Tool Request',
				'description'      => 'Cancel a pending runtime-tool request by applying the canonical timeout terminal transition.',
				'input_schema'     => agents_runtime_tool_terminal_request_input_schema(),
				'output_schema'    => agents_runtime_tool_terminal_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_runtime_tool_cancel_request',
				'permission'       => __NAMESPACE__ . '\\agents_runtime_tool_write_permission',
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
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_runtime_tool_list_requests( array $input ) {
	$store = agents_runtime_tool_request_store( $input );
	if ( is_wp_error( $store ) ) {
		return $store;
	}

	try {
		$requests = WP_Agent_Runtime_Tool_Lifecycle::recent_pending_requests( $store, agents_runtime_tool_query( $input ) );
	} catch ( \InvalidArgumentException $error ) {
		return new \WP_Error( 'agents_runtime_tool_invalid_request', $error->getMessage() );
	}

	return array(
		'status'   => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
		'requests' => $requests,
		'count'    => count( $requests ),
	);
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_runtime_tool_get_request( array $input ) {
	$store = agents_runtime_tool_request_store( $input );
	if ( is_wp_error( $store ) ) {
		return $store;
	}

	$request_id = agents_runtime_tool_required_string( $input, 'request_id' );
	if ( is_wp_error( $request_id ) ) {
		return $request_id;
	}

	$request = $store->get( $request_id );
	if ( null === $request ) {
		return new \WP_Error( 'agents_runtime_tool_request_not_found', 'No runtime-tool request was found for the requested request_id.' );
	}

	try {
		$normalized = agents_runtime_tool_normalize_stored_request( $request );
	} catch ( \InvalidArgumentException $error ) {
		return new \WP_Error( 'agents_runtime_tool_invalid_request', $error->getMessage() );
	}

	$status = is_string( $normalized['status'] ?? null ) ? $normalized['status'] : '';

	return array(
		'status'  => $status,
		'request' => $normalized,
	);
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_runtime_tool_submit_result( array $input ) {
	$store = agents_runtime_tool_request_store( $input );
	if ( is_wp_error( $store ) ) {
		return $store;
	}

	$continuation = agents_runtime_tool_continuation( $input );
	if ( is_wp_error( $continuation ) ) {
		return $continuation;
	}

	try {
		return WP_Agent_Runtime_Tool_Lifecycle::submit_result( $store, $input, $continuation, agents_runtime_tool_context( $input ) );
	} catch ( \InvalidArgumentException $error ) {
		return new \WP_Error( 'agents_runtime_tool_invalid_result', $error->getMessage() );
	}
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_runtime_tool_timeout_request( array $input ) {
	return agents_runtime_tool_terminal_request( $input, false );
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_runtime_tool_cancel_request( array $input ) {
	return agents_runtime_tool_terminal_request( $input, true );
}

/**
 * @param array<string, mixed> $input Ability input.
 * @param bool                 $cancelled Whether this terminal transition was requested as cancellation.
 * @return array<string, mixed>|\WP_Error
 */
function agents_runtime_tool_terminal_request( array $input, bool $cancelled ) {
	$store = agents_runtime_tool_request_store( $input );
	if ( is_wp_error( $store ) ) {
		return $store;
	}

	$request_id = agents_runtime_tool_required_string( $input, 'request_id' );
	if ( is_wp_error( $request_id ) ) {
		return $request_id;
	}

	$continuation = agents_runtime_tool_continuation( $input );
	if ( is_wp_error( $continuation ) ) {
		return $continuation;
	}

	try {
		$result = WP_Agent_Runtime_Tool_Lifecycle::timeout_request( $store, $request_id, $continuation, agents_runtime_tool_context( $input ) );
	} catch ( \InvalidArgumentException $error ) {
		return new \WP_Error( 'agents_runtime_tool_invalid_timeout', $error->getMessage() );
	}

	if ( $cancelled ) {
		$result['cancelled'] = true;
	}

	return $result;
}

/**
 * Resolve the host-provided runtime-tool request store.
 *
 * @param array<string, mixed> $input Ability input.
 * @return WP_Agent_Runtime_Tool_Request_Store|\WP_Error
 */
function agents_runtime_tool_request_store( array $input ) {
	/**
	 * Filters the runtime-tool request store used by lifecycle abilities.
	 *
	 * @param WP_Agent_Runtime_Tool_Request_Store|null $store Current store, or null.
	 * @param array<string, mixed>                     $input Ability input.
	 */
	$store = apply_filters( 'wp_agent_runtime_tool_request_store', null, $input );

	if ( $store instanceof WP_Agent_Runtime_Tool_Request_Store ) {
		return $store;
	}

	return new \WP_Error(
		'agents_runtime_tool_request_store_unavailable',
		'No runtime-tool request store is registered. Add a WP_Agent_Runtime_Tool_Request_Store through the wp_agent_runtime_tool_request_store filter.'
	);
}

/**
 * Resolve an optional host continuation adapter.
 *
 * @param array<string, mixed> $input Ability input.
 * @return WP_Agent_Runtime_Tool_Continuation|callable|null|\WP_Error
 */
function agents_runtime_tool_continuation( array $input ) {
	if ( empty( $input['resume'] ) ) {
		return null;
	}

	/**
	 * Filters the continuation adapter used when lifecycle abilities resume a run.
	 *
	 * @param mixed                $continuation Current continuation adapter, or null.
	 * @param array<string, mixed> $input Ability input.
	 */
	$continuation = apply_filters( 'wp_agent_runtime_tool_continuation', null, $input );
	/** @var mixed $continuation */

	if ( null === $continuation || $continuation instanceof WP_Agent_Runtime_Tool_Continuation ) {
		return $continuation;
	}

	if ( is_callable( $continuation ) ) {
		return $continuation;
	}

	return new \WP_Error( 'agents_runtime_tool_invalid_continuation', 'Runtime-tool continuation must be callable or implement WP_Agent_Runtime_Tool_Continuation.' );
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function agents_runtime_tool_query( array $input ): array {
	$query = array();
	foreach ( array( 'run_id', 'tool_name', 'before', 'after' ) as $field ) {
		if ( is_string( $input[ $field ] ?? null ) && '' !== trim( $input[ $field ] ) ) {
			$query[ $field ] = trim( $input[ $field ] );
		}
	}

	if ( isset( $input['limit'] ) && is_int( $input['limit'] ) && $input['limit'] > 0 ) {
		$query['limit'] = $input['limit'];
	}

	return $query;
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function agents_runtime_tool_context( array $input ): array {
	$context = $input['context'] ?? null;
	if ( ! is_array( $context ) ) {
		return array();
	}

	$normalized = array();
	foreach ( $context as $key => $value ) {
		if ( is_string( $key ) ) {
			$normalized[ $key ] = $value;
		}
	}

	return $normalized;
}

/**
 * @param array<string, mixed> $input Ability input.
 * @param string               $field Field name.
 * @return string|\WP_Error
 */
function agents_runtime_tool_required_string( array $input, string $field ) {
	$value = $input[ $field ] ?? '';
	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return new \WP_Error( 'agents_runtime_tool_invalid_request', $field . ' must be a non-empty string.' );
	}

	return trim( $value );
}

/**
 * @param array<string, mixed> $request Stored request.
 * @return array<string, mixed>
 */
function agents_runtime_tool_normalize_stored_request( array $request ): array {
	$status               = is_string( $request['status'] ?? null ) && '' !== trim( $request['status'] ) ? trim( $request['status'] ) : WP_Agent_Runtime_Tool_Request::STATUS_PENDING;
	$normalized           = WP_Agent_Runtime_Tool_Request::normalize( $request );
	$normalized['status'] = $status;

	if ( isset( $request['result'] ) && is_array( $request['result'] ) ) {
		$stored_result = array();
		foreach ( $request['result'] as $key => $value ) {
			if ( is_string( $key ) ) {
				$stored_result[ $key ] = $value;
			}
		}

		$normalized['result'] = WP_Agent_Runtime_Tool_Result::from_request( $normalized, $stored_result );
	}

	return $normalized;
}

/** @return array<string, mixed> */
function agents_runtime_tool_list_requests_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'run_id'    => array( 'type' => 'string' ),
			'tool_name' => array( 'type' => 'string' ),
			'before'    => array( 'type' => 'string' ),
			'after'     => array( 'type' => 'string' ),
			'limit'     => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
	);
}

/** @return array<string, mixed> */
function agents_runtime_tool_request_id_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'request_id' ),
		'properties' => array(
			'request_id' => array( 'type' => 'string' ),
		),
	);
}

/** @return array<string, mixed> */
function agents_runtime_tool_submit_result_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'request_id' ),
		'properties' => array(
			'request_id' => array( 'type' => 'string' ),
			'success'    => array( 'type' => 'boolean' ),
			'result'     => array( 'type' => 'object' ),
			'error'      => array( 'type' => 'string' ),
			'metadata'   => array( 'type' => 'object' ),
			'resume'     => array( 'type' => 'boolean' ),
			'context'    => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string, mixed> */
function agents_runtime_tool_terminal_request_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'request_id' ),
		'properties' => array(
			'request_id' => array( 'type' => 'string' ),
			'reason'     => array( 'type' => 'string' ),
			'resume'     => array( 'type' => 'boolean' ),
			'context'    => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string, mixed> */
function agents_runtime_tool_request_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'status', 'request_id', 'tool_name', 'tool_call_id' ),
		'properties' => array(
			'status'       => array( 'type' => 'string' ),
			'request_id'   => array( 'type' => 'string' ),
			'tool_name'    => array( 'type' => 'string' ),
			'tool_call_id' => array( 'type' => 'string' ),
			'parameters'   => array( 'type' => 'object' ),
			'run_id'       => array( 'type' => 'string' ),
			'timeout_at'   => array( 'type' => 'string' ),
			'runtime'      => array( 'type' => 'object' ),
			'metadata'     => array( 'type' => 'object' ),
			'result'       => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string, mixed> */
function agents_runtime_tool_result_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'status', 'request_id', 'tool_name', 'success' ),
		'properties' => array(
			'status'     => array( 'type' => 'string' ),
			'request_id' => array( 'type' => 'string' ),
			'tool_name'  => array( 'type' => 'string' ),
			'success'    => array( 'type' => 'boolean' ),
			'result'     => array( 'type' => 'object' ),
			'error'      => array( 'type' => 'string' ),
			'metadata'   => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string, mixed> */
function agents_runtime_tool_list_requests_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'status', 'requests', 'count' ),
		'properties' => array(
			'status'   => array( 'type' => 'string' ),
			'requests' => array(
				'type'  => 'array',
				'items' => agents_runtime_tool_request_schema(),
			),
			'count'    => array( 'type' => 'integer' ),
		),
	);
}

/** @return array<string, mixed> */
function agents_runtime_tool_request_status_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'status', 'request' ),
		'properties' => array(
			'status'  => array( 'type' => 'string' ),
			'request' => agents_runtime_tool_request_schema(),
		),
	);
}

/** @return array<string, mixed> */
function agents_runtime_tool_submission_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'status', 'request', 'result', 'duplicate' ),
		'properties' => array(
			'status'                => array( 'type' => 'string' ),
			'request'               => agents_runtime_tool_request_schema(),
			'result'                => agents_runtime_tool_result_schema(),
			'duplicate'             => array( 'type' => 'boolean' ),
			'tool_result_message'   => array( 'type' => 'object' ),
			'tool_execution_result' => array( 'type' => 'object' ),
			'continuation_result'   => array( 'type' => array( 'object', 'null' ) ),
		),
	);
}

/** @return array<string, mixed> */
function agents_runtime_tool_terminal_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'status', 'request', 'result' ),
		'properties' => array(
			'status'                => array( 'type' => 'string' ),
			'request'               => agents_runtime_tool_request_schema(),
			'result'                => agents_runtime_tool_result_schema(),
			'tool_result_message'   => array( 'type' => 'object' ),
			'tool_execution_result' => array( 'type' => 'object' ),
			'continuation_result'   => array( 'type' => array( 'object', 'null' ) ),
			'cancelled'             => array( 'type' => 'boolean' ),
		),
	);
}

/**
 * @param array<string, mixed> $input Ability input.
 */
function agents_runtime_tool_read_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;

	/**
	 * Filters permission for read-only runtime-tool lifecycle abilities.
	 *
	 * @param bool                 $allowed Default permission result.
	 * @param array<string, mixed> $input Ability input.
	 */
	return (bool) apply_filters( 'agents_runtime_tool_read_permission', $allowed, $input );
}

/**
 * @param array<string, mixed> $input Ability input.
 */
function agents_runtime_tool_write_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;

	/**
	 * Filters permission for mutating runtime-tool lifecycle abilities.
	 *
	 * @param bool                 $allowed Default permission result.
	 * @param array<string, mixed> $input Ability input.
	 */
	return (bool) apply_filters( 'agents_runtime_tool_write_permission', $allowed, $input );
}
