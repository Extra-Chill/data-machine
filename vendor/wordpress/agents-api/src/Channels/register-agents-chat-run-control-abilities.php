<?php
/**
 * Canonical chat run-control ability registration.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Channels;

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;
use AgentsAPI\AI\WP_Agent_Filter_Run_Control_Adapter;
use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

const AGENTS_GET_CHAT_RUN_ABILITY         = 'agents/get-chat-run';
const AGENTS_CANCEL_CHAT_RUN_ABILITY      = 'agents/cancel-chat-run';
const AGENTS_QUEUE_CHAT_MESSAGE_ABILITY   = 'agents/queue-chat-message';
const AGENTS_LIST_CHAT_RUN_EVENTS_ABILITY = 'agents/list-chat-run-events';

add_action(
	'wp_abilities_api_init',
	static function (): void {
		$abilities = array(
			AGENTS_LIST_CHAT_RUN_EVENTS_ABILITY => array(
				'label'            => 'List Chat Run Events',
				'description'      => 'List canonical lifecycle events for an addressable chat run.',
				'input_schema'     => agents_chat_run_events_input_schema(),
				'output_schema'    => agents_chat_run_events_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_list_chat_run_events',
				'permission'       => __NAMESPACE__ . '\\agents_chat_run_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_GET_CHAT_RUN_ABILITY         => array(
				'label'            => 'Get Chat Run',
				'description'      => 'Read the canonical status for an addressable chat run.',
				'input_schema'     => agents_chat_run_id_input_schema(),
				'output_schema'    => agents_chat_run_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_get_chat_run',
				'permission'       => __NAMESPACE__ . '\\agents_chat_run_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_CANCEL_CHAT_RUN_ABILITY      => array(
				'label'            => 'Cancel Chat Run',
				'description'      => 'Request best-effort cancellation for an addressable chat run.',
				'input_schema'     => agents_chat_run_id_input_schema(),
				'output_schema'    => agents_cancel_chat_run_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_cancel_chat_run',
				'permission'       => __NAMESPACE__ . '\\agents_chat_run_cancel_permission',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			AGENTS_QUEUE_CHAT_MESSAGE_ABILITY   => array(
				'label'            => 'Queue Chat Message',
				'description'      => 'Queue a user message for a conversation while another chat run is active.',
				'input_schema'     => agents_queue_chat_message_input_schema(),
				'output_schema'    => agents_queue_chat_message_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_queue_chat_message',
				'permission'       => __NAMESPACE__ . '\\agents_chat_run_enqueue_permission',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
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
function agents_get_chat_run( array $input ) {
	$context = WP_Agent_Chat_Run_Control::context_from_input( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}
	if ( $context['workspace'] instanceof WP_Agent_Workspace_Scope ) {
		$input['workspace'] = $context['workspace']->to_array();
	}

	$result = agents_chat_run_control_adapter()->get_run( $input );
	if ( null !== $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = agents_chat_run_control_normalize_result( $result, 'agents_chat_run_invalid_status' );
		return is_wp_error( $result ) ? $result : agents_chat_run_observer_payload( $result, $input );
	}

	try {
		$run = WP_Agent_Chat_Run_Control::get_run( agents_chat_run_control_string( $input['run_id'] ?? '' ), $context['workspace'], $context['owner'] );
	} catch ( \RuntimeException $error ) {
		return new \WP_Error( 'agents_chat_run_workspace_unsupported', $error->getMessage() );
	}
	$requested_session_id = agents_chat_run_control_string( $input['session_id'] ?? '' );
	if ( null !== $run && agents_chat_run_control_string( $run['session_id'] ?? '' ) !== $requested_session_id ) {
		return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested session_id and run_id.' );
	}
	if ( null !== $run ) {
		return agents_chat_run_observer_payload( $run, $input );
	}

	return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested run_id.' );
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_list_chat_run_events( array $input ) {
	$context = WP_Agent_Chat_Run_Control::context_from_input( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}
	if ( $context['workspace'] instanceof WP_Agent_Workspace_Scope ) {
		$input['workspace'] = $context['workspace']->to_array();
	}

	$result = agents_chat_run_control_adapter()->list_events( $input );
	if ( is_wp_error( $result ) && 'agents_chat_run_events_no_handler' === $result->get_error_code() ) {
		try {
			$result = WP_Agent_Chat_Run_Control::list_events(
				agents_chat_run_control_string( $input['run_id'] ?? '' ),
				agents_chat_run_control_string( $input['cursor'] ?? '' ),
				agents_chat_run_control_int( $input['limit'] ?? 100 ),
				$context['workspace'],
				$context['owner']
			);
		} catch ( \RuntimeException $error ) {
			return new \WP_Error( 'agents_chat_run_workspace_unsupported', $error->getMessage() );
		}
		if ( null === $result ) {
			return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested run_id.' );
		}
	}

	$result = agents_chat_run_events_normalize_result( $result );
	return is_wp_error( $result ) ? $result : agents_chat_run_observer_payload( $result, $input );
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_cancel_chat_run( array $input ) {
	$context = WP_Agent_Chat_Run_Control::context_from_input( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}
	if ( $context['workspace'] instanceof WP_Agent_Workspace_Scope ) {
		$input['workspace'] = $context['workspace']->to_array();
	}

	$result = agents_chat_run_control_adapter()->cancel_run( $input );
	if ( null !== $result ) {
		$result = is_wp_error( $result ) ? $result : agents_chat_run_control_normalize_result( $result, 'agents_chat_run_invalid_cancel_result' );
	} else {
		try {
			$run = WP_Agent_Chat_Run_Control::get_run( agents_chat_run_control_string( $input['run_id'] ?? '' ), $context['workspace'], $context['owner'] );
		} catch ( \RuntimeException $error ) {
			return new \WP_Error( 'agents_chat_run_workspace_unsupported', $error->getMessage() );
		}
		$requested_session_id = agents_chat_run_control_string( $input['session_id'] ?? '' );
		if ( null === $run ) {
			return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested run_id.' );
		}
		if ( agents_chat_run_control_string( $run['session_id'] ?? '' ) !== $requested_session_id ) {
			return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested session_id and run_id.' );
		}

		$result = WP_Agent_Chat_Run_Control::request_cancel( agents_chat_run_control_string( $input['run_id'] ?? '' ), $context['workspace'], $context['owner'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( null === $result ) {
			return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested run_id.' );
		}
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$status              = WP_Agent_Chat_Run_Control::normalize_status( $result['status'] ?? WP_Agent_Chat_Run_Control::STATUS_RUNNING );
	$result['status']    = $status;
	$result['cancelled'] = (bool) ( $result['cancelled'] ?? in_array(
		$status,
		array(
			WP_Agent_Chat_Run_Control::STATUS_CANCELLING,
			WP_Agent_Chat_Run_Control::STATUS_CANCELLED,
		),
		true
	) );

	return $result;
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_queue_chat_message( array $input ) {
	$context = WP_Agent_Chat_Run_Control::context_from_input( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}
	if ( $context['workspace'] instanceof WP_Agent_Workspace_Scope ) {
		$input['workspace'] = $context['workspace']->to_array();
	}
	try {
		if ( ! WP_Agent_Chat_Run_Control::can_queue_message( $input, $context['workspace'], $context['owner'], $context['conversation_store'] ) ) {
			return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested session owner.' );
		}
	} catch ( \RuntimeException $error ) {
		return new \WP_Error( 'agents_chat_run_workspace_unsupported', $error->getMessage() );
	}
	if ( is_array( $context['owner'] ) ) {
		$input['session_owner'] = $context['owner'];
	}

	$handler = apply_filters( 'wp_agent_chat_message_queue_handler', null, $input );
	if ( is_callable( $handler ) ) {
		$result = agents_chat_run_control_normalize_result( call_user_func( $handler, $input ), 'agents_chat_message_queue_invalid_result' );
	} else {
		try {
			$result = WP_Agent_Chat_Run_Control::queue_message( $input, $context['workspace'], $context['owner'], $context['conversation_store'] );
		} catch ( \InvalidArgumentException|\RuntimeException $error ) {
			return new \WP_Error( 'agents_chat_message_queue_invalid_result', $error->getMessage() );
		}
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( empty( $result['queued_message_id'] ) ) {
		return new \WP_Error( 'agents_chat_message_queue_invalid_result', 'Queued message results must include queued_message_id.' );
	}

	$result['queued_message_id'] = agents_chat_run_control_string( $result['queued_message_id'] );
	$result['position']          = max( 0, agents_chat_run_control_int( $result['position'] ?? 0 ) );

	return $result;
}

/**
 * @param array<string, mixed> $input Ability input.
 */
