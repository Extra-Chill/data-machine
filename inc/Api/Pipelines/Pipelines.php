<?php
/**
 * REST API Pipelines Endpoint
 *
 * Provides REST API access to pipeline CRUD operations.
 * Uses PipelineAbilities API primitives for centralized logic.
 * Requires WordPress manage_options capability.
 *
 * @package DataMachine\Api\Pipelines
 */

namespace DataMachine\Api\Pipelines;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\PipelineAbilities;
use DataMachine\Core\Admin\DateFormatter;
use WP_REST_Server;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Pipelines {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register pipeline CRUD endpoints
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/pipelines',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'handle_get_pipelines' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'pipeline_id' => array(
							'required'          => false,
							'type'              => 'integer',
							'description'       => __( 'Pipeline ID to retrieve (omit for all pipelines)', 'data-machine' ),
							'sanitize_callback' => 'absint',
						),
						'fields'      => array(
							'required'          => false,
							'type'              => 'string',
							'description'       => __( 'Comma-separated list of fields to return', 'data-machine' ),
							'sanitize_callback' => function ( $param ) {
								return sanitize_text_field( $param );
							},
						),
						'format'      => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'json',
							'enum'              => array( 'json', 'csv' ),
							'description'       => __( 'Response format (json or csv)', 'data-machine' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'ids'         => array(
							'required'          => false,
							'type'              => 'string',
							'description'       => __( 'Comma-separated pipeline IDs for export', 'data-machine' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'user_id'     => array(
							'required'          => false,
							'type'              => 'integer',
							'description'       => __( 'Filter by user ID (admin only, non-admins always see own data)', 'data-machine' ),
							'sanitize_callback' => 'absint',
						),
						'search'      => array(
							'required'          => false,
							'type'              => 'string',
							'description'       => __( 'Filter pipelines by name (substring match)', 'data-machine' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page'    => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'description'       => __( 'Number of pipelines per page', 'data-machine' ),
							'sanitize_callback' => 'absint',
						),
						'offset'      => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'description'       => __( 'Offset for pagination', 'data-machine' ),
							'sanitize_callback' => 'absint',
						),
						'include_flows' => array(
							'required'          => false,
							'type'              => 'boolean',
							'default'           => true,
							'description'       => __( 'Include full flows array per pipeline. Set false for list views — response returns flow_count only.', 'data-machine' ),
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle_create_pipeline' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'pipeline_name' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'Pipeline',
							'description'       => __( 'Pipeline name', 'data-machine' ),
							'sanitize_callback' => function ( $param ) {
								return sanitize_text_field( $param );
							},
						),
						'steps'         => array(
							'required'    => false,
							'type'        => 'array',
							'description' => __( 'Pipeline steps configuration (for complete mode)', 'data-machine' ),
						),
						'flow_config'   => array(
							'required'    => false,
							'type'        => 'array',
							'description' => __( 'Flow configuration', 'data-machine' ),
						),
						'batch_import'  => array(
							'required'          => false,
							'type'              => 'boolean',
							'default'           => false,
							'description'       => __( 'Enable batch import mode', 'data-machine' ),
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'format'        => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'json',
							'enum'              => array( 'json', 'csv' ),
							'description'       => __( 'Import format (json or csv)', 'data-machine' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'data'          => array(
							'required'          => false,
							'type'              => 'string',
							'description'       => __( 'CSV data for batch import', 'data-machine' ),
							'sanitize_callback' => function ( $param ) {
								return wp_unslash( $param );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/pipelines/(?P<pipeline_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_get_pipelines' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'pipeline_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Pipeline ID to retrieve', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'handle_delete_pipeline' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'pipeline_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Pipeline ID to delete', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'handle_update_pipeline_title' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'pipeline_id'   => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Pipeline ID to update', 'data-machine' ),
						),
						'pipeline_name' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'New pipeline title', 'data-machine' ),
						),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/pipelines/(?P<pipeline_id>\d+)/memory-files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_get_memory_files' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'pipeline_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Pipeline ID', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'handle_update_memory_files' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'pipeline_id'  => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Pipeline ID', 'data-machine' ),
						),
						'memory_files' => array(
							'required'    => true,
							'type'        => 'array',
							'description' => __( 'Array of agent memory filenames', 'data-machine' ),
							'items'       => array(
								'type' => 'string',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to access pipelines
	 */
	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'manage_flows' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access pipelines.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle pipeline retrieval request
	 */
	public static function handle_get_pipelines( $request ) {
		$pipeline_id     = $request->get_param( 'pipeline_id' );
		$fields          = $request->get_param( 'fields' );
		$format          = $request->get_param( 'format' ) ?? 'json';
		$ids             = $request->get_param( 'ids' );
		$search          = $request->get_param( 'search' );
		$per_page_param  = $request->get_param( 'per_page' );
		$offset_param    = $request->get_param( 'offset' );
		$include_flows_param = $request->get_param( 'include_flows' );
		$scoped_user_id  = PermissionHelper::resolve_scoped_user_id( $request );
		$scoped_agent_id = PermissionHelper::resolve_scoped_agent_id( $request );

		$abilities = new PipelineAbilities();

		// Handle CSV export
		if ( 'csv' === $format ) {
			$export_ids = array();
			if ( $ids ) {
				$export_ids = array_map( 'absint', explode( ',', $ids ) );
			} elseif ( $pipeline_id ) {
				$export_ids = array( $pipeline_id );
			}

			$result = $abilities->executeExportPipelines(
				array( 'pipeline_ids' => $export_ids )
			);

			if ( ! $result['success'] ) {
				return new \WP_Error(
					'export_failed',
					$result['error'] ?? __( 'Failed to generate CSV export.', 'data-machine' ),
					array( 'status' => 500 )
				);
			}

			$response = new \WP_REST_Response( $result['data'] );
			$response->set_headers(
				array(
					'Content-Type'        => 'text/csv; charset=utf-8',
					'Content-Disposition' => 'attachment; filename="pipelines-export-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv"',
				)
			);

			return $response;
		}

		$requested_fields = array();
		if ( $fields ) {
			$requested_fields = array_map( 'trim', explode( ',', $fields ) );
		}

		if ( $pipeline_id ) {
			$input = array(
				'pipeline_id' => (int) $pipeline_id,
				'output_mode' => 'full',
			);
			if ( null !== $scoped_agent_id ) {
				$input['agent_id'] = $scoped_agent_id;
			} elseif ( null !== $scoped_user_id ) {
				$input['user_id'] = $scoped_user_id;
			}
			$result = $abilities->executeGetPipelines( $input );

			if ( ! $result['success'] || empty( $result['pipelines'] ) ) {
				return new \WP_Error(
					'pipeline_not_found',
					$result['error'] ?? __( 'Pipeline not found.', 'data-machine' ),
					array( 'status' => 404 )
				);
			}

			$pipeline_data = $result['pipelines'][0];
			$flows         = $pipeline_data['flows'] ?? array();
			unset( $pipeline_data['flows'] );
			$pipeline = $pipeline_data;

			if ( ! empty( $requested_fields ) ) {
				$pipeline = array_intersect_key( $pipeline, array_flip( $requested_fields ) );
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'pipeline' => $pipeline,
						'flows'    => $flows,
					),
				)
			);
		} else {
			$per_page = ( null !== $per_page_param ) ? max( 1, (int) $per_page_param ) : 20;
			$offset   = ( null !== $offset_param ) ? max( 0, (int) $offset_param ) : 0;
			// Default include_flows to false for list mode — callers explicitly
			// opt-in when they need full flows embedded. This avoids the N+1
			// flow query and the large payload on admin list loads.
			$include_flows = ( null !== $include_flows_param )
				? (bool) $include_flows_param
				: false;

			$input = array(
				'per_page'      => $per_page,
				'offset'        => $offset,
				'output_mode'   => 'full',
				'include_flows' => $include_flows,
			);
			if ( null !== $scoped_agent_id ) {
				$input['agent_id'] = $scoped_agent_id;
			} elseif ( null !== $scoped_user_id ) {
				$input['user_id'] = $scoped_user_id;
			}
			if ( null !== $search && '' !== $search ) {
				$input['search'] = $search;
			}
			$result = $abilities->executeGetPipelines( $input );

			if ( ! $result['success'] ) {
				return new \WP_Error(
					'get_pipelines_failed',
					$result['error'] ?? __( 'Failed to get pipelines.', 'data-machine' ),
					array( 'status' => 500 )
				);
			}

			$pipelines = $result['pipelines'];

			if ( ! empty( $requested_fields ) ) {
				$pipelines = array_map(
					function ( $pipeline ) use ( $requested_fields ) {
						return array_intersect_key( $pipeline, array_flip( $requested_fields ) );
					},
					$pipelines
				);
			}

			return rest_ensure_response(
				array(
					'success'  => true,
					'per_page' => $result['per_page'] ?? $per_page,
					'offset'   => $result['offset'] ?? $offset,
					'total'    => $result['total'],
					'data'     => array(
						'pipelines' => $pipelines,
						'total'     => $result['total'],
					),
				)
			);
		}
	}

	/**
	 * Handle pipeline creation request
	 */
	public static function handle_create_pipeline( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! isset( $params['pipeline_name'] ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Pipeline name is required.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$abilities = new PipelineAbilities();

		$input = array(
			'pipeline_name' => $params['pipeline_name'],
			'steps'         => $params['steps'] ?? array(),
			'flow_config'   => $params['flow_config'] ?? array(),
			'user_id'       => PermissionHelper::acting_user_id(),
		);

		// Carry agent_id from body params or query string (agent interceptor).
		$scoped_agent_id = PermissionHelper::resolve_scoped_agent_id( $request );
		if ( null !== $scoped_agent_id ) {
			$input['agent_id'] = $scoped_agent_id;
		}

		$result = $abilities->executeCreatePipeline( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'rest_internal_server_error',
				$result['error'] ?? __( 'Failed to create pipeline.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Handle pipeline deletion request
	 */
	public static function handle_delete_pipeline( $request ) {
		$pipeline_id = (int) $request->get_param( 'pipeline_id' );

		// Verify ownership before deleting.
		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$pipeline     = $db_pipelines->get_pipeline( $pipeline_id );

		$resource_agent_id = isset( $pipeline['agent_id'] ) ? (int) $pipeline['agent_id'] : null;
		if ( $pipeline && ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $pipeline['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to delete this pipeline.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$abilities = new PipelineAbilities();
		$result    = $abilities->executeDeletePipeline( array( 'pipeline_id' => $pipeline_id ) );

		if ( ! $result['success'] ) {
			$status = 500;
			if ( strpos( $result['error'] ?? '', 'not found' ) !== false ) {
				$status = 404;
			}
			return new \WP_Error(
				'pipeline_deletion_failed',
				$result['error'] ?? __( 'Failed to delete pipeline.', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handle pipeline title update
	 *
	 * PATCH /datamachine/v1/pipelines/{id}
	 */
	public static function handle_update_pipeline_title( $request ) {
		$pipeline_id = (int) $request->get_param( 'pipeline_id' );
		$params      = $request->get_json_params();

		if ( ! $pipeline_id || empty( $params['pipeline_name'] ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Pipeline ID and name are required.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		// Verify ownership before updating.
		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$pipeline     = $db_pipelines->get_pipeline( $pipeline_id );

		$resource_agent_id = isset( $pipeline['agent_id'] ) ? (int) $pipeline['agent_id'] : null;
		if ( $pipeline && ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $pipeline['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update this pipeline.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$abilities = new PipelineAbilities();
		$result    = $abilities->executeUpdatePipeline(
			array(
				'pipeline_id'   => $pipeline_id,
				'pipeline_name' => $params['pipeline_name'],
			)
		);

		if ( ! $result['success'] ) {
			$status = 500;
			if ( strpos( $result['error'] ?? '', 'not found' ) !== false ) {
				$status = 404;
			}
			return new \WP_Error(
				'update_failed',
				$result['error'] ?? __( 'Failed to save pipeline title', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'pipeline_id'   => $result['pipeline_id'],
					'pipeline_name' => $result['pipeline_name'],
				),
				'message' => $result['message'] ?? __( 'Pipeline title saved successfully', 'data-machine' ),
			)
		);
	}

	/**
	 * Handle get memory files request
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function handle_get_memory_files( $request ) {
		$pipeline_id = (int) $request->get_param( 'pipeline_id' );

		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$pipeline     = $db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			return new \WP_Error(
				'pipeline_not_found',
				__( 'Pipeline not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$resource_agent_id = isset( $pipeline['agent_id'] ) ? (int) $pipeline['agent_id'] : null;
		if ( ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $pipeline['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this pipeline.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		$memory_files = $db_pipelines->get_pipeline_memory_files( $pipeline_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $memory_files,
			)
		);
	}

	/**
	 * Handle update memory files request
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function handle_update_memory_files( $request ) {
		$pipeline_id  = (int) $request->get_param( 'pipeline_id' );
		$params       = $request->get_json_params();
		$memory_files = $params['memory_files'] ?? array();

		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$pipeline     = $db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			return new \WP_Error(
				'pipeline_not_found',
				__( 'Pipeline not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$resource_agent_id = isset( $pipeline['agent_id'] ) ? (int) $pipeline['agent_id'] : null;
		if ( ! PermissionHelper::owns_agent_resource( $resource_agent_id, (int) ( $pipeline['user_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update this pipeline.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		// Sanitize filenames.
		$memory_files = array_map( 'sanitize_file_name', $memory_files );
		$memory_files = array_values( array_filter( $memory_files ) );

		$result = $db_pipelines->update_pipeline_memory_files( $pipeline_id, $memory_files );

		if ( ! $result ) {
			return new \WP_Error(
				'update_failed',
				__( 'Failed to update memory files.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $memory_files,
				'message' => __( 'Memory files updated successfully.', 'data-machine' ),
			)
		);
	}

	/**
	 * Add formatted display fields for timestamps.
	 *
	 * @param array $pipeline Pipeline data
	 * @return array Pipeline data with *_display fields added
	 */
	private static function add_display_fields( array $pipeline ): array {
		if ( isset( $pipeline['created_at'] ) ) {
			$pipeline['created_at_display'] = DateFormatter::format_for_display( $pipeline['created_at'] );
		}

		if ( isset( $pipeline['updated_at'] ) ) {
			$pipeline['updated_at_display'] = DateFormatter::format_for_display( $pipeline['updated_at'] );
		}

		return $pipeline;
	}
}
