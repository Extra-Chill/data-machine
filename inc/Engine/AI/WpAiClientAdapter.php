<?php
/**
 * WordPress AI Client adapter.
 *
 * Bridges Data Machine's RequestBuilder to WordPress core's `wp_ai_client_prompt()`
 * fluent API (introduced in WordPress 7.0). When core's AI client is present and a
 * provider plugin (e.g. `ai-provider-for-openai`) has registered the requested
 * provider, this adapter dispatches the request through core. Otherwise the caller
 * falls back to the bundled `ai-http-client` library via the `chubes_ai_request`
 * filter.
 *
 * Scope: this class is the request-execution bridge only. Admin UI, REST endpoints,
 * settings, and provider/model discovery continue to flow through the existing
 * `chubes_ai_*` filter surface. Once WordPress 7.0 is the minimum supported version
 * those layers will be migrated and `ai-http-client` will be removed entirely.
 *
 * @package DataMachine\Engine\AI
 * @since 0.69.1
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class WpAiClientAdapter {

	/**
	 * Determine whether the wp-ai-client path should handle the given provider.
	 *
	 * Three conditions must all hold:
	 *   1. `wp_ai_client_prompt()` exists (WordPress 7.0+).
	 *   2. `wp_supports_ai()` reports AI is enabled in this environment.
	 *   3. The requested provider is registered in the default registry — i.e. a
	 *      provider plugin like `ai-provider-for-openai` is installed and active.
	 *
	 * If any condition fails, callers must fall back to the legacy
	 * `chubes_ai_request` filter so behavior is preserved on sites that haven't
	 * adopted core's provider plugins yet.
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
			$registry        = \WordPress\AiClient\AiClient::defaultRegistry();
			$registered_ids  = $registry->getRegisteredProviderIds();
			$normalized_id   = self::normalizeProviderId( $provider );
		} catch ( \Throwable $e ) {
			return false;
		}

		return in_array( $normalized_id, $registered_ids, true )
			|| in_array( $provider, $registered_ids, true );
	}

	/**
	 * Dispatch a Data Machine request through wp-ai-client and normalize the result.
	 *
	 * Returns the same array shape as `apply_filters('chubes_ai_request', ...)` so the
	 * conversation loop, tool executor, and downstream consumers do not change.
	 *
	 * If the request contains content that the bridge does not yet translate (currently:
	 * any non-string message content such as multi-modal blocks), this method returns
	 * `null` so the caller can fall back to the legacy path.
	 *
	 * @since 0.69.1
	 *
	 * @param array  $request   Built request array with `model`, `messages`, optional `temperature`, `max_tokens`.
	 * @param string $provider  Provider identifier.
	 * @param array  $tools     Structured tools (name => ['name', 'description', 'parameters', ...]).
	 * @return array|null Response array on success, error response array on AI failure, or null if the
	 *                    bridge cannot handle this request and the caller should fall back.
	 */
	public static function dispatch( array $request, string $provider, array $tools ): ?array {
		// Bail to the legacy path on any non-string content (multi-modal, attachments, etc.).
		// These cases will be handled by ai-http-client, which already supports them.
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
			$registry      = \WordPress\AiClient\AiClient::defaultRegistry();
			$resolved_id   = self::resolveRegisteredProviderId( $registry, $provider );
		} catch ( \Throwable $e ) {
			return self::errorResponse( $provider, 'wp-ai-client provider resolution failed: ' . $e->getMessage() );
		}

		// Apply Data Machine's API key to the provider for this request. DM remains the
		// source of truth for keys until the full migration; we only borrow them here.
		$api_key = self::resolveApiKey( $provider );
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
			$model_instance = $registry->getProviderModel( $resolved_id, $model, $model_config );
		} catch ( \Throwable $e ) {
			return self::errorResponse( $provider, 'wp-ai-client model resolution failed: ' . $e->getMessage() );
		}

		try {
			$builder = \wp_ai_client_prompt()
				->using_provider( $resolved_id )
				->using_model( $model_instance );

			if ( null !== $conversation['system'] && '' !== $conversation['system'] ) {
				$builder = $builder->using_system_instruction( $conversation['system'] );
			}

			if ( ! empty( $conversation['history'] ) ) {
				$builder = $builder->with_history( ...$conversation['history'] );
			}

			$declarations = self::buildFunctionDeclarations( $tools );
			if ( ! empty( $declarations ) ) {
				$builder = $builder->using_function_declarations( ...$declarations );
			}

			$result = $builder->generate_text_result();
		} catch ( \Throwable $e ) {
			return self::errorResponse( $provider, 'wp-ai-client request threw: ' . $e->getMessage() );
		}

		if ( $result instanceof \WP_Error ) {
			return self::errorResponse( $provider, $result->get_error_message(), $result->get_error_code() );
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
	 * Resolve the API key for this provider via DM's existing key plumbing.
	 *
	 * Reads the same `chubes_ai_provider_api_keys` filter that ai-http-client uses,
	 * so admin UX, network settings, and key persistence remain unchanged during
	 * the bridge period.
	 *
	 * @since 0.69.1
	 *
	 * @param string $provider Provider identifier.
	 * @return string API key, or empty string when none is configured.
	 */
	private static function resolveApiKey( string $provider ): string {
		$keys = apply_filters( 'chubes_ai_provider_api_keys', null );

		if ( ! is_array( $keys ) ) {
			return '';
		}

		// Direct hit on provider id.
		if ( ! empty( $keys[ $provider ] ) && is_string( $keys[ $provider ] ) ) {
			return $keys[ $provider ];
		}

		// Some sites may have stored keys under provider-specific aliases (e.g. "google" vs "gemini").
		$alias = self::normalizeProviderId( $provider );
		if ( $alias !== $provider && ! empty( $keys[ $alias ] ) && is_string( $keys[ $alias ] ) ) {
			return $keys[ $alias ];
		}

		return '';
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
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException When the provider is not registered.
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
		throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException(
			sprintf( 'Provider %s is not registered in wp-ai-client', $provider )
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
