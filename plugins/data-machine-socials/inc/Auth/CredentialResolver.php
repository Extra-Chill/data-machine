<?php
/**
 * Credential Resolver
 *
 * The single place where Data Machine Socials turns a platform key into an
 * auth provider, an access token, and provider config. Abilities (through the
 * AbstractSocialAbility helpers or, for static callbacks, directly), publish
 * handlers, CLI commands, and REST endpoints all resolve credentials here so
 * the lookup, authentication, and token rules live in exactly one
 * implementation.
 *
 * Every failure is a `missing_auth` WP_Error with HTTP status 401:
 *
 * - "<Label> not authenticated" when the provider is not registered, is not
 *   an instance of the expected class, or (when required) reports
 *   is_authenticated() === false.
 * - "<Label> access token is missing or expired..." when a token is required
 *   and the provider cannot produce a valid one.
 *
 * Secrets are resolved server-side and never travel through ability input.
 *
 * @package    DataMachineSocials
 * @subpackage Auth
 * @since      0.34.0
 */

namespace DataMachineSocials\Auth;

use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves auth providers, access tokens, and provider config.
 */
final class CredentialResolver {

	/**
	 * Error code shared by every resolution failure.
	 */
	public const ERROR_CODE = 'missing_auth';

	/**
	 * Every registered auth provider, keyed by provider key.
	 *
	 * Mirrors AuthAbilities::getAllProviders(), which is untyped.
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( ! class_exists( AuthAbilities::class ) ) {
			return array();
		}

		return ( new AuthAbilities() )->getAllProviders();
	}

	/**
	 * Resolve a platform's auth provider.
	 *
	 * @template T of object
	 *
	 * @param string               $platform              Provider key (e.g. 'threads').
	 * @param string               $label                 Human-readable platform label for errors.
	 * @param bool                 $require_authenticated When true, enforce is_authenticated(). Pass
	 *                                                    false for status checks and for token flows
	 *                                                    that must be allowed to refresh an expired
	 *                                                    token.
	 * @param class-string<T>|null $expected_class        Expected provider class, if any.
	 * @return ($expected_class is null ? object : T|\WP_Error) The provider, or a missing_auth WP_Error.
	 */
	public static function provider( string $platform, string $label, bool $require_authenticated = true, ?string $expected_class = null ): object {
		return self::check( self::lookup( $platform, false ), $label, $require_authenticated, $expected_class );
	}

	/**
	 * Resolve the auth provider backing a handler slug.
	 *
	 * Handlers may share a provider through `auth_provider_key`, so this goes
	 * through AuthAbilities::getProviderForHandler() rather than assuming the
	 * provider key equals the handler slug.
	 *
	 * @param string $handler_slug          Handler slug (e.g. 'instagram').
	 * @param string $label                 Human-readable label for errors.
	 * @param bool   $require_authenticated When true, enforce is_authenticated().
	 * @return object The provider, or a missing_auth WP_Error.
	 */
	public static function providerForHandler( string $handler_slug, string $label, bool $require_authenticated = true ): object {
		return self::check( self::lookup( $handler_slug, true ), $label, $require_authenticated, null );
	}

	/**
	 * Resolve a platform's provider plus a valid (refreshed if needed) access token.
	 *
	 * @template T of object
	 *
	 * @param string               $platform              Provider key.
	 * @param string               $label                 Human-readable platform label for errors.
	 * @param bool                 $require_authenticated When true, enforce is_authenticated().
	 * @param class-string<T>|null $expected_class        Expected provider class, if any.
	 * @return ($expected_class is null ? array{provider: object, token: string}|\WP_Error : array{provider: T, token: string}|\WP_Error)
	 */
	public static function token( string $platform, string $label, bool $require_authenticated = true, ?string $expected_class = null ): array|\WP_Error {
		$provider = self::provider( $platform, $label, false, $expected_class );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		// Providers with a token lifecycle skip the is_authenticated() gate:
		// is_authenticated() turns false the moment the access token expires,
		// which would reject the request before get_valid_access_token() could
		// use the stored refresh token. The token lookup is the authority.
		if ( $require_authenticated && ! method_exists( $provider, 'get_valid_access_token' ) ) {
			$provider = self::check( $provider, $label, true, $expected_class );
			if ( is_wp_error( $provider ) ) {
				return $provider;
			}
		}

		$token = self::accessToken( $provider );

		if ( '' === $token ) {
			return self::error( $label . ' access token is missing or expired. Re-authorize in WP Admin > Data Machine > Settings.' );
		}

		return array(
			'provider' => $provider,
			'token'    => $token,
		);
	}

