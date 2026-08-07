<?php
/**
 * Generic conversation session ability registrations.
 *
 * These abilities expose the host-provided WP_Agent_Conversation_Store to
 * frontend clients without coupling Agents API to a concrete table or product.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

const AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY        = 'agents/list-conversation-sessions';
const AGENTS_GET_CONVERSATION_SESSION_ABILITY          = 'agents/get-conversation-session';
const AGENTS_CREATE_CONVERSATION_SESSION_ABILITY       = 'agents/create-conversation-session';
const AGENTS_UPDATE_CONVERSATION_SESSION_TITLE_ABILITY = 'agents/update-conversation-session-title';
const AGENTS_DELETE_CONVERSATION_SESSION_ABILITY       = 'agents/delete-conversation-session';

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
			AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY  => array(
				'label'            => 'List Conversation Sessions',
				'description'      => 'List conversation sessions for the current principal in a workspace.',
				'input_schema'     => agents_conversation_sessions_list_input_schema(),
				'output_schema'    => agents_conversation_sessions_list_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_list_conversation_sessions',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_GET_CONVERSATION_SESSION_ABILITY    => array(
				'label'            => 'Get Conversation Session',
				'description'      => 'Read one conversation session owned by the current principal.',
				'input_schema'     => agents_conversation_session_id_input_schema(),
				'output_schema'    => agents_conversation_session_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_get_conversation_session',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_CREATE_CONVERSATION_SESSION_ABILITY => array(
				'label'            => 'Create Conversation Session',
				'description'      => 'Create an empty conversation session for the current principal in a workspace.',
				'input_schema'     => agents_conversation_sessions_create_input_schema(),
				'output_schema'    => agents_conversation_session_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_create_conversation_session',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			AGENTS_UPDATE_CONVERSATION_SESSION_TITLE_ABILITY => array(
				'label'            => 'Update Conversation Session Title',
				'description'      => 'Update the stored display title for a conversation session owned by the current principal.',
				'input_schema'     => agents_conversation_sessions_update_title_input_schema(),
				'output_schema'    => agents_conversation_session_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_update_conversation_session_title',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			AGENTS_DELETE_CONVERSATION_SESSION_ABILITY => array(
				'label'            => 'Delete Conversation Session',
				'description'      => 'Delete a conversation session owned by the current principal.',
				'input_schema'     => agents_conversation_session_id_input_schema(),
				'output_schema'    => array(
					'type'       => 'object',
					'required'   => array( 'deleted' ),
					'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
				),
				'execute_callback' => __NAMESPACE__ . '\\agents_delete_conversation_session',
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
					'permission_callback' => __NAMESPACE__ . '\\agents_conversation_sessions_permission',
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
function agents_list_conversation_sessions( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$workspace = agents_conversation_sessions_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	$args = array(
		'limit'            => 50,
		'offset'           => 0,
		'include_messages' => false,
	);
	if ( isset( $input['limit'] ) ) {
		$args['limit'] = max( 1, min( 100, agents_conversation_sessions_int_value( $input['limit'] ) ) );
	}
	if ( isset( $input['offset'] ) ) {
		$args['offset'] = max( 0, agents_conversation_sessions_int_value( $input['offset'] ) );
	}
	if ( isset( $input['agent'] ) ) {
		$args['agent_slug'] = agents_conversation_sessions_string_value( $input['agent'] );
	}
	if ( isset( $input['context'] ) ) {
		$args['context'] = agents_conversation_sessions_string_value( $input['context'] );
	}

	$sessions = agents_conversation_sessions_list_for_owner( $context['store'], $workspace, $context['owner'], $args );
	if ( is_wp_error( $sessions ) ) {
		return $sessions;
	}

	return array(
		'sessions' => array_map( __NAMESPACE__ . '\\agents_conversation_session_summary', $sessions ),
	);
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_get_conversation_session( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$session = agents_conversation_sessions_owned_session( agents_conversation_sessions_string_value( $input['session_id'] ?? '' ), $context );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return array( 'session' => agents_conversation_session_full( $session ) );
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_create_conversation_session( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$workspace = agents_conversation_sessions_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	$metadata   = agents_conversation_sessions_array_value( $input['metadata'] ?? array() );
	$agent_slug = isset( $input['agent'] ) ? agents_conversation_sessions_string_value( $input['agent'] ) : $context['principal']->effective_agent_id;
	$mode       = isset( $input['context'] ) ? agents_conversation_sessions_string_value( $input['context'] ) : WP_Agent_Execution_Principal::REQUEST_CONTEXT_CHAT;
	$session_id = agents_conversation_sessions_create_for_owner( $context['store'], $workspace, $context['owner'], $agent_slug, $metadata, $mode );
	if ( is_wp_error( $session_id ) ) {
		return $session_id;
	}

	if ( '' === $session_id ) {
		return new \WP_Error( 'agents_conversation_session_create_failed', 'The conversation session store did not create a session.' );
	}

	$session = $context['store']->get_session( $session_id );
	return array( 'session' => agents_conversation_session_full( is_array( $session ) ? agents_conversation_sessions_array_value( $session ) : array( 'session_id' => $session_id ) ) );
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_update_conversation_session_title( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$session = agents_conversation_sessions_owned_session( agents_conversation_sessions_string_value( $input['session_id'] ?? '' ), $context );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$title = trim( agents_conversation_sessions_string_value( $input['title'] ?? '' ) );
	if ( '' === $title ) {
		return new \WP_Error( 'agents_conversation_session_invalid_title', 'Conversation session title must be a non-empty string.' );
	}

	if ( ! $context['store']->update_title( agents_conversation_sessions_string_value( $session['session_id'] ?? '' ), $title ) ) {
		return new \WP_Error( 'agents_conversation_session_update_failed', 'The conversation session store did not update the title.' );
	}

	$session['title'] = $title;

	return array( 'session' => agents_conversation_session_full( $session ) );
}

/**
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_delete_conversation_session( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$session = agents_conversation_sessions_owned_session( agents_conversation_sessions_string_value( $input['session_id'] ?? '' ), $context );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! $context['store']->delete_session( agents_conversation_sessions_string_value( $session['session_id'] ?? '' ) ) ) {
		return new \WP_Error( 'agents_conversation_session_delete_failed', 'The conversation session store did not delete the session.' );
	}

	return array( 'deleted' => true );
}

/** @param array<string,mixed> $input Ability input. */
function agents_conversation_sessions_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'read' ) : false;
	if ( ! $allowed ) {
		$principal = agents_conversation_sessions_principal( $input );
		$allowed   = $principal instanceof WP_Agent_Execution_Principal && null !== $principal->conversation_owner();
	}

	return (bool) apply_filters( 'agents_conversation_sessions_permission', $allowed, $input );
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array{store:WP_Agent_Conversation_Store,principal:WP_Agent_Execution_Principal,owner:array{type:string,key:string},input:array<string,mixed>}|\WP_Error
 */
