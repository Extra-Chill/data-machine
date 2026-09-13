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
const AGENTS_GRANT_AGENT_ACCESS_ABILITY     = 'agents/grant-agent-access';
const AGENTS_REVOKE_AGENT_ACCESS_ABILITY    = 'agents/revoke-agent-access';
const AGENTS_LIST_AGENT_USERS_ABILITY       = 'agents/list-agent-users';

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

		if ( ! wp_has_ability( AGENTS_GRANT_AGENT_ACCESS_ABILITY ) ) {
			wp_register_ability(
				AGENTS_GRANT_AGENT_ACCESS_ABILITY,
				array(
					'label'               => 'Grant Agent Access',
					'description'         => 'Grant a WordPress user access to a registered agent at a role (viewer, operator, or admin). Requires the current principal to hold an admin role grant on the target agent.',
					'category'            => 'agents-api',
					'input_schema'        => agents_grant_agent_access_input_schema(),
					'output_schema'       => agents_grant_agent_access_output_schema(),
					'execute_callback'    => __NAMESPACE__ . '\\agents_grant_agent_access',
					'permission_callback' => __NAMESPACE__ . '\\agents_access_admin_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'idempotent' => true ),
					),
				)
			);
		}

		if ( ! wp_has_ability( AGENTS_REVOKE_AGENT_ACCESS_ABILITY ) ) {
			wp_register_ability(
				AGENTS_REVOKE_AGENT_ACCESS_ABILITY,
				array(
					'label'               => 'Revoke Agent Access',
					'description'         => "Revoke a WordPress user's access grant for a registered agent. Requires the current principal to hold an admin role grant on the target agent. Refuses to revoke the last remaining admin grant on the agent so it never loses all of its administrators.",
					'category'            => 'agents-api',
					'input_schema'        => agents_revoke_agent_access_input_schema(),
					'output_schema'       => agents_revoke_agent_access_output_schema(),
					'execute_callback'    => __NAMESPACE__ . '\\agents_revoke_agent_access',
					'permission_callback' => __NAMESPACE__ . '\\agents_access_admin_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'destructive' => true ),
					),
				)
			);
		}

		if ( ! wp_has_ability( AGENTS_LIST_AGENT_USERS_ABILITY ) ) {
			wp_register_ability(
				AGENTS_LIST_AGENT_USERS_ABILITY,
				array(
					'label'               => 'List Agent Users',
					'description'         => 'List access grants (users, roles, and workspace scopes) for a registered agent. Requires the current principal to hold at least an operator role grant on the target agent.',
					'category'            => 'agents-api',
					'input_schema'        => agents_list_agent_users_input_schema(),
					'output_schema'       => agents_list_agent_users_output_schema(),
					'execute_callback'    => __NAMESPACE__ . '\\agents_list_agent_users',
					'permission_callback' => __NAMESPACE__ . '\\agents_access_operator_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'idempotent' => true,
							'readonly'   => true,
						),
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
 * Grant a user access to a registered agent.
 *
 * Requires the current principal to hold an admin role grant on the target
 * agent (enforced by the permission callback). The store contract upserts, so
 * granting an existing grant updates its role.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_grant_agent_access( array $input ) {
	$agent = agents_access_agent_input( $input );
	if ( is_wp_error( $agent ) ) {
		return $agent;
	}

	$user_id = agents_access_positive_int_input( $input, 'user_id' );
	if ( null === $user_id ) {
		return new \WP_Error( 'agents_access_invalid_user', 'user_id must be a positive integer.' );
	}

	$role = agents_access_string_input( $input, 'role', \WP_Agent_Access_Grant::ROLE_VIEWER );
	if ( ! \WP_Agent_Access_Grant::is_valid_role( $role ) ) {
		return new \WP_Error( 'agents_access_invalid_role', 'role must be admin, operator, or viewer.' );
	}

	$scope = agents_access_request_scope( $input );
	$store = agents_access_store_for_scope( $scope );
	if ( is_wp_error( $store ) ) {
		return $store;
	}

	$principal  = \WP_Agent_Access::get_current_principal( $scope );
	$granted_by = null !== $principal && $principal->acting_user_id > 0 ? $principal->acting_user_id : null;

	$grant = \WP_Agent_Access_Grant::from_array(
		array(
			'agent_id'           => $agent,
			'user_id'            => $user_id,
			'role'               => $role,
			'workspace_id'       => agents_access_nullable_string_input( $input, 'workspace_id' ),
			'granted_by_user_id' => $granted_by,
			'metadata'           => agents_access_metadata_input( $input ),
		)
	);

	$saved = $store->grant_access( $grant );

	return array(
		'granted' => true,
		'grant'   => $saved->to_array(),
	);
}

/**
 * Revoke a user's access grant for a registered agent.
 *
 * Refuses to revoke the last remaining admin grant on the agent so an agent
 * never loses all of its administrators.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_revoke_agent_access( array $input ) {
	$agent = agents_access_agent_input( $input );
	if ( is_wp_error( $agent ) ) {
		return $agent;
	}

	$user_id = agents_access_positive_int_input( $input, 'user_id' );
	if ( null === $user_id ) {
		return new \WP_Error( 'agents_access_invalid_user', 'user_id must be a positive integer.' );
	}

	$scope = agents_access_request_scope( $input );
	$store = agents_access_store_for_scope( $scope );
	if ( is_wp_error( $store ) ) {
		return $store;
	}

	$workspace_id = agents_access_nullable_string_input( $input, 'workspace_id' );
	$admin_count  = 0;
	$target_admin = false;

	foreach ( $store->get_users_for_agent( $agent, $workspace_id ) as $grant ) {
		if ( \WP_Agent_Access_Grant::ROLE_ADMIN !== $grant->role ) {
			continue;
		}

		++$admin_count;

		if ( $grant->user_id === $user_id ) {
			$target_admin = true;
		}
	}

	if ( $target_admin && $admin_count <= 1 ) {
		return new \WP_Error( 'agents_access_last_admin', 'Cannot revoke the last admin grant on an agent.' );
	}

	return array(
		'revoked' => (bool) $store->revoke_access( $agent, $user_id, $workspace_id ),
	);
}

/**
 * List access grants for a registered agent.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_list_agent_users( array $input ) {
	$agent = agents_access_agent_input( $input );
	if ( is_wp_error( $agent ) ) {
		return $agent;
	}

	$store = agents_access_store_for_scope( agents_access_request_scope( $input ) );
	if ( is_wp_error( $store ) ) {
		return $store;
	}

	$users = array();

	foreach ( $store->get_users_for_agent( $agent, agents_access_nullable_string_input( $input, 'workspace_id' ) ) as $grant ) {
		$users[] = $grant->to_array();
	}

	return array( 'users' => $users );
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
 * Shared permission gate for access admin abilities (grant/revoke).
 *
 * Requires the current principal to hold an admin role grant on the target
 * agent. Agent-role — not WordPress capabilities — is the substrate's
 * authorization model for access management. The decision runs through the
 * same {@see 'agents_access_permission'} filter as the read abilities so
 * hosts can tighten or widen it.
 *
 * @param array<string,mixed> $input Ability input.
 */
function agents_access_admin_permission( array $input ): bool {
	return agents_access_role_permission( $input, \WP_Agent_Access_Grant::ROLE_ADMIN );
}

/**
 * Shared permission gate for the agent user listing ability.
 *
 * Requires the current principal to hold at least an operator role grant on
 * the target agent, filtered through {@see 'agents_access_permission'}.
 *
 * @param array<string,mixed> $input Ability input.
 */
function agents_access_operator_permission( array $input ): bool {
	return agents_access_role_permission( $input, \WP_Agent_Access_Grant::ROLE_OPERATOR );
}

/**
 * Gate an access ability behind a minimum agent role.
 *
 * @param array<string,mixed> $input        Ability input.
 * @param string              $minimum_role Minimum agent role.
 */
function agents_access_role_permission( array $input, string $minimum_role ): bool {
	$agent_id = sanitize_title( agents_access_string_input( $input, 'agent' ) );

	$allowed = '' !== $agent_id
		&& \WP_Agent_Access::can_current_principal_access_agent( $agent_id, $minimum_role, agents_access_request_scope( $input ) );

	return (bool) apply_filters( 'agents_access_permission', $allowed, $input );
}

/**
 * Resolve and validate the agent identifier for access write abilities.
 *
 * @param array<string,mixed> $input Ability input.
 * @return string|\WP_Error
 */
function agents_access_agent_input( array $input ) {
	$agent_id = sanitize_title( agents_access_string_input( $input, 'agent' ) );

	if ( '' === $agent_id ) {
		return new \WP_Error( 'agents_access_invalid_agent', 'agent must be a non-empty string.' );
	}

	if ( ! agents_access_agent_registered( $agent_id ) ) {
		return new \WP_Error( 'agents_access_unknown_agent', sprintf( 'Agent "%s" is not registered.', $agent_id ) );
	}

	return $agent_id;
}

/**
 * Whether an agent slug is currently registered.
 *
 * Falls back to true when the registry is unavailable so the store remains
 * the final authority.
 *
 * @param string $agent_id Agent slug/id.
 */
function agents_access_agent_registered( string $agent_id ): bool {
	if ( ! class_exists( '\WP_Agents_Registry' ) ) {
		return true;
	}

	$registry = \WP_Agents_Registry::get_instance();

	return ! $registry instanceof \WP_Agents_Registry || $registry->is_registered( $agent_id );
}

/**
 * Resolve the host access store for a request scope.
 *
 * @param array<string,mixed> $scope Request scope.
 * @return \WP_Agent_Access_Store|\WP_Error
 */
function agents_access_store_for_scope( array $scope ) {
	$store = \WP_Agent_Access::get_store( $scope );

	if ( ! $store instanceof \WP_Agent_Access_Store ) {
		return new \WP_Error( 'agents_access_store_missing', 'No agent access store is available for this request.' );
	}

	return $store;
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
 * Read a positive integer from ability input.
 *
 * @param array<string,mixed> $input Ability input.
 * @param string              $key   Input key.
 */
function agents_access_positive_int_input( array $input, string $key ): ?int {
	$value = $input[ $key ] ?? null;

	if ( ! is_int( $value ) && ! is_string( $value ) ) {
		return null;
	}

	if ( is_string( $value ) && ! preg_match( '/^\d+$/', $value ) ) {
		return null;
	}

	$int_value = (int) $value;

	return $int_value > 0 ? $int_value : null;
}

/**
 * Read a metadata map from ability input.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function agents_access_metadata_input( array $input ): array {
	$value = $input['metadata'] ?? null;

	if ( ! is_array( $value ) ) {
		return array();
	}

	$metadata = array();

	foreach ( $value as $metadata_key => $metadata_value ) {
		if ( is_string( $metadata_key ) ) {
			$metadata[ $metadata_key ] = $metadata_value;
		}
	}

	return $metadata;
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

/**
 * Input schema for `agents/grant-agent-access`.
 * @return array<string, mixed>
 */
function agents_grant_agent_access_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'agent', 'user_id' ),
		'properties' => array(
			'agent'        => array(
				'type'        => 'string',
				'description' => 'Registered agent slug/id to grant access to.',
			),
			'user_id'      => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'WordPress user ID receiving access.',
			),
			'role'         => agents_access_role_schema(),
			'workspace_id' => array( 'type' => array( 'string', 'null' ) ),
			'metadata'     => array(
				'type'        => 'object',
				'description' => 'Optional host-owned metadata stored with the grant.',
			),
		),
	);
}

