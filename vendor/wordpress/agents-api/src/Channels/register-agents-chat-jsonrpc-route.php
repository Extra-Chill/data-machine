<?php
/**
 * JSON-RPC chat adapter (message/send + message/stream).
 *
 * Exposes the canonical agents/chat ability over a JSON-RPC 2.0 wire keyed by
 * agent id, so protocol clients that speak `message/send` (request/response)
 * and `message/stream` (Server-Sent Events) can drive a registered runtime.
 * Legacy Agent Protocol task method names (`tasks/send`, `tasks/sendSubscribe`)
 * are accepted as aliases for compatibility with older clients.
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

const AGENTS_CHAT_JSONRPC_NAMESPACE                   = 'agents-api/v1';
const AGENTS_CHAT_JSONRPC_ROUTE                       = '/agent/(?P<agent_id>[A-Za-z0-9._-]+)';
const AGENTS_CHAT_JSONRPC_VERSION                     = '2.0';
const AGENTS_CHAT_JSONRPC_METHOD_SEND                 = 'message/send';
const AGENTS_CHAT_JSONRPC_METHOD_STREAM               = 'message/stream';
const AGENTS_CHAT_JSONRPC_METHOD_TASKS_SEND           = 'tasks/send';
const AGENTS_CHAT_JSONRPC_METHOD_TASKS_SEND_SUBSCRIBE = 'tasks/sendSubscribe';

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

	$input = agents_chat_jsonrpc_request_input( $request );
	if ( is_wp_error( $input ) ) {
		$input = array(
			'agent'        => $agent,
			'workspace_id' => $request->get_param( 'workspace_id' ),
			'client_id'    => $request->get_param( 'client_id' ),
		);
	}
	$allowed = agents_chat_permission( $input );

	/**
	 * Filter the JSON-RPC chat permission decision.
	 *
	 * @param bool             $allowed Default access decision.
	 * @param string           $agent   Agent slug from the URL.
	 * @param \WP_REST_Request $request REST request.
	 */
	$allowed = $allowed && (bool) apply_filters( 'agents_chat_jsonrpc_permission', $allowed, $agent, $request );

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

	$input = agents_chat_jsonrpc_request_input( $request );
	if ( is_wp_error( $input ) ) {
		return rest_ensure_response(
			agents_chat_jsonrpc_error_frame( $rpc_id, AGENTS_CHAT_JSONRPC_ERR_INVALID_PARAMS, $input->get_error_message() )
		);
	}

	if ( agents_chat_jsonrpc_method_streams( $method ) ) {
		// Streams directly and exits; never returns to the REST server.
		agents_chat_jsonrpc_stream( $rpc_id, $input );
		exit;
	}

	if ( ! agents_chat_jsonrpc_method_sends( $method ) ) {
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
		agents_chat_jsonrpc_result_frame( $rpc_id, agents_chat_jsonrpc_task_from_output( $output ), $output )
	);
}

/**
 * Build and cache the canonical JSON-RPC input for one REST request.
 *
 * Permission and dispatch must observe one filtered input so stateful host
 * filters cannot authorize one context and execute another.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array<string,mixed>|\WP_Error
 */
function agents_chat_jsonrpc_request_input( \WP_REST_Request $request ) {
	static $cache = null;

	if ( ! $cache instanceof \SplObjectStorage ) {
		$cache = new \SplObjectStorage();
	}
	if ( $cache->offsetExists( $request ) ) {
		$cached = $cache[ $request ];
		if ( is_array( $cached ) ) {
			return \AgentsAPI\AI\agents_api_string_keyed_array( $cached );
		}
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}
		return new \WP_Error( 'agents_chat_jsonrpc_invalid_params', 'The cached JSON-RPC chat input is invalid.' );
	}

	$agent  = sanitize_title( \AgentsAPI\AI\agents_api_scalar_to_string( $request->get_param( 'agent_id' ) ) );
	$body   = $request->get_json_params();
	$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();
	$input  = agents_chat_jsonrpc_input_from_params( $params, $agent, $body );
	if ( is_array( $input ) ) {
		$input['workspace_id'] = $request->get_param( 'workspace_id' );
		$input['client_id']    = $request->get_param( 'client_id' );
	}

	$cache[ $request ] = $input;
	return $input;
}

