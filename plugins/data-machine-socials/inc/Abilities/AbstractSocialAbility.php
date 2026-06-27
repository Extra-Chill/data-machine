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

		if ( $require_authenticated && ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', $label . ' not authenticated', array( 'status' => 401 ) );
		}

		return $provider;
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
}
