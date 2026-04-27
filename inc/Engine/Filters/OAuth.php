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

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html( 'Insufficient permissions for OAuth operations.' ) );
			}

			$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
			$auth_instance  = $auth_abilities->getProvider( $provider );

			if ( ! $auth_instance ) {
				$auth_instance = $auth_abilities->getProviderForHandler( $provider );
			}

			if ( $auth_instance && method_exists( $auth_instance, 'handle_oauth_callback' ) ) {
				$auth_instance->handle_oauth_callback();
			} else {
				wp_die( esc_html( 'Unknown OAuth provider.' ) );
			}

			exit;
		},
		5
	);
}
