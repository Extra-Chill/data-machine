<?php
/**
 * REST API Authentication Endpoint
 *
 * Provides REST API access to OAuth and authentication operations.
 * Delegates to AuthAbilities for core logic.
 * Requires WordPress manage_options capability.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\AuthAbilities;
use WP_REST_Server;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Auth {

	private static ?AuthAbilities $abilities = null;

	private static function getAbilities(): AuthAbilities {
		if ( null === self::$abilities ) {
			self::$abilities = new AuthAbilities();
		}
		return self::$abilities;
	}

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register /datamachine/v1/auth endpoints
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/auth/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_list_providers' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/auth/(?P<handler_slug>[a-zA-Z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'handle_disconnect_account' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'handler_slug' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Handler identifier (e.g., twitter, facebook)', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'handle_save_auth_config' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'handler_slug' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Handler identifier', 'data-machine' ),
						),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/auth/(?P<handler_slug>[a-zA-Z0-9_\-]+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_check_oauth_status' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'handler_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Handler identifier', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to manage authentication
	 */
	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'manage_settings' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage authentication.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle account disconnection request
	 *
	 * DELETE /datamachine/v1/auth/{handler_slug}
	 */
	public static function handle_disconnect_account( $request ) {
		$handler_slug = sanitize_text_field( $request->get_param( 'handler_slug' ) );

		$result = self::getAbilities()->executeDisconnectAuth(
			array( 'handler_slug' => $handler_slug )
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			} elseif ( false !== strpos( $result['error'] ?? '', 'Failed to disconnect' ) ||
						false !== strpos( $result['error'] ?? '', 'does not support' ) ) {
				$status = 500;
			}

			return new \WP_Error(
				'disconnect_auth_error',
				$result['error'],
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => null,
				'message' => $result['message'],
			)
		);
	}

	/**
	 * Handle OAuth status check request
	 *
	 * GET /datamachine/v1/auth/{handler_slug}/status
	 */
	public static function handle_check_oauth_status( $request ) {
		$handler_slug = sanitize_text_field( $request->get_param( 'handler_slug' ) );

		$result = self::getAbilities()->executeGetAuthStatus(
			array( 'handler_slug' => $handler_slug )
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			} elseif ( false !== strpos( $result['error'] ?? '', 'generation' ) ) {
				$status = 500;
			}

			return new \WP_Error(
				'get_auth_status_error',
				$result['error'],
				array( 'status' => $status )
			);
		}

		$data = array(
			'handler_slug' => $result['handler_slug'] ?? $handler_slug,
		);

		if ( isset( $result['authenticated'] ) ) {
			$data['authenticated'] = $result['authenticated'];
		}

		if ( isset( $result['requires_auth'] ) ) {
			$data['requires_auth'] = $result['requires_auth'];
		}

		if ( isset( $result['message'] ) ) {
			$data['message'] = $result['message'];
		}

		if ( isset( $result['oauth_url'] ) ) {
			$data['oauth_url'] = $result['oauth_url'];
		}

		if ( isset( $result['instructions'] ) ) {
			$data['instructions'] = $result['instructions'];
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Handle auth configuration save request
	 *
	 * PUT /datamachine/v1/auth/{handler_slug}
	 */
	public static function handle_save_auth_config( $request ) {
		$handler_slug   = sanitize_text_field( $request->get_param( 'handler_slug' ) );
		$request_params = $request->get_params();

		unset( $request_params['handler_slug'] );

		$result = self::getAbilities()->executeSaveAuthConfig(
			array(
				'handler_slug' => $handler_slug,
				'config'       => $request_params,
			)
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			} elseif ( false !== strpos( $result['error'] ?? '', 'Failed to save' ) ||
						false !== strpos( $result['error'] ?? '', 'Could not retrieve' ) ) {
				$status = 500;
			}

			return new \WP_Error(
				'save_auth_config_error',
				$result['error'],
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => null,
				'message' => $result['message'],
			)
		);
	}

	/**
	 * List all registered auth providers with status and configuration.
	 *
	 * GET /datamachine/v1/auth/providers
	 *
	 * Returns each provider with its type (oauth2, oauth1, simple),
	 * authentication status, config fields, callback URL, and connected
	 * account details — everything the Settings UI needs.
	 *
	 * @since 0.44.1
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Provider list.
	 */
	public static function handle_list_providers( $request ) {
		$request;
		$abilities = self::getAbilities();
		$providers = $abilities->getAllProviders();

		$data = array();

		foreach ( $providers as $provider_key => $instance ) {
			$auth_type = 'simple';
			if ( $instance instanceof \DataMachine\Core\OAuth\BaseOAuth2Provider ) {
				$auth_type = 'oauth2';
			} elseif ( $instance instanceof \DataMachine\Core\OAuth\BaseOAuth1Provider ) {
				$auth_type = 'oauth1';
			}

			$is_authenticated = false;
			if ( method_exists( $instance, 'is_authenticated' ) ) {
				$is_authenticated = $instance->is_authenticated();
			}

			$entry = array(
				'provider_key'     => $provider_key,
				'label'            => ucfirst( str_replace( '_', ' ', $provider_key ) ),
				'auth_type'        => $auth_type,
				'is_configured'    => method_exists( $instance, 'is_configured' ) ? $instance->is_configured() : false,
				'is_authenticated' => $is_authenticated,
				'auth_fields'      => method_exists( $instance, 'get_config_fields' ) ? $instance->get_config_fields() : array(),
				'callback_url'     => null,
				'account_details'  => null,
			);

			if ( in_array( $auth_type, array( 'oauth1', 'oauth2' ), true ) && method_exists( $instance, 'get_callback_url' ) ) {
				$entry['callback_url'] = $instance->get_callback_url();
			}

			if ( $is_authenticated && method_exists( $instance, 'get_account_details' ) ) {
				$entry['account_details'] = $instance->get_account_details();
			}

			$data[] = $entry;
		}

		// Sort: authenticated first, then alphabetically by label.
		usort( $data, function ( $a, $b ) {
			if ( $a['is_authenticated'] !== $b['is_authenticated'] ) {
				return $a['is_authenticated'] ? -1 : 1;
			}
			return strcasecmp( $a['label'], $b['label'] );
		} );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}
}
