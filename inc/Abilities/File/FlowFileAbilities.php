<?php
/**
 * Flow File Abilities
 *
 * Abilities API primitives for flow-scoped file operations.
 * Handles uploaded files attached to flow steps and job data packet cleanup.
 *
 * @package DataMachine\Abilities\File
 * @since   0.38.0
 */

namespace DataMachine\Abilities\File;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\FilesRepository\FileCleanup;
use DataMachine\Core\FilesRepository\FileStorage;
use DataMachine\Abilities\Traits\HasCheckPermission;

defined( 'ABSPATH' ) || exit;

class FlowFileAbilities {

	private static bool $registered = false;

	private FileStorage $file_storage;
	private FileCleanup $file_cleanup;
	private Flows $db_flows;

	public function __construct() {
		$this->file_storage = new FileStorage();
		$this->file_cleanup = new FileCleanup();
		$this->db_flows     = new Flows();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerListFlowFiles();
			$this->registerGetFlowFile();
			$this->registerDeleteFlowFile();
			$this->registerUploadFlowFile();
			$this->registerCleanupFlowFiles();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// =========================================================================
	// Ability Registration
	// =========================================================================

	private function registerListFlowFiles(): void {
		wp_register_ability(
			'datamachine/list-flow-files',
			array(
				'label'               => __( 'List Flow Files', 'data-machine' ),
				'description'         => __( 'List uploaded files for a flow step.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_step_id' ),
					'properties' => array(
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID (e.g., "1-2" for pipeline 1, flow 2)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'files'   => array( 'type' => 'array' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeListFlowFiles' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetFlowFile(): void {
		wp_register_ability(
			'datamachine/get-flow-file',
			array(
				'label'               => __( 'Get Flow File', 'data-machine' ),
				'description'         => __( 'Get metadata for a single flow file.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename', 'flow_step_id' ),
					'properties' => array(
						'filename'     => array(
							'type'        => 'string',
							'description' => __( 'Name of the file to retrieve', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'file'    => array( 'type' => 'object' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetFlowFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerDeleteFlowFile(): void {
		wp_register_ability(
			'datamachine/delete-flow-file',
			array(
				'label'               => __( 'Delete Flow File', 'data-machine' ),
				'description'         => __( 'Delete an uploaded file from a flow step.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename', 'flow_step_id' ),
					'properties' => array(
						'filename'     => array(
							'type'        => 'string',
							'description' => __( 'Name of the file to delete', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeDeleteFlowFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerUploadFlowFile(): void {
		wp_register_ability(
			'datamachine/upload-flow-file',
			array(
				'label'               => __( 'Upload Flow File', 'data-machine' ),
				'description'         => __( 'Upload a file to a flow step.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'file_data', 'flow_step_id' ),
					'properties' => array(
						'file_data'    => array(
							'type'        => 'object',
							'description' => __( 'File data array with name, type, tmp_name, error, size', 'data-machine' ),
							'properties'  => array(
								'name'     => array( 'type' => 'string' ),
								'type'     => array( 'type' => 'string' ),
								'tmp_name' => array( 'type' => 'string' ),
								'error'    => array( 'type' => 'integer' ),
								'size'     => array( 'type' => 'integer' ),
							),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'files'   => array( 'type' => 'array' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUploadFlowFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerCleanupFlowFiles(): void {
		wp_register_ability(
			'datamachine/cleanup-flow-files',
			array(
				'label'               => __( 'Cleanup Flow Files', 'data-machine' ),
				'description'         => __( 'Cleanup data packets and temporary files for a job or flow.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'job_id'  => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Job ID to cleanup files for (requires flow_id)', 'data-machine' ),
						),
						'flow_id' => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Flow ID to cleanup files for', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'deleted_count' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
						'error'         => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeCleanupFlowFiles' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	// =========================================================================
	// Permission
	// =========================================================================

	/**
	 * @return bool
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	// =========================================================================
	// Execute callbacks
	// =========================================================================

	/**
	 * @param array $input Input parameters.
	 * @return array Result with files list.
	 */
	public function executeListFlowFiles( array $input ): array {
		$flow_step_id = $input['flow_step_id'] ?? null;

		if ( ! $flow_step_id ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required',
			);
		}

		$context = $this->resolveFlowContext( $flow_step_id );
		if ( isset( $context['error'] ) ) {
			return $context;
		}

		$files = $this->file_storage->get_all_files( $context );

		return array(
			'success' => true,
			'files'   => array_map( array( $this, 'sanitizeFileEntry' ), $files ),
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array Result with file metadata.
	 */
	public function executeGetFlowFile( array $input ): array {
		$filename     = sanitize_file_name( $input['filename'] ?? '' );
		$flow_step_id = $input['flow_step_id'] ?? null;

		if ( ! $flow_step_id ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required',
			);
		}

		$context = $this->resolveFlowContext( $flow_step_id );
		if ( isset( $context['error'] ) ) {
			return $context;
		}

		$files = $this->file_storage->get_all_files( $context );

		foreach ( $files as $file ) {
			if ( ( $file['filename'] ?? '' ) === $filename ) {
				return array(
					'success' => true,
					'file'    => $this->sanitizeFileEntry( $file ),
				);
			}
		}

		return array(
			'success' => false,
			'error'   => sprintf( 'File %s not found in flow step %s', $filename, $flow_step_id ),
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array Result with deletion status.
	 */
	public function executeDeleteFlowFile( array $input ): array {
		$filename     = sanitize_file_name( $input['filename'] ?? '' );
		$flow_step_id = $input['flow_step_id'] ?? null;

		if ( ! $flow_step_id ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required',
			);
		}

		$context = $this->resolveFlowContext( $flow_step_id );
		if ( isset( $context['error'] ) ) {
			return $context;
		}

		$deleted = $this->file_storage->delete_file( $filename, $context );

		if ( ! $deleted ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'File %s not found or could not be deleted', $filename ),
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Flow file deleted via ability',
			array(
				'flow_step_id' => $flow_step_id,
				'filename'     => $filename,
			)
		);

		return array(
			'success' => true,
			'message' => sprintf( 'File %s deleted from flow step %s', $filename, $flow_step_id ),
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array Result with files list.
	 */
	public function executeUploadFlowFile( array $input ): array {
		$file         = $input['file_data'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;

		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return array(
				'success' => false,
				'error'   => 'No file data provided',
			);
		}

		if ( ! $flow_step_id ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required',
			);
		}

		// Validate uploaded file.
		$validation_error = $this->validateFileWithWordPress( $file );
		if ( $validation_error ) {
			return array(
				'success' => false,
				'error'   => $validation_error,
			);
		}

		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );

		if ( ! $parts || empty( $parts['flow_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid flow step ID format',
			);
		}

		$flow_id = (int) $parts['flow_id'];
		$flow    = $this->db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$pipeline_id = $flow['pipeline_id'] ?? null;
		if ( ! $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Flow does not have a valid pipeline_id',
			);
		}

		$context = array(
			'pipeline_id' => $pipeline_id,
			'flow_id'     => $flow_id,
		);

		$stored = $this->file_storage->store_file( $file['tmp_name'], $file['name'], $context );

		if ( ! $stored ) {
			return array(
				'success' => false,
				'error'   => 'Failed to store file',
			);
		}

		$files = $this->file_storage->get_all_files( $context );

		return array(
			'success' => true,
			'files'   => array_map( array( $this, 'sanitizeFileEntry' ), $files ),
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array Result with cleanup status.
	 */
	public function executeCleanupFlowFiles( array $input ): array {
		$job_id  = isset( $input['job_id'] ) ? (int) $input['job_id'] : null;
		$flow_id = isset( $input['flow_id'] ) ? (int) $input['flow_id'] : null;

		if ( ! $job_id && ! $flow_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either job_id or flow_id for cleanup',
			);
		}

		if ( $job_id && ! $flow_id ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required when cleaning up by job_id',
			);
		}

		// At this point $flow_id is always set (guards above handle the null cases).
		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$pipeline_id = $flow['pipeline_id'] ?? null;
		if ( ! $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Flow does not have a valid pipeline_id',
			);
		}

		$deleted_count = 0;

		if ( $job_id ) {
			$context = array(
				'pipeline_id' => $pipeline_id,
				'flow_id'     => $flow_id,
			);

			$deleted_count = $this->file_cleanup->cleanup_job_data_packets( $job_id, $context );
		} else {
			$deleted_count = $this->file_cleanup->cleanup_old_files( 0 );
		}

		return array(
			'success'       => true,
			'deleted_count' => $deleted_count,
			'message'       => sprintf( 'Cleanup completed. %d items removed.', $deleted_count ),
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Resolve flow context (pipeline_id + flow_id) from a flow_step_id.
	 *
	 * @param string $flow_step_id Flow step identifier.
	 * @return array Context array with pipeline_id/flow_id, or error array.
	 */
	private function resolveFlowContext( string $flow_step_id ): array {
		$flow_step = apply_filters( 'datamachine_get_flow_step_config', array(), $flow_step_id );

		if ( empty( $flow_step ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow step %s not found', $flow_step_id ),
			);
		}

		$flow_id     = apply_filters( 'datamachine_get_flow_id_from_step', null, $flow_step_id );
		$flow        = apply_filters( 'datamachine_get_flow_config', array(), $flow_id );
		$pipeline_id = $flow['pipeline_id'] ?? null;

		if ( ! $pipeline_id || ! $flow_id ) {
			return array(
				'success' => false,
				'error'   => 'Invalid flow configuration',
			);
		}

		return array(
			'pipeline_id' => $pipeline_id,
			'flow_id'     => $flow_id,
		);
	}

	/**
	 * Validate uploaded file using WordPress native security functions.
	 *
	 * @param array $file File data.
	 * @return string|null Error message or null if valid.
	 */
	private function validateFileWithWordPress( array $file ): ?string {
		$file_size = filesize( $file['tmp_name'] );
		if ( false === $file_size ) {
			return __( 'Cannot determine file size.', 'data-machine' );
		}

		$max_file_size = wp_max_upload_size();
		if ( $file_size > $max_file_size ) {
			return sprintf(
				/* translators: %1$s: Current file size, %2$s: Maximum allowed file size */
				__( 'File too large: %1$s. Maximum allowed size: %2$s', 'data-machine' ),
				size_format( $file_size ),
				size_format( $max_file_size )
			);
		}

		$wp_filetype = wp_check_filetype( $file['name'] );
		if ( ! $wp_filetype['type'] ) {
			return __( 'File type not allowed.', 'data-machine' );
		}

		$filename = sanitize_file_name( $file['name'] );
		if ( $filename !== $file['name'] ) {
			return __( 'Invalid file name detected.', 'data-machine' );
		}

		if ( false !== strpos( $file['name'], '..' ) || false !== strpos( $file['name'], '/' ) || false !== strpos( $file['name'], '\\' ) ) {
			return __( 'Invalid file name detected.', 'data-machine' );
		}

		$finfo         = new \finfo( FILEINFO_MIME_TYPE );
		$detected_mime = $finfo->file( $file['tmp_name'] );

		if ( $detected_mime && $detected_mime !== $wp_filetype['type'] ) {
			$allowed_mime_variations = array(
				'text/plain'               => array( 'text/csv', 'text/tab-separated-values' ),
				'application/octet-stream' => array( 'application/zip', 'application/x-zip-compressed' ),
			);

			$is_allowed_variation = isset( $allowed_mime_variations[ $wp_filetype['type'] ] ) &&
									in_array( $detected_mime, $allowed_mime_variations[ $wp_filetype['type'] ], true );

			if ( ! $is_allowed_variation ) {
				return __( 'File content does not match file type.', 'data-machine' );
			}
		}

		return null;
	}

	/**
	 * Normalize and escape file response entry.
	 *
	 * @param array $file File data.
	 * @return array Sanitized file data.
	 */
	private function sanitizeFileEntry( array $file ): array {
		$sanitized = $file;

		if ( isset( $sanitized['filename'] ) ) {
			$sanitized['filename'] = sanitize_file_name( $sanitized['filename'] );
		}

		if ( isset( $sanitized['original_name'] ) ) {
			$sanitized['original_name'] = sanitize_file_name( $sanitized['original_name'] );
		}

		if ( isset( $sanitized['content'] ) ) {
			$sanitized['content'] = wp_kses_post( $sanitized['content'] );
		}

		return $sanitized;
	}
}
