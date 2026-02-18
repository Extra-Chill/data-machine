<?php
/**
 * REST API Files Endpoint
 *
 * Unified file API supporting both flow-level files and pipeline-level context.
 * Delegates to FileAbilities for core logic.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\FileAbilities;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FilesystemHelper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Files {

	private static ?FileAbilities $abilities = null;

	private static function getAbilities(): FileAbilities {
		if ( null === self::$abilities ) {
			self::$abilities = new FileAbilities();
		}
		return self::$abilities;
	}

	/**
	 * Register REST API routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register /datamachine/v1/files endpoints.
	 */
	public static function register_routes() {
		// POST /files - Upload file (flow or pipeline context)
		register_rest_route(
			'datamachine/v1',
			'/files',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_upload' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_step_id' => array(
						'required'          => false,
						'type'              => 'string',
						'description'       => __( 'Flow step ID for flow-level files', 'data-machine' ),
						'sanitize_callback' => function ( $param ) {
							return sanitize_text_field( $param );
						},
					),
					'pipeline_id'  => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'Pipeline ID for pipeline context files', 'data-machine' ),
						'sanitize_callback' => function ( $param ) {
							return absint( $param );
						},
					),
				),
			)
		);

		// GET /files - List files (flow or pipeline context)
		register_rest_route(
			'datamachine/v1',
			'/files',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_files' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_step_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => function ( $param ) {
							return sanitize_text_field( $param );
						},
					),
					'pipeline_id'  => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => function ( $param ) {
							return absint( $param );
						},
					),
				),
			)
		);

		// DELETE /files/{filename} - Delete file (flow or pipeline context)
		register_rest_route(
			'datamachine/v1',
			'/files/(?P<filename>[^/]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'filename'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => function ( $param ) {
							return sanitize_file_name( $param );
						},
					),
					'flow_step_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => function ( $param ) {
							return sanitize_text_field( $param );
						},
					),
					'pipeline_id'  => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => function ( $param ) {
							return absint( $param );
						},
					),
				),
			)
		);

		// GET /files/agent - List agent files
		register_rest_route(
			'datamachine/v1',
			'/files/agent',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_agent_files' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		// GET /files/agent/{filename} - Get agent file content
		register_rest_route(
			'datamachine/v1',
			'/files/agent/(?P<filename>[^/]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_agent_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'filename' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => function ( $param ) {
							return sanitize_file_name( $param );
						},
					),
				),
			)
		);

		// PUT /files/agent/{filename} - Write/update agent file content
		register_rest_route(
			'datamachine/v1',
			'/files/agent/(?P<filename>[^/]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'put_agent_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'filename' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => function ( $param ) {
							return sanitize_file_name( $param );
						},
					),
				),
			)
		);

		// DELETE /files/agent/{filename} - Delete agent file
		register_rest_route(
			'datamachine/v1',
			'/files/agent/(?P<filename>[^/]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_agent_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'filename' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => function ( $param ) {
							return sanitize_file_name( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * Check user permission
	 */
	public static function check_permission( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage files.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * List files for flow or pipeline context
	 */
	public static function list_files( WP_REST_Request $request ) {
		$flow_step_id = $request->get_param( 'flow_step_id' );
		$pipeline_id  = $request->get_param( 'pipeline_id' );

		$input = array();

		if ( $flow_step_id ) {
			$input['flow_step_id'] = sanitize_text_field( $flow_step_id );
		}

		if ( $pipeline_id ) {
			$input['pipeline_id'] = absint( $pipeline_id );
		}

		$result = self::getAbilities()->executeListFiles( $input );

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			}

			return new WP_Error(
				'list_files_error',
				$result['error'],
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['files'],
			)
		);
	}

	/**
	 * Handle file upload for flow files or pipeline context.
	 * Delegates to FileAbilities::executeUploadFile().
	 */
	public static function handle_upload( WP_REST_Request $request ) {
		$flow_step_id = $request->get_param( 'flow_step_id' );
		$pipeline_id  = $request->get_param( 'pipeline_id' );

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error(
				'missing_file',
				__( 'File upload is required.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$uploaded = $files['file'];
		$input    = array(
			'file_data' => array(
				'name'     => sanitize_file_name( $uploaded['name'] ?? '' ),
				'type'     => sanitize_mime_type( $uploaded['type'] ?? '' ),
				'tmp_name' => $uploaded['tmp_name'] ?? '',
				'error'    => intval( $uploaded['error'] ?? UPLOAD_ERR_NO_FILE ),
				'size'     => intval( $uploaded['size'] ?? 0 ),
			),
		);

		if ( $flow_step_id ) {
			$input['flow_step_id'] = sanitize_text_field( $flow_step_id );
		}

		if ( $pipeline_id ) {
			$input['pipeline_id'] = absint( $pipeline_id );
		}

		$result = self::getAbilities()->executeUploadFile( $input );

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			} elseif ( false !== strpos( $result['error'] ?? '', 'Failed to store' ) ) {
				$status = 500;
			}

			return new WP_Error(
				'upload_file_error',
				$result['error'],
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'files' => $result['files'],
					'scope' => $result['scope'],
				),
			)
		);
	}

	/**
	 * Delete file (flow or pipeline context)
	 */
	public static function delete_file( WP_REST_Request $request ) {
		$filename     = sanitize_file_name( wp_unslash( $request['filename'] ) );
		$flow_step_id = $request->get_param( 'flow_step_id' );
		$pipeline_id  = $request->get_param( 'pipeline_id' );

		$input = array(
			'filename' => $filename,
		);

		if ( $flow_step_id ) {
			$input['flow_step_id'] = sanitize_text_field( $flow_step_id );
		}

		if ( $pipeline_id ) {
			$input['pipeline_id'] = absint( $pipeline_id );
		}

		$result = self::getAbilities()->executeDeleteFile( $input );

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			}

			return new WP_Error(
				'delete_file_error',
				$result['error'],
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'scope' => $result['scope'],
				),
			)
		);
	}

	/**
	 * List agent files.
	 */
	public static function list_agent_files( WP_REST_Request $request ) {
		$result = self::getAbilities()->executeListFiles( array( 'scope' => 'agent' ) );

		if ( ! $result['success'] ) {
			return new WP_Error( 'list_agent_files_error', $result['error'], array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['files'],
			)
		);
	}

	/**
	 * Get agent file content.
	 */
	public static function get_agent_file( WP_REST_Request $request ) {
		$filename = sanitize_file_name( wp_unslash( $request['filename'] ) );

		$result = self::getAbilities()->executeGetFile(
			array(
				'filename' => $filename,
				'scope'    => 'agent',
			)
		);

		if ( ! $result['success'] ) {
			$status = false !== strpos( $result['error'] ?? '', 'not found' ) ? 404 : 400;
			return new WP_Error( 'get_agent_file_error', $result['error'], array( 'status' => $status ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['file'],
			)
		);
	}

	/**
	 * Write/update agent file content (accepts raw body as content).
	 */
	public static function put_agent_file( WP_REST_Request $request ) {
		$filename = sanitize_file_name( wp_unslash( $request['filename'] ) );
		$content  = $request->get_body();

		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();

		if ( ! $directory_manager->ensure_directory_exists( $agent_dir ) ) {
			return new WP_Error( 'put_agent_file_error', 'Failed to create agent directory', array( 'status' => 500 ) );
		}

		$filepath = "{$agent_dir}/{$filename}";

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return new WP_Error( 'put_agent_file_error', 'Filesystem not available', array( 'status' => 500 ) );
		}

		$written = $fs->put_contents( $filepath, $content, FS_CHMOD_FILE );

		if ( ! $written ) {
			return new WP_Error( 'put_agent_file_error', 'Failed to write file', array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'filename' => $filename,
			)
		);
	}

	/**
	 * Delete agent file.
	 */
	public static function delete_agent_file( WP_REST_Request $request ) {
		$filename = sanitize_file_name( wp_unslash( $request['filename'] ) );

		$result = self::getAbilities()->executeDeleteFile(
			array(
				'filename' => $filename,
				'scope'    => 'agent',
			)
		);

		if ( ! $result['success'] ) {
			$status = false !== strpos( $result['error'] ?? '', 'not found' ) ? 404 : 400;
			return new WP_Error( 'delete_agent_file_error', $result['error'], array( 'status' => $status ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array( 'scope' => $result['scope'] ),
			)
		);
	}

	/**
	 * Get file context array from flow ID.
	 *
	 * Supports both database flows (numeric ID) and direct execution ('direct').
	 *
	 * @param int|string $flow_id Flow ID or 'direct' for ephemeral workflows
	 * @return array Context array with pipeline_id and flow_id
	 */
	public static function get_file_context( int|string $flow_id ): array {
		// Direct execution mode - no database lookup needed
		if ( 'direct' === $flow_id ) {
			return array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
			);
		}

		$db_flows  = new \DataMachine\Core\Database\Flows\Flows();
		$flow_data = $db_flows->get_flow( (int) $flow_id );

		if ( ! isset( $flow_data['pipeline_id'] ) || empty( $flow_data['pipeline_id'] ) ) {
			throw new \InvalidArgumentException( 'Flow data missing required pipeline_id' );
		}

		$pipeline_id = $flow_data['pipeline_id'];

		return array(
			'pipeline_id' => $pipeline_id,
			'flow_id'     => $flow_id,
		);
	}
}