function agents_conversation_sessions_context( array $input ) {
	$principal = agents_conversation_sessions_principal( $input );
	if ( ! $principal instanceof WP_Agent_Execution_Principal ) {
		return new \WP_Error( 'agents_conversation_session_unauthenticated', 'A conversation session principal could not be resolved.' );
	}

	$owner = agents_conversation_session_owner_from_input( $input, $principal );
	if ( is_wp_error( $owner ) ) {
		return $owner;
	}

	if ( null === $owner ) {
		$owner = $principal->conversation_owner();
	}
	if ( null === $owner ) {
		return new \WP_Error( 'agents_conversation_session_owner_required', 'The current principal does not provide a conversation session owner key.' );
	}

	$store_context = array( 'principal' => $principal ) + $input;
	$store         = WP_Agent_Conversation_Sessions::get_store( $store_context );
	if ( ! $store instanceof WP_Agent_Conversation_Store ) {
		return new \WP_Error( 'agents_conversation_session_no_store', "No conversation store is registered. Enable the built-in WordPress-native store with add_filter( 'agents_api_enable_default_conversation_store', '__return_true' ), or register your own with the wp_agent_conversation_store filter." );
	}

	if ( ! $store instanceof WP_Agent_Principal_Conversation_Store && WP_Agent_Execution_Principal::OWNER_TYPE_USER !== $owner['type'] ) {
		return new \WP_Error( 'agents_conversation_session_principal_store_required', 'The registered conversation session store does not support non-user principal owners.' );
	}

	return array(
		'store'     => $store,
		'principal' => $principal,
		'owner'     => $owner,
		'input'     => $input,
	);
}

