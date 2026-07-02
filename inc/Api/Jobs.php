<?php
/**
 * Jobs REST API Endpoint
 *
 * Provides REST API access to job execution history.
 * Requires WordPress manage_options capability for all operations.
 * Delegates to concrete Job abilities for core logic.
 *
 * Endpoints:
 * - GET /datamachine/v1/jobs - Retrieve jobs list with pagination and filtering
 * - GET /datamachine/v1/jobs/{id} - Get specific job details
 * - DELETE /datamachine/v1/jobs - Clear jobs (all or failed)
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\Job\DeleteJobsAbility;
use DataMachine\Abilities\Job\GetJobsAbility;
use DataMachine\Core\AbilityResult;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Jobs {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register all jobs related REST endpoints
	 */
	public static function register_routes() {

		// GET /datamachine/v1/jobs - Retrieve jobs
		register_rest_route(
			'datamachine/v1',
			'/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_jobs' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'orderby'             => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'job_id',
						'description' => __( 'Order jobs by field', 'data-machine' ),
					),
					'order'               => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'DESC',
						'enum'        => array( 'ASC', 'DESC' ),
						'description' => __( 'Sort order', 'data-machine' ),
					),
					'per_page'            => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => __( 'Number of jobs per page', 'data-machine' ),
					),
					'offset'              => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => __( 'Offset for pagination', 'data-machine' ),
					),
					'pipeline_id'         => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => __( 'Filter by pipeline ID', 'data-machine' ),
					),
					'flow_id'             => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => __( 'Filter by flow ID', 'data-machine' ),
					),
					'status'              => array(
						'required'    => false,
						'type'        => 'string',
						'description' => __( 'Filter by job status', 'data-machine' ),
					),
					'user_id'             => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Filter by user ID (admin only, non-admins always see own data)', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
					'parent_job_id'       => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => __( 'Filter by parent job ID (for batch child jobs)', 'data-machine' ),
					),
					'hide_children'       => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Hide child jobs from top-level list', 'data-machine' ),
					),
					'metadata'            => array(
						'required'    => false,
						'type'        => 'object',
						'description' => __( 'Exact metadata filters keyed by engine_data dot-path.', 'data-machine' ),
					),
					'metadata_scan_limit' => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 1000,
						'minimum'     => 1,
						'maximum'     => 5000,
						'description' => __( 'Maximum candidate jobs to scan when applying exact metadata filters.', 'data-machine' ),
					),
				),
			)
		);

		// GET /datamachine/v1/jobs/{id} - Get specific job details
		register_rest_route(
			'datamachine/v1',
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_job_by_id' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'    => true,
						'type'        => 'integer',
						'description' => __( 'Job ID', 'data-machine' ),
					),
				),
			)
		);

		// DELETE /datamachine/v1/jobs - Clear jobs
		register_rest_route(
			'datamachine/v1',
			'/jobs',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'handle_clear' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'type'              => array(
						'required'    => true,
						'type'        => 'string',
						'enum'        => array( 'all', 'failed' ),
						'description' => __( 'Which jobs to clear: all or failed', 'data-machine' ),
					),
					'cleanup_processed' => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also clear processed items tracking', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to manage jobs
	 */
	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'manage_flows' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage jobs.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle get jobs request
	 *
	 * GET /datamachine/v1/jobs
	 */
	public static function handle_get_jobs( $request ) {
		$scoped_user_id  = PermissionHelper::resolve_scoped_user_id( $request );
		$scoped_agent_id = PermissionHelper::resolve_scoped_agent_id( $request );

		$input = array(
			'orderby'  => $request->get_param( 'orderby' ),
			'order'    => $request->get_param( 'order' ),
			'per_page' => $request->get_param( 'per_page' ),
			'offset'   => $request->get_param( 'offset' ),
		);

		if ( null !== $scoped_agent_id ) {
			$input['agent_id'] = $scoped_agent_id;
		} elseif ( null !== $scoped_user_id ) {
			$input['user_id'] = $scoped_user_id;
		}
		if ( $request->get_param( 'pipeline_id' ) ) {
			$input['pipeline_id'] = (int) $request->get_param( 'pipeline_id' );
		}
		if ( $request->get_param( 'flow_id' ) ) {
			$input['flow_id'] = (int) $request->get_param( 'flow_id' );
		}
		if ( $request->get_param( 'status' ) ) {
			$input['status'] = sanitize_text_field( $request->get_param( 'status' ) );
		}
		if ( $request->get_param( 'parent_job_id' ) ) {
			$input['parent_job_id'] = (int) $request->get_param( 'parent_job_id' );
		}
		if ( $request->get_param( 'hide_children' ) ) {
			$input['hide_children'] = true;
		}
		if ( is_array( $request->get_param( 'metadata' ) ) ) {
			$input['metadata'] = $request->get_param( 'metadata' );
		}
		if ( $request->get_param( 'metadata_scan_limit' ) ) {
			$input['metadata_scan_limit'] = (int) $request->get_param( 'metadata_scan_limit' );
		}

		$result = ( new GetJobsAbility() )->execute( $input );

		return AbilityResult::rest_collection_response( $result, 'jobs', array( 'top_extra' => array( 'filters_applied', 'metadata_query' ) ), 'get_jobs_failed', __( 'Failed to get jobs.', 'data-machine' ) );
	}

	/**
	 * Handle get specific job by ID request
	 *
	 * GET /datamachine/v1/jobs/{id}
	 */
	public static function handle_get_job_by_id( $request ) {
		$job_id = (int) $request->get_param( 'id' );

		$result = ( new GetJobsAbility() )->execute( array( 'job_id' => $job_id ) );

		$error = AbilityResult::failure_to_wp_error( $result, 'job_not_found', __( 'Job not found.', 'data-machine' ), 404 );
		if ( $error || empty( $result['jobs'] ) ) {
			return $error ? $error : new \WP_Error( 'job_not_found', __( 'Job not found.', 'data-machine' ), array( 'status' => 404 ) );
		}

		return AbilityResult::rest_item_response( $result, $result['jobs'][0] );
	}

	/**
	 * Handle clear jobs request
	 *
	 * DELETE /datamachine/v1/jobs
	 */
	public static function handle_clear( $request ) {
		$type              = $request->get_param( 'type' );
		$cleanup_processed = (bool) $request->get_param( 'cleanup_processed' );

		$result = ( new DeleteJobsAbility() )->execute(
			array(
				'type'              => $type,
				'cleanup_processed' => $cleanup_processed,
			)
		);

		$error = AbilityResult::failure_to_wp_error( $result, 'delete_failed', __( 'Failed to delete jobs.', 'data-machine' ) );
		if ( $error ) {
			return $error;
		}

		return rest_ensure_response(
			array(
				'success'                 => true,
				'message'                 => $result['message'],
				'jobs_deleted'            => $result['deleted_count'],
				'processed_items_cleaned' => $result['processed_items_cleaned'],
			)
		);
	}
}