function agents_chat_run_read_permission( array $input ): bool {
	$agent = sanitize_title( agents_chat_run_control_string( $input['agent'] ?? '' ) );
	if ( '' !== $agent && class_exists( '\WP_Agent_Access' ) && class_exists( '\WP_Agent_Access_Grant' ) ) {
		$allowed = \WP_Agent_Access::can_current_principal_access_agent(
			$agent,
			\WP_Agent_Access_Grant::ROLE_VIEWER,
			agents_chat_run_control_request_scope( $input )
		);
	} else {
		$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'read' ) : false;
	}

	$allowed = (bool) apply_filters( 'agents_chat_run_read_permission', $allowed, $input );
	return (bool) apply_filters( 'agents_chat_run_control_permission', $allowed, $input );
}

/** @param array<string, mixed> $input Ability input. */
function agents_chat_run_enqueue_permission( array $input ): bool {
	$allowed = agents_chat_run_write_permission( $input );
	$context = WP_Agent_Chat_Run_Control::context_from_input( $input );
	if ( $allowed && ! is_wp_error( $context ) ) {
		try {
			$allowed = WP_Agent_Chat_Run_Control::can_queue_message( $input, $context['workspace'], $context['owner'], $context['conversation_store'] );
		} catch ( \RuntimeException $error ) {
			unset( $error );
			$allowed = false;
		}
	} else {
		$allowed = false;
	}
	$allowed = (bool) apply_filters( 'agents_chat_run_enqueue_permission', $allowed, $input );
	return (bool) apply_filters( 'agents_chat_run_control_permission', $allowed, $input );
}

