<?php
/**
 * Bootstrap-time request and dependency checks.
 *
 * @package DataMachine\Core\Bootstrap
 * @since   0.138.0
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes cheap bootstrap decisions so the plugin entry point stays thin.
 */
class RuntimeEnvironment {

	/**
	 * Determine whether the current request is a WordPress test bootstrap.
	 *
	 * @return bool True when the request is running under WordPress tests.
	 */
	public static function is_wordpress_tests(): bool {
		return defined( 'WP_TESTS_DOMAIN' )
			|| defined( 'WP_TESTS_CONFIG_FILE_PATH' )
			|| defined( 'WP_TESTS_EMAIL' )
			|| defined( 'WP_TESTS_TITLE' );
	}

	/**
	 * Determine whether the current request is WP-CLI.
	 *
	 * @return bool True when WP-CLI is active.
	 */
	public static function is_wp_cli(): bool {
		// @phpstan-ignore-next-line Runtime constant may be defined false outside PHPStan's configured CLI context.
		return defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' );
	}

	/**
	 * Determine whether the full Data Machine runtime is needed for this request.
	 *
	 * @return bool True when full runtime registration should run.
	 */
	public static function should_load_full_runtime(): bool {
		if ( self::is_wp_codebox_agent_runtime() ) {
			return true;
		}

		if ( self::is_wordpress_tests() || self::is_wp_cli() ) {
			return true;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( str_starts_with( $path, '/wp-json/' ) || str_starts_with( $path, '/datamachine-auth/' ) ) {
			return true;
		}

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Request-shape detection only.
			return true;
		}

		return (bool) apply_filters( 'datamachine_should_load_full_runtime', false );
	}

	/**
	 * Determine whether WP Codebox is executing an agent/runtime task.
	 *
	 * @return bool True when the host-owned execution context requires full runtime registration.
	 */
	private static function is_wp_codebox_agent_runtime(): bool {
		$value = getenv( 'WP_CODEBOX_AGENT_RUNTIME' );

		return is_string( $value ) && in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