/**
 * Whether a JSON-RPC method maps to a synchronous send turn.
 *
 * @param string $method JSON-RPC method.
 */
function agents_chat_jsonrpc_method_sends( string $method ): bool {
	return in_array( $method, array( AGENTS_CHAT_JSONRPC_METHOD_SEND, AGENTS_CHAT_JSONRPC_METHOD_TASKS_SEND ), true );
}

/**
 * Whether a JSON-RPC method maps to a streaming turn.
 *
 * @param string $method JSON-RPC method.
 */
function agents_chat_jsonrpc_method_streams( string $method ): bool {
	return in_array( $method, array( AGENTS_CHAT_JSONRPC_METHOD_STREAM, AGENTS_CHAT_JSONRPC_METHOD_TASKS_SEND_SUBSCRIBE ), true );
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

		// Reserve the run before the streaming runtime executes so a replayed
		// run_id fails closed (agents_chat_run_already_started) instead of
		// repeating tool/provider side effects. This is the same exact-once
		// boundary the synchronous agents/chat dispatcher enforces.
		$output = agents_chat_run_claimed(
			$input,
			static function ( array $claimed_input ) use ( $stream_handler, $emit ) {
				return call_user_func( $stream_handler, $claimed_input, $emit );
			}
		);
	} else {
		// Graceful degradation: no streaming runtime, run the sync handler
		// (agents/chat -> agents_chat_dispatch), which claims the run itself.
		$output = agents_chat_jsonrpc_run_sync( $input );
	}

	if ( is_wp_error( $output ) ) {
		\AgentsAPI\AI\agents_api_emit_sse_json_frame(
			agents_chat_jsonrpc_error_frame( $rpc_id, AGENTS_CHAT_JSONRPC_ERR_INTERNAL, $output->get_error_message() )
		);
		return;
	}

	$output = \AgentsAPI\AI\agents_api_string_keyed_array( $output );
	if ( '' === \AgentsAPI\AI\agents_api_scalar_to_string( $output['run_id'] ?? null ) ) {
		$output['run_id'] = $task_id;
	}

	\AgentsAPI\AI\agents_api_emit_sse_json_frame(
		agents_chat_jsonrpc_result_frame( $rpc_id, agents_chat_jsonrpc_task_from_output( $output ), $output )
	);
}

/**
 * Build canonical agents/chat input from JSON-RPC MessageSendParams.
 *
 * @param array<mixed> $params JSON-RPC params (MessageSendParams).
 * @param string       $agent  Agent slug from the URL.
 * @param array<mixed> $body   Full JSON-RPC request body.
 * @return array<string,mixed>|\WP_Error
 */
