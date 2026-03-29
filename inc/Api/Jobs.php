<?php
/**
 * Jobs REST API Endpoint
 *
 * Provides REST API access to job execution history.
 * Requires WordPress manage_options capability for all operations.
 * Delegates to JobAbilities for core logic.
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
use DataMachine\Abilities\JobAbilities;
use DataMachine\Api\Traits\HasRegister;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Jobs {
	use HasRegister;


	private static ?JobAbilities $abilities = null;

	private static function getAbilities(): JobAbilities {
		if ( null === self::$abilities ) {
			self::$abilities = new JobAbilities();
		}
		return self::$abilities;
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
					'orderby'       => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'job_id',
						'description' => __( 'Order jobs by field', 'data-machine' ),
					),
					'order'         => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'DESC',
						'enum'        => array( 'ASC', 'DESC' ),
						'description' => __( 'Sort order', 'data-machine' ),
					),
					'per_page'      => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => __( 'Number of jobs per page', 'data-machine' ),
					),
					'offset'        => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => __( 'Offset for pagination', 'data-machine' ),
					),
					'pipeline_id'   => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => __( 'Filter by pipeline ID', 'data-machine' ),
					),
					'flow_id'       => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => __( 'Filter by flow ID', 'data-machine' ),
					),
					'status'        => array(
						'required'    => false,
						'type'        => 'string',
						'description' => __( 'Filter by job status', 'data-machine' ),
					),
					'user_id'       => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Filter by user ID (admin only, non-admins always see own data)', 'data-machine' ),
						'sanitize_callback' => 'absint',
					),
					'parent_job_id' => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => __( 'Filter by parent job ID (for batch child jobs)', 'data-machine' ),
					),
					'hide_children' => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Hide child jobs from top-level list', 'data-machine' ),
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

		$result = self::getAbilities()->executeGetJobs( $input );

		return rest_ensure_response(
			array(
				'success'  => $result['success'],
				'data'     => $result['jobs'],
				'total'    => $result['total'],
				'per_page' => $result['per_page'],
				'offset'   => $result['offset'],
			)
		);
	}

	/**
	 * Handle get specific job by ID request
	 *
	 * GET /datamachine/v1/jobs/{id}
	 */
	public static function handle_get_job_by_id( $request ) {
		$job_id = (int) $request->get_param( 'id' );

		$result = self::getAbilities()->executeGetJobs( array( 'job_id' => $job_id ) );

		if ( ! $result['success'] || empty( $result['jobs'] ) ) {
			return new \WP_Error(
				'job_not_found',
				$result['error'] ?? __( 'Job not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['jobs'][0],
			)
		);
	}

	/**
	 * Handle clear jobs request
	 *
	 * DELETE /datamachine/v1/jobs
	 */
	public static function handle_clear( $request ) {
		$type              = $request->get_param( 'type' );
		$cleanup_processed = (bool) $request->get_param( 'cleanup_processed' );

		$result = self::getAbilities()->executeDeleteJobs(
			array(
				'type'              => $type,
				'cleanup_processed' => $cleanup_processed,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'delete_failed',
				$result['error'] ?? __( 'Failed to delete jobs.', 'data-machine' ),
				array( 'status' => 500 )
			);
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