/**
 * Resolve an explicit canonical session owner from ability input.
 *
 * @param array<string,mixed>           $input     Ability input.
 * @param WP_Agent_Execution_Principal $principal Authenticated execution principal.
 * @return array{type:string,key:string}|null|\WP_Error
 */
function agents_conversation_session_owner_from_input( array $input, WP_Agent_Execution_Principal $principal ) {
	$owner = is_array( $input['session_owner'] ?? null ) ? $input['session_owner'] : null;
	if ( null === $owner ) {
		$client_context = is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array();
		$owner          = is_array( $client_context['session_owner'] ?? null ) ? $client_context['session_owner'] : null;
	}

	if ( null === $owner ) {
		return null;
	}

	$type = sanitize_key( agents_conversation_sessions_string_value( $owner['type'] ?? $owner['owner_type'] ?? '' ) );
	$key  = trim( agents_conversation_sessions_string_value( $owner['key'] ?? $owner['owner_key'] ?? '' ) );
	if ( '' === $type || '' === $key ) {
		return new \WP_Error( 'agents_conversation_session_invalid_owner', 'Conversation session owner type and key are required.' );
	}

	if ( WP_Agent_Execution_Principal::OWNER_TYPE_AUDIENCE === $type && in_array( $key, array( 'public', 'audience:public' ), true ) ) {
		return new \WP_Error( 'agents_conversation_session_non_isolating_owner', 'Public audience is not an isolating conversation session owner.' );
	}

	$principal_owner = $principal->conversation_owner();
	if ( WP_Agent_Execution_Principal::OWNER_TYPE_USER === $type ) {
		if ( ! is_array( $principal_owner ) || WP_Agent_Execution_Principal::OWNER_TYPE_USER !== $principal_owner['type'] || preg_replace( '/^user:/', '', $key ) !== (string) $principal_owner['key'] ) {
			return new \WP_Error( 'agents_conversation_session_user_owner_forbidden', 'User conversation session owners must match the authenticated user principal.' );
		}

		$key = (string) $principal_owner['key'];
	}

	return array(
		'type' => $type,
		'key'  => $key,
	);
}

/**
 * @param array{type:string,key:string} $owner    Canonical principal owner.
 * @param array<string,mixed>           $metadata Session metadata.
 * @return string|\WP_Error
 */
function agents_conversation_sessions_create_for_owner( WP_Agent_Conversation_Store $store, WP_Agent_Workspace_Scope $workspace, array $owner, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ) {
	if ( $store instanceof WP_Agent_Principal_Conversation_Store ) {
		return $store->create_session_for_owner( $workspace, $owner, $agent_slug, $metadata, $context );
	}

	if ( WP_Agent_Execution_Principal::OWNER_TYPE_USER !== $owner['type'] ) {
		return new \WP_Error( 'agents_conversation_session_principal_store_required', 'The registered conversation session store does not support non-user principal owners.' );
	}

	return $store->create_session( $workspace, (int) $owner['key'], $agent_slug, $metadata, $context );
}

