<?php
/**
 * wp-ai-client capability gate.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class WpAiClientCapability {

	/**
	 * Explain why wp-ai-client cannot handle this request.
	 *
	 * This is the single request-runtime capability gate. It intentionally does not
	 * preserve a secondary provider fallback path; unavailable wp-ai-client support is
	 * a configuration/runtime error that should surface before dispatch.
	 *
	 * @since next
	 *
	 * @param string $provider Provider identifier.
	 * @return string|null Human-readable failure reason, or null when available.
	 */
	public static function unavailableReason( string $provider ): ?string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return 'wp-ai-client is unavailable: wp_ai_client_prompt() is not defined';
		}

		if ( ! function_exists( 'wp_supports_ai' ) ) {
			return 'wp-ai-client is unavailable: wp_supports_ai() is not defined';
		}

		if ( ! wp_supports_ai() ) {
			return 'wp-ai-client is unavailable: WordPress reports AI support is disabled';
		}

		$ai_client_class = '\\WordPress\\AiClient\\AiClient';
		if ( ! class_exists( $ai_client_class ) ) {
			return 'wp-ai-client is unavailable: WordPress\\AiClient\\AiClient is not loaded';
		}

		try {
			$registry       = $ai_client_class::defaultRegistry();
			$registered_ids = $registry->getRegisteredProviderIds();
			$normalized_id  = self::normalizeProviderId( $provider );
		} catch ( \Throwable $e ) {
			return 'wp-ai-client provider registry failed: ' . $e->getMessage();
		}

		if ( in_array( $provider, $registered_ids, true ) || in_array( $normalized_id, $registered_ids, true ) ) {
			return null;
		}

		return sprintf( 'wp-ai-client provider "%s" is not registered', $provider );
	}

	/**
	 * Map Data Machine provider ids to wp-ai-client provider plugin ids.
	 *
	 * @param string $provider Provider identifier.
	 * @return string Normalized provider identifier.
	 */
	private static function normalizeProviderId( string $provider ): string {
		$map = array(
			'google' => 'gemini',
		);

		return $map[ $provider ] ?? $provider;
	}
}
