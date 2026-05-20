<?php
/**
 * PageSpeed REST API endpoint.
 *
 * @package DataMachineBusiness\Api
 */

namespace DataMachineBusiness\Api;

use DataMachine\Abilities\PermissionHelper;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class PageSpeedAnalytics {

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'datamachine/v1',
			'/analytics/pagespeed',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'action' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'The PageSpeed action to perform.', 'data-machine-business' ),
					),
				),
			)
		);
	}

	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'manage_flows' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access analytics data.', 'data-machine-business' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public static function handle_request( $request ) {
		$ability = wp_get_ability( 'datamachine/pagespeed' );

		if ( ! $ability ) {
			return new \WP_Error(
				'ability_not_found',
				__( 'PageSpeed ability not registered. Ensure Data Machine Business is active and WordPress 6.9+ is available.', 'data-machine-business' ),
				array( 'status' => 500 )
			);
		}

		$input  = $request->get_json_params();
		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'analytics_error', $result->get_error_message(), array( 'status' => 500 ) );
		}

		if ( ! empty( $result['error'] ) ) {
			return new \WP_Error( 'analytics_error', $result['error'], array( 'status' => 400 ) );
		}

		return rest_ensure_response( $result );
	}
}