function agents_chat_jsonrpc_input_from_params( array $params, string $agent, array $body = array() ) {
	if ( '' === $agent ) {
		return new \WP_Error( 'agents_chat_jsonrpc_invalid_params', 'A non-empty agent id is required.' );
	}

	$message = isset( $params['message'] ) && is_array( $params['message'] ) ? $params['message'] : array();
	$text           = agents_chat_jsonrpc_extract_text( $message );
	$input_messages = agents_chat_jsonrpc_input_messages( $message );
	if ( is_wp_error( $input_messages ) ) {
		return $input_messages;
	}
	if ( '' === trim( $text ) && array() === $input_messages ) {
		return new \WP_Error( 'agents_chat_jsonrpc_invalid_params', 'params.message must contain non-empty text or paired tool call/results.' );
	}

	$session_id = \AgentsAPI\AI\agents_api_scalar_to_string( $params['sessionId'] ?? null );
	$run_id     = \AgentsAPI\AI\agents_api_scalar_to_string( $params['id'] ?? null );

	$client_context = agents_chat_strip_runtime_tool_declaration_fields( agents_chat_jsonrpc_client_context( $message ) );
	$client_context = array_merge(
		$client_context,
		array(
			'source'      => 'jsonrpc',
			'client_name' => 'jsonrpc-chat',
		)
	);
	if ( isset( $params['metadata'] ) && is_array( $params['metadata'] ) ) {
		$client_context['metadata'] = $params['metadata'];
	}

	$input = array(
		'agent'          => $agent,
		'message'        => $text,
		'history'        => agents_chat_jsonrpc_history( $message ),
		'session_id'     => '' !== $session_id ? $session_id : null,
		'run_id'         => '' !== $run_id ? $run_id : null,
		'attachments'    => agents_chat_jsonrpc_attachments( $message ),
		'client_context' => $client_context,
	);
	if ( array() !== $input_messages ) {
		$input['input_messages'] = $input_messages;
	}

	if ( array_key_exists( 'tokenStreaming', $body ) ) {
		$input['token_streaming'] = (bool) $body['tokenStreaming'];
	}

	/**
	 * Filter the canonical agents/chat input built by the JSON-RPC adapter.
	 *
	 * @param array<string,mixed> $input  Canonical agents/chat input.
	 * @param array<mixed>        $params JSON-RPC params.
	 * @param string              $agent  Agent slug.
	 * @param array<mixed>        $body   Full JSON-RPC request body.
	 */
	/** @var mixed $filtered Hosts may return invalid values from this filter. */
	$filtered = apply_filters( 'agents_chat_jsonrpc_input', $input, $params, $agent, $body );

	if ( ! is_array( $filtered ) ) {
		return $input;
	}

	$input = \AgentsAPI\AI\agents_api_string_keyed_array( $filtered );
	if ( is_array( $input['client_context'] ?? null ) ) {
		$input['client_context'] = agents_chat_strip_runtime_tool_declaration_fields( \AgentsAPI\AI\agents_api_string_keyed_array( $input['client_context'] ) );
	}

	return $input;
}

/**
 * Map paired A2A tool call/result data parts to canonical inbound messages.
 *
 * @param array<mixed> $message JSON-RPC Message.
 * @return array<int,array<string,mixed>>|\WP_Error
 */
function agents_chat_jsonrpc_input_messages( array $message ) {
	$parts   = is_array( $message['parts'] ?? null ) ? $message['parts'] : array();
	$calls   = array();
	$results = array();
	foreach ( $parts as $part ) {
		$data = is_array( $part ) && 'data' === ( $part['type'] ?? null ) && is_array( $part['data'] ?? null ) ? $part['data'] : array();
		$id   = \AgentsAPI\AI\agents_api_scalar_to_string( $data['toolCallId'] ?? null );
		if ( '' === $id ) {
			continue;
		}
		if ( array_key_exists( 'result', $data ) ) {
			if ( isset( $results[ $id ] ) ) {
				return new \WP_Error( 'agents_chat_jsonrpc_duplicate_tool_result', 'Inbound tool results must have unique toolCallId values.', array( 'status' => 400 ) );
			}
			$results[ $id ] = $data;
		} else {
			if ( isset( $calls[ $id ] ) ) {
				return new \WP_Error( 'agents_chat_jsonrpc_duplicate_tool_call', 'Inbound tool calls must have unique toolCallId values.', array( 'status' => 400 ) );
			}
			$calls[ $id ] = $data;
		}
	}

	if ( array() === $calls && array() === $results ) {
		return array();
	}
	if ( array_diff_key( $calls, $results ) || array_diff_key( $results, $calls ) ) {
		return new \WP_Error( 'agents_chat_jsonrpc_tool_call_mismatch', 'Every inbound tool call must have one matching result.', array( 'status' => 400 ) );
	}

	$messages = array();
	foreach ( $calls as $id => $call ) {
		$tool_name  = \AgentsAPI\AI\agents_api_scalar_to_string( $call['toolId'] ?? null );
		$parameters = is_array( $call['arguments'] ?? null ) ? $call['arguments'] : array();
		if ( '' === $tool_name ) {
			return new \WP_Error( 'agents_chat_jsonrpc_invalid_tool_call', 'Inbound tool calls require toolId.', array( 'status' => 400 ) );
		}
		$result_tool_name = \AgentsAPI\AI\agents_api_scalar_to_string( $results[ $id ]['toolId'] ?? null );
		if ( '' !== $result_tool_name && $result_tool_name !== $tool_name ) {
			return new \WP_Error( 'agents_chat_jsonrpc_tool_name_mismatch', 'Inbound tool call and result toolId values must match.', array( 'status' => 400 ) );
		}
		$messages[] = \AgentsAPI\AI\WP_Agent_Message::toolCall( '', $tool_name, $parameters, 0, array( 'tool_call_id' => $id ) );
		$result_json = wp_json_encode( $results[ $id ]['result'] );
		$messages[]  = \AgentsAPI\AI\WP_Agent_Message::toolResult(
			false === $result_json ? '' : $result_json,
			$tool_name,
			array( 'result' => $results[ $id ]['result'] ),
			array( 'tool_call_id' => $id )
		);
	}

	return $messages;
}

