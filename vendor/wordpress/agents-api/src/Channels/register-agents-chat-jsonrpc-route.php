<?php
/**
 * JSON-RPC chat adapter (message/send + message/stream).
 *
 * Exposes the canonical agents/chat ability over a JSON-RPC 2.0 wire keyed by
 * agent id, so protocol clients that speak `message/send` (request/response)
 * and `message/stream` (Server-Sent Events) can drive a registered runtime.
 *
 * The route is intentionally a thin envelope: `message/send` is one synchronous
 * agents/chat call wrapped in a Task; `message/stream` emits the same Task over
 * SSE, plus per-token `message/delta` frames when a streaming runtime is
 * registered via the `wp_agent_chat_stream_handler` filter. Without a streaming
 * runtime, `message/stream` degrades gracefully to a single terminal Task frame
 * produced by the synchronous agents/chat handler.
 *
 * Wire shape (mapped onto canonical agents/chat output):
 *   agents/chat output   JSON-RPC Task
 *   ------------------   -------------
 *   run_id            -> id
 *   session_id        -> sessionId
 *   reply             -> status.message.parts[0].text
 *   completed===false -> status.state: 'input-required' (else 'completed')
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Channels;

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;

defined( 'ABSPATH' ) || exit;

const AGENTS_CHAT_JSONRPC_NAMESPACE     = 'agents-api/v1';
const AGENTS_CHAT_JSONRPC_ROUTE         = '/agent/(?P<agent_id>[A-Za-z0-9._-]+)';
const AGENTS_CHAT_JSONRPC_VERSION       = '2.0';
const AGENTS_CHAT_JSONRPC_METHOD_SEND   = 'message/send';
const AGENTS_CHAT_JSONRPC_METHOD_STREAM = 'message/stream';

// JSON-RPC 2.0 reserved error codes (see the spec + agenttic-client ErrorCodes).
const AGENTS_CHAT_JSONRPC_ERR_PARSE            = -32700;
const AGENTS_CHAT_JSONRPC_ERR_INVALID_REQUEST  = -32600;
const AGENTS_CHAT_JSONRPC_ERR_METHOD_NOT_FOUND = -32601;
const AGENTS_CHAT_JSONRPC_ERR_INVALID_PARAMS   = -32602;
const AGENTS_CHAT_JSONRPC_ERR_INTERNAL         = -32603;

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			AGENTS_CHAT_JSONRPC_NAMESPACE,
			AGENTS_CHAT_JSONRPC_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\agents_chat_jsonrpc_dispatch',
				'permission_callback' => __NAMESPACE__ . '\\agents_chat_jsonrpc_permission',
				'args'                => array(
					'agent_id' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Agent slug this JSON-RPC endpoint is bound to.',
					),
				),
			)
		);
	}
);

/**
 * Register a streaming chat runtime.
 *
 * The streaming handler is the token-by-token sibling of the synchronous
 * `wp_agent_chat_handler`. It receives the canonical agents/chat input plus an
 * `$emit` callback and must:
 *   - call `$emit( $delta )` for each chunk as it arrives, where `$delta` is a
 *     canonical delta array (see agents_chat_jsonrpc_delta_to_wire for shapes):
 *       array( 'type' => 'content',  'text' => '...' )
 *       array( 'type' => 'tool_call', 'tool_call_id' => '', 'tool_name' => '', 'index' => 0 )
 *       array( 'type' => 'tool_argument', 'tool_call_id' => '', 'text' => '<json fragment>', 'index' => 0 )
 *   - return the canonical agents/chat output array (or WP_Error) once complete.
 *
 * Equivalent to `add_filter( 'wp_agent_chat_stream_handler', ... )` but reads
 * more intentionally at the call site, mirroring register_chat_handler().
 *
 * @param callable $handler  Receives ( array $input, callable $emit ), returns
 *                           canonical output array or WP_Error.
 * @param int      $priority Filter priority. Default 10.
 */
function register_chat_stream_handler( callable $handler, int $priority = 10 ): void {
	add_filter(
		'wp_agent_chat_stream_handler',
		static function ( $existing, array $input ) use ( $handler ) {
			unset( $input );
			if ( null !== $existing ) {
				return $existing;
			}
			return $handler;
		},
		$priority,
		2
	);
}

/**
 * Permission gate. Mirrors the synchronous frontend chat route, keyed on the
 * agent slug carried in the URL.
 *
 * @param \WP_REST_Request $request REST request.
 */
