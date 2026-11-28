<?php
/**
 * Canonical pending-action ability registrations.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

const AGENTS_LIST_PENDING_ACTIONS_ABILITY    = 'agents/list-pending-actions';
const AGENTS_SUMMARY_PENDING_ACTIONS_ABILITY = 'agents/summary-pending-actions';
const AGENTS_GET_PENDING_ACTION_ABILITY      = 'agents/get-pending-action';
const AGENTS_RESOLVE_PENDING_ACTION_ABILITY  = 'agents/resolve-pending-action';

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
			AGENTS_LIST_PENDING_ACTIONS_ABILITY    => array(
				'label'            => 'List Pending Actions',
				'description'      => 'List pending action records from the host-provided pending action store.',
				'input_schema'     => agents_pending_action_filters_input_schema(),
				'output_schema'    => agents_list_pending_actions_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_list_pending_actions',
				'idempotent'       => true,
			),
			AGENTS_SUMMARY_PENDING_ACTIONS_ABILITY => array(
				'label'            => 'Summarize Pending Actions',
				'description'      => 'Summarize pending action records from the host-provided pending action store.',
				'input_schema'     => agents_pending_action_filters_input_schema(),
				'output_schema'    => array( 'type' => 'object' ),
				'execute_callback' => __NAMESPACE__ . '\\agents_summary_pending_actions',
				'idempotent'       => true,
			),
			AGENTS_GET_PENDING_ACTION_ABILITY      => array(
				'label'            => 'Get Pending Action',
				'description'      => 'Fetch one pending action record from the host-provided pending action store.',
				'input_schema'     => agents_get_pending_action_input_schema(),
				'output_schema'    => agents_get_pending_action_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_get_pending_action',
				'idempotent'       => true,
			),
			AGENTS_RESOLVE_PENDING_ACTION_ABILITY  => array(
				'label'            => 'Resolve Pending Action',
				'description'      => 'Accept or reject a pending action through the host-provided pending action resolver.',
				'input_schema'     => agents_resolve_pending_action_input_schema(),
				'output_schema'    => agents_resolve_pending_action_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_resolve_pending_action',
				'idempotent'       => false,
			),
		);

		foreach ( $abilities as $name => $ability ) {
			if ( wp_has_ability( $name ) ) {
				continue;
			}

			wp_register_ability(
				$name,
				array(
					'label'               => $ability['label'],
					'description'         => $ability['description'],
					'category'            => 'agents-api',
					'input_schema'        => $ability['input_schema'],
					'output_schema'       => $ability['output_schema'],
					'execute_callback'    => $ability['execute_callback'],
					'permission_callback' => __NAMESPACE__ . '\\agents_pending_action_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'destructive' => ! $ability['idempotent'],
							'idempotent'  => $ability['idempotent'],
						),
					),
				)
			);
		}
	}
);

/**
 * Discover the host-provided pending action store.
 *
 * @param array<string,mixed> $input Ability input.
 * @return WP_Agent_Pending_Action_Store|null
 */
function agents_get_pending_action_store( array $input = array() ): ?WP_Agent_Pending_Action_Store {
	$store = apply_filters( 'wp_agent_pending_action_store', null, $input );

	return $store instanceof WP_Agent_Pending_Action_Store ? $store : null;
}

/**
 * Discover the host-provided pending action resolver.
 *
 * @param array<string,mixed> $input Ability input.
 * @return WP_Agent_Pending_Action_Resolver|null
 */
function agents_get_pending_action_resolver( array $input = array() ): ?WP_Agent_Pending_Action_Resolver {
	$resolver = apply_filters( 'wp_agent_pending_action_resolver', null, $input );

	return $resolver instanceof WP_Agent_Pending_Action_Resolver ? $resolver : null;
}

