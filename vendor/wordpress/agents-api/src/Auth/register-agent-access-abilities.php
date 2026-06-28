<?php
/**
 * Agent access ability registrations.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Auth;

defined( 'ABSPATH' ) || exit;

const AGENTS_CAN_ACCESS_AGENT_ABILITY       = 'agents/can-access-agent';
const AGENTS_LIST_ACCESSIBLE_AGENTS_ABILITY = 'agents/list-accessible-agents';

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
		if ( ! wp_has_ability( AGENTS_CAN_ACCESS_AGENT_ABILITY ) ) {
			wp_register_ability(
				AGENTS_CAN_ACCESS_AGENT_ABILITY,
				array(
					'label'               => 'Can Access Agent',
					'description'         => 'Check whether the current request principal can access a registered agent at the requested role.',
					'category'            => 'agents-api',
					'input_schema'        => agents_can_access_agent_input_schema(),
					'output_schema'       => agents_can_access_agent_output_schema(),
					'execute_callback'    => __NAMESPACE__ . '\\agents_can_access_agent',
					'permission_callback' => __NAMESPACE__ . '\\agents_access_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'idempotent' => true ),
					),
				)
			);
		}

		if ( ! wp_has_ability( AGENTS_LIST_ACCESSIBLE_AGENTS_ABILITY ) ) {
			wp_register_ability(
				AGENTS_LIST_ACCESSIBLE_AGENTS_ABILITY,
				array(
					'label'               => 'List Accessible Agents',
					'description'         => 'List registered agents accessible to the current request principal.',
					'category'            => 'agents-api',
					'input_schema'        => agents_list_accessible_agents_input_schema(),
					'output_schema'       => agents_list_accessible_agents_output_schema(),
					'execute_callback'    => __NAMESPACE__ . '\\agents_list_accessible_agents',
					'permission_callback' => __NAMESPACE__ . '\\agents_access_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'idempotent' => true ),
					),
				)
			);
		}
	}
);

/**
 * Check current-principal access to an agent.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function agents_can_access_agent( array $input ): array {
	$agent_id      = sanitize_title( agents_access_string_input( $input, 'agent' ) );
	$minimum_role  = agents_access_string_input( $input, 'minimum_role', \WP_Agent_Access_Grant::ROLE_VIEWER );
	$request_scope = agents_access_request_scope( $input );

	$allowed = '' !== $agent_id && \WP_Agent_Access::can_current_principal_access_agent( $agent_id, $minimum_role, $request_scope );

	return array(
		'allowed'      => $allowed,
		'agent'        => $agent_id,
		'minimum_role' => $minimum_role,
	);
}

/**
 * List current-principal accessible agents.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function agents_list_accessible_agents( array $input ): array {
	$minimum_role = agents_access_string_input( $input, 'minimum_role', \WP_Agent_Access_Grant::ROLE_VIEWER );
	$agents       = \WP_Agent_Access::list_accessible_agents_for_current_principal( $minimum_role, agents_access_request_scope( $input ) );

	return array( 'agents' => $agents );
}

/**
 * Shared permission gate for access read abilities.
 *
 * @param array<string,mixed> $input Ability input.
 */
function agents_access_permission( array $input ): bool {
	$allowed = \WP_Agent_Access::get_current_principal( agents_access_request_scope( $input ) ) instanceof \AgentsAPI\AI\WP_Agent_Execution_Principal;

	return (bool) apply_filters( 'agents_access_permission', $allowed, $input );
}

/**
 * Extract request scope fields forwarded to access helpers.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function agents_access_request_scope( array $input ): array {
	$scope = array(
		'request_context' => \AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
	);

	if ( array_key_exists( 'workspace_id', $input ) ) {
		$scope['workspace_id'] = agents_access_nullable_string_input( $input, 'workspace_id' );
	}

	if ( array_key_exists( 'client_id', $input ) ) {
		$scope['client_id'] = agents_access_nullable_string_input( $input, 'client_id' );
	}

	return $scope;
}

/**
 * Read a scalar string from ability input.
 *
 * @param array<string,mixed> $input Ability input.
 */
function agents_access_string_input( array $input, string $key, string $fallback = '' ): string {
	$value = $input[ $key ] ?? null;

	return is_scalar( $value ) ? (string) $value : $fallback;
}

/**
 * Read a nullable scalar string from ability input.
 *
 * @param array<string,mixed> $input Ability input.
 */
function agents_access_nullable_string_input( array $input, string $key ): ?string {
	if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
		return null;
	}

	return agents_access_string_input( $input, $key );
}

/**
 * Input schema for `agents/can-access-agent`.
 * @return array<string, mixed>
 */
function agents_can_access_agent_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'agent' ),
		'properties' => array(
			'agent'        => array(
				'type'        => 'string',
				'description' => 'Registered agent slug/id to check.',
			),
			'minimum_role' => agents_access_role_schema(),
			'workspace_id' => array( 'type' => array( 'string', 'null' ) ),
			'client_id'    => array( 'type' => array( 'string', 'null' ) ),
		),
	);
}

/**
 * Output schema for `agents/can-access-agent`.
 * @return array<string, mixed>
 */
function agents_can_access_agent_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'allowed', 'agent', 'minimum_role' ),
		'properties' => array(
			'allowed'      => array( 'type' => 'boolean' ),
			'agent'        => array( 'type' => 'string' ),
			'minimum_role' => agents_access_role_schema(),
		),
	);
}

/**
 * Input schema for `agents/list-accessible-agents`.
 * @return array<string, mixed>
 */
function agents_list_accessible_agents_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'minimum_role' => agents_access_role_schema(),
			'workspace_id' => array( 'type' => array( 'string', 'null' ) ),
			'client_id'    => array( 'type' => array( 'string', 'null' ) ),
		),
	);
}

/**
 * Output schema for `agents/list-accessible-agents`.
 * @return array<string, mixed>
 */
function agents_list_accessible_agents_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'agents' ),
		'properties' => array(
			'agents' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'required'   => array( 'slug', 'label' ),
					'properties' => array(
						'slug'        => array( 'type' => 'string' ),
						'label'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'meta'        => array( 'type' => 'object' ),
					),
				),
			),
		),
	);
}

/**
 * JSON schema fragment for access roles.
 * @return array<string, mixed>
 */
function agents_access_role_schema(): array {
	return array(
		'type'        => 'string',
		'enum'        => \WP_Agent_Access_Grant::roles(),
		'default'     => \WP_Agent_Access_Grant::ROLE_VIEWER,
		'description' => 'Minimum access role required for the check.',
	);
}
