<?php
/**
 * Shared auth resolution for Google Drive abilities.
 *
 * Every Drive ability needs the same dance: look up the unified Google
 * OAuth provider via the `datamachine_auth_providers` filter, confirm
 * the connected account has the Drive scope, and pull a refreshed
 * access token. This helper centralizes that so each ability stays
 * focused on its own input/output shape.
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\GoogleDrive
 * @since 0.4.0
 */

namespace DataMachineBusiness\Abilities\GoogleDrive;

use DataMachineBusiness\Handlers\GoogleDrive\GoogleDriveClient;
use DataMachineBusiness\OAuth\Providers\GoogleAuth;

defined( 'ABSPATH' ) || exit;

class AuthHelper {

	/**
	 * Resolve the unified Google OAuth provider instance, if registered.
	 */
	public static function get_provider(): ?GoogleAuth {
		$providers = apply_filters( 'datamachine_auth_providers', array() );
		$provider  = $providers['google'] ?? null;
		return $provider instanceof GoogleAuth ? $provider : null;
	}

	/**
	 * Obtain a valid Drive access token, or a WP_Error explaining why not.
	 *
	 * Surfaces three distinct error codes so callers can produce the right
	 * user-facing message:
	 * - `googledrive_auth_unavailable` — provider not registered (plugin
	 *   load order or missing GoogleAuth class).
	 * - `googledrive_scope_missing` — provider registered but the stored
	 *   token does not cover the Drive scope. User must disconnect and
	 *   reconnect.
	 * - any token-refresh WP_Error from GoogleAuth::get_service().
	 *
	 * @return string|\WP_Error Access token on success.
	 */
	public static function get_access_token() {
		$provider = self::get_provider();
		if ( ! $provider ) {
			return new \WP_Error(
				'googledrive_auth_unavailable',
				__( 'Google authentication provider is not registered.', 'data-machine-business' )
			);
		}

		if ( ! $provider->has_scope( GoogleDriveClient::REQUIRED_SCOPE ) ) {
			return new \WP_Error(
				'googledrive_scope_missing',
				sprintf(
					/* translators: %s: OAuth scope URL. */
					__( 'The connected Google account is missing the Drive scope (%s). Disconnect and reconnect the Google integration in Data Machine settings to grant Drive access.', 'data-machine-business' ),
					GoogleDriveClient::REQUIRED_SCOPE
				)
			);
		}

		$token = $provider->get_service();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		if ( ! is_string( $token ) || '' === $token ) {
			return new \WP_Error(
				'googledrive_token_missing',
				__( 'Failed to obtain a Google access token.', 'data-machine-business' )
			);
		}

		return $token;
	}
}
