<?php
/**
 * Provider-turn result contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalizes provider-turn adapter results.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Provider_Turn_Result {

	/**
	 * Normalize provider-turn adapter output.
	 *
	 * The adapter reports one assistant turn only. The conversation loop owns
	 * continuation, mediated tool execution, transcript events, and final result
	 * assembly.
	 *
	 * @param array<mixed> $result Raw provider-turn adapter result.
	 * @return array<string, mixed> Normalized provider-turn result.
	 */
	public static function normalize( array $result ): array {
		$normalized = array(
			'content'               => self::string_value( $result['content'] ?? '' ),
			'message'               => null,
			'continuation_messages' => array(),
			'tool_calls'            => array(),
			'usage'                 => self::assoc_array( $result['usage'] ?? array(), 'usage' ),
			'request_metadata'      => self::assoc_array( $result['request_metadata'] ?? array(), 'request_metadata' ),
			'provider_diagnostics'  => self::assoc_array( $result['provider_diagnostics'] ?? array(), 'provider_diagnostics' ),
		);

		if ( isset( $result['message'] ) ) {
			if ( ! is_array( $result['message'] ) ) {
				throw self::invalid( 'message', 'must be an array when present' );
			}
			$normalized['message'] = WP_Agent_Message::normalize( $result['message'] );
		}

		if ( isset( $result['tool_calls'] ) ) {
			if ( ! is_array( $result['tool_calls'] ) ) {
				throw self::invalid( 'tool_calls', 'must be an array when present' );
			}
			$normalized['tool_calls'] = self::normalize_tool_calls( $result['tool_calls'] );
		} else {
			$normalized['tool_calls'] = self::extract_tool_calls( $result );
		}

		if ( isset( $result['continuation_messages'] ) ) {
			if ( ! is_array( $result['continuation_messages'] ) ) {
				throw self::invalid( 'continuation_messages', 'must be an array when present' );
			}
			$normalized['continuation_messages'] = WP_Agent_Message::normalize_many( $result['continuation_messages'] );
		}

		if ( isset( $result['failure'] ) ) {
			if ( ! is_array( $result['failure'] ) ) {
				throw self::invalid( 'failure', 'must be an array when present' );
			}
			$failure = self::assoc_array( $result['failure'], 'failure' );
			foreach ( array( 'type', 'message' ) as $field ) {
				if ( ! isset( $failure[ $field ] ) || ! is_string( $failure[ $field ] ) || '' === $failure[ $field ] ) {
					throw self::invalid( 'failure.' . $field, 'must be a non-empty string' );
				}
			}
			$normalized['failure'] = $failure;
		}

		return $normalized;
	}

	/**
	 * Extract canonical tool calls from a provider-turn result.
	 *
	 * Structured function calls are preferred. Text fallback parsing is bounded to
	 * explicit tool-call envelopes and whole-line named call forms so providers that
	 * emit tool calls as text can still participate in loop mediation.
	 *
	 * @param mixed $result Provider-turn array or wp-ai-client generative result.
	 * @return array<int, array<string, mixed>>
	 */
	public static function extract_tool_calls( $result ): array {
		if ( is_array( $result ) && isset( $result['tool_calls'] ) ) {
			return is_array( $result['tool_calls'] ) ? self::normalize_tool_calls( $result['tool_calls'] ) : array();
		}

		$texts      = array();
		$tool_calls = self::extract_structured_tool_calls( $result, $texts );
		if ( ! empty( $tool_calls ) ) {
			return $tool_calls;
		}

		if ( is_array( $result ) ) {
			foreach ( array( 'content', 'text', 'output_text' ) as $field ) {
				if ( isset( $result[ $field ] ) && is_string( $result[ $field ] ) ) {
					$texts[] = $result[ $field ];
				}
			}

			if ( isset( $result['message'] ) && is_array( $result['message'] ) && isset( $result['message']['content'] ) && is_string( $result['message']['content'] ) ) {
				$texts[] = $result['message']['content'];
			}
		}

		$extracted = array();
		foreach ( array_slice( array_unique( $texts ), 0, 8 ) as $text ) {
			if ( '' === trim( $text ) ) {
				continue;
			}

			$extracted = array_merge(
				$extracted,
				self::extract_xml_tool_calls( $text ),
				self::extract_json_tool_calls( $text ),
				self::extract_tag_tool_calls( $text ),
				self::extract_named_text_tool_calls( $text )
			);
		}

		return self::dedupe_tool_calls( $extracted );
	}

	/**
	 * Extract assistant text from a wp-ai-client result.
	 *
	 * Consumers that own dispatch (via the default adapter's dispatch-provider
	 * seam, or entirely outside the adapter) can reuse this normalization so they
	 * do not duplicate the result-text extraction boilerplate. Results without
	 * text content (tool-only turns) yield an empty string.
	 *
	 * @param mixed $result wp-ai-client GenerativeAiResult.
	 * @return string
	 */
	public static function result_text( $result ): string {
		if ( is_object( $result ) && method_exists( $result, 'toText' ) ) {
			try {
				$text = $result->toText();

				return is_string( $text ) ? $text : '';
			} catch ( \Throwable $error ) {
				// Results without text content (tool-only turns) throw; treat as empty.
				if ( false !== strpos( $error->getMessage(), 'No text content found' ) ) {
					return '';
				}

				throw $error;
			}
		}

		return '';
	}

	/**
	 * Normalize token usage from a wp-ai-client result.
	 *
	 * Consumers that own dispatch can reuse this normalization to produce the
	 * canonical `{prompt_tokens, completion_tokens, total_tokens}` usage shape
	 * without duplicating the token-usage extraction boilerplate.
	 *
	 * @param mixed $result wp-ai-client GenerativeAiResult.
	 * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
	 */
	public static function result_usage( $result ): array {
		$usage = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		);

		if ( ! is_object( $result ) || ! method_exists( $result, 'getTokenUsage' ) ) {
			return $usage;
		}

		$token_usage = $result->getTokenUsage();
		if ( ! is_object( $token_usage ) ) {
			return $usage;
		}

		if ( method_exists( $token_usage, 'getPromptTokens' ) ) {
			$prompt_tokens          = $token_usage->getPromptTokens();
			$usage['prompt_tokens'] = is_numeric( $prompt_tokens ) ? (int) $prompt_tokens : 0;
		}

		if ( method_exists( $token_usage, 'getCompletionTokens' ) ) {
			$completion_tokens          = $token_usage->getCompletionTokens();
			$usage['completion_tokens'] = is_numeric( $completion_tokens ) ? (int) $completion_tokens : 0;
		}

		if ( method_exists( $token_usage, 'getTotalTokens' ) ) {
			$total_tokens          = $token_usage->getTotalTokens();
			$usage['total_tokens'] = is_numeric( $total_tokens ) ? (int) $total_tokens : 0;
		}

		return $usage;
	}

	/**
	 * Normalize provider tool calls.
	 *
	 * @param array<mixed> $tool_calls Raw tool calls.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_tool_calls( array $tool_calls ): array {
		$normalized = array();
		foreach ( $tool_calls as $index => $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				throw self::invalid( 'tool_calls[' . $index . ']', 'must be an array' );
			}

			$tool_call = self::assoc_array( $tool_call, 'tool_calls[' . $index . ']' );
			$name      = $tool_call['name'] ?? $tool_call['tool_name'] ?? '';
			if ( ! is_string( $name ) || '' === $name ) {
				throw self::invalid( 'tool_calls[' . $index . '].name', 'must be a non-empty string' );
			}

			$parameters = $tool_call['parameters'] ?? array();
			if ( ! is_array( $parameters ) ) {
				throw self::invalid( 'tool_calls[' . $index . '].parameters', 'must be an array when present' );
			}

			$normalized_call = array(
				'name'       => $name,
				'parameters' => self::assoc_array( $parameters, 'tool_calls[' . $index . '].parameters' ),
			);

			if ( isset( $tool_call['id'] ) && is_string( $tool_call['id'] ) && '' !== $tool_call['id'] ) {
				$normalized_call['id'] = $tool_call['id'];
			}

			if ( isset( $tool_call['metadata'] ) && is_array( $tool_call['metadata'] ) ) {
				$normalized_call['metadata'] = self::assoc_array( $tool_call['metadata'], 'tool_calls[' . $index . '].metadata' );
			}

			$normalized[] = $normalized_call;
		}

		return $normalized;
	}

	/**
	 * Extract structured function calls from wp-ai-client-style results.
	 *
	 * @param mixed              $result Provider result.
	 * @param array<int,string>  $texts  Text parts discovered while scanning.
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_structured_tool_calls( $result, array &$texts ): array {
		$candidates = self::call_no_args( $result, 'getCandidates' );
		if ( ! is_array( $candidates ) || empty( $candidates ) ) {
			return array();
		}

		$tool_calls    = array();
		$scanned_parts = 0;
		foreach ( $candidates as $candidate ) {
			$message = self::call_no_args( $candidate, 'getMessage' );
			$parts   = self::call_no_args( $message, 'getParts' );
			if ( ! is_array( $parts ) ) {
				continue;
			}

			foreach ( $parts as $part ) {
				if ( $scanned_parts >= 64 ) {
					break 2;
				}

				++$scanned_parts;
				$function_call = self::call_no_args( $part, 'getFunctionCall' );
				if ( null !== $function_call ) {
					$name = self::call_no_args( $function_call, 'getName' );
					if ( is_string( $name ) && '' !== $name ) {
						$tool_calls[] = array(
							'name'       => $name,
							'parameters' => self::normalize_function_args( self::call_no_args( $function_call, 'getArgs' ) ),
							'id'         => self::call_no_args( $function_call, 'getId' ),
						);
					}
				}

				$text = self::call_no_args( $part, 'getText' );
				if ( is_string( $text ) ) {
					$texts[] = $text;
				}
			}
		}

		return self::normalize_tool_calls( $tool_calls );
	}

	/**
	 * Extract XML-style tool calls emitted as plain text.
	 *
	 * @param string $text Text candidate content.
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_xml_tool_calls( string $text ): array {
		if ( false === strpos( $text, '<function_calls>' ) || ! preg_match_all( '/<invoke\s+name=["\']([^"\']+)["\']\s*>(.*?)<\/invoke>/is', $text, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$tool_calls = array();
		foreach ( array_slice( $matches, 0, 16 ) as $index => $match ) {
			$name = self::clean_tool_name( (string) $match[1] );
			if ( '' === $name ) {
				continue;
			}

			$parameters = array();
			if ( preg_match_all( '/<parameter\s+name=["\']([^"\']+)["\']\s*>(.*?)<\/parameter>/is', (string) $match[2], $parameter_matches, PREG_SET_ORDER ) ) {
				foreach ( array_slice( $parameter_matches, 0, 32 ) as $parameter_match ) {
					$parameter_name = self::clean_parameter_name( (string) $parameter_match[1] );
					if ( '' === $parameter_name ) {
						continue;
					}

					$parameter_value               = function_exists( '\wp_strip_all_tags' )
						? \wp_strip_all_tags( (string) $parameter_match[2] )
						: (string) preg_replace( '/<[^>]*>/', '', (string) $parameter_match[2] );
					$parameters[ $parameter_name ] = html_entity_decode( trim( $parameter_value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}

			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'xml-tool-call-' . ( $index + 1 ),
			);
		}

		return $tool_calls;
	}

	/**
	 * Extract JSON tool calls emitted as text/code fences.
	 *
	 * @param string $text Text candidate content.
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_json_tool_calls( string $text ): array {
		$payloads = array();
		if ( false !== strpos( $text, '<tool_call>' ) && preg_match_all( '/<tool_call>\s*(.*?)\s*<\/tool_call>/is', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$payloads[] = (string) $match[1];
			}
		}

		if ( false !== strpos( $text, '```' ) && preg_match_all( '/```(?:json)?\s*(\{.*?\})\s*```/is', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$payloads[] = (string) $match[1];
			}
		}

		return self::tool_calls_from_json_payloads( $payloads, 'json-tool-call' );
	}

	/**
	 * Extract tag-style tool calls with JSON bodies.
	 *
	 * @param string $text Text candidate content.
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_tag_tool_calls( string $text ): array {
		if ( ! preg_match_all( '/<tool_call\s+name=["\']([^"\']+)["\']\s*>(.*?)<\/tool_call>/is', $text, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$tool_calls = array();
		foreach ( array_slice( $matches, 0, 16 ) as $index => $match ) {
			$name = self::clean_tool_name( (string) $match[1] );
			if ( '' === $name ) {
				continue;
			}

			$decoded      = json_decode( html_entity_decode( trim( (string) $match[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => is_array( $decoded ) ? self::assoc_array( $decoded, 'tag_tool_call.parameters' ) : array(),
				'id'         => 'tag-tool-call-' . ( $index + 1 ),
			);
		}

		return $tool_calls;
	}

	/**
	 * Extract a conservative whole-line named tool call form: `tool_name key=value`.
	 *
	 * @param string $text Text candidate content.
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_named_text_tool_calls( string $text ): array {
		$line = trim( $text );
		if ( str_contains( $line, "\n" ) || ! preg_match( '/^([A-Za-z0-9_\/.:-]+)\s+(.+)$/', $line, $matches ) ) {
			return array();
		}

		$name = self::clean_tool_name( (string) $matches[1] );
		if ( '' === $name || ! preg_match_all( '/([A-Za-z_][A-Za-z0-9_-]*)=("[^"]*"|\'[^\']*\'|[^\s]+)/', (string) $matches[2], $parameter_matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$parameters = array();
		foreach ( array_slice( $parameter_matches, 0, 32 ) as $parameter_match ) {
			$key = self::clean_parameter_name( (string) $parameter_match[1] );
			if ( '' === $key ) {
				continue;
			}

			$parameters[ $key ] = trim( (string) $parameter_match[2], "\"'" );
		}

		return empty( $parameters ) ? array() : array(
			array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'text-tool-call-1',
			),
		);
	}

	/**
	 * Convert JSON payloads into canonical tool calls.
	 *
	 * @param array<int,string> $payloads Raw JSON payload strings.
	 * @param string            $id_prefix Generated ID prefix.
	 * @return array<int, array<string, mixed>>
	 */
	private static function tool_calls_from_json_payloads( array $payloads, string $id_prefix ): array {
		$tool_calls = array();
		foreach ( array_slice( $payloads, 0, 16 ) as $index => $raw_payload ) {
			$payload = json_decode( html_entity_decode( trim( $raw_payload ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$calls = isset( $payload['tool_calls'] ) && is_array( $payload['tool_calls'] ) ? $payload['tool_calls'] : array( $payload );
			foreach ( array_slice( $calls, 0, 16 ) as $call_index => $call ) {
				if ( ! is_array( $call ) ) {
					continue;
				}

				$function   = isset( $call['function'] ) && is_array( $call['function'] ) ? $call['function'] : array();
				$raw_name   = $function['name'] ?? ( $call['name'] ?? '' );
				$name       = is_string( $raw_name ) ? self::clean_tool_name( $raw_name ) : '';
				$parameters = $function['arguments'] ?? ( $call['arguments'] ?? ( $call['parameters'] ?? array() ) );
				if ( '' === $name ) {
					continue;
				}

				$tool_calls[] = array(
					'name'       => $name,
					'parameters' => self::normalize_function_args( $parameters ),
					'id'         => isset( $call['id'] ) && is_string( $call['id'] ) && '' !== $call['id'] ? $call['id'] : $id_prefix . '-' . ( $index + 1 ) . '-' . ( $call_index + 1 ),
				);
			}
		}

		return $tool_calls;
	}

	/**
	 * Dedupe parser overlap while preserving first-seen order.
	 *
	 * @param array<int, array<string, mixed>> $tool_calls Tool calls.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupe_tool_calls( array $tool_calls ): array {
		$seen   = array();
		$result = array();
		foreach ( self::normalize_tool_calls( $tool_calls ) as $tool_call ) {
			if ( ! is_string( $tool_call['name'] ) || ! is_array( $tool_call['parameters'] ) ) {
				continue;
			}

			$key = $tool_call['name'] . '|' . wp_json_encode( $tool_call['parameters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$result[]     = $tool_call;
		}

		return $result;
	}

	/**
	 * Coerce provider function-call args into tool parameters.
	 *
	 * @param mixed $args Raw function-call args.
	 * @return array<string, mixed>
	 */
	private static function normalize_function_args( $args ): array {
		if ( is_array( $args ) ) {
			return self::assoc_array( $args, 'function_call.arguments' );
		}

		if ( is_string( $args ) && '' !== $args ) {
			$decoded = json_decode( $args, true );
			if ( is_array( $decoded ) ) {
				return self::assoc_array( $decoded, 'function_call.arguments' );
			}
		}

		if ( is_object( $args ) ) {
			return self::assoc_array( (array) $args, 'function_call.arguments' );
		}

		return array();
	}

	/**
	 * Call a no-argument object method when available.
	 *
	 * @param mixed  $candidate Object candidate.
	 * @param string $method Method name.
	 * @return mixed
	 */
	private static function call_no_args( $candidate, string $method ) {
		return is_object( $candidate ) && method_exists( $candidate, $method ) ? $candidate->{$method}() : null;
	}

	/**
	 * Normalize tool names without depending on WordPress sanitizers.
	 *
	 * @param string $name Raw tool name.
	 * @return string
	 */
	private static function clean_tool_name( string $name ): string {
		$name = trim( $name );
		return preg_match( '/^[A-Za-z0-9_\/.:-]{1,128}$/', $name ) ? $name : '';
	}

	/**
	 * Normalize parameter names without depending on WordPress sanitizers.
	 *
	 * @param string $name Raw parameter name.
	 * @return string
	 */
	private static function clean_parameter_name( string $name ): string {
		$name = trim( $name );
		return preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,127}$/', $name ) ? $name : '';
	}

	/**
	 * Normalize an associative array.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $path  Field path.
	 * @return array<string, mixed>
	 */
	private static function assoc_array( $value, string $path ): array {
		if ( ! is_array( $value ) ) {
			throw self::invalid( $path, 'must be an array' );
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		if ( false === wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) {
			throw self::invalid( $path, 'must be JSON serializable' );
		}

		return $normalized;
	}

	/**
	 * Return a string value or an empty string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function string_value( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Build a machine-readable validation exception.
	 *
	 * @param string $path Field path.
	 * @param string $reason Failure reason.
	 * @return \InvalidArgumentException Validation exception.
	 */
	private static function invalid( string $path, string $reason ): \InvalidArgumentException {
		return new \InvalidArgumentException( 'invalid_agent_provider_turn_result: ' . $path . ' ' . $reason );
	}
}