function agents_chat_jsonrpc_permission( \WP_REST_Request $request ): bool|\WP_Error {
	$agent = sanitize_title( \AgentsAPI\AI\agents_api_scalar_to_string( $request->get_param( 'agent_id' ) ) );
	if ( '' === $agent ) {
		return new \WP_Error(
			'agents_chat_jsonrpc_forbidden',
			'A non-empty agent id is required.',
			array( 'status' => 403 )
		);
	}

	$input   = array( 'agent' => $agent );
	$allowed = agents_chat_permission( $input );

	if ( ! $allowed ) {
		$allowed = \WP_Agent_Access::can_current_principal_access_agent(
			$agent,
			\WP_Agent_Access_Grant::ROLE_OPERATOR,
			agents_chat_jsonrpc_scope( $request )
		);
	}

	/**
	 * Filter the JSON-RPC chat permission decision.
	 *
	 * @param bool             $allowed Default access decision.
	 * @param string           $agent   Agent slug from the URL.
	 * @param \WP_REST_Request $request REST request.
	 */
	$allowed = (bool) apply_filters( 'agents_chat_jsonrpc_permission', $allowed, $agent, $request );

	if ( $allowed ) {
		return true;
	}

	return new \WP_Error(
		'agents_chat_jsonrpc_forbidden',
		'You are not allowed to chat with this agent.',
		array( 'status' => 403 )
	);
}

/**
 * Dispatch a JSON-RPC chat request. Branches on the JSON-RPC method:
 * `message/send` returns a JSON Task response; `message/stream` streams SSE.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response
 */
function agents_chat_jsonrpc_dispatch( \WP_REST_Request $request ): \WP_REST_Response {
	$agent  = sanitize_title( \AgentsAPI\AI\agents_api_scalar_to_string( $request->get_param( 'agent_id' ) ) );
	$body   = $request->get_json_params();
	$rpc_id = agents_chat_jsonrpc_request_id( $body );
	$method = isset( $body['method'] ) && is_string( $body['method'] ) ? $body['method'] : '';
	$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

	if ( AGENTS_CHAT_JSONRPC_VERSION !== ( $body['jsonrpc'] ?? null ) ) {
		return rest_ensure_response(
			agents_chat_jsonrpc_error_frame( $rpc_id, AGENTS_CHAT_JSONRPC_ERR_INVALID_REQUEST, 'Request must be JSON-RPC 2.0.' )
		);
	}

	$input = agents_chat_jsonrpc_input_from_params( $params, $agent );
	if ( is_wp_error( $input ) ) {
		return rest_ensure_response(
			agents_chat_jsonrpc_error_frame( $rpc_id, AGENTS_CHAT_JSONRPC_ERR_INVALID_PARAMS, $input->get_error_message() )
		);
	}

	if ( AGENTS_CHAT_JSONRPC_METHOD_STREAM === $method ) {
		// Streams directly and exits; never returns to the REST server.
		agents_chat_jsonrpc_stream( $rpc_id, $input );
		exit;
	}

	if ( AGENTS_CHAT_JSONRPC_METHOD_SEND !== $method ) {
		return rest_ensure_response(
			agents_chat_jsonrpc_error_frame( $rpc_id, AGENTS_CHAT_JSONRPC_ERR_METHOD_NOT_FOUND, sprintf( 'Unknown JSON-RPC method "%s".', $method ) )
		);
	}

	$output = agents_chat_jsonrpc_run_sync( $input );
	if ( is_wp_error( $output ) ) {
		return rest_ensure_response(
			agents_chat_jsonrpc_error_frame( $rpc_id, AGENTS_CHAT_JSONRPC_ERR_INTERNAL, $output->get_error_message() )
		);
	}

	return rest_ensure_response(
		agents_chat_jsonrpc_result_frame( $rpc_id, agents_chat_jsonrpc_task_from_output( $output ) )
	);
}

