<?php
/**
 * Internal Links REST API Endpoints
 *
 * Provides REST API access to internal link audit and diagnostics:
 * - POST /datamachine/v1/links/audit    — Build + cache link graph.
 * - GET  /datamachine/v1/links/orphans  — Orphaned posts from cached graph.
 * - POST /datamachine/v1/links/broken   — HTTP HEAD check for broken links.
 * - GET  /datamachine/v1/links/diagnose — Meta-based coverage report.
 *
 * Each endpoint delegates to InternalLinkingAbilities via wp_get_ability().
 * All endpoints require manage_options capability.
 *
 * @package DataMachine\Api
 * @since 0.32.0
 */

namespace DataMachine\Api;

use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InternalLinks {

	/**
	 * Ability slugs mapped to their route names and HTTP methods.
	 *
	 * @var array
	 */
	const ROUTE_MAP = array(
		'audit'    => array(
			'ability' => 'datamachine/audit-internal-links',
			'method'  => WP_REST_Server::CREATABLE,
		),
		'orphans'  => array(
			'ability' => 'datamachine/get-orphaned-posts',
			'method'  => WP_REST_Server::READABLE,
		),
		'broken'   => array(
			'ability' => 'datamachine/check-broken-links',
			'method'  => WP_REST_Server::CREATABLE,
		),
		'diagnose' => array(
			'ability' => 'datamachine/diagnose-internal-links',
			'method'  => WP_REST_Server::READABLE,
		),
	);

	/**
	 * Register the API endpoints.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register REST API routes for internal link tools.
	 */
	public static function register_routes() {
		foreach ( self::ROUTE_MAP as $route => $config ) {
			register_rest_route(
				'datamachine/v1',
				'/links/' . $route,
				array(
					'methods'             => $config['method'],
					'callback'            => array( self::class, 'handle_request' ),
					'permission_callback' => array( self::class, 'check_permission' ),
				)
			);
		}
	}

	/**
	 * Check if user has permission to access internal link tools.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function check_permission( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access internal link tools.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle a request by routing to the appropriate ability.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_request( $request ) {
		// Extract route name from the request path.
		$route = $request->get_route();
		$parts = explode( '/', trim( $route, '/' ) );
		$tool  = end( $parts );

		if ( ! isset( self::ROUTE_MAP[ $tool ] ) ) {
			return new \WP_Error(
				'invalid_tool',
				__( 'Invalid internal links tool.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$ability_slug = self::ROUTE_MAP[ $tool ]['ability'];
		$ability      = wp_get_ability( $ability_slug );

		if ( ! $ability ) {
			return new \WP_Error(
				'ability_not_found',
				sprintf(
					/* translators: %s: ability slug */
					__( 'Internal links ability "%s" not registered. Ensure WordPress 6.9+ and InternalLinkingAbilities is loaded.', 'data-machine' ),
					$ability_slug
				),
				array( 'status' => 500 )
			);
		}

		// GET requests use query params, POST requests use JSON body.
		if ( $request->get_method() === 'GET' ) {
			$input = $request->get_query_params();
		} else {
			$input = $request->get_json_params();
		}

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'internal_links_error',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		if ( ! empty( $result['error'] ) ) {
			return new \WP_Error(
				'internal_links_error',
				$result['error'],
				array( 'status' => 400 )
			);
		}

		// Strip internal keys (prefixed with _) from REST response.
		$clean = array_filter(
			$result,
			fn( $key ) => 0 !== strpos( $key, '_' ),
			ARRAY_FILTER_USE_KEY
		);

		return rest_ensure_response( $clean );
	}
}
