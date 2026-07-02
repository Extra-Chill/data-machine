<?php
/**
 * PHPStan function stub for the WordPress core wp-ai-client entrypoint.
 *
 * agents-api has no hard dependency on wp-ai-client; the default provider-turn
 * adapter reaches it only behind `function_exists()`/`class_exists()` guards at
 * runtime. This stub gives the static analyzer a type for the builder so the
 * generic mapping/dispatch logic can be checked at level max. It is a companion
 * to wp-ai-client-dtos.php, which carries the DTO class stubs (phpcs requires a
 * file to contain either OO structures or function declarations, not both).
 *
 * @see https://github.com/WordPress/wordpress-develop wp-includes/ai-client.php
 */

/**
 * @param mixed $prompt Optional initial prompt content.
 */
function wp_ai_client_prompt( $prompt = null ): WP_AI_Client_Prompt_Builder {
	unset( $prompt );
	return new WP_AI_Client_Prompt_Builder();
}
