<?php
/**
 * External login callback router.
 *
 * @package DataMachine\Core\Auth
 */

namespace DataMachine\Core\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Routes external human-login callbacks independently from credential auth.
 */
final class ExternalLoginRouter {

	/**
	 * Register rewrite and direct path handling.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_callback' ), 0 );
	}

	/**
	 * Register callback rewrites for known providers.
	 */
	public static function register_rewrites(): void {
		foreach ( self::providers() as $provider ) {
			$path = trim( $provider->get_callback_path(), '/' );
			if ( '' === $path ) {
				continue;
			}

			add_rewrite_rule(
				'^' . preg_quote( $path, '#' ) . '/?$',
				'index.php?datamachine_external_login_provider=' . rawurlencode( $provider->get_slug() ),
				'top'
			);
		}
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[] Query vars.
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'datamachine_external_login_provider';
		return $vars;
	}

	/**
	 * Handle a callback request when a registered provider claims it.
	 */
	public static function maybe_handle_callback(): void {
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( '' === $request_path ) {
			return;
		}

		$request_params = self::sanitize_request_params( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- External login callbacks validate OAuth state in provider handlers.

		foreach ( self::providers() as $provider ) {
			if ( untrailingslashit( $request_path ) !== untrailingslashit( $provider->get_callback_path() ) ) {
				continue;
			}

			$result = $provider->handle_external_login_callback( $request_params );
			if ( null === $result ) {
				continue;
			}

			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ), esc_html__( 'External sign-in failed', 'data-machine' ), array( 'response' => 400 ) );
			}

			$redirect_to = wp_validate_redirect( (string) ( $result['redirect_to'] ?? '' ), home_url( '/' ) );
			wp_safe_redirect( $redirect_to );
			exit;
		}
	}

	/**
	 * Return a provider callback URL.
	 */
	public static function callback_url( string $provider_slug ): string {
		$provider = self::provider( $provider_slug );
		return $provider ? site_url( $provider->get_callback_path() ) : '';
	}

	/**
	 * @return array<string,ExternalLoginProviderInterface>
	 */
	public static function providers(): array {
		/**
		 * Filters registered external human-login providers.
		 *
		 * @param array<string,ExternalLoginProviderInterface> $providers Providers keyed by slug.
		 */
		$providers = apply_filters( 'datamachine_external_login_providers', array() );
		if ( ! is_array( $providers ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof ExternalLoginProviderInterface ) {
				continue;
			}

			$slug = sanitize_key( $provider->get_slug() );
			if ( '' !== $slug ) {
				$normalized[ $slug ] = $provider;
			}
		}

		return $normalized;
	}

	public static function provider( string $provider_slug ): ?ExternalLoginProviderInterface {
		$providers = self::providers();
		$slug      = sanitize_key( $provider_slug );
		return $providers[ $slug ] ?? null;
	}

	/**
	 * @param array<string,mixed> $params Raw request params.
	 * @return array<string,mixed> Sanitized request params.
	 */
	private static function sanitize_request_params( array $params ): array {
		$sanitized = array();
		foreach ( $params as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$sanitized[ $key ] = is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
		}

		return $sanitized;
	}
}
