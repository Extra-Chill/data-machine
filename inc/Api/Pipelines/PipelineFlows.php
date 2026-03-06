<?php
/**
 * REST API Pipeline Flows Endpoint
 *
 * Provides REST API access to pipeline-flow relationship operations.
 * Requires WordPress manage_options capability.
 *
 * @package DataMachine\Api\Pipelines
 */

namespace DataMachine\Api\Pipelines;

use DataMachine\Abilities\PermissionHelper;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class PipelineFlows {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register pipeline flows relationship endpoint
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/pipelines/(?P<pipeline_id>\d+)/flows',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_pipeline_flows' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Pipeline ID to retrieve flows for', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to access pipeline flows
	 */
	public static function check_permission( $request ) {
		if ( ! PermissionHelper::can( 'manage_flows' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access pipeline flows.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle pipeline flows retrieval request
	 */
	public static function handle_get_pipeline_flows( $request ) {
		$pipeline_id = (int) $request->get_param( 'pipeline_id' );

		// Retrieve flows for pipeline via filter
		$db_flows       = new \DataMachine\Core\Database\Flows\Flows();
		$db_pipelines   = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$pipeline_flows = $db_flows->get_flows_for_pipeline( $pipeline_id );

		// Verify pipeline exists by checking if it has any data
		$pipeline = $db_pipelines->get_pipeline( $pipeline_id );
		if ( ! $pipeline ) {
			return new \WP_Error(
				'pipeline_not_found',
				__( 'Pipeline not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$first_flow_id = null;
		if ( ! empty( $pipeline_flows ) ) {
			$first_flow_id = $pipeline_flows[0]['flow_id'] ?? null;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'pipeline_id'   => $pipeline_id,
					'flows'         => $pipeline_flows,
					'flow_count'    => count( $pipeline_flows ),
					'first_flow_id' => $first_flow_id,
				),
			)
		);
	}
}
