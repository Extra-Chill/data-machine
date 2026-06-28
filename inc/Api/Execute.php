<?php
/**
 * Unified execution endpoint for database flows and ephemeral workflows.
 *
 * Routes database flows to datamachine/run-flow (immediate) or
 * datamachine/schedule-flow (delayed). Ephemeral workflows go to
 * datamachine/execute-workflow.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Execute {

	/**
	 * Initialize REST API hooks
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

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
					'count'        => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => 'Number of times to run (1-10, database flow only)',
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
	 * Handle execute endpoint requests.
	 *
	 * Routes to the appropriate ability:
	 * - flow_id → datamachine/run-flow (immediate) or datamachine/schedule-flow (delayed)
	 * - workflow → datamachine/execute-workflow (ephemeral)
	 */
	public static function handle_execute( $request ) {
		$flow_id  = $request->get_param( 'flow_id' );
		$workflow = $request->get_param( 'workflow' );

		if ( ! $flow_id && ! $workflow ) {
			return new \WP_Error( 'missing_input', 'Must provide either flow_id or workflow', array( 'status' => 400 ) );
		}

		if ( $flow_id && $workflow ) {
			return new \WP_Error( 'invalid_input', 'Cannot provide both flow_id and workflow', array( 'status' => 400 ) );
		}

		if ( $flow_id ) {
			return self::handle_flow_execution( $request );
		}

		return self::handle_ephemeral_execution( $request );
	}

	/**
	 * Handle database flow execution via datamachine/run-flow or schedule-flow.
	 */
	private static function handle_flow_execution( $request ) {
		$flow_id      = (int) $request->get_param( 'flow_id' );
		$timestamp    = $request->get_param( 'timestamp' );
		$initial_data = $request->get_param( 'initial_data' );
		$count        = max( 1, min( 10, (int) ( $request->get_param( 'count' ) ?? 1 ) ) );

		// Delayed execution → schedule-flow.
		if ( ! empty( $timestamp ) && is_numeric( $timestamp ) && (int) $timestamp > time() ) {
			if ( $count > 1 ) {
				return new \WP_Error(
					'invalid_input',
					'Cannot schedule multiple runs with a timestamp.',
					array( 'status' => 400 )
				);
			}

			$ability = wp_get_ability( 'datamachine/schedule-flow' );
			if ( ! $ability ) {
				return new \WP_Error( 'ability_not_found', 'Schedule flow ability not found', array( 'status' => 500 ) );
			}

			$result = $ability->execute(
				array(
					'flow_id'               => $flow_id,
					'interval_or_timestamp' => (int) $timestamp,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! ( $result['success'] ?? false ) ) {
				return new \WP_Error(
					'schedule_failed',
					$result['error'] ?? 'Failed to schedule flow',
					array( 'status' => 400 )
				);
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'execution_type' => 'delayed',
						'execution_mode' => 'database',
						'flow_id'        => $flow_id,
						'scheduled_time' => $result['scheduled_time'] ?? null,
					),
					'message' => 'Flow scheduled for delayed execution.',
				)
			);
		}

		// Immediate execution → run-flow (loop for count).
		$ability = wp_get_ability( 'datamachine/run-flow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Run flow ability not found', array( 'status' => 500 ) );
		}

		$job_ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$input = array( 'flow_id' => $flow_id );

			if ( $initial_data && is_array( $initial_data ) ) {
				$input['initial_data'] = $initial_data;
			}

			$result = $ability->execute( $input );

			if ( is_wp_error( $result ) ) {
				if ( empty( $job_ids ) ) {
					return $result;
				}
				break;
			}

			if ( ! ( $result['success'] ?? false ) ) {
				if ( empty( $job_ids ) ) {
					$status = 400;
					$error  = $result['error'] ?? 'Execution failed';
					if ( false !== strpos( $error, 'not found' ) ) {
						$status = 404;
					} elseif ( false !== strpos( $error, 'Failed to create' ) ) {
						$status = 500;
					}
					return new \WP_Error( 'execute_failed', $error, array( 'status' => $status ) );
				}
				break;
			}

			$job_ids[] = $result['job_id'] ?? null;
		}

		$response_data = array(
			'execution_type' => 'immediate',
			'execution_mode' => 'database',
			'flow_id'        => $flow_id,
		);

		if ( 1 === $count ) {
			$response_data['job_id'] = $job_ids[0] ?? null;
		} else {
			$response_data['job_ids'] = $job_ids;
			$response_data['count']   = count( $job_ids );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $response_data,
				'message' => 'Execution started',
			)
		);
	}

	/**
	 * Handle ephemeral workflow execution via datamachine/execute-workflow.
	 */
	private static function handle_ephemeral_execution( $request ) {
		$workflow     = $request->get_param( 'workflow' );
		$timestamp    = $request->get_param( 'timestamp' );
		$initial_data = $request->get_param( 'initial_data' );
		$dry_run      = $request->get_param( 'dry_run' );

		$ability = wp_get_ability( 'datamachine/execute-workflow' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Execute workflow ability not found', array( 'status' => 500 ) );
		}

		$input = array( 'workflow' => $workflow );

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

		if ( is_wp_error( $result ) ) {
			return $result;
		}

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

		$response_data = array(
			'execution_type' => $result['execution_type'] ?? 'immediate',
			'execution_mode' => $result['execution_mode'] ?? 'direct',
		);

		if ( isset( $result['job_id'] ) ) {
			$response_data['job_id'] = $result['job_id'];
		}

		if ( isset( $result['step_count'] ) ) {
			$response_data['step_count'] = $result['step_count'];
		}

		if ( isset( $result['dry_run'] ) && $result['dry_run'] ) {
			$response_data['dry_run'] = true;
		}

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
}
