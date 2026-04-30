<?php
/**
 * Direct wp-ai-client execution surface for Agents API.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WpAiClient {

	/**
	 * Explain why wp-ai-client cannot handle the requested provider.
	 *
	 * @param string $provider Provider identifier registered with wp-ai-client.
	 * @return string|null Human-readable failure reason, or null when available.
	 */
	public static function unavailable_reason( string $provider ): ?string {
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
			self::registered_provider_id( $registry, $provider );
		} catch ( \Throwable $e ) {
			return 'wp-ai-client provider registry failed: ' . $e->getMessage();
		}

		return null;
	}

	/**
	 * Generate text through wp-ai-client and return the Agents API runtime result shape.
	 *
	 * @param string $provider Provider identifier registered with wp-ai-client.
	 * @param string $model    Model identifier.
	 * @param array  $messages Canonical message envelopes or legacy role/content messages.
	 * @param array  $tools    Runtime tool declarations keyed by tool name.
	 * @param array  $request  Generation config fields.
	 * @param string $api_key  Optional API key.
	 * @return array{success:bool,content:string,tool_calls:array,usage:array,error?:string,error_code?:string}
	 */
	public static function generate_text( string $provider, string $model, array $messages, array $tools = array(), array $request = array(), string $api_key = '' ): array {
		$unsupported_reason = self::unsupported_message_part_reason( $messages );
		if ( null !== $unsupported_reason ) {
			$response               = self::error_result( $unsupported_reason );
			$response['error_code'] = 'wp_ai_client_unsupported_message_part';
			return $response;
		}

		$core_messages = self::build_core_messages( $messages );
		$result        = self::generate_text_result(
			$provider,
			$model,
			$core_messages['history'],
			$core_messages['system'],
			self::build_function_declarations( $tools ),
			self::model_config_from_request( $request ),
			$api_key
		);

		if ( $result instanceof \WP_Error ) {
			$response               = self::error_result( 'wp-ai-client request failed: ' . $result->get_error_message() );
			$response['error_code'] = $result->get_error_code();
			return $response;
		}

		return self::normalize_text_result( $result );
	}

	/**
	 * Generate text through wp-ai-client.
	 *
	 * @param string                                                     $provider              Provider identifier registered with wp-ai-client.
	 * @param string                                                     $model                 Model identifier.
	 * @param list<\WordPress\AiClient\Messages\DTO\Message>           $history               Conversation history.
	 * @param string|null                                                $system_instruction    Optional system instruction.
	 * @param list<\WordPress\AiClient\Tools\DTO\FunctionDeclaration>  $function_declarations Function declarations.
	 * @param \WordPress\AiClient\Providers\Models\DTO\ModelConfig|null $model_config          Optional model config.
	 * @param string                                                     $api_key               Optional API key.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult|\WP_Error
	 */
	public static function generate_text_result( string $provider, string $model, array $history, ?string $system_instruction, array $function_declarations, ?\WordPress\AiClient\Providers\Models\DTO\ModelConfig $model_config = null, string $api_key = '' ) {
		try {
			$registry    = \WordPress\AiClient\AiClient::defaultRegistry();
			$provider_id = self::registered_provider_id( $registry, $provider );
			self::set_authentication( $registry, $provider_id, $api_key );

			$model_instance = self::provider_model( $registry, $provider_id, $model, $model_config );

			$builder = \wp_ai_client_prompt()
				->using_provider( $provider_id )
				->using_model( $model_instance );

			if ( null !== $system_instruction && '' !== $system_instruction ) {
				$builder = self::call_builder_method( $builder, 'using_system_instruction', $system_instruction );
			}

			if ( ! empty( $history ) ) {
				$builder = self::call_builder_method( $builder, 'with_history', ...$history );
			}

			if ( ! empty( $function_declarations ) ) {
				$builder = self::call_builder_method( $builder, 'using_function_declarations', ...$function_declarations );
			}

			$generator = array( $builder, 'generate_text_result' );
			if ( ! is_callable( $generator ) ) {
				return new \WP_Error( 'wp_ai_client_text_unavailable', 'wp-ai-client text generation API is unavailable' );
			}

			return call_user_func( $generator );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wp_ai_client_text_exception', 'wp-ai-client request threw: ' . $e->getMessage() );
		}
	}

	/**
	 * Generate an image file through wp-ai-client.
	 *
	 * @param string $prompt       Prompt text.
	 * @param string $provider     Provider identifier registered with wp-ai-client.
	 * @param string $model        Model identifier.
	 * @param string $aspect_ratio Product-level aspect ratio.
	 * @param string $api_key      Optional API key.
	 * @return object|\WP_Error wp-ai-client file object or error.
	 */
	public static function generate_image_file( string $prompt, string $provider, string $model, string $aspect_ratio, string $api_key = '' ) {
		try {
			$registry    = \WordPress\AiClient\AiClient::defaultRegistry();
			$provider_id = self::registered_provider_id( $registry, $provider );
			self::set_authentication( $registry, $provider_id, $api_key );

			$model_instance = self::provider_model( $registry, $provider_id, $model, null );

			$builder = \wp_ai_client_prompt( $prompt )
				->using_provider( $provider_id )
				->using_model( $model_instance );

			if ( class_exists( '\\WordPress\\AiClient\\Files\\Enums\\FileTypeEnum' ) ) {
				$builder = self::call_builder_method( $builder, 'as_output_file_type', \WordPress\AiClient\Files\Enums\FileTypeEnum::remote() );
			}

			$builder = self::apply_image_aspect_ratio( $builder, $aspect_ratio );

			$support_check = array( $builder, 'is_supported_for_image_generation' );
			if ( ! is_callable( $support_check ) ) {
				return new \WP_Error( 'wp_ai_client_image_support_unavailable', 'wp-ai-client image support checks are unavailable' );
			}

			$supported = call_user_func( $support_check );
			if ( is_wp_error( $supported ) ) {
				return $supported;
			}

			if ( ! $supported ) {
				return new \WP_Error( 'wp_ai_client_image_unsupported', sprintf( 'wp-ai-client model "%s" does not support image generation for provider "%s"', $model, $provider_id ) );
			}

			$image_generator = array( $builder, 'generate_image' );
			if ( ! is_callable( $image_generator ) ) {
				return new \WP_Error( 'wp_ai_client_image_unavailable', 'wp-ai-client image generation API is unavailable' );
			}

			return call_user_func( $image_generator );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wp_ai_client_image_exception', 'wp-ai-client image generation threw: ' . $e->getMessage() );
		}
	}

	/**
	 * Build a function declaration from a JSON schema-shaped parameter array.
	 *
	 * @param string $name        Function name.
	 * @param string $description Function description.
	 * @param mixed  $parameters  JSON schema or property map.
	 * @return \WordPress\AiClient\Tools\DTO\FunctionDeclaration
	 */
	public static function function_declaration( string $name, string $description, $parameters ): \WordPress\AiClient\Tools\DTO\FunctionDeclaration {
		return new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
			$name,
			$description,
			self::ensure_json_schema( $parameters )
		);
	}

	/**
	 * Convert agent message envelopes into wp-ai-client message DTOs.
	 *
	 * @param array $messages Message envelopes.
	 * @return array{system: ?string, history: list<\WordPress\AiClient\Messages\DTO\Message>}
	 */
	private static function build_core_messages( array $messages ): array {
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
				$history[] = new \WordPress\AiClient\Messages\DTO\UserMessage( array( $part ) );
			}
		}

		return array(
			'system'  => empty( $system_parts ) ? null : implode( "\n\n", $system_parts ),
			'history' => $history,
		);
	}

	/**
	 * Convert runtime tool definitions into wp-ai-client function declarations.
	 *
	 * @param array $tools Runtime tools keyed by tool name.
	 * @return list<\WordPress\AiClient\Tools\DTO\FunctionDeclaration>
	 */
	private static function build_function_declarations( array $tools ): array {
		$declarations = array();

		foreach ( $tools as $tool_name => $tool_config ) {
			$name        = (string) ( $tool_config['name'] ?? $tool_name );
			$description = (string) ( $tool_config['description'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$declarations[] = self::function_declaration( $name, $description, $tool_config['parameters'] ?? array() );
		}

		return $declarations;
	}

	/**
	 * Detect message parts that the current wp-ai-client text path cannot express.
	 *
	 * @param array $messages Message envelopes or legacy role/content messages.
	 * @return string|null Failure reason, or null when the text path can proceed.
	 */
	private static function unsupported_message_part_reason( array $messages ): ?string {
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$type = is_string( $message['type'] ?? null ) ? $message['type'] : '';
			if ( AgentMessageEnvelope::TYPE_MULTIMODAL_PART === $type ) {
				return 'wp-ai-client text generation does not support multimodal message parts yet';
			}

			if ( is_array( $message['content'] ?? null ) ) {
				return 'wp-ai-client text generation does not support multimodal message parts yet';
			}
		}

		return null;
	}

	/**
	 * Normalize a successful wp-ai-client result into the Agents API runtime result shape.
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result The wp-ai-client result.
	 * @return array{success:bool,content:string,tool_calls:array,usage:array}
	 */
	private static function normalize_text_result( \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): array {
		$content    = '';
		$tool_calls = array();

		$candidates = $result->getCandidates();
		if ( ! empty( $candidates ) ) {
			$message = $candidates[0]->getMessage();
			foreach ( $message->getParts() as $part ) {
				$text = $part->getText();
				if ( null !== $text && '' !== $text ) {
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
						'parameters' => self::normalize_function_args( $function_call->getArgs() ),
						'id'         => $function_call->getId(),
					);
				}
			}
		}

		$token_usage = $result->getTokenUsage();

		return array(
			'success'    => true,
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'usage'      => array(
				'prompt_tokens'     => $token_usage->getPromptTokens(),
				'completion_tokens' => $token_usage->getCompletionTokens(),
				'total_tokens'      => $token_usage->getTotalTokens(),
			),
		);
	}

	/**
	 * Build a failed Agents API runtime result.
	 *
	 * @param string $message Error message.
	 * @return array{success:bool,content:string,tool_calls:array,usage:array,error:string}
	 */
	private static function error_result( string $message ): array {
		return array(
			'success'    => false,
			'content'    => '',
			'tool_calls' => array(),
			'usage'      => array(
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'total_tokens'      => 0,
			),
			'error'      => $message,
		);
	}

	/**
	 * Coerce wp-ai-client function call args into a parameter array.
	 *
	 * @param mixed $args Args returned by FunctionCall::getArgs().
	 * @return array
	 */
	private static function normalize_function_args( $args ): array {
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
	 * Build a ModelConfig from request generation parameters.
	 *
	 * @param array $request Request fields.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelConfig|null
	 */
	public static function model_config_from_request( array $request ): ?\WordPress\AiClient\Providers\Models\DTO\ModelConfig {
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
	 * Resolve a provider id exactly as registered with wp-ai-client.
	 *
	 * @param \WordPress\AiClient\Providers\ProviderRegistry $registry Provider registry.
	 * @param string                                          $provider Provider identifier.
	 * @return string Registered provider id.
	 */
	private static function registered_provider_id( \WordPress\AiClient\Providers\ProviderRegistry $registry, string $provider ): string {
		$registered = $registry->getRegisteredProviderIds();

		if ( in_array( $provider, $registered, true ) ) {
			return $provider;
		}

		throw new \InvalidArgumentException(
			sprintf( 'Provider %s is not registered in wp-ai-client', esc_html( $provider ) )
		);
	}

	/**
	 * Set provider authentication when an API key is available.
	 *
	 * @param \WordPress\AiClient\Providers\ProviderRegistry $registry    Provider registry.
	 * @param string                                          $provider_id Registered provider id.
	 * @param string                                          $api_key     API key.
	 */
	private static function set_authentication( \WordPress\AiClient\Providers\ProviderRegistry $registry, string $provider_id, string $api_key ): void {
		if ( '' === $api_key ) {
			return;
		}

		$registry->setProviderRequestAuthentication(
			$provider_id,
			new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $api_key )
		);
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
	private static function provider_model( \WordPress\AiClient\Providers\ProviderRegistry $registry, string $provider, string $model, $config ) {
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
	private static function call_builder_method( object $builder, string $method, ...$args ): object {
		$callback = array( $builder, $method );
		if ( ! is_callable( $callback ) ) {
			return $builder;
		}

		$result = call_user_func( $callback, ...$args );
		return is_object( $result ) ? $result : $builder;
	}

	/**
	 * Apply product aspect-ratio vocabulary to wp-ai-client media orientation.
	 *
	 * @param object $builder      Prompt builder.
	 * @param string $aspect_ratio Product-level aspect ratio.
	 * @return object Builder instance.
	 */
	private static function apply_image_aspect_ratio( object $builder, string $aspect_ratio ): object {
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
	 * Wrap a parameter array as a minimal JSON schema object when needed.
	 *
	 * @param mixed $parameters Raw parameters definition.
	 * @return array<string, mixed>|null
	 */
	private static function ensure_json_schema( $parameters ): ?array {
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

		return self::normalize_json_schema_required_flags( $schema );
	}

	/**
	 * Convert property-level required flags to JSON Schema object-level required list.
	 *
	 * @param array<string, mixed> $schema JSON schema.
	 * @return array<string, mixed>
	 */
	private static function normalize_json_schema_required_flags( array $schema ): array {
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
