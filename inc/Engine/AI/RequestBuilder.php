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

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\Directives\DirectivePolicyResolver;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/WpAiClientCache.php';
require_once __DIR__ . '/DefaultOptionsHttpTransporter.php';

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
	 * request error. Data Machine uses this direct provider path for one-shot/pipeline
	 * requests; Agents API is only needed when callers require durable runtime semantics.
	 *
	 * @param array  $messages    Initial canonical message envelopes.
	 * @param string $provider    AI provider name (openai, anthropic, google, grok, openrouter)
	 * @param string $model       Model identifier
	 * @param array  $tools       Raw tools array from filters
	 * @param array  $modes       Execution modes.
	 * @param array  $payload     Step payload (session_id, job_id, flow_step_id, data, etc)
	 * @param array|null $request_metadata Optional output parameter for request metadata.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult|\WP_Error AI response from wp-ai-client.
	 */
	public static function build(
		array $messages,
		string $provider,
		string $model,
		array $tools,
		array $modes,
		array $payload = array(),
		?array &$request_metadata = null
	) {
		WpAiClientCache::install();

		$modes                 = self::normalizeModes( $modes );
		$mode_label            = implode( ',', $modes );
		$assembled             = self::assemble( $messages, $provider, $model, $tools, $modes, $payload );
		$request               = $assembled['request'];
		$structured_tools      = $assembled['structured_tools'];
		$provider_tool_aliases = self::providerToolNameAliases( $structured_tools );
		$provider_request      = ProviderRequestAssembler::toProviderRequest( $request );
		$prompt_context        = self::wpAiClientPromptContext( $request['messages'] ?? array(), $provider_tool_aliases['logical_to_provider'] );
		if ( '' !== $prompt_context['prompt'] ) {
			$provider_request['prompt'] = $prompt_context['prompt'];
		}
		$applied_directives = $assembled['applied_directives'];
		$directive_metadata = $assembled['directive_metadata'];

		$request_metadata = RequestMetadata::build(
			$provider_request,
			$structured_tools,
			$directive_metadata,
			$provider,
			$model,
			$mode_label
		);
		if ( ! empty( $provider_tool_aliases['logical_to_provider'] ) ) {
			$request_metadata['tool_name_aliases'] = $provider_tool_aliases;
		}
		RequestMetadata::warn_if_oversized( $request_metadata, $payload );

		do_action(
			'datamachine_log',
			'debug',
			'AI request built',
			array_filter(
				array(
					'mode'                  => $mode_label,
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

		$adapter_result = apply_filters( 'datamachine_ai_request_result', null, $provider_request, $provider, $model, $request, $request_metadata );
		if ( null !== $adapter_result ) {
			return self::normalizeAdapterResult( $adapter_result, $provider, $model );
		}

		// 4. Dispatch the request through Data Machine's default wp-ai-client adapter.
		$unavailable_reason = self::wpAiClientUnavailableReason( $provider );
		if ( null !== $unavailable_reason ) {
			do_action(
				'datamachine_log',
				'error',
				'AI request blocked: wp-ai-client unavailable',
				array_filter(
					array(
						'mode'         => $mode_label,
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

		$filtered_result = apply_filters( 'datamachine_wp_ai_client_text_result', null, $provider_request, $provider, $model, $request );
		if ( null !== $filtered_result ) {
			return self::normalizeAdapterResult( $filtered_result, $provider, $model );
		}

		$result                        = null;
		$request_options               = null;
		$transport_profile             = self::wpAiClientTransportProfile( $mode_label, $provider, $model, $payload );
		$request_timeout               = (float) $transport_profile['request_timeout'];
		$connect_timeout               = (float) $transport_profile['connect_timeout'];
		$request_metadata['transport'] = $transport_profile;
		if ( class_exists( '\WordPress\AiClient\Providers\Http\DTO\RequestOptions' ) ) {
			$request_options = new \WordPress\AiClient\Providers\Http\DTO\RequestOptions();
			$request_options->setTimeout( $request_timeout );
			$request_options->setConnectTimeout( $connect_timeout );
			$transport_profile['request_options_used'] = true;
			$request_metadata['transport']             = $transport_profile;
		}
		$timeout_filter = static function ( $default_timeout ) use ( $request_timeout ) {
			return max( (float) $default_timeout, $request_timeout );
		};
		$curl_filter    = static function ( $handle ) use ( $request_timeout, $connect_timeout ) {
			if ( defined( 'CURLOPT_CONNECTTIMEOUT' ) ) {
				curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, (int) ceil( $connect_timeout ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress exposes the cURL handle only through this hook.
			}

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
			$transport_profile['curl_hook_installed'] = true;
			$request_metadata['transport']            = $transport_profile;

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

			self::installDefaultRequestOptionsTransporter( $registry, $request_options );

			/** @var callable $model_resolver wp-ai-client exposes this through __call() in some versions. */
			$model_resolver = array( $registry, 'getProviderModel' );
			$model_instance = call_user_func( $model_resolver, $provider_id, $model, null );
			if ( null !== $request_options && is_object( $model_instance ) && method_exists( $model_instance, 'setRequestOptions' ) ) {
				$model_instance->setRequestOptions( $request_options );
			}

			do_action(
				'datamachine_log',
				'debug',
				'AI transport profile resolved',
				array_filter(
					$transport_profile,
					fn( $v ) => null !== $v
				)
			);

			// The current user message can be multimodal (text + file parts) for
			// vision-capable tasks like alt text generation. Build it from the
			// MessagePart[] returned by wpAiClientPromptContext() and attach via
			// with_message_parts(); fall back to an empty builder when there are
			// no parts and let history + system instruction carry the conversation.
			$prompt_parts = $prompt_context['prompt_parts'];
			$builder      = \wp_ai_client_prompt();
			if ( ! empty( $prompt_parts ) ) {
				$builder = $builder->with_message_parts( ...$prompt_parts );
			}
			if ( null !== $request_options && is_callable( array( $builder, 'using_request_options' ) ) ) {
				$builder = $builder->using_request_options( $request_options );
			}
			$builder = $builder->using_provider( $provider_id )
				->using_model( $model_instance );

			$model_config = self::productModelConfig( $provider_request );
			if ( null !== $model_config ) {
				$builder = $builder->using_model_config( $model_config );
			}

			if ( ! empty( $prompt_context['system_parts'] ) ) {
				$builder = $builder->using_system_instruction( implode( "\n\n", $prompt_context['system_parts'] ) );
			}

			if ( ! empty( $prompt_context['history'] ) ) {
				$builder = $builder->with_history( ...$prompt_context['history'] );
			}

			$function_declarations = array();
			foreach ( $structured_tools as $tool_name => $tool_config ) {
				$name = (string) ( $tool_config['name'] ?? $tool_name );
				if ( '' === $name ) {
					continue;
				}
				$provider_name = $provider_tool_aliases['logical_to_provider'][ $name ] ?? $name;

				$function_declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
					$provider_name,
					(string) ( $tool_config['description'] ?? '' ),
					ToolSchemaNormalizer::normalize( $tool_config['parameters'] ?? array() )
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

		$dispatch_context = array_filter(
			array_merge(
				$transport_profile,
				array(
					'success'       => ! is_wp_error( $result ),
					'error_code'    => is_wp_error( $result ) ? $result->get_error_code() : null,
					'error_message' => is_wp_error( $result ) ? $result->get_error_message() : null,
				)
			),
			fn( $v ) => null !== $v
		);

		do_action(
			'datamachine_log',
			is_wp_error( $result ) ? 'error' : 'debug',
			'AI request dispatched via wp-ai-client',
			$dispatch_context
		);

		return $result;
	}

	/**
	 * Convert Data Machine's canonical provider-message array into a wp-ai-client message DTO.
	 *
	 * Walks all content blocks (text, file, image_url) and builds a full
	 * MessagePart[] so multimodal context — including file/image attachments
	 * for vision-capable models — survives the conversion. Prior to 0.118.x
	 * this collapsed everything to a single text part, silently dropping
	 * file blocks and causing vision tasks (alt text generation, chat media)
	 * to hallucinate from filename context. See #2053.
	 *
	 * @param array $message Provider-message array.
	 * @return \WordPress\AiClient\Messages\DTO\Message|null Message DTO, or null when the shape is unsupported.
	 */
	private static function wpAiClientHistoryMessage( array $message, array $tool_name_aliases = array() ): ?\WordPress\AiClient\Messages\DTO\Message {
		$role  = (string) ( $message['role'] ?? '' );
		$parts = self::wpAiClientMessagePartsFromMessage( $message, $tool_name_aliases );
		if ( empty( $parts ) ) {
			return null;
		}

		if ( 'assistant' === $role || 'model' === $role ) {
			return new \WordPress\AiClient\Messages\DTO\ModelMessage( $parts );
		}

		if ( 'user' === $role ) {
			return new \WordPress\AiClient\Messages\DTO\UserMessage( $parts );
		}

		return null;
	}

	/**
	 * Split Data Machine messages into wp-ai-client's current prompt + history.
	 *
	 * wp-ai-client expects the current user turn passed to wp_ai_client_prompt()
	 * (or attached via with_message_parts()) and earlier turns supplied via
	 * with_history(). The latest user message becomes the current prompt; earlier
	 * conversational turns remain history.
	 *
	 * The current prompt is returned as a MessagePart[] so multimodal content
	 * (text + file) survives intact for vision tasks. A `prompt` string is also
	 * surfaced for legacy callers and request metadata, but the canonical input
	 * is `prompt_parts`.
	 *
	 * @param array $messages Canonical message envelopes.
	 * @return array{prompt:string,prompt_parts:array<int,\WordPress\AiClient\Messages\DTO\MessagePart>,system_parts:array<int,string>,history:array<int,\WordPress\AiClient\Messages\DTO\Message>}
	 */
	private static function wpAiClientPromptContext( array $messages, array $tool_name_aliases = array() ): array {
		$prompt_index = null;
		$prompt       = '';
		$prompt_parts = array();
		$system_parts = array();

		foreach ( $messages as $index => $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = (string) ( $message['role'] ?? '' );
			$content = $message['content'] ?? '';

			if ( 'system' === $role && is_string( $content ) ) {
				$system_parts[] = $content;
				continue;
			}

			if ( 'user' !== $role ) {
				continue;
			}

			$candidate_parts = self::wpAiClientMessagePartsFromMessage( $message, $tool_name_aliases );
			if ( ! empty( $candidate_parts ) ) {
				$prompt_index = $index;
				$prompt_parts = $candidate_parts;
				$prompt       = (string) self::wpAiClientMessageText( $content );
			}
		}

		$history = array();
		foreach ( $messages as $index => $message ) {
			if ( $index === $prompt_index || ! is_array( $message ) ) {
				continue;
			}

			$history_message = self::wpAiClientHistoryMessage( $message, $tool_name_aliases );
			if ( null !== $history_message ) {
				$history[] = $history_message;
			}
		}

		return array(
			'prompt'       => $prompt,
			'prompt_parts' => $prompt_parts,
			'system_parts' => $system_parts,
			'history'      => $history,
		);
	}

	/**
	 * Convert a canonical message envelope into wp-ai-client MessagePart objects.
	 *
	 * @param array $message Canonical message envelope or legacy role/content message.
	 * @return array<int,\WordPress\AiClient\Messages\DTO\MessagePart>
	 */
	private static function wpAiClientMessagePartsFromMessage( array $message, array $tool_name_aliases = array() ): array {
		try {
			$envelope = \AgentsAPI\AI\WP_Agent_Message::normalize( $message );
		} catch ( \Throwable $e ) {
			return self::wpAiClientMessageParts( $message['content'] ?? '' );
		}

		$type     = (string) ( $envelope['type'] ?? \AgentsAPI\AI\WP_Agent_Message::TYPE_TEXT );
		$payload  = is_array( $envelope['payload'] ?? null ) ? $envelope['payload'] : array();
		$metadata = is_array( $envelope['metadata'] ?? null ) ? $envelope['metadata'] : array();

		if ( \AgentsAPI\AI\WP_Agent_Message::TYPE_TOOL_CALL === $type ) {
			$tool_name = isset( $payload['tool_name'] ) ? (string) $payload['tool_name'] : '';
			$tool_name = $tool_name_aliases[ $tool_name ] ?? $tool_name;
			$call_id   = isset( $metadata['tool_call_id'] ) ? (string) $metadata['tool_call_id'] : '';
			if ( '' === $call_id ) {
				$call_id = isset( $payload['tool_call_id'] ) ? (string) $payload['tool_call_id'] : '';
			}
			if ( '' === $tool_name && '' === $call_id ) {
				return array();
			}

			$parameters = is_array( $payload['parameters'] ?? null ) ? $payload['parameters'] : array();
			return array(
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionCall(
						'' !== $call_id ? $call_id : null,
						'' !== $tool_name ? $tool_name : null,
						$parameters
					)
				),
			);
		}

		if ( \AgentsAPI\AI\WP_Agent_Message::TYPE_TOOL_RESULT === $type ) {
			$tool_name = isset( $payload['tool_name'] ) ? (string) $payload['tool_name'] : '';
			$tool_name = $tool_name_aliases[ $tool_name ] ?? $tool_name;
			$call_id   = isset( $metadata['tool_call_id'] ) ? (string) $metadata['tool_call_id'] : '';
			if ( '' === $call_id ) {
				$call_id = isset( $payload['tool_call_id'] ) ? (string) $payload['tool_call_id'] : '';
			}
			if ( '' === $tool_name && '' === $call_id ) {
				return array();
			}

			return array(
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionResponse(
						'' !== $call_id ? $call_id : null,
						'' !== $tool_name ? $tool_name : null,
						$payload
					)
				),
			);
		}

		return self::wpAiClientMessageParts( $envelope['content'] ?? '' );
	}

	/**
	 * Convert canonical content blocks into wp-ai-client MessagePart objects.
	 *
	 * Supports:
	 * - plain strings → text MessagePart
	 * - ['type' => 'text', 'text' => ...] / ['content' => ...] → text MessagePart
	 * - ['type' => 'file', 'file_path' => ..., 'mime_type' => ...] → file MessagePart (local path, base64-encoded by File DTO)
	 * - ['type' => 'image_url', 'image_url' => ['url' => ...]] → file MessagePart (remote URL)
	 *
	 * File blocks that point at non-existent paths or throw during File DTO
	 * construction are skipped with a logged warning rather than aborting the
	 * whole request — keeps text-only fallback behavior identical to the
	 * legacy path while letting valid file parts flow through to the model.
	 *
	 * @param mixed $content Message content (string or array of blocks).
	 * @return array<int,\WordPress\AiClient\Messages\DTO\MessagePart>
	 */
	private static function wpAiClientMessageParts( $content ): array {
		if ( is_string( $content ) ) {
			return '' !== $content
				? array( new \WordPress\AiClient\Messages\DTO\MessagePart( $content ) )
				: array();
		}

		if ( ! is_array( $content ) ) {
			return array();
		}

		$parts = array();
		foreach ( $content as $part ) {
			if ( is_string( $part ) && '' !== $part ) {
				$parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart( $part );
				continue;
			}

			if ( ! is_array( $part ) ) {
				continue;
			}

			$type = isset( $part['type'] ) ? (string) $part['type'] : '';

			if ( 'file' === $type ) {
				$file_part = self::buildFileMessagePart( $part );
				if ( null !== $file_part ) {
					$parts[] = $file_part;
				}
				continue;
			}

			if ( 'image_url' === $type ) {
				$file_part = self::buildImageUrlMessagePart( $part );
				if ( null !== $file_part ) {
					$parts[] = $file_part;
				}
				continue;
			}

			// Default to text extraction for unknown / text-typed parts.
			$text = $part['text'] ?? $part['content'] ?? null;
			if ( is_string( $text ) && '' !== $text ) {
				$parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart( $text );
			}
		}

		return $parts;
	}

	/**
	 * Build a file MessagePart from a canonical ['type' => 'file', ...] block.
	 *
	 * @param array $part Canonical content block.
	 * @return \WordPress\AiClient\Messages\DTO\MessagePart|null
	 */
	private static function buildFileMessagePart( array $part ): ?\WordPress\AiClient\Messages\DTO\MessagePart {
		$file_path = isset( $part['file_path'] ) ? (string) $part['file_path'] : '';
		$mime_type = isset( $part['mime_type'] ) ? (string) $part['mime_type'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'AI request: dropped file message part with missing or invalid path',
				array(
					'file_path' => $file_path,
					'mime_type' => $mime_type,
				)
			);
			return null;
		}

		try {
			$file = new \WordPress\AiClient\Files\DTO\File( $file_path, '' !== $mime_type ? $mime_type : null );
			return new \WordPress\AiClient\Messages\DTO\MessagePart( $file );
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'AI request: failed to build file message part',
				array(
					'file_path' => $file_path,
					'mime_type' => $mime_type,
					'error'     => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Build a file MessagePart from a canonical ['type' => 'image_url', ...] block.
	 *
	 * @param array $part Canonical content block.
	 * @return \WordPress\AiClient\Messages\DTO\MessagePart|null
	 */
	private static function buildImageUrlMessagePart( array $part ): ?\WordPress\AiClient\Messages\DTO\MessagePart {
		$url       = '';
		$mime_type = '';

		if ( isset( $part['image_url'] ) && is_array( $part['image_url'] ) ) {
			$url       = isset( $part['image_url']['url'] ) ? (string) $part['image_url']['url'] : '';
			$mime_type = isset( $part['image_url']['mime_type'] ) ? (string) $part['image_url']['mime_type'] : '';
		} elseif ( isset( $part['url'] ) ) {
			$url       = (string) $part['url'];
			$mime_type = isset( $part['mime_type'] ) ? (string) $part['mime_type'] : '';
		}

		if ( '' === $url ) {
			return null;
		}

		try {
			$file = new \WordPress\AiClient\Files\DTO\File( $url, '' !== $mime_type ? $mime_type : null );
			return new \WordPress\AiClient\Messages\DTO\MessagePart( $file );
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'AI request: failed to build image_url message part',
				array(
					'url'       => $url,
					'mime_type' => $mime_type,
					'error'     => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Extract text content from canonical message content shapes.
	 *
	 * Retained for callers that still need a flattened text view of a message
	 * (logging, the legacy `prompt` string in wpAiClientPromptContext, request
	 * metadata previews). The authoritative multimodal path is
	 * wpAiClientMessageParts().
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
	 * @param array  $modes    Execution modes.
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @param array  $payload  Step payload.
	 * @return float Timeout in seconds.
	 */
	private static function wpAiClientRequestTimeout( string $mode, string $provider, string $model, array $payload ): float {
		$setting_default = PluginSettings::get(
			'wp_ai_client_request_timeout',
			PluginSettings::DEFAULT_WP_AI_CLIENT_REQUEST_TIMEOUT
		);
		if ( ! is_numeric( $setting_default ) ) {
			$setting_default = PluginSettings::DEFAULT_WP_AI_CLIENT_REQUEST_TIMEOUT;
		}

		$default_timeout = max(
			0.0,
			min( PluginSettings::MAX_WP_AI_CLIENT_REQUEST_TIMEOUT, (float) $setting_default )
		);
		$timeout         = apply_filters(
			'datamachine_wp_ai_client_request_timeout',
			$default_timeout,
			$mode,
			$provider,
			$model,
			$payload
		);

		if ( ! is_numeric( $timeout ) ) {
			return $default_timeout;
		}

		return max( 0.0, (float) $timeout );
	}

	/**
	 * Resolve the connection timeout Data Machine applies to wp-ai-client calls.
	 *
	 * @param string $mode            Execution mode.
	 * @param string $provider        Provider identifier.
	 * @param string $model           Model identifier.
	 * @param array  $payload         Step payload.
	 * @param float  $request_timeout Resolved full request timeout in seconds.
	 * @return float Timeout in seconds.
	 */
	private static function wpAiClientConnectTimeout( string $mode, string $provider, string $model, array $payload, float $request_timeout ): float {
		$setting_default = PluginSettings::get(
			'wp_ai_client_connect_timeout',
			PluginSettings::DEFAULT_WP_AI_CLIENT_CONNECT_TIMEOUT
		);
		if ( ! is_numeric( $setting_default ) ) {
			$setting_default = PluginSettings::DEFAULT_WP_AI_CLIENT_CONNECT_TIMEOUT;
		}

		$default_timeout = min(
			max(
				0.0,
				min( PluginSettings::MAX_WP_AI_CLIENT_CONNECT_TIMEOUT, (float) $setting_default )
			),
			$request_timeout
		);
		$timeout         = apply_filters(
			'datamachine_wp_ai_client_connect_timeout',
			$default_timeout,
			$mode,
			$provider,
			$model,
			$payload,
			$request_timeout
		);

		if ( ! is_numeric( $timeout ) ) {
			return $default_timeout;
		}

		return max( 0.0, (float) $timeout );
	}

	/**
	 * Resolve the transport profile Data Machine applies to a wp-ai-client request.
	 *
	 * @param string $mode     Execution mode.
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @param array  $payload  Step payload.
	 * @return array<string,mixed> Resolved transport profile for logging and inspection.
	 */
	public static function wpAiClientTransportProfile( string $mode, string $provider, string $model, array $payload ): array {
		$request_timeout = self::wpAiClientRequestTimeout( $mode, $provider, $model, $payload );
		$connect_timeout = self::wpAiClientConnectTimeout( $mode, $provider, $model, $payload, $request_timeout );

		return array(
			'mode'                            => $mode,
			'provider'                        => $provider,
			'model'                           => $model,
			'job_id'                          => $payload['job_id'] ?? null,
			'flow_step_id'                    => $payload['flow_step_id'] ?? null,
			'request_timeout'                 => $request_timeout,
			'connect_timeout'                 => $connect_timeout,
			'request_options_class_available' => class_exists( '\\WordPress\\AiClient\\Providers\\Http\\DTO\\RequestOptions' ),
			'request_options_used'            => false,
			'curl_hook_installed'             => false,
		);
	}

	/**
	 * Build provider-safe function-name aliases for wp-ai-client tool declarations.
	 *
	 * Data Machine and Agents API use slash-bearing logical tool names such as
	 * `client/filesystem-write`. OpenAI-compatible providers reject those names, so
	 * the wp-ai-client boundary uses a request-local alias while the conversation
	 * loop maps returned calls back to the logical tool name before execution.
	 *
	 * @param array<string,array<string,mixed>> $tools Structured logical tools.
	 * @return array{logical_to_provider:array<string,string>,provider_to_logical:array<string,string>}
	 */
	public static function providerToolNameAliases( array $tools ): array {
		$logical_to_provider = array();
		$provider_to_logical = array();
		$used                = array();

		foreach ( $tools as $tool_name => $tool_config ) {
			$logical_name = (string) ( $tool_config['name'] ?? $tool_name );
			if ( '' === $logical_name ) {
				continue;
			}

			$provider_name = self::providerToolName( $logical_name, $tool_config );
			if ( isset( $used[ $provider_name ] ) && $used[ $provider_name ] !== $logical_name ) {
				$provider_name = self::uniqueProviderToolName( $provider_name, $logical_name, $used );
			}

			$used[ $provider_name ] = $logical_name;
			if ( $provider_name !== $logical_name ) {
				$logical_to_provider[ $logical_name ]  = $provider_name;
				$provider_to_logical[ $provider_name ] = $logical_name;
			}
		}

		$aliases = array(
			'logical_to_provider' => $logical_to_provider,
			'provider_to_logical' => $provider_to_logical,
		);

		return function_exists( 'apply_filters' )
			? apply_filters( 'datamachine_provider_tool_name_aliases', $aliases, $tools )
			: $aliases;
	}

	/**
	 * Resolve the provider-facing tool name for one logical tool declaration.
	 *
	 * @param string $logical_name Logical tool name.
	 * @param array<string,mixed> $tool_config Tool declaration.
	 * @return string Provider-safe tool name.
	 */
	private static function providerToolName( string $logical_name, array $tool_config ): string {
		$filtered_name = function_exists( 'apply_filters' )
			? apply_filters( 'datamachine_provider_tool_name', null, $logical_name, $tool_config )
			: null;
		if ( is_string( $filtered_name ) && self::isProviderSafeToolName( $filtered_name ) ) {
			return $filtered_name;
		}

		$runtime_tool_id = is_string( $tool_config['runtime_tool_id'] ?? null ) ? trim( (string) $tool_config['runtime_tool_id'] ) : '';
		if ( self::isProviderSafeToolName( $runtime_tool_id ) ) {
			return $runtime_tool_id;
		}

		if ( self::isProviderSafeToolName( $logical_name ) ) {
			return $logical_name;
		}

		$provider_name = preg_replace( '/[^a-zA-Z0-9_-]+/', '_', $logical_name );
		$provider_name = is_string( $provider_name ) ? trim( $provider_name, '_' ) : '';
		if ( '' === $provider_name || ! preg_match( '/^[a-zA-Z0-9_]/', $provider_name ) ) {
			$provider_name = 'tool_' . $provider_name;
		}

		return self::isProviderSafeToolName( $provider_name ) ? $provider_name : 'tool_' . substr( sha1( $logical_name ), 0, 12 );
	}

	/**
	 * Ensure an aliased provider tool name is unique within one request.
	 *
	 * @param string $provider_name Base provider name.
	 * @param string $logical_name Logical tool name.
	 * @param array<string,string> $used Provider names already assigned.
	 * @return string Unique provider-safe tool name.
	 */
	private static function uniqueProviderToolName( string $provider_name, string $logical_name, array $used ): string {
		$suffix = '_' . substr( sha1( $logical_name ), 0, 8 );
		$base   = substr( $provider_name, 0, max( 1, 64 - strlen( $suffix ) ) );
		$unique = $base . $suffix;
		$count  = 2;
		while ( isset( $used[ $unique ] ) ) {
			$extra  = '_' . $count;
			$unique = substr( $base, 0, max( 1, 64 - strlen( $suffix ) - strlen( $extra ) ) ) . $suffix . $extra;
			++$count;
		}

		return $unique;
	}

	/**
	 * Check the OpenAI-compatible provider function-name grammar.
	 *
	 * @param string $name Candidate name.
	 * @return bool Whether the name can be sent as a provider tool/function name.
	 */
	private static function isProviderSafeToolName( string $name ): bool {
		return '' !== $name && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $name );
	}

	/**
	 * Install a transporter that applies request options to provider metadata calls.
	 *
	 * wp-ai-client resolves API-backed models by listing provider models before a
	 * model instance exists, so model-level RequestOptions are too late for that
	 * discovery request. Wrap the registry transporter so model discovery and the
	 * final generation request share Data Machine's timeout profile.
	 *
	 * @param object $registry        wp-ai-client provider registry.
	 * @param mixed  $request_options wp-ai-client RequestOptions instance.
	 * @return void
	 */
	private static function installDefaultRequestOptionsTransporter( object $registry, $request_options ): void {
		if ( null === $request_options || ! method_exists( $registry, 'getHttpTransporter' ) || ! method_exists( $registry, 'setHttpTransporter' ) ) {
			return;
		}

		if ( ! interface_exists( '\WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface' ) || ! class_exists( '\DataMachine\Engine\AI\DefaultOptionsHttpTransporter' ) ) {
			return;
		}

		try {
			$current = $registry->getHttpTransporter();
		} catch ( \Throwable $e ) {
			if ( ! class_exists( '\WordPress\AiClient\Providers\Http\HttpTransporterFactory' ) ) {
				return;
			}

			try {
				$current = \WordPress\AiClient\Providers\Http\HttpTransporterFactory::createTransporter();
			} catch ( \Throwable $inner ) {
				return;
			}
		}

		if ( $current instanceof DefaultOptionsHttpTransporter ) {
			$current->setDefaultOptions( $request_options );
			return;
		}

		$registry->setHttpTransporter( new DefaultOptionsHttpTransporter( $current, $request_options ) );
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
		array $modes,
		array $payload = array()
	): array {
		$modes            = self::normalizeModes( $modes );
		$payload          = self::withDirectiveContext( array_merge( $payload, array( 'agent_modes' => $modes ) ) );
		$directives       = apply_filters( 'datamachine_directives', array() );
		$directive_policy = ( new DirectivePolicyResolver() )->resolve(
			$directives,
			array(
				'modes'    => $modes,
				'agent_id' => $payload['agent_id'] ?? 0,
			)
		);
		$directives       = $directive_policy['directives'];
		$suppressed       = $directive_policy['suppressed'];

		$assembled = ( new ProviderRequestAssembler() )->assemble( $messages, $provider, $model, $tools, $modes, $payload, $directives );

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
				'agent_slug'   => $payload['agent_slug'] ?? null,
			),
			fn( $value ) => null !== $value
		);

		if ( ! empty( $context ) ) {
			$payload['directive_context'] = $context;
		}

		return $payload;
	}

	/**
	 * Normalize an adapter/filter result into the wp-ai-client result contract.
	 *
	 * Custom dispatch adapters can return a WP_Error, a GenerativeAiResult, or compact
	 * response data matching the existing test adapter shape.
	 *
	 * @param mixed  $result   Adapter result.
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult|\WP_Error|mixed Normalized result when possible.
	 */
	private static function normalizeAdapterResult( $result, string $provider, string $model ) {
		if ( $result instanceof \WP_Error || $result instanceof \WordPress\AiClient\Results\DTO\GenerativeAiResult ) {
			return $result;
		}

		if ( is_array( $result ) ) {
			$data = $result['data'] ?? $result;
			if ( is_callable( array( '\WordPress\AiClient\Results\DTO\GenerativeAiResult', 'fromData' ) ) ) {
				return \WordPress\AiClient\Results\DTO\GenerativeAiResult::fromData( $data );
			}

			if ( is_callable( array( '\WordPress\AiClient\Results\DTO\GenerativeAiResult', 'fromArray' ) ) ) {
				return \WordPress\AiClient\Results\DTO\GenerativeAiResult::fromArray( self::wpAiClientResultArray( $data, $provider, $model ) );
			}
		}

		return $result;
	}

	/**
	 * Normalize compact test-dispatch response data into wp-ai-client's DTO array shape.
	 *
	 * @param array  $data     Compact response data.
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @return array wp-ai-client GenerativeAiResult array shape.
	 */
	private static function wpAiClientResultArray( array $data, string $provider, string $model ): array {
		$parts = array();
		if ( '' !== (string) ( $data['content'] ?? '' ) ) {
			$parts[] = array(
				'channel' => 'content',
				'type'    => 'text',
				'text'    => (string) $data['content'],
			);
		}

		foreach ( $data['tool_calls'] ?? array() as $tool_call ) {
			$parts[] = array(
				'channel'      => 'content',
				'type'         => 'function_call',
				'functionCall' => array_filter(
					array(
						'id'   => $tool_call['id'] ?? null,
						'name' => $tool_call['name'] ?? null,
						'args' => $tool_call['parameters'] ?? array(),
					),
					fn( $value ) => null !== $value
				),
			);
		}

		if ( empty( $parts ) ) {
			$parts[] = array(
				'channel' => 'content',
				'type'    => 'text',
				'text'    => '',
			);
		}

		return array(
			'id'               => 'datamachine-test-result',
			'candidates'       => array(
				array(
					'message'      => array(
						'role'  => 'model',
						'parts' => $parts,
					),
					'finishReason' => ! empty( $data['tool_calls'] ) ? 'tool_calls' : 'stop',
				),
			),
			'tokenUsage'       => array(
				'promptTokens'     => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
				'completionTokens' => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
				'totalTokens'      => (int) ( $data['usage']['total_tokens'] ?? 0 ),
			),
			'providerMetadata' => array(
				'id'   => $provider,
				'name' => $provider,
				'type' => 'cloud',
			),
			'modelMetadata'    => array(
				'id'                    => $model,
				'name'                  => $model,
				'supportedCapabilities' => array( 'text_generation' ),
				'supportedOptions'      => array(),
			),
		);
	}

	/** @return array<int,string> */
	private static function normalizeModes( mixed $modes ): array {
		if ( is_string( $modes ) ) {
			$modes = array( $modes );
		}
		if ( ! is_array( $modes ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $modes as $mode ) {
			if ( ! is_scalar( $mode ) ) {
				continue;
			}
			$mode = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $mode ) : strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $mode ) ?? '' );
			if ( '' !== $mode ) {
				$normalized[] = $mode;
			}
		}

		return array_values( array_unique( $normalized ) );
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
		$availability = apply_filters( 'datamachine_wp_ai_client_availability', null, $provider );
		if ( true === $availability ) {
			return null;
		}
		if ( is_string( $availability ) && '' !== $availability ) {
			return $availability;
		}

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

		WpAiClientCache::install();

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
		try {
			return (string) $result->toText();
		} catch ( \Throwable $e ) {
			if ( str_contains( $e->getMessage(), 'No text content found' ) ) {
				return '';
			}

			throw $e;
		}
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
}