/** @param array<string, mixed> $input Ability input. */
function agents_chat_run_cancel_permission( array $input ): bool {
	$allowed = agents_chat_run_write_permission( $input );
	$allowed = (bool) apply_filters( 'agents_chat_run_cancel_permission', $allowed, $input );
	return (bool) apply_filters( 'agents_chat_run_control_permission', $allowed, $input );
}

/** @param array<string, mixed> $input Ability input. */
function agents_chat_run_write_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;
	$agent   = sanitize_title( agents_chat_run_control_string( $input['agent'] ?? '' ) );
	if ( '' !== $agent && class_exists( '\WP_Agent_Access' ) && class_exists( '\WP_Agent_Access_Grant' ) ) {
		$allowed = $allowed || \WP_Agent_Access::can_current_principal_access_agent(
			$agent,
			\WP_Agent_Access_Grant::ROLE_OPERATOR,
			agents_chat_run_control_request_scope( $input )
		);
	}

	return $allowed || agents_chat_run_current_user_owns_session( $input );
}

/** @param array<string,mixed> $input Ability input. */
function agents_chat_run_unredacted_read_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;
	$agent   = sanitize_title( agents_chat_run_control_string( $input['agent'] ?? '' ) );
	if ( '' !== $agent && class_exists( '\WP_Agent_Access' ) && class_exists( '\WP_Agent_Access_Grant' ) ) {
		$allowed = $allowed || \WP_Agent_Access::can_current_principal_access_agent(
			$agent,
			\WP_Agent_Access_Grant::ROLE_OPERATOR,
			agents_chat_run_control_request_scope( $input )
		);
	}

	return (bool) apply_filters( 'agents_chat_run_unredacted_read_permission', $allowed, $input );
}

/**
 * @param array<string,mixed> $payload Run or event-page payload.
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function agents_chat_run_observer_payload( array $payload, array $input ): array {
	return agents_chat_run_unredacted_read_permission( $input ) ? $payload : WP_Agent_Run_Control::redacted_observer_payload( $payload );
}

/** @param array<string, mixed> $input Ability input. */
function agents_chat_run_current_user_owns_session( array $input ): bool {
	$owner = is_array( $input['session_owner'] ?? null ) ? agents_chat_run_control_string_keyed_array( $input['session_owner'] ) : array();
	if ( 'user' !== agents_chat_run_control_string( $owner['type'] ?? '' ) ) {
		return false;
	}

	$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	return 0 < $user_id && agents_chat_run_control_string( $owner['key'] ?? '' ) === (string) $user_id;
}