/**
 * Output schema for `agents/grant-agent-access`.
 * @return array<string, mixed>
 */
function agents_grant_agent_access_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'granted', 'grant' ),
		'properties' => array(
			'granted' => array( 'type' => 'boolean' ),
			'grant'   => agents_access_grant_schema(),
		),
	);
}

/**
 * Input schema for `agents/revoke-agent-access`.
 * @return array<string, mixed>
 */
function agents_revoke_agent_access_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'agent', 'user_id' ),
		'properties' => array(
			'agent'        => array(
				'type'        => 'string',
				'description' => 'Registered agent slug/id to revoke access from.',
			),
			'user_id'      => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'WordPress user ID whose access is revoked.',
			),
			'workspace_id' => array( 'type' => array( 'string', 'null' ) ),
		),
	);
}

/**
 * Output schema for `agents/revoke-agent-access`.
 * @return array<string, mixed>
 */
function agents_revoke_agent_access_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'revoked' ),
		'properties' => array(
			'revoked' => array( 'type' => 'boolean' ),
		),
	);
}

/**
 * Input schema for `agents/list-agent-users`.
 * @return array<string, mixed>
 */
function agents_list_agent_users_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'agent' ),
		'properties' => array(
			'agent'        => array(
				'type'        => 'string',
				'description' => 'Registered agent slug/id to list access grants for.',
			),
			'workspace_id' => array( 'type' => array( 'string', 'null' ) ),
		),
	);
}

/**
 * Output schema for `agents/list-agent-users`.
 * @return array<string, mixed>
 */
function agents_list_agent_users_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'users' ),
		'properties' => array(
			'users' => array(
				'type'  => 'array',
				'items' => agents_access_grant_schema(),
			),
		),
	);
}

/**
 * JSON schema fragment for an exported access grant.
 * @return array<string, mixed>
 */
function agents_access_grant_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'grant_id'           => array( 'type' => array( 'integer', 'null' ) ),
			'agent_id'           => array( 'type' => 'string' ),
			'user_id'            => array( 'type' => 'integer' ),
			'role'               => agents_access_role_schema(),
			'workspace_id'       => array( 'type' => array( 'string', 'null' ) ),
			'granted_by_user_id' => array( 'type' => array( 'integer', 'null' ) ),
			'granted_at'         => array( 'type' => array( 'string', 'null' ) ),
			'metadata'           => array( 'type' => 'object' ),
			'audience_id'        => array( 'type' => array( 'string', 'null' ) ),
		),
	);
}