/**
 * Run one synchronous agents/chat turn.
 *
 * @param array<string,mixed> $input Canonical agents/chat input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_chat_jsonrpc_run_sync( array $input ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( AGENTS_CHAT_ABILITY ) : null;
	if ( ! $ability ) {
		return new \WP_Error( 'agents_chat_jsonrpc_ability_unavailable', 'The agents/chat ability is not available.' );
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return \AgentsAPI\AI\agents_api_string_keyed_array( is_array( $result ) ? $result : array() );
}

/**
 * Stream a chat turn as Server-Sent Events.
 *
 * Emits per-token `message/delta` frames when a streaming runtime is registered
 * (wp_agent_chat_stream_handler), then a terminal `result: Task` frame. Without
 * a streaming runtime it falls back to the synchronous handler and emits a
 * single terminal frame. This function writes to the output buffer and is
 * expected to be followed by `exit`.
 *
 * @param string|int|null     $rpc_id JSON-RPC request id to echo on the terminal frame.
 * @param array<string,mixed> $input  Canonical agents/chat input.
 * @return void
 */
function agents_chat_jsonrpc_stream( $rpc_id, array $input ): void {
	\AgentsAPI\AI\agents_api_open_sse_response();

	$task_id = \AgentsAPI\AI\agents_api_scalar_to_string( $input['run_id'] ?? null );
	if ( '' === $task_id ) {
		$task_id         = WP_Agent_Chat_Run_Control::generate_run_id();
		$input['run_id'] = $task_id;
	}

	$stream_handler = apply_filters( 'wp_agent_chat_stream_handler', null, $input );

	if ( is_callable( $stream_handler ) ) {
		/**
		 * @param array<string,mixed> $delta Canonical delta emitted by the runtime.
		 */
		$emit = static function ( array $delta ) use ( $task_id ): void {
			\AgentsAPI\AI\agents_api_emit_sse_json_frame(
				agents_chat_jsonrpc_delta_frame( $task_id, \AgentsAPI\AI\agents_api_string_keyed_array( $delta ) )
			);
		};

		$output = call_user_func( $stream_handler, $input, $emit );
	} else {
		// Graceful degradation: no streaming runtime, run the sync handler.
		$output = agents_chat_jsonrpc_run_sync( $input );
	}

	if ( is_wp_error( $output ) ) {
		\AgentsAPI\AI\agents_api_emit_sse_json_frame(
			agents_chat_jsonrpc_error_frame( $rpc_id, AGENTS_CHAT_JSONRPC_ERR_INTERNAL, $output->get_error_message() )
		);
		return;
	}

	$output = \AgentsAPI\AI\agents_api_string_keyed_array( is_array( $output ) ? $output : array() );
	if ( '' === \AgentsAPI\AI\agents_api_scalar_to_string( $output['run_id'] ?? null ) ) {
		$output['run_id'] = $task_id;
	}

	\AgentsAPI\AI\agents_api_emit_sse_json_frame(
		agents_chat_jsonrpc_result_frame( $rpc_id, agents_chat_jsonrpc_task_from_output( $output ) )
	);
}

/**
 * Build canonical agents/chat input from JSON-RPC MessageSendParams.
 *
 * @param array<mixed> $params JSON-RPC params (MessageSendParams).
 * @param string       $agent  Agent slug from the URL.
 * @return array<string,mixed>|\WP_Error
 */
function agents_chat_jsonrpc_input_from_params( array $params, string $agent ) {
	if ( '' === $agent ) {
		return new \WP_Error( 'agents_chat_jsonrpc_invalid_params', 'A non-empty agent id is required.' );
	}

	$message = isset( $params['message'] ) && is_array( $params['message'] ) ? $params['message'] : array();
	$text    = agents_chat_jsonrpc_extract_text( $message );
	if ( '' === trim( $text ) ) {
		return new \WP_Error( 'agents_chat_jsonrpc_invalid_params', 'params.message must contain non-empty text.' );
	}

	$session_id = \AgentsAPI\AI\agents_api_scalar_to_string( $params['sessionId'] ?? null );
	$run_id     = \AgentsAPI\AI\agents_api_scalar_to_string( $params['id'] ?? null );

	$client_context = array(
		'source'      => 'jsonrpc',
		'client_name' => 'jsonrpc-chat',
	);
	if ( isset( $params['metadata'] ) && is_array( $params['metadata'] ) ) {
		$client_context['metadata'] = $params['metadata'];
	}

	$input = array(
		'agent'          => $agent,
		'message'        => $text,
		'session_id'     => '' !== $session_id ? $session_id : null,
		'run_id'         => '' !== $run_id ? $run_id : null,
		'attachments'    => agents_chat_jsonrpc_attachments( $message ),
		'client_context' => $client_context,
	);

	/**
	 * Filter the canonical agents/chat input built by the JSON-RPC adapter.
	 *
	 * @param array<string,mixed> $input  Canonical agents/chat input.
	 * @param array<mixed>        $params JSON-RPC params.
	 * @param string              $agent  Agent slug.
	 */
	/** @var mixed $filtered Hosts may return invalid values from this filter. */
	$filtered = apply_filters( 'agents_chat_jsonrpc_input', $input, $params, $agent );

	return is_array( $filtered ) ? \AgentsAPI\AI\agents_api_string_keyed_array( $filtered ) : $input;
}

