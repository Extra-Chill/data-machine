<?php
/**
 * Analytics REST API Endpoints
 *
 * Provides REST API access to extension-backed analytics integrations.
 *
 * Each endpoint delegates to its respective ability via wp_get_ability().
 * All endpoints require manage_options capability.
 *
 * Extensions can add routes via datamachine_analytics_ability_map.
 *
 * @package DataMachine\Api
 * @since 0.31.0
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Analytics {

	/**
	 * Ability slugs mapped to their route names.
	 *
	 * @var array
	 */
	const ABILITY_MAP = array();

	/**
	 * Return analytics route-to-ability mappings.
	 *
	 * Extensions can add analytics routes without Data Machine core hardcoding
	 * named external integrations.
	 *
	 * @return array<string,string>
	 */
	private static function get_ability_map(): array {
		/**
		 * Filter analytics REST route ability mappings.
		 *
		 * Keys are route suffixes for /datamachine/v1/analytics/{key}; values are
		 * registered ability slugs.
		 *
		 * @param array<string,string> $ability_map Route-to-ability map.
		 */
		return apply_filters( 'datamachine_analytics_ability_map', self::ABILITY_MAP );
	}

	/**
	 * Register the API endpoints.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register REST API routes for all analytics tools.
	 */
	public static function register_routes() {
		foreach ( array_keys( self::get_ability_map() ) as $route ) {
			register_rest_route(
				'datamachine/v1',
				'/analytics/' . $route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'handle_request' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'action' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'The analytics action to perform.', 'data-machine' ),
						),
					),
				)
			);
		}
	}

	/**
	 * Check if user has permission to access analytics.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'view_analytics' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access analytics data.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle an analytics request by routing to the appropriate ability.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_request( $request ) {
		// Extract route name from the request path.
		$route = $request->get_route();
		$parts = explode( '/', trim( $route, '/' ) );
		$tool  = end( $parts );

		$ability_map = self::get_ability_map();

		if ( ! isset( $ability_map[ $tool ] ) ) {
			return new \WP_Error(
				'invalid_tool',
				__( 'Invalid analytics tool.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$ability_slug = $ability_map[ $tool ];
		$ability      = wp_get_ability( $ability_slug );

		if ( ! $ability ) {
			return new \WP_Error(
				'ability_not_found',
				sprintf(
					/* translators: %s: ability slug */
					__( 'Analytics ability "%s" not registered. Ensure WordPress 6.9+ and the ability class is loaded.', 'data-machine' ),
					$ability_slug
				),
				array( 'status' => 500 )
			);
		}

		$input  = $request->get_json_params();
		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'analytics_error',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		if ( ! empty( $result['error'] ) ) {
			$status = 400;
			$error  = strtolower( $result['error'] );
			if ( strpos( $error, 'not configured' ) !== false ) {
				$status = 422;
			}

			return new \WP_Error(
				'analytics_error',
				$result['error'],
				array( 'status' => $status )
			);
		}

		return rest_ensure_response( $result );
	}
}
