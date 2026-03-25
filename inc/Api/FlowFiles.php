<?php
/**
 * REST API Flow Files Endpoint
 *
 * Routes for flow-scoped uploaded files.
 * Delegates to FlowFileAbilities.
 *
 * @package DataMachine\Api
 * @since   0.38.0
 */

namespace DataMachine\Api;

use DataMachine\Abilities\File\FlowFileAbilities;
use DataMachine\Abilities\PermissionHelper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use DataMachine\Api\Traits\HasRegister;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FlowFiles {

	private static ?FlowFileAbilities $abilities = null;

	private static function getAbilities(): FlowFileAbilities {
		if ( null === self::$abilities ) {
			self::$abilities = new FlowFileAbilities();
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
	 * Register /datamachine/v1/files endpoints for flow-scoped operations.
	 */
	public static function register_routes() {
		$flow_step_arg = array(
			'required'          => false,
			'type'              => 'string',
			'description'       => __( 'Flow step ID for flow-level files', 'data-machine' ),
			'sanitize_callback' => function ( $param ) {
				return sanitize_text_field( $param );
			},
		);

		// POST /files — Upload file to flow.
		register_rest_route(
			'datamachine/v1',
			'/files',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_upload' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_step_id' => $flow_step_arg,
				),
			)
		);

		// GET /files — List flow files.
		register_rest_route(
			'datamachine/v1',
			'/files',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_files' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_step_id' => $flow_step_arg,
				),
			)
		);

		// DELETE /files/{filename} — Delete flow file.
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
					'flow_step_id' => $flow_step_arg,
				),
			)
		);
	}

	// =========================================================================
	// Permission
	// =========================================================================

	public static function check_permission( $request ) {
		$request;
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to manage files.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		return PermissionHelper::can_manage();
	}

	// =========================================================================
	// Handlers
	// =========================================================================

	public static function list_files( WP_REST_Request $request ) {
		$flow_step_id = $request->get_param( 'flow_step_id' );

		if ( ! $flow_step_id ) {
			return new WP_Error( 'list_files_error', 'flow_step_id is required', array( 'status' => 400 ) );
		}

		$result = self::getAbilities()->executeListFlowFiles( array(
			'flow_step_id' => sanitize_text_field( $flow_step_id ),
		) );

		if ( ! $result['success'] ) {
			$status = false !== strpos( $result['error'] ?? '', 'not found' ) ? 404 : 400;
			return new WP_Error( 'list_files_error', $result['error'], array( 'status' => $status ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $result['files'],
		) );
	}

	public static function handle_upload( WP_REST_Request $request ) {
		$flow_step_id = $request->get_param( 'flow_step_id' );

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

		$result = self::getAbilities()->executeUploadFlowFile( $input );

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			} elseif ( false !== strpos( $result['error'] ?? '', 'Failed to store' ) ) {
				$status = 500;
			}

			return new WP_Error( 'upload_file_error', $result['error'], array( 'status' => $status ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'files' => $result['files'],
			),
		) );
	}

	public static function delete_file( WP_REST_Request $request ) {
		$filename     = sanitize_file_name( wp_unslash( $request['filename'] ) );
		$flow_step_id = $request->get_param( 'flow_step_id' );

		if ( ! $flow_step_id ) {
			return new WP_Error( 'delete_file_error', 'flow_step_id is required', array( 'status' => 400 ) );
		}

		$result = self::getAbilities()->executeDeleteFlowFile( array(
			'filename'     => $filename,
			'flow_step_id' => sanitize_text_field( $flow_step_id ),
		) );

		if ( ! $result['success'] ) {
			$status = false !== strpos( $result['error'] ?? '', 'not found' ) ? 404 : 400;
			return new WP_Error( 'delete_file_error', $result['error'], array( 'status' => $status ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array( 'message' => $result['message'] ),
		) );
	}

	/**
	 * Get file context array from flow ID.
	 *
	 * Supports database flows (numeric ID), direct execution ('direct'),
	 * and jobs without flow context (null flow_id).
	 *
	 * @param int|string|null $flow_id Flow ID, 'direct', or null.
	 * @return array Context array with pipeline_id and flow_id.
	 */
	public static function get_file_context( int|string|null $flow_id ): array {
		if ( null === $flow_id ) {
			return array(
				'pipeline_id' => null,
				'flow_id'     => null,
			);
		}

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

		return array(
			'pipeline_id' => $flow_data['pipeline_id'],
			'flow_id'     => $flow_id,
		);
	}
}
