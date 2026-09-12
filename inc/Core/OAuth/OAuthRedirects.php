<?php
/**
 * OAuth redirect helpers.
 *
 * Shared by OAuth1 and OAuth2 callback handlers.
 *
 * @package DataMachine\Core\OAuth
 */

namespace DataMachine\Core\OAuth;

defined( 'ABSPATH' ) || exit;

trait OAuthRedirects {

	/**
	 * Redirect to admin with error message.
	 *
	 * @param string $provider_key Provider identifier.
	 * @param string $error_code Error code.
	 * @return void
	 */
	private function redirect_with_error( string $provider_key, string $error_code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'datamachine-settings',
					'auth_error' => $error_code,
					'provider'   => $provider_key,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect to admin with success message.
	 *
	 * @param string $provider_key Provider identifier.
	 * @return void
	 */
	private function redirect_with_success( string $provider_key ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'datamachine-settings',
					'auth_success' => '1',
					'provider'     => $provider_key,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
