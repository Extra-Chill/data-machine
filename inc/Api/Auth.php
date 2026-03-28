<?php
/**
 * REST API Authentication Endpoint
 *
 * Thin REST transport layer for authentication operations.
 * All business logic lives in AuthAbilities — this file only handles
 * HTTP concerns (route registration, request parsing, response formatting).
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\AuthAbilities;
use WP_REST_Server;
use DataMachine\Api\Traits\HasRegister;

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
		// List all providers.
		register_rest_route(
			'datamachine/v1',
			'/auth/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_list_providers' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		// Disconnect (DELETE) and save config (PUT) for a handler.
		register_rest_route(
			'datamachine/v1',
			'/auth/(?P<handler_slug>[a-zA-Z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'handle_disconnect_account' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => self::handler_slug_args(),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'handle_save_auth_config' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => self::handler_slug_args(),
				),
			)
		);

		// Get auth status for a handler.
		register_rest_route(
			'datamachine/v1',
			'/auth/(?P<handler_slug>[a-zA-Z0-9_\-]+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_check_oauth_status' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => self::handler_slug_args(),
			)
		);

		// Set token manually.
		register_rest_route(
			'datamachine/v1',
			'/auth/(?P<handler_slug>[a-zA-Z0-9_\-]+)/token',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'handle_set_token' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => self::handler_slug_args(),
			)
		);

		// Force token refresh.
		register_rest_route(
			'datamachine/v1',
			'/auth/(?P<handler_slug>[a-zA-Z0-9_\-]+)/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_refresh' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => self::handler_slug_args(),
			)
		);
	}

	/**
	 * Check if user has permission to manage authentication.
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
	 * List all registered auth providers.
	 *
	 * GET /datamachine/v1/auth/providers
	 */
	public static function handle_list_providers( $request ) {
		$request;
		$result = self::getAbilities()->executeListProviders( array() );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['providers'] ?? array(),
			)
		);
	}

	/**
	 * Handle account disconnection request.
	 *
	 * DELETE /datamachine/v1/auth/{handler_slug}
	 */
	public static function handle_disconnect_account( $request ) {
		$result = self::getAbilities()->executeDisconnectAuth(
			array( 'handler_slug' => sanitize_text_field( $request->get_param( 'handler_slug' ) ) )
		);

		return self::ability_to_response( $result, 'disconnect_auth_error' );
	}

	/**
	 * Handle OAuth status check request.
	 *
	 * GET /datamachine/v1/auth/{handler_slug}/status
	 */
	public static function handle_check_oauth_status( $request ) {
		$handler_slug = sanitize_text_field( $request->get_param( 'handler_slug' ) );

		$result = self::getAbilities()->executeGetAuthStatus(
			array( 'handler_slug' => $handler_slug )
		);

		if ( ! $result['success'] ) {
			return self::ability_to_response( $result, 'get_auth_status_error' );
		}

		// Pass through relevant fields from the ability result.
		$data = array( 'handler_slug' => $result['handler_slug'] ?? $handler_slug );

		foreach ( array( 'authenticated', 'requires_auth', 'message', 'oauth_url', 'instructions' ) as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$data[ $key ] = $result[ $key ];
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Handle auth configuration save request.
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

		return self::ability_to_response( $result, 'save_auth_config_error' );
	}

	/**
	 * Handle manual token injection.
	 *
	 * PUT /datamachine/v1/auth/{handler_slug}/token
	 */
	public static function handle_set_token( $request ) {
		$handler_slug = sanitize_text_field( $request->get_param( 'handler_slug' ) );
		$body         = $request->get_json_params();

		$result = self::getAbilities()->executeSetAuthToken(
			array(
				'handler_slug' => $handler_slug,
				'account_data' => $body,
			)
		);

		return self::ability_to_response( $result, 'set_auth_token_error' );
	}

	/**
	 * Handle forced token refresh.
	 *
	 * POST /datamachine/v1/auth/{handler_slug}/refresh
	 */
	public static function handle_refresh( $request ) {
		$handler_slug = sanitize_text_field( $request->get_param( 'handler_slug' ) );

		$result = self::getAbilities()->executeRefreshAuth(
			array( 'handler_slug' => $handler_slug )
		);

		return self::ability_to_response( $result, 'refresh_auth_error' );
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Common handler_slug route args definition.
	 *
	 * @return array Route args.
	 */
	private static function handler_slug_args(): array {
		return array(
			'handler_slug' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Handler identifier (e.g., twitter, facebook, linkedin)', 'data-machine' ),
			),
		);
	}

	/**
	 * Convert an ability result to a REST response.
	 *
	 * Success results are returned as 200 with the ability's data.
	 * Failure results are returned as WP_Error with an inferred HTTP status.
	 *
	 * @param array  $result     Ability result array.
	 * @param string $error_code WP_Error code for failures.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function ability_to_response( array $result, string $error_code ) {
		if ( ! empty( $result['success'] ) ) {
			return rest_ensure_response( $result );
		}

		$error  = $result['error'] ?? 'Unknown error';
		$status = 400;

		if ( str_contains( $error, 'not found' ) ) {
			$status = 404;
		} elseif ( str_contains( $error, 'not authenticated' ) || str_contains( $error, 'not currently authenticated' ) ) {
			$status = 409;
		} elseif ( str_contains( $error, 'Failed' ) || str_contains( $error, 'Could not' ) ) {
			$status = 500;
		}

		return new \WP_Error( $error_code, $error, array( 'status' => $status ) );
	}
}
