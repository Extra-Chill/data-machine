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

use DataMachine\Engine\AI\Directives\DirectivePolicyResolver;

defined( 'ABSPATH' ) || exit;

class RequestBuilder {

	/**
	 * Default timeout for Data Machine wp-ai-client requests.
	 *
	 * WordPress AI Client defaults to 30 seconds, which is too short for
	 * non-streaming LLM requests with large prompts. Studio's cURL low-speed
	 * watchdog is longer than this; without raising the AI Client request
	 * timeout, the request-level cap wins first.
	 */
	private const DEFAULT_WP_AI_CLIENT_REQUEST_TIMEOUT = 300.0;

	/**
	 * Build standardized AI request for any execution mode.
	 *
	 * Centralizes request construction logic to ensure chat and pipeline flows
	 * build identical request structures. Handles tool restructuring, directive
	 * application via PromptBuilder, and request dispatch.
	 *
	 * Dispatch requires WordPress core's wp-ai-client plus a provider plugin that has
	 * registered the requested provider. Missing wp-ai-client support is surfaced as a
	 * request error. Data Machine uses this direct provider path for one-shot/pipeline
	 * requests; Agents API is only needed when callers require durable runtime semantics.
	 *
	 * @param array  $messages    Initial messages array with role/content
	 * @param string $provider    AI provider name (openai, anthropic, google, grok, openrouter)
	 * @param string $model       Model identifier
	 * @param array  $tools       Raw tools array from filters
	 * @param string $mode     Execution mode: 'chat' or 'pipeline'
	 * @param array  $payload     Step payload (session_id, job_id, flow_step_id, data, etc)
	 * @param array|null $request_metadata Optional output parameter for request metadata.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult|\WP_Error AI response from wp-ai-client.
	 */
	public static function build(
		array $messages,
		string $provider,
		string $model,
		array $tools,
		string $mode,
		array $payload = array(),
		?array &$request_metadata = null
	) {
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
		$unavailable_reason = self::wpAiClientUnavailableReason( $provider );
		if ( null !== $unavailable_reason ) {
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

			return new \WP_Error( 'wp_ai_client_unavailable', $unavailable_reason );
		}

		$result          = null;
		$request_timeout = self::wpAiClientRequestTimeout( $mode, $provider, $model, $payload );
		$timeout_filter  = static function ( $default_timeout ) use ( $request_timeout ) {
			return max( (float) $default_timeout, $request_timeout );
		};
		$curl_filter     = static function ( $handle ) use ( $request_timeout ) {
			if ( defined( 'CURLOPT_LOW_SPEED_TIME' ) ) {
				curl_setopt( $handle, CURLOPT_LOW_SPEED_TIME, (int) ceil( $request_timeout ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress exposes the cURL handle only through this hook.
			}

			if ( defined( 'CURLOPT_LOW_SPEED_LIMIT' ) ) {
				curl_setopt( $handle, CURLOPT_LOW_SPEED_LIMIT, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress exposes the cURL handle only through this hook.
			}
		};

		try {
			add_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, 10, 1 );
			add_action( 'http_api_curl', $curl_filter, 10, 1 );

			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			/** @var callable $has_provider wp-ai-client exposes this through __call() in some versions. */
			$has_provider = array( $registry, 'hasProvider' );
			if ( ! call_user_func( $has_provider, $provider ) ) {
				throw new \InvalidArgumentException( sprintf( 'Provider %s is not registered in wp-ai-client', esc_html( $provider ) ) );
			}

			/** @var callable $provider_id_resolver wp-ai-client exposes this through __call() in some versions. */
			$provider_id_resolver = array( $registry, 'getProviderId' );
			$provider_id          = call_user_func( $provider_id_resolver, $provider );
			$api_key              = WpAiClientProviderAdmin::resolveApiKey( $provider );
			if ( '' !== $api_key ) {
				$registry->setProviderRequestAuthentication(
					$provider_id,
					new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $api_key )
				);
			}

			/** @var callable $model_resolver wp-ai-client exposes this through __call() in some versions. */
			$model_resolver = array( $registry, 'getProviderModel' );
			$model_instance = call_user_func( $model_resolver, $provider_id, $model, null );
			$builder        = \wp_ai_client_prompt()
				->using_provider( $provider_id )
				->using_model( $model_instance );

			$model_config = self::productModelConfig( $provider_request );
			if ( null !== $model_config ) {
				$builder = $builder->using_model_config( $model_config );
			}

			$history      = array();
			$system_parts = array();
			foreach ( $request['messages'] ?? array() as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role    = (string) ( $message['role'] ?? '' );
				$content = $message['content'] ?? '';
				if ( '' === $content || array() === $content ) {
					continue;
				}

				if ( 'system' === $role && is_string( $content ) ) {
					$system_parts[] = $content;
					continue;
				}

				$history_message = self::wpAiClientHistoryMessage( $message );
				if ( null !== $history_message ) {
					$history[] = $history_message;
				}
			}

			if ( ! empty( $system_parts ) ) {
				$builder = $builder->using_system_instruction( implode( "\n\n", $system_parts ) );
			}

			if ( ! empty( $history ) ) {
				$builder = $builder->with_history( ...$history );
			}

			$function_declarations = array();
			foreach ( $structured_tools as $tool_name => $tool_config ) {
				$name = (string) ( $tool_config['name'] ?? $tool_name );
				if ( '' === $name ) {
					continue;
				}

				$function_declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
					$name,
					(string) ( $tool_config['description'] ?? '' ),
					self::normalizeLegacyToolSchema( $tool_config['parameters'] ?? array() )
				);
			}

			if ( ! empty( $function_declarations ) ) {
				$builder = $builder->using_function_declarations( ...$function_declarations );
			}

			$result = $builder->generate_text_result();
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'wp_ai_client_text_exception', 'wp-ai-client request failed: ' . $e->getMessage() );
		} finally {
			if ( function_exists( 'remove_filter' ) ) {
				remove_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, 10 );
				remove_filter( 'http_api_curl', $curl_filter, 10 );
			}
		}

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
					'success'      => ! is_wp_error( $result ),
				),
				fn( $v ) => null !== $v
			)
		);

		return $result;
	}

	/**
	 * Convert Data Machine's canonical provider-message array into a wp-ai-client message DTO.
	 *
	 * @param array $message Provider-message array.
	 * @return \WordPress\AiClient\Messages\DTO\Message|null Message DTO, or null when the shape is unsupported.
	 */
	private static function wpAiClientHistoryMessage( array $message ): ?\WordPress\AiClient\Messages\DTO\Message {
		$role = (string) ( $message['role'] ?? '' );
		$text = self::wpAiClientMessageText( $message['content'] ?? '' );
		if ( null === $text ) {
			return null;
		}

		$parts = array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) );

		if ( 'assistant' === $role || 'model' === $role ) {
			return new \WordPress\AiClient\Messages\DTO\ModelMessage( $parts );
		}

		if ( 'user' === $role ) {
			return new \WordPress\AiClient\Messages\DTO\UserMessage( $parts );
		}

		return null;
	}

	/**
	 * Extract text content from canonical message content shapes.
	 *
	 * @param mixed $content Message content.
	 * @return string|null Text content, or null when no text is available.
	 */
	private static function wpAiClientMessageText( $content ): ?string {
		if ( is_string( $content ) ) {
			return '' !== $content ? $content : null;
		}

		if ( ! is_array( $content ) ) {
			return null;
		}

		$parts = array();
		foreach ( $content as $part ) {
			if ( is_string( $part ) && '' !== $part ) {
				$parts[] = $part;
				continue;
			}

			if ( ! is_array( $part ) ) {
				continue;
			}

			$text = $part['text'] ?? $part['content'] ?? null;
			if ( is_string( $text ) && '' !== $text ) {
				$parts[] = $text;
			}
		}

		return ! empty( $parts ) ? implode( "\n\n", $parts ) : null;
	}

	/**
	 * Resolve the request timeout Data Machine applies to wp-ai-client calls.
	 *
	 * @param string $mode     Execution mode.
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @param array  $payload  Step payload.
	 * @return float Timeout in seconds.
	 */
	private static function wpAiClientRequestTimeout( string $mode, string $provider, string $model, array $payload ): float {
		$timeout = apply_filters(
			'datamachine_wp_ai_client_request_timeout',
			self::DEFAULT_WP_AI_CLIENT_REQUEST_TIMEOUT,
			$mode,
			$provider,
			$model,
			$payload
		);

		if ( ! is_numeric( $timeout ) ) {
			return self::DEFAULT_WP_AI_CLIENT_REQUEST_TIMEOUT;
		}

		return max( 0.0, (float) $timeout );
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

	/**
	 * Explain why wp-ai-client cannot handle the requested provider.
	 *
	 * Agents API sits above wp-ai-client; this product execution path calls the
	 * core provider primitive directly instead of reimplementing its dispatch API.
	 *
	 * @param string $provider Provider identifier registered with wp-ai-client.
	 * @return string|null Human-readable failure reason, or null when available.
	 */
	public static function wpAiClientUnavailableReason( string $provider ): ?string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return 'wp-ai-client is unavailable: wp_ai_client_prompt() is not defined';
		}

		if ( ! function_exists( 'wp_supports_ai' ) ) {
			return 'wp-ai-client is unavailable: wp_supports_ai() is not defined';
		}

		if ( ! wp_supports_ai() ) {
			return 'wp-ai-client is unavailable: WordPress reports AI support is disabled';
		}

		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			return 'wp-ai-client is unavailable: WordPress\\AiClient\\AiClient is not loaded';
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			/** @var callable $has_provider wp-ai-client exposes this through __call() in some versions. */
			$has_provider = array( $registry, 'hasProvider' );
			if ( ! call_user_func( $has_provider, $provider ) ) {
				return sprintf( 'wp-ai-client provider registry failed: Provider %s is not registered in wp-ai-client', esc_html( $provider ) );
			}
		} catch ( \Throwable $e ) {
			return 'wp-ai-client provider registry failed: ' . $e->getMessage();
		}

		return null;
	}

	/**
	 * Extract content text from a wp-ai-client result for Data Machine product tasks.
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result The wp-ai-client result.
	 * @return string Text content.
	 */
	public static function resultText( \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): string {
		return (string) $result->toText();
	}

	/**
	 * Build product model config from Data Machine's provider request fields.
	 *
	 * @param array $request Request fields.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelConfig|null
	 */
	private static function productModelConfig( array $request ): ?\WordPress\AiClient\Providers\Models\DTO\ModelConfig {
		$config = array();

		if ( isset( $request['temperature'] ) && is_numeric( $request['temperature'] ) ) {
			$config[ \WordPress\AiClient\Providers\Models\DTO\ModelConfig::KEY_TEMPERATURE ] = (float) $request['temperature'];
		}

		if ( isset( $request['max_tokens'] ) && is_numeric( $request['max_tokens'] ) ) {
			$config[ \WordPress\AiClient\Providers\Models\DTO\ModelConfig::KEY_MAX_TOKENS ] = (int) $request['max_tokens'];
		}

		if ( empty( $config ) ) {
			return null;
		}

		try {
			return \WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray( $config );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Normalize Data Machine's legacy tool parameter map into a JSON Schema object.
	 *
	 * @param mixed $parameters Raw parameters definition.
	 * @return array<string, mixed>|null
	 */
	private static function normalizeLegacyToolSchema( $parameters ): ?array {
		if ( ! is_array( $parameters ) || empty( $parameters ) ) {
			return null;
		}

		$schema = $parameters;
		if ( ! isset( $schema['type'] ) && ! isset( $schema['properties'] ) && ! isset( $schema['$ref'] ) ) {
			$schema = array(
				'type'       => 'object',
				'properties' => $schema,
			);
		}

		return self::normalizeLegacyRequiredFlags( $schema );
	}

	/**
	 * Convert property-level required flags to JSON Schema object-level required list.
	 *
	 * @param array<string, mixed> $schema JSON schema.
	 * @return array<string, mixed>
	 */
	private static function normalizeLegacyRequiredFlags( array $schema ): array {
		if ( empty( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return $schema;
		}

		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

		foreach ( $schema['properties'] as $property_name => $property_schema ) {
			if ( ! is_array( $property_schema ) || ! array_key_exists( 'required', $property_schema ) ) {
				continue;
			}

			if ( true === $property_schema['required'] ) {
				$required[] = (string) $property_name;
			}

			unset( $property_schema['required'] );
			$schema['properties'][ $property_name ] = $property_schema;
		}

		$required = array_values( array_unique( array_filter( $required, 'is_string' ) ) );
		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		} else {
			unset( $schema['required'] );
		}

		return $schema;
	}
}