/**
 * Extract request-scope fields for run-control access checks.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed> Access request scope.
 */
function agents_chat_run_control_request_scope( array $input ): array {
	$scope = array();
	if ( is_array( $input['workspace'] ?? null ) ) {
		foreach ( array( 'workspace_id', 'workspace_type' ) as $key ) {
			if ( isset( $input['workspace'][ $key ] ) && is_scalar( $input['workspace'][ $key ] ) ) {
				$scope[ $key ] = (string) $input['workspace'][ $key ];
			}
		}
	}
	foreach ( array( 'workspace_id', 'workspace_type', 'request_context', 'client_id', 'audience_id' ) as $key ) {
		if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
			$scope[ $key ] = (string) $input[ $key ];
		}
	}

	return $scope;
}

/**
 * @param mixed  $result     Handler result.
 * @param string $error_code Error code for invalid results.
 * @return array<string, mixed>|\WP_Error
 */
function agents_chat_run_control_normalize_result( $result, string $error_code ) {
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result = WP_Agent_Run_Control::normalize_run_result( $result, $error_code );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	try {
		return WP_Agent_Chat_Run_Control::normalize_run( agents_chat_run_control_string_keyed_array( $result ) );
	} catch ( \InvalidArgumentException $error ) {
		return new \WP_Error( $error_code, $error->getMessage() );
	}
}

/**
 * @param mixed $result Handler result.
 * @return array<string, mixed>|\WP_Error
 */
function agents_chat_run_events_normalize_result( $result ) {
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result = WP_Agent_Run_Control::normalize_events_result( $result, 'agents_chat_run_invalid_events_result' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result               = agents_chat_run_control_string_keyed_array( $result );
	$result['run_id']     = agents_chat_run_control_string( $result['run_id'] ?? '' );
	$result['session_id'] = agents_chat_run_control_string( $result['session_id'] ?? '' );
	$result['status']     = WP_Agent_Chat_Run_Control::normalize_status( $result['status'] ?? WP_Agent_Chat_Run_Control::STATUS_RUNNING );
	$result['events']     = is_array( $result['events'] ?? null ) ? array_values( $result['events'] ) : array();
	$result['cursor']     = agents_chat_run_control_string( $result['cursor'] ?? '' );
	$result['has_more']   = (bool) ( $result['has_more'] ?? false );

	return $result;
}

function agents_chat_run_control_adapter(): WP_Agent_Filter_Run_Control_Adapter {
	static $adapter = null;
	if ( ! $adapter instanceof WP_Agent_Filter_Run_Control_Adapter ) {
		$adapter = new WP_Agent_Filter_Run_Control_Adapter(
			'wp_agent_chat_run_status_handler',
			'wp_agent_chat_run_events_handler',
			'wp_agent_chat_run_cancel_handler',
			'agents_chat_run_invalid_status',
			'agents_chat_run_invalid_events_result',
			'agents_chat_run_invalid_cancel_result',
			'agents_chat_run_events_no_handler',
			'No chat run events handler is registered.'
		);
	}

	return $adapter;
}

function agents_chat_run_control_string( mixed $value ): string {
	if ( is_scalar( $value ) ) {
		return (string) $value;
	}

	return '';
}

function agents_chat_run_control_int( mixed $value ): int {
	if ( is_int( $value ) ) {
		return $value;
	}

	if ( is_float( $value ) || is_string( $value ) ) {
		return (int) $value;
	}

	return 0;
}

/**
 * @param array<array-key, mixed> $value Input array.
 * @return array<string, mixed>
 */
function agents_chat_run_control_string_keyed_array( array $value ): array {
	$result = array();
	foreach ( $value as $key => $item ) {
		if ( is_string( $key ) ) {
			$result[ $key ] = $item;
		}
	}

	return $result;
}

function agents_chat_run_control_no_handler( string $code, string $message ): \WP_Error {
	return new \WP_Error( $code, $message );
}

/**
 * @return array<string, mixed>
 */
function agents_chat_run_id_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'session_id', 'run_id' ),
		'properties' => array(
			'workspace'     => agents_chat_workspace_schema(),
			'session_id'    => array( 'type' => 'string' ),
			'run_id'        => array( 'type' => 'string' ),
			'session_owner' => agents_chat_session_owner_schema(),
		),
	);
}

