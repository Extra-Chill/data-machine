<?php
/**
 * Abstract Social Ability
 *
 * Base class for the social primitive abilities in inc/Abilities. Centralizes
 * the register scaffold (the `$registered` guard plus the
 * did_action/doing_action dispatch) and the auth-resolve preamble that every
 * platform ability repeated.
 *
 * HTTP transport (request dispatch + response normalization) is handled by the
 * shared DataMachine\Core\HttpClient, which every read, auth, and write ability
 * now calls directly — so the base no longer carries a wp_remote normalizer.
 *
 * The base intentionally exposes reusable helpers rather than a rigid template
 * method: the per-platform execute bodies differ substantially (endpoints,
 * payloads, multi-step media flows, error field paths), so subclasses keep
 * their own execute logic and opt into the shared scaffold via:
 *
 * - registerAbility()        Register one or more abilities once, guarded.
 * - resolveProvider()        Resolve + authenticate the platform provider.
 * - resolveAccessToken()     Resolve a valid OAuth access token server-side.
 * - apiError()               Build the standard api_error WP_Error.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities
 * @since      0.6.0
 */

namespace DataMachineSocials\Abilities;

use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base for social abilities.
 */
abstract class AbstractSocialAbility {

	/**
	 * Whether this ability has been registered.
	 *
	 * @var bool
	 */
	protected static bool $registered = false;

	/**
	 * Register the ability's wp_register_ability() calls exactly once.
	 *
	 * Wraps the duplicated registration scaffold: the static $registered guard,
	 * the optional WP_Ability availability check, and the
	 * did_action/doing_action dispatch onto wp_abilities_api_init.
	 *
	 * @param callable $register_callback Closure that performs the
	 *                                    wp_register_ability() call(s).
	 * @param bool     $require_wp_ability When true, bail if WP_Ability is not
	 *                                     yet available (matches the instance-
	 *                                     callback ability flavor).
	 * @return void
	 */
	protected function registerAbility( callable $register_callback, bool $require_wp_ability = false ): void {
		if ( $require_wp_ability && ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( static::$registered ) {
			return;
		}

		if ( did_action( 'wp_abilities_api_init' ) || doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}

		static::$registered = true;
	}

	/**
	 * Resolve and authenticate the platform auth provider.
	 *
	 * @param string $platform              Provider key (e.g. 'threads').
	 * @param string $label                 Human-readable platform label for errors.
	 * @param bool   $require_authenticated When true, enforce is_authenticated().
	 * @return object|\WP_Error The provider on success, WP_Error otherwise.
	 */
	protected function resolveProvider( string $platform, string $label, bool $require_authenticated = true ) {
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( $platform );

		if ( ! $provider ) {
			return new \WP_Error( 'missing_auth', $label . ' not authenticated', array( 'status' => 401 ) );
		}

		$is_authenticated = array( $provider, 'is_authenticated' );
		if ( $require_authenticated && ! ( is_callable( $is_authenticated ) && call_user_func( $is_authenticated ) ) ) {
			return new \WP_Error( 'missing_auth', $label . ' not authenticated', array( 'status' => 401 ) );
		}

		return $provider;
	}

	/**
	 * Resolve a valid OAuth access token for a platform, entirely server-side.
	 *
	 * Resolves the provider, then asks it for a valid (refreshed if needed)
	 * access token. Secrets never travel through ability input.
	 *
	 * Providers with a token lifecycle skip the is_authenticated() gate:
	 * is_authenticated() reports false once a token hard-expires, which would
	 * reject the request before get_valid_access_token() could use the stored
	 * refresh token. get_valid_access_token() is the authority here and
	 * returns null when no usable token can be obtained.
	 *
	 * @param string $platform Provider key (e.g. 'reddit').
	 * @param string $label    Human-readable platform label for errors.
	 * @return string|\WP_Error Access token, or a missing_auth WP_Error.
	 */
	protected function resolveAccessToken( string $platform, string $label ): string|\WP_Error {
		$provider = $this->resolveProvider( $platform, $label, false );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		if ( ! method_exists( $provider, 'get_valid_access_token' ) ) {
			return new \WP_Error( 'missing_auth', $label . ' not authenticated', array( 'status' => 401 ) );
		}

		$token = $provider->get_valid_access_token();
		if ( ! is_string( $token ) || '' === trim( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Could not obtain a valid ' . $label . ' access token.', array( 'status' => 401 ) );
		}

		return trim( $token );
	}

	/**
	 * Build the standard api_error WP_Error returned by social abilities.
	 *
	 * @param string $message Error message.
	 * @param int    $status  HTTP status to attach. Default 500.
	 * @return \WP_Error
	 */
	protected function apiError( string $message, int $status = 500 ): \WP_Error {
		return new \WP_Error( 'api_error', $message, array( 'status' => $status ) );
	}

	/**
	 * Extract a human-readable message from a provider ability result.
	 *
	 * @param array|\WP_Error $result   Provider result envelope or error.
	 * @param string          $fallback Message when the result carries none.
	 * @return string
	 */
	protected function errorMessage( $result, string $fallback ): string {
		return is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? $fallback );
	}
}
