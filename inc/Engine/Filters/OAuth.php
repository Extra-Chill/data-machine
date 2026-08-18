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
			$request_params = array_merge(
				$_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state is verified before agent callback authorization.
				$_POST // phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth state is verified before agent callback authorization.
			);
			$can_handle     = current_user_can( 'manage_options' );
			$agent_callback = false;

			if ( $auth_instance instanceof \DataMachine\Core\OAuth\BaseOAuth2Provider && $auth_instance->supports_agent_scoped_oauth_callback() ) {
				$is_implicit_initial_request = 'token' === $auth_instance->get_oauth_response_type() && empty( $_POST['datamachine_implicit_flow'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only permits rendering a token/state relay page.
				$agent_callback             = $is_implicit_initial_request || false !== datamachine_oauth_agent_callback_payload( $auth_instance, $request_params );
				if ( ! $can_handle && $agent_callback ) {
					$can_handle = $is_implicit_initial_request || datamachine_oauth_can_handle_agent_scoped_callback( $auth_instance, $request_params );
				}
			}

			$can_handle = apply_filters(
				'datamachine_oauth_can_handle_callback',
				$can_handle,
				$provider,
				$request_params
			);

			if ( ! $can_handle ) {
				wp_die(
					esc_html__( 'You do not have permission to perform this action.', 'data-machine' ),
					esc_html__( 'Permission Denied', 'data-machine' ),
					array( 'response' => 403 )
				);
			}

			if ( $agent_callback ) {
				$auth_instance->handle_agent_scoped_oauth_callback();
			} else {
				$auth_instance->handle_oauth_callback();
			}

			exit;
		},
		5
	);
}

/**
 * Authorize an agent-scoped OAuth callback from a verified, unconsumed state.
 *
 * The central callback handler consumes state before it stores an account.
 * Unscoped state intentionally remains on the legacy site-admin path.
 *
 * @param \DataMachine\Core\OAuth\BaseOAuth2Provider $provider OAuth provider.
 * @param array                                         $request  Callback request parameters.
 * @return bool Whether the current user may complete this callback.
 */
function datamachine_oauth_can_handle_agent_scoped_callback( \DataMachine\Core\OAuth\BaseOAuth2Provider $provider, array $request ): bool {
	$payload = datamachine_oauth_agent_callback_payload( $provider, $request );
	if ( false === $payload ) {
		return false;
	}

	$agent_id = absint( $payload['agent_id'] ?? 0 );
	$user_id  = absint( $payload['user_id'] ?? 0 );
	if ( get_current_user_id() <= 0 ) {
		return false;
	}

	$agent = ( new \DataMachine\Core\Database\Agents\Agents() )->get_agent( $agent_id );
	if ( ! $agent || (int) $agent['owner_id'] !== $user_id ) {
		return false;
	}

	if ( get_current_user_id() === $user_id ) {
		return true;
	}

	return ( new \DataMachine\Core\Database\Agents\AgentAccess() )->user_can_access( $agent_id, get_current_user_id(), \WP_Agent_Access_Grant::ROLE_ADMIN );
}

/**
 * Return the verified agent-bound state payload without consuming it.
 *
 * @param \DataMachine\Core\OAuth\BaseOAuth2Provider $provider OAuth provider.
 * @param array                                         $request  Callback request parameters.
 * @return array|false Verified agent principal payload, or false.
 */
function datamachine_oauth_agent_callback_payload( \DataMachine\Core\OAuth\BaseOAuth2Provider $provider, array $request ) {
	$state = isset( $request['state'] ) ? sanitize_text_field( wp_unslash( $request['state'] ) ) : '';
	if ( '' === $state ) {
		return false;
	}

	$payload  = ( new \DataMachine\Core\OAuth\OAuth2Handler() )->peek_state( $provider->get_provider_slug(), $state );
	$agent_id = is_array( $payload ) ? absint( $payload['agent_id'] ?? 0 ) : 0;
	$user_id  = is_array( $payload ) ? absint( $payload['user_id'] ?? 0 ) : 0;
	if ( $agent_id <= 0 || $user_id <= 0 ) {
		return false;
	}

	return $payload;
}
