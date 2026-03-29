<?php
/**
 * Tools REST API Endpoint
 *
 * Exposes general AI tools for frontend discovery.
 * Enables dynamic tool selection with configuration status.
 *
 * @package DataMachine\Api
 * @since 0.1.2
 */

namespace DataMachine\Api;

use WP_REST_Server;
use DataMachine\Api\Traits\HasRegister;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools API Handler
 *
 * Provides REST endpoint for general AI tool discovery and configuration status.
 */
class Tools {
	use HasRegister;


	/**
	 * Register REST API routes
	 *
	 * @since 0.1.2
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/tools',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_tools' ),
				'permission_callback' => '__return_true', // Public endpoint
				'args'                => array(
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'pipeline', 'chat', 'system' ),
						'required'    => false,
						'description' => 'Filter tools by execution context. Returns only tools available in the specified context.',
					),
				),
			)
		);
	}

	/**
	 * Get all registered general AI tools
	 *
	 * Returns tool metadata including labels, descriptions, and configuration status.
	 * Filters to only global tools (excludes handler-specific tools).
	 *
	 * @since 0.1.2
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response Tools response
	 */
	public static function handle_get_tools( $request ) {
		$context      = $request->get_param( 'context' );
		$tool_manager = new \DataMachine\Engine\AI\Tools\ToolManager();
		$tools        = $tool_manager->get_tools_for_api( $context );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $tools,
			)
		);
	}

	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'manage_flows' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage processed items.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
