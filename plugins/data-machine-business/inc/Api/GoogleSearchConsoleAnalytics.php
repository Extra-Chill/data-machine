<?php
/**
 * Google Search Console analytics REST endpoint.
 *
 * @package DataMachineBusiness\Api
 */

namespace DataMachineBusiness\Api;

use DataMachine\Abilities\PermissionHelper;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleSearchConsoleAnalytics {

	const ABILITY = 'datamachine/google-search-console';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'datamachine/v1',
			'/analytics/gsc',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'action' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'The Google Search Console action to perform.', 'data-machine-business' ),
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

	public static function handle_request( \WP_REST_Request $request ) {
		$ability = wp_get_ability( self::ABILITY );

		if ( ! $ability ) {
			return new \WP_Error(
				'ability_not_found',
				sprintf(
					/* translators: %s: Ability slug. */
					__( 'Analytics ability "%s" not registered. Ensure Data Machine Business is active and WordPress 6.9+ is available.', 'data-machine-business' ),
					self::ABILITY
				),
				array( 'status' => 500 )
			);
		}

		$input  = $request->get_json_params();
		$input  = is_array( $input ) ? $input : $request->get_params();
		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['success'] ) ) {
			$status = self::determine_error_status( $result['error'] ?? '' );
			return new \WP_Error(
				'analytics_error',
				$result['error'] ?? __( 'Analytics request failed.', 'data-machine-business' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response( $result );
	}

	private static function determine_error_status( string $error ): int {
		if ( false !== stripos( $error, 'not configured' ) || false !== stripos( $error, 'service account' ) ) {
			return 422;
		}

		if ( false !== stripos( $error, 'invalid action' ) || false !== stripos( $error, 'required' ) ) {
			return 400;
		}

		return 500;
	}
}