/**
 * Map canonical agents/chat output onto a JSON-RPC Task.
 *
 * @param array<string,mixed> $output Canonical agents/chat output.
 * @return array<string,mixed> Task.
 */
function agents_chat_jsonrpc_task_from_output( array $output ): array {
	$run_id     = \AgentsAPI\AI\agents_api_scalar_to_string( $output['run_id'] ?? null );
	$session_id = \AgentsAPI\AI\agents_api_scalar_to_string( $output['session_id'] ?? null );
	$reply      = \AgentsAPI\AI\agents_api_scalar_to_string( $output['reply'] ?? null );

	// `completed` defaults to true when absent (mirrors run-control in agents_chat_dispatch).
	$completed = ! array_key_exists( 'completed', $output ) || ! empty( $output['completed'] );
	$state     = $completed ? 'completed' : 'input-required';

	$task = array(
		'id'     => '' !== $run_id ? $run_id : ( '' !== $session_id ? $session_id : 'run' ),
		'status' => array(
			'state'   => $state,
			'message' => agents_chat_jsonrpc_agent_message( $reply, $run_id ),
		),
	);

	if ( '' !== $session_id ) {
		$task['sessionId'] = $session_id;
	}

	return $task;
}

/**
 * Build an agent Message object for a Task status.
 *
 * @param string $text   Assistant text.
 * @param string $run_id Run id used to derive a stable message id.
 * @return array<string,mixed>
 */
function agents_chat_jsonrpc_agent_message( string $text, string $run_id ): array {
	$message_id = ( '' !== $run_id ? $run_id : 'run' ) . '-message';

	return array(
		'role'      => 'agent',
		'parts'     => array(
			array(
				'type' => 'text',
				'text' => $text,
			),
		),
		'messageId' => $message_id,
		'kind'      => 'message',
	);
}

/**
 * Wrap a Task in a JSON-RPC success frame.
 *
 * @param string|int|null     $rpc_id JSON-RPC request id.
 * @param array<string,mixed> $task   Task object.
 * @return array<string,mixed>
 */
function agents_chat_jsonrpc_result_frame( $rpc_id, array $task ): array {
	return array(
		'jsonrpc' => AGENTS_CHAT_JSONRPC_VERSION,
		'id'      => $rpc_id,
		'result'  => $task,
	);
}

/**
 * Build a JSON-RPC error frame.
 *
 * @param string|int|null $rpc_id  JSON-RPC request id.
 * @param int             $code    JSON-RPC error code.
 * @param string          $message Human-readable error message.
 * @return array<string,mixed>
 */
function agents_chat_jsonrpc_error_frame( $rpc_id, int $code, string $message ): array {
	return array(
		'jsonrpc' => AGENTS_CHAT_JSONRPC_VERSION,
		'id'      => $rpc_id,
		'error'   => array(
			'code'    => $code,
			'message' => $message,
		),
	);
}

/**
 * Build a `message/delta` notification frame from a canonical delta.
 *
 * @param string              $task_id Task id the delta belongs to.
 * @param array<string,mixed> $delta   Canonical delta.
 * @return array<string,mixed>
 */
function agents_chat_jsonrpc_delta_frame( string $task_id, array $delta ): array {
	return array(
		'jsonrpc' => AGENTS_CHAT_JSONRPC_VERSION,
		'method'  => 'message/delta',
		'params'  => array(
			'id'    => $task_id,
			'delta' => agents_chat_jsonrpc_delta_to_wire( $delta ),
		),
	);
}

/**
 * Translate a canonical delta into the client's StreamDelta wire shape.
 *
 * Canonical -> wire:
 *   content       { type:'content',       text }                          -> { deltaType:'content', content:text }
 *   tool_call     { type:'tool_call',     tool_call_id, tool_name, index } -> { deltaType:'tool_name', content:tool_name, toolCallId, toolCallName, toolCallIndex }
 *   tool_argument { type:'tool_argument', tool_call_id, text, index }      -> { deltaType:'tool_argument', content:text, toolCallId, toolCallIndex }
 *
 * @param array<string,mixed> $delta Canonical delta.
 * @return array<string,mixed> Wire StreamDelta.
 */