/**
 * @param array{type:string,key:string} $owner Canonical principal owner.
 * @param array<string,mixed>           $args  List arguments.
 * @return array<int,array<string,mixed>>|\WP_Error
 */
function agents_conversation_sessions_list_for_owner( WP_Agent_Conversation_Store $store, WP_Agent_Workspace_Scope $workspace, array $owner, array $args = array() ) {
	if ( $store instanceof WP_Agent_Principal_Conversation_Store ) {
		return $store->list_sessions_for_owner( $workspace, $owner, $args );
	}

	if ( WP_Agent_Execution_Principal::OWNER_TYPE_USER !== $owner['type'] ) {
		return new \WP_Error( 'agents_conversation_session_principal_store_required', 'The registered conversation session store does not support non-user principal owners.' );
	}

	return $store->list_sessions( $workspace, (int) $owner['key'], $args );
}

/** @param array<string,mixed> $input Ability input. */
function agents_conversation_sessions_principal( array $input ): ?WP_Agent_Execution_Principal {
	// Caller-supplied principals are honored only outside REST request context.
	// REST callers go through the standard resolver chain so identity is
	// established by the request itself rather than declared in the body.
	$accepts_caller_principal = ! defined( 'REST_REQUEST' );

	if ( $accepts_caller_principal && isset( $input['principal'] ) ) {
		if ( $input['principal'] instanceof WP_Agent_Execution_Principal ) {
			return $input['principal'];
		}
		if ( is_array( $input['principal'] ) ) {
			return WP_Agent_Execution_Principal::from_array( agents_conversation_sessions_array_value( $input['principal'] ) );
		}
	}

	$request_context = array( 'request_context' => WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST ) + $input;
	$principal       = WP_Agent_Execution_Principal::resolve( $request_context );
	if ( $principal instanceof WP_Agent_Execution_Principal ) {
		return $principal;
	}

	$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( $user_id <= 0 ) {
		return null;
	}

	return WP_Agent_Execution_Principal::user_session(
		$user_id,
		isset( $input['agent'] ) ? agents_conversation_sessions_string_value( $input['agent'] ) : '__wordpress_user__',
		WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
		array(),
		agents_conversation_sessions_workspace_key( $input )
	);
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return WP_Agent_Workspace_Scope|\WP_Error
 */
function agents_conversation_sessions_workspace( array $input ) {
	try {
		if ( isset( $input['workspace'] ) && is_array( $input['workspace'] ) ) {
			return WP_Agent_Workspace_Scope::from_array( $input['workspace'] );
		}

		return WP_Agent_Workspace_Scope::from_parts(
			agents_conversation_sessions_string_value( $input['workspace_type'] ?? 'site' ),
			agents_conversation_sessions_string_value( $input['workspace_id'] ?? agents_conversation_sessions_default_workspace_id() )
		);
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'agents_conversation_session_invalid_workspace', $exception->getMessage() );
	}
}

/** @param array<string,mixed> $input Ability input. */
function agents_conversation_sessions_workspace_key( array $input ): ?string {
	$workspace = agents_conversation_sessions_workspace( $input );
	return $workspace instanceof WP_Agent_Workspace_Scope ? $workspace->key() : null;
}

function agents_conversation_sessions_default_workspace_id(): string {
	if ( function_exists( 'get_current_blog_id' ) ) {
		return (string) get_current_blog_id();
	}

	return 'default';
}

/**
 * @param array{store:WP_Agent_Conversation_Store,owner:array{type:string,key:string},input:array<string,mixed>} $context Session context.
 * @return array<string,mixed>|\WP_Error
 */