/**
 * @return array<string, mixed>
 */
function agents_chat_run_events_input_schema(): array {
	$schema     = agents_chat_run_id_input_schema();
	$properties = is_array( $schema['properties'] ?? null ) ? agents_chat_run_control_string_keyed_array( $schema['properties'] ) : array();

	$properties['cursor'] = array( 'type' => 'string' );
	$properties['limit']  = array(
		'type'    => 'integer',
		'minimum' => 1,
		'maximum' => 1000,
	);
	$schema['properties'] = $properties;

	return $schema;
}

/**
 * @return array<string, mixed>
 */
function agents_chat_run_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id', 'session_id', 'status' ),
		'properties' => array(
			'run_id'     => array( 'type' => 'string' ),
			'session_id' => array( 'type' => 'string' ),
			'status'     => array(
				'type' => 'string',
				'enum' => WP_Agent_Chat_Run_Control::statuses(),
			),
			'started_at' => array( 'type' => 'string' ),
			'updated_at' => array( 'type' => 'string' ),
			'metadata'   => array(
				'type'        => 'object',
				'description' => 'Opaque run metadata. External durable-run adapters should use orchestration.provider, orchestration.run_id, and orchestration.event_cursor for provider identity, provider run identity, and latest event cursor.',
			),
		),
	);
}

/**
 * @return array<string, mixed>
 */
function agents_chat_run_events_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id', 'session_id', 'status', 'events', 'cursor' ),
		'properties' => array(
			'run_id'     => array( 'type' => 'string' ),
			'session_id' => array( 'type' => 'string' ),
			'status'     => array(
				'type' => 'string',
				'enum' => WP_Agent_Chat_Run_Control::statuses(),
			),
			'events'     => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'required'   => array( 'id', 'type', 'created_at', 'metadata' ),
					'properties' => array(
						'id'         => array( 'type' => 'string' ),
						'type'       => array( 'type' => 'string' ),
						'message'    => array( 'type' => 'string' ),
						'created_at' => array( 'type' => 'string' ),
						'metadata'   => array( 'type' => 'object' ),
					),
				),
			),
			'cursor'     => array(
				'type'        => 'string',
				'description' => 'Opaque cursor for the next events page. External durable-run adapters should also mirror the latest durable cursor in metadata.orchestration.event_cursor when useful for status polling.',
			),
			'has_more'   => array( 'type' => 'boolean' ),
			'metadata'   => array(
				'type'        => 'object',
				'description' => 'Opaque event-page metadata using the same orchestration.provider, orchestration.run_id, and orchestration.event_cursor convention as run status payloads.',
			),
		),
	);
}

/**
 * @return array<string, mixed>
 */
function agents_cancel_chat_run_output_schema(): array {
	$schema     = agents_chat_run_output_schema();
	$required   = is_array( $schema['required'] ?? null ) ? array_values( $schema['required'] ) : array();
	$properties = is_array( $schema['properties'] ?? null ) ? agents_chat_run_control_string_keyed_array( $schema['properties'] ) : array();

	$required[]              = 'cancelled';
	$properties['cancelled'] = array( 'type' => 'boolean' );
	$schema['required']      = $required;
	$schema['properties']    = $properties;

	return $schema;
}

/**
 * @return array<string, mixed>
 */
function agents_queue_chat_message_input_schema(): array {
	$schema             = agents_chat_input_schema();
	$required           = is_array( $schema['required'] ?? null ) ? array_values( $schema['required'] ) : array();
	$required[]         = 'session_id';
	$schema['required'] = $required;

	return $schema;
}

/**
 * @return array<string, mixed>
 */
function agents_queue_chat_message_output_schema(): array {
	$schema     = agents_chat_run_output_schema();
	$required   = is_array( $schema['required'] ?? null ) ? array_values( $schema['required'] ) : array();
	$properties = is_array( $schema['properties'] ?? null ) ? agents_chat_run_control_string_keyed_array( $schema['properties'] ) : array();

	$required[]                      = 'queued_message_id';
	$properties['queued_message_id'] = array( 'type' => 'string' );
	$properties['position']          = array( 'type' => 'integer' );
	$schema['required']              = $required;
	$schema['properties']            = $properties;

	return $schema;
}
