<?php
/**
 * External login provider contract.
 *
 * @package DataMachine\Core\Auth
 */

namespace DataMachine\Core\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticates a human visitor with an external identity provider.
 *
 * Credential auth providers connect tools and store provider tokens. External
 * login providers prove a visitor identity, create/link a WordPress user, set
 * the site auth cookie, and return the visitor to the frontend.
 */
interface ExternalLoginProviderInterface {

	/**
	 * Stable provider slug, for example `wpcom`.
	 */
	public function get_slug(): string;

	/**
	 * Site-relative callback path handled by this provider.
	 */
	public function get_callback_path(): string;

	/**
	 * Handle a callback request.
	 *
	 * Return null when the request belongs to another provider or login intent
	 * sharing the same callback path.
	 *
	 * @param array<string,mixed> $request_params Sanitized request parameters.
	 * @return array{redirect_to:string,user_id?:int,migrated_sessions?:int}|WP_Error|null
	 */
	public function handle_external_login_callback( array $request_params );
}
