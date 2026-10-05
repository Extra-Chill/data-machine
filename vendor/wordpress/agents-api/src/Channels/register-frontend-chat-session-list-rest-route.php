<?php
/**
 * Generic frontend conversation session list REST adapter.
 *
 * Symmetric counterpart to register-frontend-chat-rest-route.php: dispatches
 * the canonical agents/list-conversation-sessions ability over REST behind
 * an `agents_frontend_chat_rest_session_list_input` filter seam, so a host
 * can scope session lists (by workspace, agent, context, or session owner)
 * once, against the substrate, instead of every chat client shipping its own
 * route and its own filter for this half of the surface.
 *
 * See agents-api#558 and Automattic/intelligence#1066.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

const AGENTS_FRONTEND_CHAT_REST_SESSION_LIST_NAMESPACE = 'agents-api/v1';
const AGENTS_FRONTEND_CHAT_REST_SESSION_LIST_ROUTE     = '/sessions';

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			AGENTS_FRONTEND_CHAT_REST_SESSION_LIST_NAMESPACE,
			AGENTS_FRONTEND_CHAT_REST_SESSION_LIST_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\agents_frontend_chat_rest_session_list_dispatch',
				'permission_callback' => __NAMESPACE__ . '\\agents_frontend_chat_rest_session_list_permission',
				'args'                => agents_frontend_chat_rest_session_list_args(),
			)
		);
	}
);

/**
 * Dispatch one REST session list read through the canonical
 * agents/list-conversation-sessions ability.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function agents_frontend_chat_rest_session_list_dispatch( \WP_REST_Request $request ) {
	$input = agents_frontend_chat_rest_session_list_input( $request );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( \AgentsAPI\Core\Database\Chat\AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY ) : null;

	if ( ! $ability ) {
		return new \WP_Error(
			'agents_frontend_chat_session_list_ability_unavailable',
			'The agents/list-conversation-sessions ability is not available.',
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Permission gate for the frontend conversation session list REST route.
 *
 * Defers entirely to the canonical ability's own permission decision so this
 * transport adapts authorization rather than widening it; the transport
 * filter below can only narrow the outcome further.
 *
 * @param \WP_REST_Request $request REST request.
 */
function agents_frontend_chat_rest_session_list_permission( \WP_REST_Request $request ): bool|\WP_Error {
	$input = agents_frontend_chat_rest_session_list_input( $request );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$allowed = \AgentsAPI\Core\Database\Chat\agents_conversation_sessions_permission( $input );

	/**
	 * Filter the frontend conversation session list REST permission decision.
	 *
	 * @param bool             $allowed Default access decision.
	 * @param array<mixed>     $input   Canonical agents/list-conversation-sessions input.
	 * @param \WP_REST_Request $request REST request.
	 */
	$allowed = $allowed && (bool) apply_filters( 'agents_frontend_chat_rest_session_list_permission', $allowed, $input, $request );

	if ( $allowed ) {
		return true;
	}

	return new \WP_Error(
		'agents_frontend_chat_session_list_forbidden',
		'You are not allowed to list conversation sessions.',
		array( 'status' => 403 )
	);
}

/**
 * Build canonical agents/list-conversation-sessions input from a REST request.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array<string,mixed>|\WP_Error
 */
