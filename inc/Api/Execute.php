<?php
/**
 * Unified execution endpoint for database flows and ephemeral workflows.
 *
 * Delegates to the datamachine/execute-workflow ability for all execution logic.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Api\Traits\HasRegister;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Execute {
	use HasRegister;


	/**
	 * Register execute REST route
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/execute',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_execute' ),
				'permission_callback' => function () {
					return PermissionHelper::can( 'manage_flows' );
				},
				'args'                => array(
					'flow_id'      => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => 'Database flow ID to execute',
					),
					'workflow'     => array(
						'type'        => 'object',
						'required'    => false,
						'description' => 'Ephemeral workflow structure',
					),
					'timestamp'    => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => 'Unix timestamp for delayed execution',
					),
					'initial_data' => array(
						'type'        => 'object',
						'required'    => false,
						'description' => 'Initial engine data to merge before workflow execution',
					),
					'dry_run'      => array(
						'type'        => 'boolean',
						'required'    => false,
						'default'     => false,
						'description' => 'Preview execution without creating posts (ephemeral workflows only)',
					),
				),
			)
		);
	}

	/**
	 * Handle execute endpoint requests
	 *
	 * Pure execution endpoint - handles immediate and delayed execution only.
	 * Delegates to datamachine/execute-workflow ability for all execution logic.
	 * For scheduling/recurring execution, use the /schedule endpoint.
	 */
	public static function handle_execute( $request ) {
		$flow_id      = $request->get_param( 'flow_id' );
		$workflow     = $request->get_param( 'workflow' );
		$timestamp    = $request->get_param( 'timestamp' );
		$initial_data = $request->get_param( 'initial_data' );
		$dry_run      = $request->get_param( 'dry_run' );

		$ability = wp_get_ability( 'datamachine/execute-workflow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Execute workflow ability not found', array( 'status' => 500 ) );
		}

		// Build input for ability
		$input = array();

		if ( $flow_id ) {
			$input['flow_id'] = (int) $flow_id;
		}

		if ( $workflow ) {
			$input['workflow'] = $workflow;
		}

		if ( $timestamp && is_numeric( $timestamp ) && (int) $timestamp > time() ) {
			$input['timestamp'] = (int) $timestamp;
		}

		if ( $initial_data && is_array( $initial_data ) ) {
			$input['initial_data'] = $initial_data;
		}

		if ( $dry_run ) {
			$input['dry_run'] = true;
		}

		$result = $ability->execute( $input );

		if ( ! ( $result['success'] ?? false ) ) {
			$status = 400;
			$error  = $result['error'] ?? __( 'Execution failed', 'data-machine' );

			if ( false !== strpos( $error, 'not found' ) ) {
				$status = 404;
			} elseif ( false !== strpos( $error, 'Failed to create' ) || false !== strpos( $error, 'not available' ) ) {
				$status = 500;
			}

			return new \WP_Error( 'execute_failed', $error, array( 'status' => $status ) );
		}

		// Build response data
		$response_data = array(
			'execution_type' => $result['execution_type'] ?? 'immediate',
			'execution_mode' => $result['execution_mode'] ?? 'unknown',
		);

		// Database flow fields
		if ( isset( $result['flow_id'] ) ) {
			$response_data['flow_id']   = $result['flow_id'];
			$response_data['flow_name'] = $result['flow_name'] ?? "Flow {$result['flow_id']}";
		}

		// Job ID(s)
		if ( isset( $result['job_id'] ) ) {
			$response_data['job_id'] = $result['job_id'];
		}
		if ( isset( $result['job_ids'] ) ) {
			$response_data['job_ids'] = $result['job_ids'];
			$response_data['count']   = $result['count'] ?? count( $result['job_ids'] );
		}

		// Ephemeral workflow fields
		if ( isset( $result['step_count'] ) ) {
			$response_data['step_count'] = $result['step_count'];
		}

		// Dry-run mode
		if ( isset( $result['dry_run'] ) && $result['dry_run'] ) {
			$response_data['dry_run'] = true;
		}

		// Delayed execution fields
		if ( isset( $result['timestamp'] ) ) {
			$response_data['timestamp']      = $result['timestamp'];
			$response_data['scheduled_time'] = $result['scheduled_time'] ?? wp_date( 'c', $result['timestamp'] );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $response_data,
				'message' => $result['message'] ?? __( 'Execution started', 'data-machine' ),
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