/**
 * List pending actions through the discovered store.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_list_pending_actions( array $input ) {
	$store = agents_get_pending_action_store( $input );
	if ( null === $store ) {
		return agents_pending_action_no_store_error();
	}

	$actions = array();
	foreach ( $store->list( agents_pending_action_filters( $input ) ) as $action ) {
		$actions[] = $action->to_array();
	}

	return array( 'actions' => $actions );
}

/**
 * Summarize pending actions through the discovered store.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_summary_pending_actions( array $input ) {
	$store = agents_get_pending_action_store( $input );
	if ( null === $store ) {
		return agents_pending_action_no_store_error();
	}

	return $store->summary( agents_pending_action_filters( $input ) );
}

/**
 * Get one pending action through the discovered store.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_get_pending_action( array $input ) {
	$action_id = agents_pending_action_string_input( $input, 'action_id' );
	if ( '' === $action_id ) {
		return new \WP_Error( 'agents_pending_action_missing_action_id', 'action_id is required.' );
	}

	$store = agents_get_pending_action_store( $input );
	if ( null === $store ) {
		return agents_pending_action_no_store_error();
	}

	$action = $store->get( $action_id, (bool) ( $input['include_resolved'] ?? false ) );

	return array( 'action' => null === $action ? null : $action->to_array() );
}

/**
 * Resolve one pending action through the discovered resolver.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_resolve_pending_action( array $input ) {
	$action_id   = agents_pending_action_string_input( $input, 'action_id' );
	$resolver_id = agents_pending_action_string_input( $input, 'resolver' );
	if ( '' === $action_id ) {
		return new \WP_Error( 'agents_pending_action_missing_action_id', 'action_id is required.' );
	}
	if ( '' === $resolver_id ) {
		return new \WP_Error( 'agents_pending_action_missing_resolver', 'resolver is required.' );
	}

	try {
		$decision = WP_Agent_Approval_Decision::from_string( agents_pending_action_string_input( $input, 'decision' ) );
	} catch ( \InvalidArgumentException $error ) {
		return new \WP_Error( 'agents_pending_action_invalid_decision', $error->getMessage() );
	}

	$resolver = agents_get_pending_action_resolver( $input );
	if ( null === $resolver ) {
		return new \WP_Error(
			'agents_pending_action_no_resolver',
			'No pending action resolver is registered. Add a WP_Agent_Pending_Action_Resolver to the wp_agent_pending_action_resolver filter.'
		);
	}

	$result = $resolver->resolve_pending_action(
		$action_id,
		$decision,
		$resolver_id,
		agents_pending_action_array_input( $input, 'payload' ),
		agents_pending_action_array_input( $input, 'context' )
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'action_id' => $action_id,
		'decision'  => $decision->value(),
		'result'    => $result,
	);
}

/**
 * Permission gate for pending action abilities.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function agents_pending_action_permission( array $input ): bool {
	return (bool) apply_filters(
		'agents_pending_action_permission',
		current_user_can( 'manage_options' ),
		$input
	);
}

/**
 * Extract store filters from ability input.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function agents_pending_action_filters( array $input ): array {
	return agents_pending_action_array_input( $input, 'filters' );
}

/**
 * Read a trimmed scalar string from ability input.
 *
 * @param array<string,mixed> $input Ability input.
 */
function agents_pending_action_string_input( array $input, string $key ): string {
	$value = $input[ $key ] ?? '';

	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

/**
 * Read a string-keyed array from ability input.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function agents_pending_action_array_input( array $input, string $key ): array {
	$value = $input[ $key ] ?? array();
	if ( ! is_array( $value ) ) {
		return array();
	}

	$result = array();
	foreach ( $value as $item_key => $item ) {
		if ( is_string( $item_key ) ) {
			$result[ $item_key ] = $item;
		}
	}

	return $result;
}

/**
 * Standard no-store error.
 *
 * @return \WP_Error
 */
function agents_pending_action_no_store_error(): \WP_Error {
	return new \WP_Error(
		'agents_pending_action_no_store',
		'No pending action store is registered. Add a WP_Agent_Pending_Action_Store to the wp_agent_pending_action_store filter.'
	);
}

/** @return array<string,mixed> * @return array<string, mixed>
 */
function agents_pending_action_filters_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'filters' => array(
				'type'        => 'object',
				'description' => 'Implementation-defined pending action store filters such as status, kind, workspace, agent, creator, limit, or offset.',
				'default'     => array(),
			),
		),
	);
}

/** @return array<string,mixed> * @return array<string, mixed>
 */
function agents_get_pending_action_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'action_id' ),
		'properties' => array(
			'action_id'        => array( 'type' => 'string' ),
			'include_resolved' => array(
				'type'        => 'boolean',
				'description' => 'Whether terminal audit records may be returned.',
				'default'     => false,
			),
		),
	);
}

/** @return array<string,mixed> * @return array<string, mixed>
 */
function agents_resolve_pending_action_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'action_id', 'decision', 'resolver' ),
		'properties' => array(
			'action_id' => array( 'type' => 'string' ),
			'decision'  => array(
				'type' => 'string',
				'enum' => array( WP_Agent_Approval_Decision::ACCEPTED, WP_Agent_Approval_Decision::REJECTED ),
			),
			'resolver'  => array(
				'type'        => 'string',
				'description' => 'Resolver identifier, such as a user, token, or service actor.',
			),
			'payload'   => array(
				'type'        => 'object',
				'description' => 'Decision payload forwarded to the resolver.',
				'default'     => array(),
			),
			'context'   => array(
				'type'        => 'object',
				'description' => 'Caller context forwarded to the resolver.',
				'default'     => array(),
			),
		),
	);
}

/** @return array<string,mixed> * @return array<string, mixed>
 */
function agents_list_pending_actions_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'actions' ),
		'properties' => array(
			'actions' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
		),
	);
}

/** @return array<string,mixed> * @return array<string, mixed>
 */
function agents_get_pending_action_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'action' ),
		'properties' => array(
			'action' => array( 'type' => array( 'object', 'null' ) ),
		),
	);
}

/** @return array<string,mixed> * @return array<string, mixed>
 */
function agents_resolve_pending_action_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'action_id', 'decision', 'result' ),
		'properties' => array(
			'action_id' => array( 'type' => 'string' ),
			'decision'  => array( 'type' => 'string' ),
			'result'    => array(),
		),
	);
}