function agents_frontend_chat_rest_session_list_input( \WP_REST_Request $request ) {
	static $cache = null;

	if ( ! $cache instanceof \SplObjectStorage ) {
		$cache = new \SplObjectStorage();
	}

	if ( $cache->offsetExists( $request ) ) {
		$cached = $cache[ $request ];
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}
		if ( is_array( $cached ) ) {
			return \AgentsAPI\AI\agents_api_string_keyed_array( $cached );
		}
	}

	$input = array();

	$agent = sanitize_title( \AgentsAPI\AI\agents_api_scalar_to_string( $request->get_param( 'agent' ) ) );
	if ( '' !== $agent ) {
		$input['agent'] = $agent;
	}

	$context = \AgentsAPI\AI\agents_api_scalar_to_string( $request->get_param( 'context' ) );
	if ( '' !== $context ) {
		$input['context'] = $context;
	}

	$limit = $request->get_param( 'limit' );
	if ( null !== $limit ) {
		$input['limit'] = \AgentsAPI\Core\Database\Chat\agents_conversation_sessions_int_value( $limit );
	}

	$offset = $request->get_param( 'offset' );
	if ( null !== $offset ) {
		$input['offset'] = \AgentsAPI\Core\Database\Chat\agents_conversation_sessions_int_value( $offset );
	}

	$workspace_type = $request->get_param( 'workspace_type' );
	$workspace_id   = $request->get_param( 'workspace_id' );
	if ( null !== $workspace_type || null !== $workspace_id ) {
		$input['workspace'] = array(
			'workspace_type' => '' !== \AgentsAPI\AI\agents_api_scalar_to_string( $workspace_type ) ? \AgentsAPI\AI\agents_api_scalar_to_string( $workspace_type ) : 'site',
			'workspace_id'   => \AgentsAPI\AI\agents_api_scalar_to_string( $workspace_id ),
		);
	}

	$session_owner = $request->get_param( 'session_owner' );
	if ( is_array( $session_owner ) ) {
		$input['session_owner'] = \AgentsAPI\AI\agents_api_string_keyed_array( $session_owner );
	}

	/**
	 * Filter the canonical agents/list-conversation-sessions input built by
	 * the REST adapter.
	 *
	 * Symmetric to `agents_frontend_chat_rest_input`: hosts use this to scope
	 * session lists (by workspace, agent, context, or session owner) once,
	 * against the substrate, for every client.
	 *
	 * @param array<mixed>     $input   Canonical agents/list-conversation-sessions input.
	 * @param \WP_REST_Request $request REST request.
	 */
	/** @var mixed $filtered_input Hosts may accidentally return invalid values from this filter. */
	$filtered_input = apply_filters( 'agents_frontend_chat_rest_session_list_input', $input, $request );
	if ( ! is_array( $filtered_input ) ) {
		$cache[ $request ] = new \WP_Error(
			'agents_frontend_chat_session_list_invalid_input',
			'The frontend conversation session list REST input filter must return an array.',
			array( 'status' => 400 )
		);

		return $cache[ $request ];
	}

	$input = \AgentsAPI\AI\agents_api_string_keyed_array( $filtered_input );

	$cache[ $request ] = $input;
	return $input;
}

/**
 * REST argument schema.
 *
 * Derived from the canonical agents/list-conversation-sessions input schema
 * so the route's argument contract cannot drift from the ability it adapts.
 *
 * @return array<string,array<string,mixed>>
 */
function agents_frontend_chat_rest_session_list_args(): array {
	$schema     = \AgentsAPI\Core\Database\Chat\agents_conversation_sessions_list_input_schema();
	$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();

	return array(
		'agent'          => array_merge(
			agents_frontend_chat_rest_schema_property( $properties, 'agent', array( 'type' => 'string' ) ),
			array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_title',
			)
		),
		'context'        => array_merge( agents_frontend_chat_rest_schema_property( $properties, 'context', array( 'type' => 'string' ) ), array( 'required' => false ) ),
		'limit'          => array(
			'type'        => 'integer',
			'required'    => false,
			'description' => 'Maximum number of sessions to return. The ability applies its own default and ceiling.',
		),
		'offset'         => array(
			'type'        => 'integer',
			'required'    => false,
			'description' => 'Pagination offset into the caller\'s session list.',
		),
		'workspace_type' => array(
			'type'        => array( 'string', 'null' ),
			'required'    => false,
			'description' => 'Optional canonical conversation workspace type. Defaults to "site" when omitted.',
		),
		'workspace_id'   => array(
			'type'        => array( 'string', 'null' ),
			'required'    => false,
			'description' => 'Optional canonical conversation workspace identifier. Defaults to the current site when omitted.',
		),
		'session_owner'  => array_merge( agents_frontend_chat_rest_schema_property( $properties, 'session_owner', array( 'type' => array( 'object', 'null' ) ) ), array( 'required' => false ) ),
	);
}