/**
 * Map client-supplied text backscroll into canonical stateless chat history.
 *
 * @param array<mixed> $message JSON-RPC Message.
 * @return array<int,array{role:string,content:string}>
 */
function agents_chat_jsonrpc_history( array $message ): array {
	$parts   = is_array( $message['parts'] ?? null ) ? $message['parts'] : array();
	$history = array();
	$roles   = array( 'user' => 'user', 'agent' => 'assistant' );

	foreach ( $parts as $part ) {
		if ( ! is_array( $part ) || 'data' !== ( $part['type'] ?? null ) || ! is_array( $part['data'] ?? null ) ) {
			continue;
		}

		$role    = is_string( $part['data']['role'] ?? null ) ? $part['data']['role'] : '';
		$content = is_string( $part['data']['text'] ?? null ) ? $part['data']['text'] : '';
		if ( ! isset( $roles[ $role ] ) || '' === trim( $content ) ) {
			continue;
		}

		$history[] = array( 'role' => $roles[ $role ], 'content' => $content );
	}

	return $history;
}

/**
 * Extract client context from Agent Protocol data parts.
 *
 * @param array<mixed> $message JSON-RPC Message.
 * @return array<string,mixed>
 */
function agents_chat_jsonrpc_client_context( array $message ): array {
	$parts          = isset( $message['parts'] ) && is_array( $message['parts'] ) ? $message['parts'] : array();
	$client_context = array();

	foreach ( $parts as $part ) {
		if ( ! is_array( $part ) || 'data' !== ( $part['type'] ?? null ) ) {
			continue;
		}

		$data = isset( $part['data'] ) && is_array( $part['data'] ) ? $part['data'] : array();
		if ( isset( $data['clientContext'] ) && is_array( $data['clientContext'] ) ) {
			$client_context = array_merge( $client_context, \AgentsAPI\AI\agents_api_string_keyed_array( $data['clientContext'] ) );
		}
	}

	return $client_context;
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
 * @param array<string,mixed> $output Original canonical agents/chat output.
 * @return array<string,mixed>
 */
function agents_chat_jsonrpc_result_frame( $rpc_id, array $task, array $output = array() ): array {
	$frame = array(
		'jsonrpc' => AGENTS_CHAT_JSONRPC_VERSION,
		'id'      => $rpc_id,
		'result'  => $task,
	);

	/**
	 * Filters the complete terminal JSON-RPC frame.
	 *
	 * Hosts can preserve an established A2A envelope by projecting canonical
	 * output metadata without replacing input mapping, execution, or streaming.
	 * Invalid filter values retain the canonical default frame.
	 *
	 * @param array<string,mixed> $frame  Default terminal frame.
	 * @param array<string,mixed> $task   Default Task projection.
	 * @param array<string,mixed> $output Original canonical agents/chat output.
	 * @param string|int|null     $rpc_id JSON-RPC request id.
	 */
	/** @var mixed $filtered Hosts may return invalid values from this filter. */
	$filtered = apply_filters( 'agents_chat_jsonrpc_terminal_frame', $frame, $task, $output, $rpc_id );

	return is_array( $filtered ) ? \AgentsAPI\AI\agents_api_string_keyed_array( $filtered ) : $frame;
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
