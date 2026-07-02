<?php
/**
 * Canonical runtime package execution ability.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/interface-wp-agent-run-control-store.php';
require_once __DIR__ . '/class-wp-agent-option-run-control-store.php';
require_once __DIR__ . '/class-wp-agent-run-control.php';

const AGENTS_RUN_RUNTIME_PACKAGE_ABILITY             = 'agents/run-runtime-package';
const AGENTS_GET_RUNTIME_PACKAGE_RUN_ABILITY         = 'agents/get-runtime-package-run';
const AGENTS_CANCEL_RUNTIME_PACKAGE_RUN_ABILITY      = 'agents/cancel-runtime-package-run';
const AGENTS_LIST_RUNTIME_PACKAGE_RUN_EVENTS_ABILITY = 'agents/list-runtime-package-run-events';
const AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE       = 'agents_api_runtime_package_run_control';

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\agents_register_runtime_package_ability_category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\agents_register_runtime_package_run_abilities' );

if ( function_exists( 'did_action' ) && did_action( 'wp_abilities_api_init' ) ) {
	agents_register_runtime_package_run_abilities();
}

/**
 * Register the Agents API runtime package ability category.
 */
function agents_register_runtime_package_ability_category(): void {
	if ( ! function_exists( 'wp_has_ability_category' ) || ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( wp_has_ability_category( 'agents-api' ) ) {
		return;
	}

	/** @var array<string,mixed> $args */
	$args = array(
		'label'       => 'Agents API',
		'description' => 'Cross-cutting abilities provided by the Agents API substrate.',
	);

	if ( doing_action( 'wp_abilities_api_categories_init' ) ) {
		wp_register_ability_category( 'agents-api', $args );
		return;
	}

	if ( ! did_action( 'init' ) || ! class_exists( '\WP_Ability_Categories_Registry' ) ) {
		return;
	}

	$registry = \WP_Ability_Categories_Registry::get_instance();
	if ( null === $registry ) {
		return;
	}

	$registry->register( 'agents-api', $args );
}

/**
 * Register canonical runtime package execution abilities.
 */
function agents_register_runtime_package_run_abilities(): void {
	if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	agents_register_runtime_package_ability_category();

	$abilities = array(
			AGENTS_RUN_RUNTIME_PACKAGE_ABILITY             => array(
				'label'            => 'Run Runtime Package',
				'description'      => 'Canonical entry point for running a portable agent package workflow. Dispatches to a consumer-provided runtime handler.',
				'input_schema'     => agents_runtime_package_run_input_schema(),
				'output_schema'    => agents_runtime_package_run_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_runtime_package_run_dispatch',
				'permission'       => __NAMESPACE__ . '\\agents_runtime_package_run_permission',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			AGENTS_GET_RUNTIME_PACKAGE_RUN_ABILITY         => array(
				'label'            => 'Get Runtime Package Run',
				'description'      => 'Read the canonical status envelope for an addressable runtime package run.',
				'input_schema'     => agents_runtime_package_run_id_input_schema(),
				'output_schema'    => agents_runtime_package_run_control_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_get_runtime_package_run',
				'permission'       => __NAMESPACE__ . '\\agents_runtime_package_run_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_CANCEL_RUNTIME_PACKAGE_RUN_ABILITY      => array(
				'label'            => 'Cancel Runtime Package Run',
				'description'      => 'Request best-effort cancellation for an addressable runtime package run.',
				'input_schema'     => agents_runtime_package_run_id_input_schema(),
				'output_schema'    => agents_runtime_package_run_control_output_schema( true ),
				'execute_callback' => __NAMESPACE__ . '\\agents_cancel_runtime_package_run',
				'permission'       => __NAMESPACE__ . '\\agents_runtime_package_run_cancel_permission',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			AGENTS_LIST_RUNTIME_PACKAGE_RUN_EVENTS_ABILITY => array(
				'label'            => 'List Runtime Package Run Events',
				'description'      => 'List canonical lifecycle events for an addressable runtime package run.',
				'input_schema'     => agents_runtime_package_run_events_input_schema(),
				'output_schema'    => agents_runtime_package_run_events_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_list_runtime_package_run_events',
				'permission'       => __NAMESPACE__ . '\\agents_runtime_package_run_read_permission',
				'annotations'      => array( 'idempotent' => true ),
			),
		);

	foreach ( $abilities as $ability => $args ) {
		if ( wp_has_ability( $ability ) ) {
			continue;
		}

		agents_register_runtime_package_ability(
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

/**
 * Register a runtime package ability across normal and late-loaded runtimes.
 *
 * @param string              $ability Ability name.
 * @param array<string,mixed> $args Ability args.
 */
function agents_register_runtime_package_ability( string $ability, array $args ): void {
	if ( wp_has_ability( $ability ) ) {
		return;
	}

	if ( doing_action( 'wp_abilities_api_init' ) ) {
		wp_register_ability( $ability, $args );
		return;
	}

	if ( ! did_action( 'init' ) || ! class_exists( '\WP_Abilities_Registry' ) ) {
		return;
	}

	$registry = \WP_Abilities_Registry::get_instance();
	$registry->register( $ability, $args );
}

/**
 * Dispatch a runtime package workflow run to a registered consumer handler.
 *
 * @param array<mixed> $input Canonical input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_runtime_package_run_dispatch( array $input ) {
	$run_id            = agents_runtime_package_run_string( $input['run_id'] ?? '' );
	$run_id            = '' !== $run_id ? $run_id : WP_Agent_Run_Control::generate_run_id( 'runtime_run_' );
	$input['run_id']   = $run_id;
	$options           = is_array( $input['options'] ?? null ) ? agents_runtime_package_run_string_keyed_array( $input['options'] ) : array();
	$options['run_id'] = $run_id;
	$input['options']  = $options;

	$request = WP_Agent_Runtime_Package_Run_Request::from_array( $input );
	if ( is_wp_error( $request ) ) {
		do_action( 'agents_runtime_package_run_dispatch_failed', $request->get_error_code(), $input );
		return $request;
	}

	WP_Agent_Run_Control::start_run(
		AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE,
		$run_id,
		array(
			'metadata' => array(
				'package'  => $request->get_package(),
				'workflow' => $request->get_workflow(),
			),
		)
	);

	/**
	 * Filters the runtime package execution handler.
	 *
	 * Handlers receive the value object and raw input and must return a canonical
	 * result array, WP_Agent_Runtime_Package_Run_Result, or WP_Error.
	 *
	 * @param callable|null $handler Current handler, or null.
	 * @param WP_Agent_Runtime_Package_Run_Request $request Normalized request.
	 * @param array<mixed> $input Raw ability input.
	 */
	$handler = apply_filters( 'wp_agent_runtime_package_run_handler', null, $request, $input );
	if ( ! is_callable( $handler ) ) {
		WP_Agent_Run_Control::finish_run( AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE, $run_id, WP_Agent_Run_Control::STATUS_FAILED );
		do_action( 'agents_runtime_package_run_dispatch_failed', 'no_handler', $input );
		return new \WP_Error(
			'agents_runtime_package_run_no_handler',
			'No agents/run-runtime-package handler is registered. Install a consumer runtime or add a callable to the wp_agent_runtime_package_run_handler filter.'
		);
	}

	$result = call_user_func( $handler, $request, $input );
	if ( is_wp_error( $result ) ) {
		WP_Agent_Run_Control::finish_run( AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE, $run_id, WP_Agent_Run_Control::STATUS_FAILED );
		do_action( 'agents_runtime_package_run_dispatch_failed', $result->get_error_code(), $input );
		return $result;
	}

	if ( $result instanceof WP_Agent_Runtime_Package_Run_Result ) {
		$result = $result->to_array();
	} elseif ( ! is_array( $result ) ) {
		WP_Agent_Run_Control::finish_run( AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE, $run_id, WP_Agent_Run_Control::STATUS_FAILED );
		do_action( 'agents_runtime_package_run_dispatch_failed', 'invalid_result', $input );
		return new \WP_Error(
			'agents_runtime_package_run_invalid_result',
			'agents/run-runtime-package handlers must return an array, WP_Agent_Runtime_Package_Run_Result, or WP_Error.'
		);
	}

	$result           = agents_runtime_package_run_string_keyed_array( $result );
	$result['run_id'] = $run_id;
	$normalized       = WP_Agent_Runtime_Package_Run_Result::from_array( $result )->to_array();
	$status           = WP_Agent_Run_Control::normalize_status( $normalized['status'] ?? WP_Agent_Run_Control::STATUS_SUCCEEDED );
	WP_Agent_Run_Control::save_run(
		AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE,
		array(
			'run_id'     => $run_id,
			'status'     => $status,
			'metadata'   => array(
				'package'  => $request->get_package(),
				'workflow' => $request->get_workflow(),
			),
			'started_at' => WP_Agent_Run_Control::now(),
		)
	);

	return $normalized;
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_get_runtime_package_run( array $input ) {
	$handler = apply_filters( 'wp_agent_runtime_package_run_status_handler', null, $input );
	if ( is_callable( $handler ) ) {
		$result = WP_Agent_Run_Control::normalize_run_result( call_user_func( $handler, $input ), 'agents_runtime_package_run_invalid_status' );
		return is_wp_error( $result ) ? $result : agents_runtime_package_run_observer_payload( $result, $input );
	}

	$run = WP_Agent_Run_Control::get_run( AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE, agents_runtime_package_run_string( $input['run_id'] ?? '' ) );
	if ( null === $run ) {
		return new \WP_Error( 'agents_runtime_package_run_not_found', 'No runtime package run was found for the requested run_id.' );
	}

	return agents_runtime_package_run_observer_payload( $run, $input );
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_cancel_runtime_package_run( array $input ) {
	$handler = apply_filters( 'wp_agent_runtime_package_run_cancel_handler', null, $input );
	if ( is_callable( $handler ) ) {
		$result = WP_Agent_Run_Control::normalize_cancel_result( call_user_func( $handler, $input ), 'agents_runtime_package_run_invalid_cancel_result' );
	} else {
		$result = WP_Agent_Run_Control::request_cancel( AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE, agents_runtime_package_run_string( $input['run_id'] ?? '' ) );
		if ( null === $result ) {
			return new \WP_Error( 'agents_runtime_package_run_not_found', 'No runtime package run was found for the requested run_id.' );
		}
		$result = WP_Agent_Run_Control::normalize_cancel_result( $result, 'agents_runtime_package_run_invalid_cancel_result' );
	}

	return $result;
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_list_runtime_package_run_events( array $input ) {
	$handler = apply_filters( 'wp_agent_runtime_package_run_events_handler', null, $input );
	if ( is_callable( $handler ) ) {
		$result = WP_Agent_Run_Control::normalize_events_result( call_user_func( $handler, $input ), 'agents_runtime_package_run_invalid_events_result' );
		return is_wp_error( $result ) ? $result : agents_runtime_package_run_observer_payload( $result, $input );
	}

	$result = WP_Agent_Run_Control::list_events(
		AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE,
		agents_runtime_package_run_string( $input['run_id'] ?? '' ),
		agents_runtime_package_run_string( $input['cursor'] ?? '' ),
		'' !== agents_runtime_package_run_string( $input['limit'] ?? '' ) ? (int) agents_runtime_package_run_string( $input['limit'] ?? '' ) : 100
	);
	if ( null === $result ) {
		return new \WP_Error( 'agents_runtime_package_run_not_found', 'No runtime package run was found for the requested run_id.' );
	}

	return agents_runtime_package_run_observer_payload( $result, $input );
}

/**
 * Permission gate for runtime package execution.
 *
 * @param array<mixed> $input Canonical input.
 */
function agents_runtime_package_run_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;

	/**
	 * Filters permission for agents/run-runtime-package.
	 *
	 * @param bool $allowed Default permission result.
	 * @param array<mixed> $input Canonical input.
	 */
	return (bool) apply_filters( 'agents_runtime_package_run_permission', $allowed, $input );
}

/** @param array<string,mixed> $input Ability input. */
function agents_runtime_package_run_read_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;
	return (bool) apply_filters( 'agents_runtime_package_run_read_permission', $allowed, $input );
}

/** @param array<string,mixed> $input Ability input. */
function agents_runtime_package_run_unredacted_read_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;
	return (bool) apply_filters( 'agents_runtime_package_run_unredacted_read_permission', $allowed, $input );
}

/**
 * @param array<string,mixed> $payload Run or event-page payload.
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function agents_runtime_package_run_observer_payload( array $payload, array $input ): array {
	return agents_runtime_package_run_unredacted_read_permission( $input ) ? $payload : WP_Agent_Run_Control::redacted_observer_payload( $payload );
}

/** @param array<string,mixed> $input Ability input. */
function agents_runtime_package_run_cancel_permission( array $input ): bool {
	return agents_runtime_package_run_permission( $input );
}

/** @return array<string,mixed> */
function agents_runtime_package_run_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'package', 'workflow' ),
		'properties' => array(
			'package'  => array(
				'type'        => 'object',
				'description' => 'Portable package descriptor. Use source for a path/URI or slug/id for a runtime-resolved package.',
			),
			'workflow' => array(
				'type'        => 'object',
				'description' => 'Workflow selector or inline spec. Provide id or spec.',
			),
			'input'    => array( 'type' => 'object' ),
			'options'  => array( 'type' => 'object' ),
			'metadata' => array( 'type' => 'object' ),
			'replay'   => array( 'type' => 'object' ),
			'run_id'   => array( 'type' => array( 'string', 'null' ) ),
		),
	);
}

/** @return array<string,mixed> */
function agents_runtime_package_run_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'status', 'result', 'evidence_refs' ),
		'properties' => array(
			'status'        => array(
				'type' => 'string',
				'enum' => WP_Agent_Runtime_Package_Run_Result::statuses(),
			),
			'run_id'        => array( 'type' => 'string' ),
			'result'        => array( 'type' => 'object' ),
			'error'         => array( 'type' => 'object' ),
			'evidence_refs' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'metadata'      => array( 'type' => 'object' ),
			'replay'        => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_runtime_package_run_id_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id' ),
		'properties' => array(
			'run_id' => array( 'type' => 'string' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_runtime_package_run_events_input_schema(): array {
	$schema               = agents_runtime_package_run_id_input_schema();
	$properties           = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
	$properties['cursor'] = array( 'type' => 'string' );
	$properties['limit']  = array( 'type' => 'integer' );
	$schema['properties'] = $properties;
	return $schema;
}

/** @return array<string,mixed> */
function agents_runtime_package_run_control_output_schema( bool $include_cancelled = false ): array {
	$properties = array(
		'run_id'     => array( 'type' => 'string' ),
		'status'     => array(
			'type' => 'string',
			'enum' => WP_Agent_Run_Control::statuses(),
		),
		'started_at' => array( 'type' => 'string' ),
		'updated_at' => array( 'type' => 'string' ),
		'metadata'   => array( 'type' => 'object' ),
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
function agents_runtime_package_run_events_output_schema(): array {
	$schema                 = agents_runtime_package_run_control_output_schema();
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

function agents_runtime_package_run_string( mixed $value ): string {
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

/**
 * @param array<array-key,mixed> $data Raw array.
 * @return array<string,mixed>
 */
function agents_runtime_package_run_string_keyed_array( array $data ): array {
	$result = array();
	foreach ( $data as $key => $value ) {
		if ( is_string( $key ) ) {
			$result[ $key ] = $value;
		}
	}
	return $result;
}
