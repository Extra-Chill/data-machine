<?php
/**
 * AI Request Builder - Centralized AI request construction for all agents
 *
 * Single source of truth for building standardized AI requests across chat and pipeline modes.
 * Ensures consistent request structure, tool formatting, and directive application to prevent
 * architectural drift between different AI agent types.
 *
 * @package DataMachine\Engine\AI
 * @since 0.2.0
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\Engine\AI\WpAiClient;
use DataMachine\Engine\AI\Directives\DirectivePolicyResolver;

defined( 'ABSPATH' ) || exit;

class RequestBuilder {

	/**
	 * Build standardized AI request for any execution mode.
	 *
	 * Centralizes request construction logic to ensure chat and pipeline flows
	 * build identical request structures. Handles tool restructuring, directive
	 * application via PromptBuilder, and request dispatch.
	 *
	 * Dispatch requires WordPress core's wp-ai-client plus a provider plugin that has
	 * registered the requested provider. Missing wp-ai-client support is surfaced as a
	 * request error; agents-api must not silently fall back to another provider client.
	 *
	 * @param array  $messages    Initial messages array with role/content
	 * @param string $provider    AI provider name (openai, anthropic, google, grok, openrouter)
	 * @param string $model       Model identifier
	 * @param array  $tools       Raw tools array from filters
	 * @param string $mode     Execution mode: 'chat' or 'pipeline'
	 * @param array  $payload     Step payload (session_id, job_id, flow_step_id, data, etc)
	 * @return array AI response from provider
	 */
	public static function build(
		array $messages,
		string $provider,
		string $model,
		array $tools,
		string $mode,
		array $payload = array()
	): array {
		$assembled          = self::assemble( $messages, $provider, $model, $tools, $mode, $payload );
		$request            = $assembled['request'];
		$provider_request   = ProviderRequestAssembler::toProviderRequest( $request );
		$structured_tools   = $assembled['structured_tools'];
		$applied_directives = $assembled['applied_directives'];
		$directive_metadata = $assembled['directive_metadata'];

		$request_metadata = RequestMetadata::build(
			$provider_request,
			$structured_tools,
			$directive_metadata,
			$provider,
			$model,
			$mode
		);
		RequestMetadata::warn_if_oversized( $request_metadata, $payload );

		do_action(
			'datamachine_log',
			'debug',
			'AI request built',
			array_filter(
				array(
					'mode'                  => $mode,
					'job_id'                => $payload['job_id'] ?? null,
					'flow_step_id'          => $payload['flow_step_id'] ?? null,
					'provider'              => $provider,
					'model'                 => $model,
					'message_count'         => count( $provider_request['messages'] ),
					'tool_count'            => count( $structured_tools ),
					'directives'            => $applied_directives,
					'suppressed_directives' => ! empty( $assembled['suppressed_directives'] ) ? $assembled['suppressed_directives'] : null,
					'request_json_bytes'    => $request_metadata['request_json_bytes'] ?? null,
					'messages_json_bytes'   => $request_metadata['messages_json_bytes'] ?? null,
					'tools_json_bytes'      => $request_metadata['tools_json_bytes'] ?? null,
				),
				fn( $v ) => null !== $v
			)
		);

		// 4. Dispatch the request. wp-ai-client is the only runtime provider path.
		$unavailable_reason = WpAiClient::unavailable_reason( $provider );
		if ( null !== $unavailable_reason ) {
			$response                     = self::errorResponse( $provider, $unavailable_reason );
			$response['request_metadata'] = $request_metadata;

			do_action(
				'datamachine_log',
				'error',
				'AI request blocked: wp-ai-client unavailable',
				array_filter(
					array(
						'mode'         => $mode,
						'job_id'       => $payload['job_id'] ?? null,
						'flow_step_id' => $payload['flow_step_id'] ?? null,
						'provider'     => $provider,
						'model'        => $model,
						'error'        => $unavailable_reason,
					),
					fn( $v ) => null !== $v
				)
			);

			return $response;
		}

		$agents_api_result = WpAiClient::generate_text(
			$provider,
			$model,
			$request['messages'] ?? array(),
			$structured_tools,
			$provider_request,
			WpAiClientProviderAdmin::resolveApiKey( $provider )
		);

		$wp_ai_response = self::fromAgentsApiResult( $agents_api_result, $provider );

		do_action(
			'datamachine_log',
			'debug',
			'AI request dispatched via wp-ai-client',
			array_filter(
				array(
					'mode'         => $mode,
					'job_id'       => $payload['job_id'] ?? null,
					'flow_step_id' => $payload['flow_step_id'] ?? null,
					'provider'     => $provider,
					'model'        => $model,
					'success'      => $wp_ai_response['success'] ?? false,
				),
				fn( $v ) => null !== $v
			)
		);

		$wp_ai_response['request_metadata'] = $request_metadata;
		return $wp_ai_response;
	}

	/**
	 * Convert an Agents API runtime result into Data Machine's conversation turn shape.
	 *
	 * @param array  $result   Agents API runtime result.
	 * @param string $provider Provider identifier.
	 * @return array
	 */
	private static function fromAgentsApiResult( array $result, string $provider ): array {
		$response = array(
			'success'  => ! empty( $result['success'] ),
			'provider' => $provider,
			'data'     => array(
				'content'    => (string) ( $result['content'] ?? '' ),
				'tool_calls' => is_array( $result['tool_calls'] ?? null ) ? $result['tool_calls'] : array(),
				'usage'      => is_array( $result['usage'] ?? null ) ? $result['usage'] : array(),
			),
		);

		if ( isset( $result['error'] ) && '' !== $result['error'] ) {
			$response['error'] = (string) $result['error'];
		}

		if ( isset( $result['error_code'] ) && '' !== $result['error_code'] ) {
			$response['error_code'] = (string) $result['error_code'];
		}

		return $response;
	}

	/**
	 * Build an error response in Data Machine's conversation turn shape.
	 *
	 * @param string      $provider Provider identifier.
	 * @param string      $message  Error message.
	 * @param string|null $code     Optional error code.
	 * @return array
	 */
	private static function errorResponse( string $provider, string $message, ?string $code = null ): array {
		$response = array(
			'success'  => false,
			'provider' => $provider,
			'error'    => $message,
			'data'     => array(
				'content'    => '',
				'tool_calls' => array(),
				'usage'      => array(
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
					'total_tokens'      => 0,
				),
			),
		);

		if ( null !== $code && '' !== $code ) {
			$response['error_code'] = $code;
		}

		return $response;
	}

	/**
	 * Assemble a provider request without dispatching it.
	 *
	 * @param array  $messages Initial messages array with role/content.
	 * @param string $provider AI provider name.
	 * @param string $model    Model identifier.
	 * @param array  $tools    Raw tools array from filters.
	 * @param string $mode     Execution mode.
	 * @param array  $payload  Step payload.
	 * @return array Assembled request and inspection metadata.
	 */
	public static function assemble(
		array $messages,
		string $provider,
		string $model,
		array $tools,
		string $mode,
		array $payload = array()
	): array {
		$payload          = self::withDirectiveContext( $payload );
		$directives       = apply_filters( 'datamachine_directives', array() );
		$directive_policy = ( new DirectivePolicyResolver() )->resolve(
			$directives,
			array(
				'mode'     => $mode,
				'agent_id' => $payload['agent_id'] ?? 0,
			)
		);
		$directives       = $directive_policy['directives'];
		$suppressed       = $directive_policy['suppressed'];

		$assembled = ( new ProviderRequestAssembler() )->assemble( $messages, $provider, $model, $tools, $mode, $payload, $directives );

		return array(
			'request'               => $assembled['request'],
			'structured_tools'      => $assembled['structured_tools'],
			'applied_directives'    => $assembled['applied_directives'],
			'directive_metadata'    => $assembled['directive_metadata'],
			'directive_breakdown'   => $assembled['directive_breakdown'],
			'suppressed_directives' => $suppressed,
		);
	}

	/**
	 * Carry Data Machine validation identifiers through an explicit directive context.
	 *
	 * PromptBuilder is the generic prompt/directive assembly surface; it should not
	 * know about jobs or flow steps directly. RequestBuilder is still the Data
	 * Machine adapter layer, so it maps the existing payload shape into the neutral
	 * context consumed by directive validation/logging.
	 *
	 * @param array $payload Step or chat payload.
	 * @return array Payload with directive_context populated when available.
	 */
	private static function withDirectiveContext( array $payload ): array {
		if ( isset( $payload['directive_context'] ) && is_array( $payload['directive_context'] ) ) {
			return $payload;
		}

		$context = array_filter(
			array(
				'job_id'       => $payload['job_id'] ?? null,
				'flow_step_id' => $payload['flow_step_id'] ?? null,
			),
			fn( $value ) => null !== $value
		);

		if ( ! empty( $context ) ) {
			$payload['directive_context'] = $context;
		}

		return $payload;
	}
}