function agents_chat_jsonrpc_delta_to_wire( array $delta ): array {
	$type = \AgentsAPI\AI\agents_api_scalar_to_string( $delta['type'] ?? null );

	if ( 'tool_call' === $type ) {
		return array(
			'deltaType'     => 'tool_name',
			'content'       => \AgentsAPI\AI\agents_api_scalar_to_string( $delta['tool_name'] ?? null ),
			'toolCallId'    => \AgentsAPI\AI\agents_api_scalar_to_string( $delta['tool_call_id'] ?? null ),
			'toolCallName'  => \AgentsAPI\AI\agents_api_scalar_to_string( $delta['tool_name'] ?? null ),
			'toolCallIndex' => \AgentsAPI\AI\agents_api_numeric_to_int( $delta['index'] ?? null ),
		);
	}

	if ( 'tool_argument' === $type ) {
		return array(
			'deltaType'     => 'tool_argument',
			'content'       => \AgentsAPI\AI\agents_api_scalar_to_string( $delta['text'] ?? null ),
			'toolCallId'    => \AgentsAPI\AI\agents_api_scalar_to_string( $delta['tool_call_id'] ?? null ),
			'toolCallIndex' => \AgentsAPI\AI\agents_api_numeric_to_int( $delta['index'] ?? null ),
		);
	}

	// Default: content delta.
	return array(
		'deltaType' => 'content',
		'content'   => \AgentsAPI\AI\agents_api_scalar_to_string( $delta['text'] ?? ( $delta['content'] ?? null ) ),
	);
}

/**
 * Extract concatenated user text from a JSON-RPC Message's text parts.
 * Parts with contentType 'context' are excluded from the visible message.
 *
 * @param array<mixed> $message JSON-RPC Message.
 * @return string
 */
function agents_chat_jsonrpc_extract_text( array $message ): string {
	$parts = isset( $message['parts'] ) && is_array( $message['parts'] ) ? $message['parts'] : array();
	$texts = array();

	foreach ( $parts as $part ) {
		if ( ! is_array( $part ) || 'text' !== ( $part['type'] ?? null ) ) {
			continue;
		}
		if ( 'context' === ( $part['contentType'] ?? null ) ) {
			continue;
		}
		$texts[] = \AgentsAPI\AI\agents_api_scalar_to_string( $part['text'] ?? null );
	}

	return trim( implode( '', $texts ) );
}

/**
 * Extract file parts from a JSON-RPC Message into canonical attachments.
 *
 * @param array<mixed> $message JSON-RPC Message.
 * @return array<int,array<string,mixed>>
 */
function agents_chat_jsonrpc_attachments( array $message ): array {
	$parts       = isset( $message['parts'] ) && is_array( $message['parts'] ) ? $message['parts'] : array();
	$attachments = array();

	foreach ( $parts as $part ) {
		if ( ! is_array( $part ) || 'file' !== ( $part['type'] ?? null ) ) {
			continue;
		}
		$file          = isset( $part['file'] ) && is_array( $part['file'] ) ? $part['file'] : array();
		$attachments[] = \AgentsAPI\AI\agents_api_string_keyed_array( $file );
	}

	return $attachments;
}

/**
 * Read the JSON-RPC request id, preserving string or int, defaulting to null.
 *
 * @param array<mixed> $body Decoded request body.
 * @return string|int|null
 */
function agents_chat_jsonrpc_request_id( array $body ) {
	$id = $body['id'] ?? null;
	if ( is_string( $id ) || is_int( $id ) ) {
		return $id;
	}

	return null;
}

/**
 * Request context for principal/access helpers.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array<string,mixed>
 */
function agents_chat_jsonrpc_scope( \WP_REST_Request $request ): array {
	$scope                     = \AgentsAPI\AI\Auth\agents_access_request_scope(
		array(
			'workspace_id' => $request->get_param( 'workspace_id' ),
			'client_id'    => $request->get_param( 'client_id' ),
		)
	);
	$scope['request_metadata'] = array(
		'rest_route' => AGENTS_CHAT_JSONRPC_NAMESPACE . '/agent/' . sanitize_title( \AgentsAPI\AI\agents_api_scalar_to_string( $request->get_param( 'agent_id' ) ) ),
	);

	return $scope;
}
