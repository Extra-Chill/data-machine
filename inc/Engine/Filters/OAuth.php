<?php

/**
 * OAuth system registration and routing.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Legacy storage functions removed. Use BaseAuthProvider methods instead.
// datamachine_get_oauth_account, datamachine_save_oauth_account, etc.

function datamachine_register_oauth_system() {

	add_action(
		'init',
		function () {
			add_rewrite_rule(
				'^datamachine-auth/([^/]+)/?$',
				'index.php?datamachine_oauth_provider=$matches[1]',
				'top'
			);
		}
	);

	add_filter(
		'query_vars',
		function ( $vars ) {
			$vars[] = 'datamachine_oauth_provider';
			return $vars;
		}
	);

	add_action(
		'template_redirect',
		function () {
			$provider = get_query_var( 'datamachine_oauth_provider' );

			if ( ! $provider ) {
				return;
			}

			$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
			$auth_instance  = $auth_abilities->getProvider( $provider );

			if ( ! $auth_instance ) {
				$auth_instance = $auth_abilities->getProviderForHandler( $provider );
			}

			if ( ! $auth_instance || ! method_exists( $auth_instance, 'handle_oauth_callback' ) ) {
				wp_die( esc_html( 'Unknown OAuth provider.' ) );
			}

			/**
			 * Filters whether the current request is authorized to handle an OAuth callback.
			 *
			 * This filter is the PRIMARY AUTHORIZATION GATE for OAuth callback handling.
			 * Returning `true` allows the current request to execute the full OAuth callback
			 * flow for the given provider, which may write credentials to site options.
			 *
			 * WARNING: Providers MUST validate authorization specific to their use case
			 * (e.g. nonce in state param, ownership of the resource being connected).
			 * Do not blanket-return `true` without additional provider-level checks.
			 *
			 * The filter fires BEFORE provider lookup so that unknown-slug requests
			 * receive a 404 (not a 403) regardless of authorization state.
			 *
			 * @since 0.88.0
			 *
			 * @param bool   $can_handle     Whether the current user can handle the callback.
			 *                               Default: current_user_can( 'manage_options' ).
			 * @param string $provider_slug  The provider slug from the URL (e.g. 'instagram', 'twitter').
			 * @param array  $request_params The raw $_GET parameters for the callback request.
			 */
			$can_handle = apply_filters(
				'datamachine_oauth_can_handle_callback',
				current_user_can( 'manage_options' ),
				$provider,
				$_GET // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callbacks use provider-specific state validation, not WP nonces.
			);

			if ( ! $can_handle ) {
				wp_die(
					esc_html__( 'You do not have permission to perform this action.', 'data-machine' ),
					esc_html__( 'Permission Denied', 'data-machine' ),
					array( 'response' => 403 )
				);
			}

			$auth_instance->handle_oauth_callback();

			exit;
		},
		5
	);
}
