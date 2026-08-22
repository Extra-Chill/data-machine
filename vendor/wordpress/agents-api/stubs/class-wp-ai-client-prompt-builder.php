<?php
/**
 * PHPStan class stub for the WordPress core wp-ai-client prompt builder facade.
 *
 * agents-api has no hard dependency on wp-ai-client. This stub exists only so
 * the static analyzer can type the snake-case fluent builder the default
 * provider-turn adapter drives behind `function_exists()` guards. It mirrors
 * the real WordPress core facade (wp-includes/ai-client) but is never loaded at
 * runtime.
 *
 * @see https://github.com/WordPress/wordpress-develop wp-includes/ai-client/class-wp-ai-client-prompt-builder.php
 */

/**
 * Snake-case fluent prompt builder facade (WP core wp-includes/ai-client).
 */
class WP_AI_Client_Prompt_Builder {

	public function with_message_parts( object ...$parts ): self {
		unset( $parts );
		return $this;
	}

	public function using_provider( string $provider ): self {
		unset( $provider );
		return $this;
	}

	public function using_model( \WordPress\AiClient\Providers\Models\Contracts\ModelInterface $model ): self {
		unset( $model );
		return $this;
	}

	/**
	 * @param string|\WordPress\AiClient\Providers\Models\Contracts\ModelInterface|array{0:string,1:string} ...$preferred_models Preferred models.
	 */
	public function using_model_preference( ...$preferred_models ): self {
		unset( $preferred_models );
		return $this;
	}

	public function using_system_instruction( string $system_instruction ): self {
		unset( $system_instruction );
		return $this;
	}

	public function using_temperature( float $temperature ): self {
		unset( $temperature );
		return $this;
	}

	public function using_max_tokens( int $max_tokens ): self {
		unset( $max_tokens );
		return $this;
	}

	public function with_history( object ...$messages ): self {
		unset( $messages );
		return $this;
	}

	public function using_function_declarations( object ...$function_declarations ): self {
		unset( $function_declarations );
		return $this;
	}

	public function using_request_options( \WordPress\AiClient\Providers\Http\DTO\RequestOptions $options ): self {
		unset( $options );
		return $this;
	}

	/**
	 * @return object|\WP_Error wp-ai-client GenerativeAiResult or a WP_Error on failure.
	 */
	public function generate_text_result() {
		return new \stdClass();
	}
}