function agents_conversation_sessions_owned_session( string $session_id, array $context ) {
	if ( '' === trim( $session_id ) ) {
		return new \WP_Error( 'agents_conversation_session_invalid_id', 'Conversation session ID must be a non-empty string.' );
	}

	$workspace = agents_conversation_sessions_workspace( $context['input'] );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	if ( $context['store'] instanceof WP_Agent_Principal_Conversation_Session_Reader ) {
		$session = $context['store']->get_session_for_owner( $workspace, $context['owner'], $session_id );
		if ( ! is_array( $session ) ) {
			return new \WP_Error( 'agents_conversation_session_not_found', 'Conversation session not found.' );
		}

		return agents_conversation_sessions_array_value( $session );
	}

	$session = $context['store']->get_session( $session_id );
	if ( ! is_array( $session ) ) {
		return new \WP_Error( 'agents_conversation_session_not_found', 'Conversation session not found.' );
	}

	$session = agents_conversation_sessions_array_value( $session );
	if ( ! agents_conversation_sessions_session_matches_owner( $session, $context['owner'] ) && ! agents_conversation_sessions_can_manage_any() ) {
		return new \WP_Error( 'agents_conversation_session_forbidden', 'The current principal cannot access this conversation session.' );
	}

	return $session;
}

/**
 * @param array<string,mixed>           $session Session row.
 * @param array{type:string,key:string} $owner   Canonical principal owner.
 */
function agents_conversation_sessions_session_matches_owner( array $session, array $owner ): bool {
	$session_owner_type = $session['owner_type'] ?? $session['principal_owner_type'] ?? null;
	$session_owner_key  = $session['owner_key'] ?? $session['principal_owner_key'] ?? null;

	if ( null !== $session_owner_type || null !== $session_owner_key ) {
		return agents_conversation_sessions_string_value( $session_owner_type ) === $owner['type'] && agents_conversation_sessions_string_value( $session_owner_key ) === $owner['key'];
	}

	return WP_Agent_Execution_Principal::OWNER_TYPE_USER === $owner['type'] && agents_conversation_sessions_int_value( $session['user_id'] ?? 0 ) === (int) $owner['key'];
}

function agents_conversation_sessions_can_manage_any(): bool {
	return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

/**
 * @param array<string,mixed> $session Session row.
 * @return array<string,mixed>
 */
function agents_conversation_session_summary( array $session ): array {
	unset( $session['messages'] );
	return $session;
}

/**
 * @param array<string,mixed> $session Session row.
 * @return array<string,mixed>
 */
function agents_conversation_session_full( array $session ): array {
	if ( ! isset( $session['messages'] ) || ! is_array( $session['messages'] ) ) {
		$session['messages'] = array();
	}

	if ( ! isset( $session['metadata'] ) || ! is_array( $session['metadata'] ) ) {
		$session['metadata'] = array();
	}

	foreach ( array( 'session_id', 'workspace_type', 'workspace_id', 'owner_type', 'owner_key', 'agent_slug', 'title', 'provider', 'model', 'context' ) as $field ) {
		$session[ $field ] = isset( $session[ $field ] ) && is_scalar( $session[ $field ] ) ? (string) $session[ $field ] : '';
	}

	$session['user_id'] = isset( $session['user_id'] ) && is_numeric( $session['user_id'] ) ? (int) $session['user_id'] : 0;

	return $session;
}

/** @return array<string,mixed> */
function agents_conversation_sessions_workspace_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'workspace_type', 'workspace_id' ),
		'properties' => array(
			'workspace_type' => array( 'type' => 'string' ),
			'workspace_id'   => array( 'type' => 'string' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_conversation_sessions_list_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'workspace'     => agents_conversation_sessions_workspace_schema(),
			'session_owner' => agents_conversation_session_owner_schema(),
			'limit'         => array( 'type' => 'integer' ),
			'offset'        => array( 'type' => 'integer' ),
			'agent'         => array( 'type' => 'string' ),
			'context'       => array( 'type' => 'string' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_conversation_session_owner_schema(): array {
	return array(
		'type'        => array( 'object', 'null' ),
		'description' => 'Opaque, isolating owner for persisted conversation sessions. Use for anonymous browser or external-channel chats when no logged-in user owns the transcript. Do not use a shared public audience as a session owner. Runtime adapters may store this key hashed, but canonical abilities scope list/get/create/update-title/delete by the resolved owner tuple.',
		'properties'  => array(
			'type'  => array( 'type' => 'string' ),
			'key'   => array( 'type' => 'string' ),
			'label' => array( 'type' => 'string' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_conversation_sessions_create_input_schema(): array {
	$schema = agents_conversation_sessions_list_input_schema();
	if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
		$schema['properties']['metadata'] = array(
			'type'                 => 'object',
			'description'          => 'Optional JSON-serializable session metadata. Product-specific fields should live under namespaced keys so the generic session schema remains runtime-neutral.',
			'additionalProperties' => true,
		);
	}
	return $schema;
}

/** @return array<string,mixed> */
function agents_conversation_session_id_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'session_id' ),
		'properties' => array(
			'session_id'    => array( 'type' => 'string' ),
			'session_owner' => agents_conversation_session_owner_schema(),
		),
	);
}

/** @return array<string,mixed> */
function agents_conversation_sessions_update_title_input_schema(): array {
	$schema = agents_conversation_session_id_input_schema();
	if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
		$schema['required'][] = 'title';
	}
	if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
		$schema['properties']['title'] = array( 'type' => 'string' );
	}
	return $schema;
}

/** @return array<string,mixed> */
function agents_conversation_sessions_list_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'sessions' ),
		'properties' => array(
			'sessions' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
		),
	);
}

