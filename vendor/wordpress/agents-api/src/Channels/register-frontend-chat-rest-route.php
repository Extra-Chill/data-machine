<?php
/**
 * Generic frontend chat REST adapter.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

const AGENTS_FRONTEND_CHAT_REST_NAMESPACE = 'agents-api/v1';
const AGENTS_FRONTEND_CHAT_REST_ROUTE     = '/chat';

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			AGENTS_FRONTEND_CHAT_REST_NAMESPACE,
			AGENTS_FRONTEND_CHAT_REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\agents_frontend_chat_rest_dispatch',
				'permission_callback' => __NAMESPACE__ . '\\agents_frontend_chat_rest_permission',
				'args'                => agents_frontend_chat_rest_args(),
			)
		);
	}
);

/**
 * Dispatch one REST chat turn through the canonical agents/chat ability.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function agents_frontend_chat_rest_dispatch( \WP_REST_Request $request ) {
	$input = agents_frontend_chat_rest_input( $request );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( AGENTS_CHAT_ABILITY ) : null;

	if ( ! $ability ) {
		return new \WP_Error(
			'agents_frontend_chat_ability_unavailable',
			'The agents/chat ability is not available.',
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
 * Permission gate for the frontend chat REST route.
 *
 * @param \WP_REST_Request $request REST request.
 */
function agents_frontend_chat_rest_permission( \WP_REST_Request $request ): bool|\WP_Error {
	$input = agents_frontend_chat_rest_input( $request );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$agent   = \AgentsAPI\AI\agents_api_scalar_to_string( $input['agent'] ?? null );
	$allowed = '' !== $agent && agents_chat_permission( $input );

	if ( ! $allowed && '' !== $agent ) {
		$allowed = \WP_Agent_Access::can_current_principal_access_agent( $agent, \WP_Agent_Access_Grant::ROLE_OPERATOR, agents_frontend_chat_rest_scope( $request ) );
	}

	/**
	 * Filter the frontend chat REST permission decision.
	 *
	 * @param bool             $allowed Default access decision.
	 * @param array<mixed>            $input   Canonical agents/chat input.
	 * @param \WP_REST_Request $request REST request.
	 */
	$allowed = (bool) apply_filters( 'agents_frontend_chat_rest_permission', $allowed, $input, $request );

	if ( $allowed ) {
		return true;
	}

	return new \WP_Error(
		'agents_frontend_chat_forbidden',
		'You are not allowed to chat with this agent.',
		array( 'status' => 403 )
	);
}

/**
 * Build canonical agents/chat input from a REST request.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array<string,mixed>|\WP_Error
 */
function agents_frontend_chat_rest_input( \WP_REST_Request $request ) {
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

	$client_context = $request->get_param( 'client_context' );
	$client_context = is_array( $client_context ) ? \AgentsAPI\AI\agents_api_string_keyed_array( $client_context ) : array();
	$session_id     = $request->get_param( 'session_id' );
	$client_name    = \AgentsAPI\AI\agents_api_scalar_to_string( $client_context['client_name'] ?? null );
	$client_context = array_merge(
		$client_context,
		array(
			'source'      => 'rest',
			'client_name' => '' !== $client_name ? $client_name : 'frontend-chat',
		)
	);

	$attachments = $request->get_param( 'attachments' );
	$input       = array(
		'agent'          => sanitize_title( \AgentsAPI\AI\agents_api_scalar_to_string( $request->get_param( 'agent' ) ) ),
		'message'        => \AgentsAPI\AI\agents_api_scalar_to_string( $request->get_param( 'message' ) ),
		'session_id'     => null !== $session_id ? \AgentsAPI\AI\agents_api_scalar_to_string( $session_id ) : null,
		'attachments'    => is_array( $attachments ) ? $attachments : array(),
		'client_context' => $client_context,
	);

	/**
	 * Filter the canonical agents/chat input built by the REST adapter.
	 *
	 * @param array<mixed>            $input   Canonical agents/chat input.
	 * @param \WP_REST_Request $request REST request.
	 */
	/** @var mixed $filtered_input Hosts may accidentally return invalid values from this filter. */
	$filtered_input = apply_filters( 'agents_frontend_chat_rest_input', $input, $request );
	if ( ! is_array( $filtered_input ) ) {
		$cache[ $request ] = new \WP_Error(
			'agents_frontend_chat_invalid_input',
			'The frontend chat REST input filter must return an array.',
			array( 'status' => 400 )
		);

		return $cache[ $request ];
	}
	$input = \AgentsAPI\AI\agents_api_string_keyed_array( $filtered_input );

	if ( '' === \AgentsAPI\AI\agents_api_scalar_to_string( $input['agent'] ?? null ) || '' === trim( \AgentsAPI\AI\agents_api_scalar_to_string( $input['message'] ?? null ) ) ) {
		$cache[ $request ] = new \WP_Error(
			'agents_frontend_chat_invalid_input',
			'The frontend chat REST request requires a non-empty agent and message.',
			array( 'status' => 400 )
		);

		return $cache[ $request ];
	}

	$cache[ $request ] = $input;
	return $input;
}

