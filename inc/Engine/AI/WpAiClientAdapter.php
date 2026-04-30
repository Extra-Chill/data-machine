<?php
/**
 * WordPress AI Client adapter.
 *
 * Data Machine request-execution adapter for WordPress core's `wp_ai_client_prompt()`
 * fluent API. RequestBuilder stays in the Data Machine product layer while it carries
 * directive discovery, logging, and legacy request-array normalization; the provider
 * runtime it consumes is the wp-ai-client public API.
 *
 * Scope: this class converts Data Machine's assembled provider request shape into
 * wp-ai-client calls and normalizes the result back to Data Machine's historical
 * response array. It is adapter vocabulary because it belongs to Data Machine's
 * product/runtime compatibility layer, not because Agents API requires a bridge to
 * wp-ai-client.
 *
 * @package DataMachine\Engine\AI
 * @since 0.69.1
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class WpAiClientAdapter {

	/**
	 * Generate an image through wp-ai-client and return the generated file handle.
	 *
	 * Data Machine owns prompt refinement, post-context handling, and media insertion.
	 * Provider dispatch belongs to wp-ai-client, so this method deliberately returns
	 * only the generated file pointer/data plus a normalized error envelope.
	 *
	 * @since next
	 *
	 * @param string $prompt       Image generation prompt.
	 * @param string $provider     Provider identifier.
	 * @param string $model        Model identifier.
	 * @param string $aspect_ratio Requested product-level aspect ratio.
	 * @return array Generated image response.
	 */
	public static function generateImage( string $prompt, string $provider, string $model, string $aspect_ratio ): array {
		if ( '' === trim( $prompt ) ) {
			return self::errorResponse( $provider, 'wp-ai-client image generation requires a prompt' );
		}

		if ( '' === trim( $model ) ) {
			return self::errorResponse( $provider, 'wp-ai-client image generation requires a model identifier' );
		}

		try {
			$registry    = \WordPress\AiClient\AiClient::defaultRegistry();
			$resolved_id = self::resolveRegisteredProviderId( $registry, $provider );
		} catch ( \Throwable $e ) {
			return self::errorResponse( $provider, 'wp-ai-client image provider resolution failed: ' . $e->getMessage() );
		}

		$api_key = WpAiClientProviderAdmin::resolveApiKey( $provider );
		if ( '' !== $api_key ) {
			try {
				$registry->setProviderRequestAuthentication(
					$resolved_id,
					new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $api_key )
				);
			} catch ( \Throwable $e ) {
				return self::errorResponse( $provider, 'wp-ai-client image auth setup failed: ' . $e->getMessage() );
			}
		}

		try {
			$model_instance = self::getProviderModel( $registry, $resolved_id, $model, null );

			$builder = \wp_ai_client_prompt( $prompt )
				->using_provider( $resolved_id )
				->using_model( $model_instance );

			if ( class_exists( '\\WordPress\\AiClient\\Files\\Enums\\FileTypeEnum' ) ) {
				$builder = self::callBuilderMethod( $builder, 'as_output_file_type', \WordPress\AiClient\Files\Enums\FileTypeEnum::remote() );
			}

			$builder = self::applyImageAspectRatio( $builder, $aspect_ratio );

			$support_check = array( $builder, 'is_supported_for_image_generation' );
			if ( ! is_callable( $support_check ) ) {
				return self::errorResponse( $provider, 'wp-ai-client image support checks are unavailable' );
			}

			$supported = call_user_func( $support_check );
			if ( is_wp_error( $supported ) ) {
				return self::errorResponse(
					$provider,
					'wp-ai-client image support check failed: ' . $supported->get_error_message()
				);
			}

			if ( ! $supported ) {
				return self::errorResponse(
					$provider,
					sprintf(
						'wp-ai-client model "%s" does not support image generation for provider "%s"',
						$model,
						$resolved_id
					)
				);
			}

			$image_generator = array( $builder, 'generate_image' );
			if ( ! is_callable( $image_generator ) ) {
				return self::errorResponse( $provider, 'wp-ai-client image generation API is unavailable' );
			}

			$file = call_user_func( $image_generator );
			if ( is_wp_error( $file ) ) {
				return self::errorResponse( $provider, 'wp-ai-client image generation failed: ' . $file->get_error_message() );
			}
		} catch ( \Throwable $e ) {
			return self::errorResponse( $provider, 'wp-ai-client image generation threw: ' . $e->getMessage() );
		}

		if ( ! is_object( $file ) ) {
			return self::errorResponse( $provider, 'wp-ai-client image generation returned an invalid file object' );
		}

		$image_url      = method_exists( $file, 'getUrl' ) ? call_user_func( array( $file, 'getUrl' ) ) : null;
		$image_data_uri = method_exists( $file, 'getDataUri' ) ? call_user_func( array( $file, 'getDataUri' ) ) : null;

		if ( empty( $image_url ) && empty( $image_data_uri ) ) {
			return self::errorResponse( $provider, 'wp-ai-client image generation returned no usable image file' );
		}

		$response = array(
			'success'  => true,
			'provider' => $resolved_id,
			'model'    => $model,
		);

		if ( ! empty( $image_url ) ) {
			$response['image_url'] = $image_url;
		}

		if ( ! empty( $image_data_uri ) ) {
			$response['image_data_uri'] = $image_data_uri;
		}

		return $response;
	}

	/**
	 * Apply Data Machine's historical aspect-ratio vocabulary to wp-ai-client.
	 *
	 * wp-ai-client's OpenAI-compatible model base supports a small exact-ratio set;
	 * for DM's existing portrait/landscape presets, request orientation instead of
	 * inventing provider-specific size parameters.
	 *
	 * @param object $builder      WP_AI_Client_Prompt_Builder-like object.
	 * @param string $aspect_ratio Product-level aspect ratio.
	 * @return object Builder instance.
	 */
	private static function applyImageAspectRatio( object $builder, string $aspect_ratio ): object {
		if ( ! class_exists( '\\WordPress\\AiClient\\Files\\Enums\\MediaOrientationEnum' ) ) {
			return $builder;
		}

		$orientation_setter = array( $builder, 'as_output_media_orientation' );
		if ( ! is_callable( $orientation_setter ) ) {
			return $builder;
		}

		if ( '1:1' === $aspect_ratio ) {
			return call_user_func( $orientation_setter, \WordPress\AiClient\Files\Enums\MediaOrientationEnum::square() );
		}

		if ( in_array( $aspect_ratio, array( '3:4', '9:16' ), true ) ) {
			return call_user_func( $orientation_setter, \WordPress\AiClient\Files\Enums\MediaOrientationEnum::portrait() );
		}

		if ( in_array( $aspect_ratio, array( '4:3', '16:9' ), true ) ) {
			return call_user_func( $orientation_setter, \WordPress\AiClient\Files\Enums\MediaOrientationEnum::landscape() );
		}

		return $builder;
	}

	/**
	 * Determine whether the wp-ai-client path should handle the given provider.
	 *
	 * Three conditions must all hold:
	 *   1. `wp_ai_client_prompt()` exists (WordPress 7.0+).
	 *   2. `wp_supports_ai()` reports AI is enabled in this environment.
	 *   3. The requested provider is registered in the default registry — i.e. a
	 *      provider plugin like `ai-provider-for-openai` is installed and active.
	 *
	 * If any condition fails, callers surface a structured request error. The
	 * provider runtime path is wp-ai-client; there is no ai-http-client fallback in
	 * this Agents API direction.
	 *
	 * @since 0.69.1
	 *
	 * @param string $provider Provider identifier (openai, anthropic, google, grok, openrouter, ...).
	 * @return bool True if wp-ai-client can handle the request, false otherwise.
	 */
	public static function isAvailable( string $provider ): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) ) {
			return false;
		}

		if ( ! wp_supports_ai() ) {
			return false;
		}

		try {
			$registry       = \WordPress\AiClient\AiClient::defaultRegistry();
			$registered_ids = $registry->getRegisteredProviderIds();
			$normalized_id  = self::normalizeProviderId( $provider );
		} catch ( \Throwable $e ) {
			return false;
		}

		return in_array( $normalized_id, $registered_ids, true )
			|| in_array( $provider, $registered_ids, true );
	}

	/**
	 * Dispatch a Data Machine request through wp-ai-client and normalize the result.
	 *
	 * Returns Data Machine's existing normalized response shape so the conversation
	 * loop, tool executor, and downstream consumers do not change.
	 *
	 * If the request contains content that the adapter does not yet translate (currently:
	 * any non-string message content such as multi-modal blocks), this method returns
	 * `null` so the caller can return a structured unsupported-shape error.
	 *
	 * @since 0.69.1
	 *
	 * @param array  $request   Built request array with `model`, `messages`, optional `temperature`, `max_tokens`.
	 * @param string $provider  Provider identifier.
	 * @param array  $tools     Structured tools (name => ['name', 'description', 'parameters', ...]).
	 * @return array|null Response array on success, error response array on AI failure, or null if the
	 *                    adapter cannot handle this request shape.
	 */
	public static function dispatch( array $request, string $provider, array $tools ): ?array {
		// Bail with an unsupported-shape signal on non-string content until this adapter
		// can map multi-modal request parts into wp-ai-client DTOs.
		foreach ( $request['messages'] ?? array() as $message ) {
			if ( isset( $message['content'] ) && ! is_string( $message['content'] ) ) {
				return null;
			}
		}

		$model = (string) ( $request['model'] ?? '' );
		if ( '' === $model ) {
			return self::errorResponse( $provider, 'wp-ai-client adapter requires a model identifier' );
		}

		try {
			$registry    = \WordPress\AiClient\AiClient::defaultRegistry();
			$resolved_id = self::resolveRegisteredProviderId( $registry, $provider );
		} catch ( \Throwable $e ) {
			return self::errorResponse( $provider, 'wp-ai-client provider resolution failed: ' . $e->getMessage() );
		}

		$api_key = WpAiClientProviderAdmin::resolveApiKey( $provider );
		if ( '' !== $api_key ) {
			try {
				$registry->setProviderRequestAuthentication(
					$resolved_id,
					new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $api_key )
				);
			} catch ( \Throwable $e ) {
				return self::errorResponse( $provider, 'wp-ai-client auth setup failed: ' . $e->getMessage() );
			}
		}

		// Split DM messages into a system instruction and user/model history.
		$conversation = self::buildHistory( $request['messages'] ?? array() );

		// Build a ModelConfig for generation parameters (temperature, max_tokens) when present.
		$model_config = self::buildModelConfig( $request );

		try {
			$model_instance = self::getProviderModel( $registry, $resolved_id, $model, $model_config );
		} catch ( \Throwable $e ) {
			return self::errorResponse( $provider, 'wp-ai-client model resolution failed: ' . $e->getMessage() );
		}

		try {
			$builder = \wp_ai_client_prompt()
				->using_provider( $resolved_id )
				->using_model( $model_instance );

			if ( null !== $conversation['system'] && '' !== $conversation['system'] ) {
				$builder = self::callBuilderMethod( $builder, 'using_system_instruction', $conversation['system'] );
			}

			if ( ! empty( $conversation['history'] ) ) {
				$builder = self::callBuilderMethod( $builder, 'with_history', ...$conversation['history'] );
			}

			$declarations = self::buildFunctionDeclarations( $tools );
			if ( ! empty( $declarations ) ) {
				$builder = self::callBuilderMethod( $builder, 'using_function_declarations', ...$declarations );
			}

			$generator = array( $builder, 'generate_text_result' );
			if ( ! is_callable( $generator ) ) {
				return self::errorResponse( $provider, 'wp-ai-client text generation API is unavailable' );
			}

			$result = call_user_func( $generator );
		} catch ( \Throwable $e ) {
			return self::errorResponse( $provider, 'wp-ai-client request threw: ' . $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return self::errorResponse( $provider, 'wp-ai-client request failed: ' . $result->get_error_message() );
		}

		return self::normalizeResult( $result, $provider );
	}

	/**
	 * Convert DM message array into a system instruction plus a list of Message DTOs.
	 *
	 * DM uses three roles in conversation history: `system`, `user`, and `assistant`.
	 * `system` messages are concatenated and surfaced as a single system instruction.
	 * `user` and `assistant` messages become USER / MODEL Messages with a single text part.
	 *
	 * Tool call / tool response messages are already stringified by ConversationManager
	 * (`"AI ACTION (Turn 1): Executing FooTool ..."` / `"TOOL RESPONSE (Turn 1): ..."`),
	 * so they pass through as plain assistant/user text — wp-ai-client does not need to
	 * see them as FunctionCall / FunctionResponse parts.
	 *
	 * @since 0.69.1
	 *
	 * @param array $messages DM messages with 'role' and string 'content'.
	 * @return array{system: ?string, history: list<\WordPress\AiClient\Messages\DTO\Message>}
	 */
	private static function buildHistory( array $messages ): array {
		$system_parts = array();
		$history      = array();

		foreach ( $messages as $message ) {
			$role    = (string) ( $message['role'] ?? '' );
			$content = (string) ( $message['content'] ?? '' );

			if ( '' === $content ) {
				continue;
			}

			if ( 'system' === $role ) {
				$system_parts[] = $content;
				continue;
			}

			$part = new \WordPress\AiClient\Messages\DTO\MessagePart( $content );

			if ( 'assistant' === $role ) {
				$history[] = new \WordPress\AiClient\Messages\DTO\ModelMessage( array( $part ) );
			} else {
				// Treat unknown roles as user input rather than dropping content.
				$history[] = new \WordPress\AiClient\Messages\DTO\UserMessage( array( $part ) );
			}
		}

		return array(
			'system'  => empty( $system_parts ) ? null : implode( "\n\n", $system_parts ),
			'history' => $history,
		);
	}

	/**
	 * Convert DM's structured tools array into wp-ai-client FunctionDeclaration DTOs.
	 *
	 * @since 0.69.1
	 *
	 * @param array $tools Structured tools keyed by tool name.
	 * @return list<\WordPress\AiClient\Tools\DTO\FunctionDeclaration>
	 */
	private static function buildFunctionDeclarations( array $tools ): array {
		$declarations = array();

		foreach ( $tools as $tool_name => $tool_config ) {
			$name        = (string) ( $tool_config['name'] ?? $tool_name );
			$description = (string) ( $tool_config['description'] ?? '' );
			$parameters  = $tool_config['parameters'] ?? array();

			if ( '' === $name ) {
				continue;
			}

			// wp-ai-client expects a JSON schema object (or null) for parameters.
			// DM stores the raw parameter map; wrap it in a minimal object schema when needed.
			$schema = self::ensureJsonSchema( $parameters );

			$declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
				$name,
				$description,
				$schema
			);
		}

		return $declarations;
	}

	/**
	 * Build a ModelConfig from request-level generation parameters.
	 *
	 * Returns null when no parameters are set so the model uses provider defaults.
	 *
	 * @since 0.69.1
	 *
	 * @param array $request Built request array.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelConfig|null
	 */
	private static function buildModelConfig( array $request ): ?\WordPress\AiClient\Providers\Models\DTO\ModelConfig {
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
	 * Normalize a successful GenerativeAiResult into DM's expected response array.
	 *
	 * @since 0.69.1
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result   The result from wp-ai-client.
	 * @param string                                             $provider Provider identifier (echoed in response).
	 * @return array DM response shape.
	 */
	private static function normalizeResult(
		\WordPress\AiClient\Results\DTO\GenerativeAiResult $result,
		string $provider
	): array {
		$content    = '';
		$tool_calls = array();

		// First candidate is canonical — DM does not consume multi-candidate responses.
		$candidates = $result->getCandidates();
		if ( ! empty( $candidates ) ) {
			$message = $candidates[0]->getMessage();
			foreach ( $message->getParts() as $part ) {
				$text = $part->getText();
				if ( null !== $text && '' !== $text ) {
					// Only collect content-channel text; thought/reasoning parts are skipped.
					$channel = $part->getChannel();
					if ( $channel->isContent() ) {
						$content .= ( '' === $content ) ? $text : "\n" . $text;
					}
					continue;
				}

				$function_call = $part->getFunctionCall();
				if ( null !== $function_call ) {
					$tool_calls[] = array(
						'name'       => (string) $function_call->getName(),
						'parameters' => self::normalizeFunctionArgs( $function_call->getArgs() ),
						'id'         => $function_call->getId(),
					);
				}
			}
		}

		$token_usage = $result->getTokenUsage();
		$usage       = array(
			'prompt_tokens'     => $token_usage->getPromptTokens(),
			'completion_tokens' => $token_usage->getCompletionTokens(),
			'total_tokens'      => $token_usage->getTotalTokens(),
		);

		return array(
			'success'  => true,
			'provider' => $provider,
			'data'     => array(
				'content'    => $content,
				'tool_calls' => $tool_calls,
				'usage'      => $usage,
			),
		);
	}

	/**
	 * Coerce wp-ai-client function call args into DM's parameter array shape.
	 *
	 * @since 0.69.1
	 *
	 * @param mixed $args Args returned by FunctionCall::getArgs() — typically array, sometimes JSON string.
	 * @return array
	 */
	private static function normalizeFunctionArgs( $args ): array {
		if ( is_array( $args ) ) {
			return $args;
		}

		if ( is_string( $args ) && '' !== $args ) {
			$decoded = json_decode( $args, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		if ( is_object( $args ) ) {
			return (array) $args;
		}

		return array();
	}

	/**
	 * Resolve a provider model through wp-ai-client's registry.
	 *
	 * @param \WordPress\AiClient\Providers\ProviderRegistry $registry Provider registry.
	 * @param string                                          $provider Provider identifier.
	 * @param string                                          $model    Model identifier.
	 * @param mixed                                           $config   Optional model config.
	 * @return mixed Provider model instance.
	 */
	private static function getProviderModel( \WordPress\AiClient\Providers\ProviderRegistry $registry, string $provider, string $model, $config ) {
		/** @var callable $callback wp-ai-client exposes this through __call() in some versions. */
		$callback = array( $registry, 'getProviderModel' );
		return call_user_func( $callback, $provider, $model, $config );
	}

	/**
	 * Call a wp-ai-client builder method exposed directly or through __call().
	 *
	 * @param object $builder Builder instance.
	 * @param string $method  Builder method name.
	 * @param mixed  ...$args Method arguments.
	 * @return object Builder instance.
	 */
	private static function callBuilderMethod( object $builder, string $method, ...$args ): object {
		$callback = array( $builder, $method );
		if ( ! is_callable( $callback ) ) {
			return $builder;
		}

		$result = call_user_func( $callback, ...$args );
		return is_object( $result ) ? $result : $builder;
	}

	/**
	 * Find the registered provider id that matches DM's provider identifier.
	 *
	 * Handles the common case where DM uses one canonical name (e.g. `google`) while
	 * core's provider plugin registers under a different id (e.g. `gemini`).
	 *
	 * @since 0.69.1
	 *
	 * @param \WordPress\AiClient\Providers\ProviderRegistry $registry
	 * @param string                                          $provider
	 * @return string The registered provider id to use with the registry.
	 *
	 * @throws \InvalidArgumentException When the provider is not registered.
	 */
	private static function resolveRegisteredProviderId( \WordPress\AiClient\Providers\ProviderRegistry $registry, string $provider ): string {
		$registered = $registry->getRegisteredProviderIds();

		if ( in_array( $provider, $registered, true ) ) {
			return $provider;
		}

		$alias = self::normalizeProviderId( $provider );
		if ( $alias !== $provider && in_array( $alias, $registered, true ) ) {
			return $alias;
		}

		// Surface a clear failure; caller will translate to an error response.
		throw new \InvalidArgumentException(
			sprintf( 'Provider %s is not registered in wp-ai-client', esc_html( $provider ) )
		);
	}

	/**
	 * Map DM's canonical provider names to the equivalents core provider plugins use.
	 *
	 * DM long-standing identifiers diverge from the wp-ai-client provider plugin ids
	 * in a few places. Keep this list small and explicit; defaults to the input.
	 *
	 * @since 0.69.1
	 *
	 * @param string $provider DM provider identifier.
	 * @return string Normalized identifier.
	 */
	private static function normalizeProviderId( string $provider ): string {
		$map = array(
			'google' => 'gemini',
		);

		return $map[ $provider ] ?? $provider;
	}

	/**
	 * Wrap a parameter array as a minimal JSON schema object when needed.
	 *
	 * Tool parameters are already JSON-schema-shaped in DM ({type:object, properties:{...}}),
	 * but tolerate the case where a tool has only a properties map without the wrapper.
	 *
	 * @since 0.69.1
	 *
	 * @param mixed $parameters Raw parameters definition from a tool.
	 * @return array<string, mixed>|null
	 */
	private static function ensureJsonSchema( $parameters ): ?array {
		if ( ! is_array( $parameters ) || empty( $parameters ) ) {
			return null;
		}

		// Already a JSON-schema-shaped object.
		if ( isset( $parameters['type'] ) || isset( $parameters['properties'] ) || isset( $parameters['$ref'] ) ) {
			return $parameters;
		}

		// Best effort: assume the array IS the properties map, wrap it.
		return array(
			'type'       => 'object',
			'properties' => $parameters,
		);
	}

	/**
	 * Build an error response in DM's expected shape.
	 *
	 * @since 0.69.1
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
}