/** @return array<string,mixed> */
function agents_conversation_session_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'session' ),
		'properties' => array(
			'session' => agents_conversation_session_row_schema(),
		),
	);
}

/** @return array<string,mixed> */
function agents_conversation_session_row_schema(): array {
	return array(
		'type'                 => 'object',
		'description'          => 'Canonical conversation session row. Unknown fields are optional runtime/product extensions; product-specific metadata should be namespaced inside metadata.',
		'additionalProperties' => true,
		'properties'           => array(
			'session_id'           => array( 'type' => 'string' ),
			'workspace_type'       => array( 'type' => 'string' ),
			'workspace_id'         => array( 'type' => 'string' ),
			'owner_type'           => array( 'type' => 'string' ),
			'owner_key'            => array( 'type' => 'string' ),
			'user_id'              => array( 'type' => 'integer' ),
			'agent_slug'           => array( 'type' => 'string' ),
			'title'                => array( 'type' => 'string' ),
			'messages'             => array( 'type' => 'array' ),
			'metadata'             => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'provider'             => array( 'type' => 'string' ),
			'model'                => array( 'type' => 'string' ),
			'provider_response_id' => array( 'type' => array( 'string', 'null' ) ),
			'context'              => array( 'type' => 'string' ),
			'created_at'           => array( 'type' => array( 'string', 'null' ) ),
			'updated_at'           => array( 'type' => array( 'string', 'null' ) ),
			'last_read_at'         => array( 'type' => array( 'string', 'null' ) ),
			'expires_at'           => array( 'type' => array( 'string', 'null' ) ),
		),
	);
}

function agents_conversation_sessions_int_value( mixed $value ): int {
	if ( is_int( $value ) ) {
		return $value;
	}

	if ( is_float( $value ) || is_string( $value ) || is_bool( $value ) ) {
		return (int) $value;
	}

	return 0;
}

function agents_conversation_sessions_string_value( mixed $value ): string {
	return is_scalar( $value ) ? (string) $value : '';
}

/** @return array<string,mixed> */
function agents_conversation_sessions_array_value( mixed $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	$result = array();
	foreach ( $value as $key => $item ) {
		if ( is_string( $key ) ) {
			$result[ $key ] = $item;
		}
	}

	return $result;
}