	/**
	 * Resolve a platform's provider, access token, and provider config.
	 *
	 * @template T of object
	 *
	 * @param string               $platform              Provider key.
	 * @param string               $label                 Human-readable platform label for errors.
	 * @param bool                 $require_authenticated When true, enforce is_authenticated().
	 * @param class-string<T>|null $expected_class        Expected provider class, if any.
	 * @return ($expected_class is null ? array{provider: object, token: string, config: array<string, mixed>}|\WP_Error : array{provider: T, token: string, config: array<string, mixed>}|\WP_Error)
	 */
	public static function credentials( string $platform, string $label, bool $require_authenticated = true, ?string $expected_class = null ): array|\WP_Error {
		$resolved = self::token( $platform, $label, $require_authenticated, $expected_class );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$resolved['config'] = self::config( $resolved['provider'] );

		return $resolved;
	}

	/**
	 * Resolve whatever credentials exist, without treating absence as an error.
	 *
	 * For callers with a non-OAuth fallback (e.g. YouTube search's API key):
	 * the provider is null when it is not registered (or not an instance of
	 * $expected_class), the token is '' unless the provider is authenticated
	 * and can produce a valid token, and config is the provider's stored config.
	 *
	 * @template T of object
	 *
	 * @param string               $platform       Provider key.
	 * @param class-string<T>|null $expected_class Expected provider class, if any.
	 * @return ($expected_class is null ? array{provider: object|null, token: string, config: array<string, mixed>} : array{provider: T|null, token: string, config: array<string, mixed>})
	 */
	public static function optional( string $platform, ?string $expected_class = null ): array {
		$provider = self::provider( $platform, $platform, false, $expected_class );
		if ( is_wp_error( $provider ) ) {
			return array(
				'provider' => null,
				'token'    => '',
				'config'   => array(),
			);
		}

		$authenticated = method_exists( $provider, 'is_authenticated' ) && $provider->is_authenticated();

		return array(
			'provider' => $provider,
			'token'    => $authenticated ? self::accessToken( $provider ) : '',
			'config'   => self::config( $provider ),
		);
	}

	/**
	 * Read a resolved provider's stored config.
	 *
	 * @param object $provider Auth provider.
	 * @return array<string, mixed>
	 */
	public static function config( object $provider ): array {
		if ( ! method_exists( $provider, 'get_config' ) ) {
			return array();
		}

		$config = $provider->get_config();

		return is_array( $config ) ? $config : array();
	}

	/**
	 * Ask a provider for a valid (refreshed if needed) access token.
	 *
	 * @param object $provider Auth provider.
	 * @return string Trimmed token, or '' when unavailable.
	 */
	private static function accessToken( object $provider ): string {
		$token = method_exists( $provider, 'get_valid_access_token' ) ? $provider->get_valid_access_token() : null;

		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Look a provider up in the Data Machine auth registry.
	 *
	 * @param string $key         Provider key or handler slug.
	 * @param bool   $for_handler Whether $key is a handler slug.
	 * @return object|null
	 */
	private static function lookup( string $key, bool $for_handler ): ?object {
		if ( ! class_exists( AuthAbilities::class ) ) {
			return null;
		}

		$auth = new AuthAbilities();

		return $for_handler ? $auth->getProviderForHandler( $key ) : $auth->getProvider( $key );
	}

	/**
	 * Validate a looked-up provider.
	 *
	 * @template T of object
	 *
	 * @param object|null          $provider              Looked-up provider.
	 * @param string               $label                 Human-readable label for errors.
	 * @param bool                 $require_authenticated When true, enforce is_authenticated().
	 * @param class-string<T>|null $expected_class        Expected provider class, if any.
	 * @return ($expected_class is null ? object : T|\WP_Error)
	 */
	private static function check( ?object $provider, string $label, bool $require_authenticated, ?string $expected_class ): object {
		if ( null === $provider || ( null !== $expected_class && ! $provider instanceof $expected_class ) ) {
			return self::error( $label . ' not authenticated' );
		}

		if ( $require_authenticated && ! ( method_exists( $provider, 'is_authenticated' ) && $provider->is_authenticated() ) ) {
			return self::error( $label . ' not authenticated' );
		}

		return $provider;
	}

	/**
	 * Build the shared missing_auth error.
	 *
	 * @param string $message Error message.
	 * @return \WP_Error
	 */
	private static function error( string $message ): \WP_Error {
		return new \WP_Error( self::ERROR_CODE, $message, array( 'status' => 401 ) );
	}
}
