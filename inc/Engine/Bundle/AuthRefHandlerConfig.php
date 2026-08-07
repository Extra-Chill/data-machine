<?php
/**
 * Handler config auth_ref export/import bridge.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\OAuth\BaseAuthProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges live handler configs to portable auth_ref handles.
 */
final class AuthRefHandlerConfig {

	private static bool $registered = false;

	/**
	 * Register default filter callbacks.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		add_filter( 'datamachine_handler_config_to_auth_ref', array( self::class, 'handler_config_to_auth_ref' ), 10, 3 );
		add_filter( 'datamachine_auth_ref_to_handler_config', array( self::class, 'auth_ref_to_handler_config' ), 10, 3 );

		self::$registered = true;
	}

	/**
	 * Rewrite inline credential config to auth_ref form for portable export.
	 *
	 * @param array|\WP_Error $handler_config Handler config or previous error.
	 * @param string          $handler_slug Handler slug.
	 * @param array           $context Export context.
	 * @return array|\WP_Error Rewritten config or original value.
	 */
	public static function handler_config_to_auth_ref( array|\WP_Error $handler_config, string $handler_slug, array $context = array() ): array|\WP_Error {
		if ( is_wp_error( $handler_config ) ) {
			return $handler_config;
		}

		if ( isset( $handler_config['auth_ref'] ) ) {
			return $handler_config;
		}

		$provider = self::provider_for_handler( $handler_slug );
		if ( ! $provider ) {
			return $handler_config;
		}

		$ref = $provider->get_auth_ref_for_config( $handler_config, $handler_slug, $context );
		if ( null === $ref || '' === trim( $ref ) ) {
			return $handler_config;
		}

		$parsed = self::parse_ref( $ref );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$rewritten             = $provider->strip_auth_config_secrets( $handler_config );
		$rewritten['auth_ref'] = $parsed->ref();

		ksort( $rewritten, SORT_STRING );
		return $rewritten;
	}

	/**
	 * Resolve auth_ref form to local handler config for import/runtime.
	 *
	 * @param array|\WP_Error $handler_config Handler config or previous error.
	 * @param string          $handler_slug Handler slug.
	 * @param array           $context Import/runtime context.
	 * @return array|\WP_Error Resolved config or failure.
	 */
	public static function auth_ref_to_handler_config( array|\WP_Error $handler_config, string $handler_slug, array $context = array() ): array|\WP_Error {
		if ( is_wp_error( $handler_config ) ) {
			return $handler_config;
		}

		$ref_string = $handler_config['auth_ref'] ?? null;
		if ( ! is_string( $ref_string ) || '' === trim( $ref_string ) ) {
			return $handler_config;
		}

		$ref = self::parse_ref( $ref_string );
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}

		$provider = self::provider_for_ref( $ref, $handler_slug );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$resolved = $provider->resolve_auth_ref( $ref->account(), $handler_slug, $context );
		if ( is_wp_error( $resolved ) ) {
			return self::redact_error( $resolved, $ref );
		}

		$static_config = $handler_config;
		unset( $static_config['auth_ref'] );
		$static_config = $provider->strip_auth_config_secrets( $static_config );

		return array_merge( $resolved, $static_config );
	}

	/**
	 * Resolve a handler config for runtime use.
	 *
	 * @param array  $handler_config Handler config.
	 * @param string $handler_slug Handler slug.
	 * @param array  $context Runtime context.
	 * @return array|\WP_Error Resolved handler config or failure.
	 */
	public static function resolve_runtime_config( array $handler_config, string $handler_slug, array $context = array() ): array|\WP_Error {
		$context['runtime'] = $context['runtime'] ?? true;
		return apply_filters( 'datamachine_auth_ref_to_handler_config', $handler_config, $handler_slug, $context );
	}

	private static function provider_for_handler( string $handler_slug ): ?BaseAuthProvider {
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProviderForHandler( $handler_slug );

		return $provider instanceof BaseAuthProvider ? $provider : null;
	}

	private static function provider_for_ref( AuthRef $ref, string $handler_slug ): BaseAuthProvider|\WP_Error {
		$auth_abilities   = new AuthAbilities();
		$handler_provider = $auth_abilities->getProviderForHandler( $handler_slug );
		$ref_provider     = $auth_abilities->getProvider( $ref->provider() );

		if ( ! $ref_provider instanceof BaseAuthProvider ) {
			return new \WP_Error(
				'auth_ref_unresolved',
				sprintf(
					/* translators: 1: auth ref, 2: provider slug. */
					__( 'Auth ref "%1$s" cannot be resolved because provider "%2$s" is not registered on this install.', 'data-machine' ),
					$ref->ref(),
					$ref->provider()
				)
			);
		}

		if ( $handler_provider instanceof BaseAuthProvider && $handler_provider->get_provider_slug() !== $ref_provider->get_provider_slug() ) {
			return new \WP_Error(
				'auth_ref_provider_mismatch',
				sprintf(
					/* translators: 1: auth ref, 2: handler slug. */
					__( 'Auth ref "%1$s" does not match handler "%2$s".', 'data-machine' ),
					$ref->ref(),
					$handler_slug
				)
			);
		}

		return $ref_provider;
	}

	private static function parse_ref( string $ref ): AuthRef|\WP_Error {
		try {
			return AuthRef::from_string( $ref );
		} catch ( BundleValidationException $e ) {
			return new \WP_Error( 'auth_ref_invalid', $e->getMessage() );
		}
	}

	private static function redact_error( \WP_Error $error, AuthRef $ref ): \WP_Error {
		$error_code = $error->get_error_code();
		if ( '' === $error_code ) {
			$error_code = 'auth_ref_unresolved';
		}

		return new \WP_Error(
			$error_code,
			sprintf(
				/* translators: %s: auth ref. */
				__( 'Auth ref "%s" could not be resolved on this install.', 'data-machine' ),
				$ref->ref()
			)
		);
	}
}