/**
 * Build request context for principal/access helpers.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array<string,mixed>
 */
function agents_frontend_chat_rest_scope( \WP_REST_Request $request ): array {
	$client_context            = $request->get_param( 'client_context' );
	$client_context            = is_array( $client_context ) ? \AgentsAPI\AI\agents_api_string_keyed_array( $client_context ) : array();
	$scope                     = \AgentsAPI\AI\Auth\agents_access_request_scope(
		array(
			'workspace_id' => $request->get_param( 'workspace_id' ),
			'client_id'    => $request->get_param( 'client_id' ),
		)
	);
	$scope['request_metadata'] = array(
		'rest_route'     => AGENTS_FRONTEND_CHAT_REST_NAMESPACE . AGENTS_FRONTEND_CHAT_REST_ROUTE,
		'client_context' => $client_context,
	);
	$scope['client_context']   = $client_context;

	return $scope;
}

/**
 * REST argument schema.
 *
 * @return array<string,array<string,mixed>>
 */
function agents_frontend_chat_rest_args(): array {
	$schema     = agents_chat_input_schema();
	$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();

	return array(
		'agent'          => array_merge(
			agents_frontend_chat_rest_schema_property( $properties, 'agent', array( 'type' => 'string' ) ),
			array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => __NAMESPACE__ . '\\agents_frontend_chat_rest_validate_non_empty_string',
			)
		),
		'message'        => array_merge(
			agents_frontend_chat_rest_schema_property( $properties, 'message', array( 'type' => 'string' ) ),
			array(
				'required'          => true,
				'validate_callback' => __NAMESPACE__ . '\\agents_frontend_chat_rest_validate_non_empty_string',
			)
		),
		'session_id'     => array_merge( agents_frontend_chat_rest_schema_property( $properties, 'session_id', array( 'type' => array( 'string', 'null' ) ) ), array( 'required' => false ) ),
		'attachments'    => array_merge( agents_frontend_chat_rest_schema_property( $properties, 'attachments', array( 'type' => 'array' ) ), array( 'required' => false ) ),
		'client_context' => array_merge( agents_frontend_chat_rest_schema_property( $properties, 'client_context', array( 'type' => 'object' ) ), array( 'required' => false ) ),
		'workspace_id'   => array(
			'type'        => array( 'string', 'null' ),
			'required'    => false,
			'description' => 'Optional host workspace/scope identifier for access checks.',
		),
		'client_id'      => array(
			'type'        => array( 'string', 'null' ),
			'required'    => false,
			'description' => 'Optional host client identifier for access checks.',
		),
	);
}

/**
 * Read a string-keyed schema property from the chat input schema.
 *
 * @param array<mixed>        $properties Schema properties.
 * @param string              $name       Property name.
 * @param array<string,mixed> $fallback   Fallback schema.
 * @return array<string,mixed>
 */
function agents_frontend_chat_rest_schema_property( array $properties, string $name, array $fallback ): array {
	$property = $properties[ $name ] ?? null;
	if ( ! is_array( $property ) ) {
		return $fallback;
	}

	return \AgentsAPI\AI\agents_api_string_keyed_array( $property );
}

/**
 * Validate non-empty REST string arguments.
 *
 * @param mixed $value REST argument value.
 */
function agents_frontend_chat_rest_validate_non_empty_string( $value ): bool {
	return is_string( $value ) && '' !== trim( $value );
}
