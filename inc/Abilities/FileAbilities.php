<?php
/**
 * File Abilities
 *
 * Abilities API primitives for file operations.
 * Centralizes file listing, retrieval, deletion, and cleanup logic for REST API, CLI, and Chat tools.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\FilesRepository\FileCleanup;
use DataMachine\Core\FilesRepository\FileStorage;

defined( 'ABSPATH' ) || exit;

class FileAbilities {

	private static bool $registered = false;

	private FileStorage $file_storage;
	private FileCleanup $file_cleanup;
	private Flows $db_flows;
	private Pipelines $db_pipelines;

	public function __construct() {
		$this->file_storage = new FileStorage();
		$this->file_cleanup = new FileCleanup();
		$this->db_flows     = new Flows();
		$this->db_pipelines = new Pipelines();

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
			$this->registerListFiles();
			$this->registerGetFile();
			$this->registerDeleteFile();
			$this->registerCleanupFiles();
			$this->registerUploadFile();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Register datamachine/list-files ability.
	 */
	private function registerListFiles(): void {
		wp_register_ability(
			'datamachine/list-files',
			array(
				'label'               => __( 'List Files', 'data-machine' ),
				'description'         => __( 'List files for a flow or pipeline context. Provide either flow_step_id for flow files or pipeline_id for pipeline context files.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'flow_step_id' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Flow step ID for flow-level files (e.g., "1-2" for pipeline 1, flow 2)', 'data-machine' ),
						),
						'pipeline_id'  => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Pipeline ID for pipeline context files', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'files'   => array( 'type' => 'array' ),
						'scope'   => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeListFiles' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/get-file ability.
	 */
	private function registerGetFile(): void {
		wp_register_ability(
			'datamachine/get-file',
			array(
				'label'               => __( 'Get File', 'data-machine' ),
				'description'         => __( 'Get metadata for a single file.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename' ),
					'properties' => array(
						'filename'     => array(
							'type'        => 'string',
							'description' => __( 'Name of the file to retrieve', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Flow step ID for flow-level files', 'data-machine' ),
						),
						'pipeline_id'  => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Pipeline ID for pipeline context files', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'file'    => array( 'type' => 'object' ),
						'scope'   => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/delete-file ability.
	 */
	private function registerDeleteFile(): void {
		wp_register_ability(
			'datamachine/delete-file',
			array(
				'label'               => __( 'Delete File', 'data-machine' ),
				'description'         => __( 'Delete a specific file from flow or pipeline context.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename' ),
					'properties' => array(
						'filename'     => array(
							'type'        => 'string',
							'description' => __( 'Name of the file to delete', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Flow step ID for flow-level files', 'data-machine' ),
						),
						'pipeline_id'  => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Pipeline ID for pipeline context files', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'scope'   => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeDeleteFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/cleanup-files ability.
	 */
	private function registerCleanupFiles(): void {
		wp_register_ability(
			'datamachine/cleanup-files',
			array(
				'label'               => __( 'Cleanup Files', 'data-machine' ),
				'description'         => __( 'Cleanup files for a job or entire flow. Removes data packets and temporary files.', 'data-machine' ),
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
				'execute_callback'    => array( $this, 'executeCleanupFiles' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/upload-file ability.
	 */
	private function registerUploadFile(): void {
		wp_register_ability(
			'datamachine/upload-file',
			array(
				'label'               => __( 'Upload File', 'data-machine' ),
				'description'         => __( 'Upload a file to flow or pipeline context.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'file_data' ),
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
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Flow step ID for flow-level files', 'data-machine' ),
						),
						'pipeline_id'  => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Pipeline ID for pipeline context files', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'files'   => array( 'type' => 'array' ),
						'scope'   => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUploadFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute list-files ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with files list.
	 */
	public function executeListFiles( array $input ): array {
		$flow_step_id = $input['flow_step_id'] ?? null;
		$pipeline_id  = $input['pipeline_id'] ?? null;

		if ( ! $flow_step_id && ! $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either flow_step_id or pipeline_id',
			);
		}

		if ( $flow_step_id && $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Cannot provide both flow_step_id and pipeline_id',
			);
		}

		if ( $pipeline_id ) {
			return $this->listPipelineFiles( (int) $pipeline_id );
		}

		return $this->listFlowFiles( $flow_step_id );
	}

	/**
	 * Execute get-file ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with file metadata.
	 */
	public function executeGetFile( array $input ): array {
		$filename     = $input['filename'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$pipeline_id  = $input['pipeline_id'] ?? null;

		if ( empty( $filename ) ) {
			return array(
				'success' => false,
				'error'   => 'filename is required',
			);
		}

		if ( ! $flow_step_id && ! $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either flow_step_id or pipeline_id',
			);
		}

		if ( $flow_step_id && $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Cannot provide both flow_step_id and pipeline_id',
			);
		}

		$filename = sanitize_file_name( $filename );

		if ( $pipeline_id ) {
			return $this->getFileFromPipeline( $filename, (int) $pipeline_id );
		}

		return $this->getFileFromFlow( $filename, $flow_step_id );
	}

	/**
	 * Execute delete-file ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with deletion status.
	 */
	public function executeDeleteFile( array $input ): array {
		$filename     = $input['filename'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$pipeline_id  = $input['pipeline_id'] ?? null;

		if ( empty( $filename ) ) {
			return array(
				'success' => false,
				'error'   => 'filename is required',
			);
		}

		if ( ! $flow_step_id && ! $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either flow_step_id or pipeline_id',
			);
		}

		if ( $flow_step_id && $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Cannot provide both flow_step_id and pipeline_id',
			);
		}

		$filename = sanitize_file_name( $filename );

		if ( $pipeline_id ) {
			return $this->deleteFileFromPipeline( $filename, (int) $pipeline_id );
		}

		return $this->deleteFileFromFlow( $filename, $flow_step_id );
	}

	/**
	 * Execute cleanup-files ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with cleanup count.
	 */
	public function executeCleanupFiles( array $input ): array {
		$job_id  = $input['job_id'] ?? null;
		$flow_id = $input['flow_id'] ?? null;

		if ( ! $job_id && ! $flow_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either job_id or flow_id',
			);
		}

		if ( $job_id && ! $flow_id ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required when providing job_id',
			);
		}

		$flow_id = (int) $flow_id;
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

		if ( $job_id ) {
			$job_id        = (int) $job_id;
			$deleted_count = $this->file_cleanup->cleanup_job_data_packets( $job_id, $context );

			do_action(
				'datamachine_log',
				'info',
				'Job files cleaned up via ability',
				array(
					'job_id'        => $job_id,
					'flow_id'       => $flow_id,
					'deleted_count' => $deleted_count,
				)
			);

			return array(
				'success'       => true,
				'deleted_count' => $deleted_count,
				'message'       => sprintf( 'Cleaned up files for job %d', $job_id ),
			);
		}

		$deleted_count = $this->file_cleanup->cleanup_job_data_packets( 0, $context );

		do_action(
			'datamachine_log',
			'info',
			'Flow files cleaned up via ability',
			array(
				'flow_id'       => $flow_id,
				'deleted_count' => $deleted_count,
			)
		);

		return array(
			'success'       => true,
			'deleted_count' => $deleted_count,
			'message'       => sprintf( 'Cleaned up files for flow %d', $flow_id ),
		);
	}

	/**
	 * Execute upload-file ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with uploaded files list.
	 */
	public function executeUploadFile( array $input ): array {
		$file_data    = $input['file_data'] ?? array();
		$flow_step_id = $input['flow_step_id'] ?? null;
		$pipeline_id  = $input['pipeline_id'] ?? null;

		if ( ! $flow_step_id && ! $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either flow_step_id or pipeline_id',
			);
		}

		if ( $flow_step_id && $pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Cannot provide both flow_step_id and pipeline_id',
			);
		}

		if ( empty( $file_data ) || ! is_array( $file_data ) ) {
			return array(
				'success' => false,
				'error'   => 'file_data is required',
			);
		}

		$upload_error = intval( $file_data['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			return array(
				'success' => false,
				'error'   => 'File upload failed',
			);
		}

		$tmp_name = $file_data['tmp_name'] ?? '';
		if ( empty( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid temporary file',
			);
		}

		$file_name = sanitize_file_name( $file_data['name'] ?? '' );
		$file_type = sanitize_mime_type( $file_data['type'] ?? '' );

		if ( '' === $file_name ) {
			return array(
				'success' => false,
				'error'   => 'Invalid file name',
			);
		}

		$file = array(
			'name'     => $file_name,
			'type'     => $file_type,
			'tmp_name' => $tmp_name,
			'error'    => $upload_error,
			'size'     => intval( $file_data['size'] ?? 0 ),
		);

		$validation_error = $this->validateFileWithWordPress( $file );
		if ( null !== $validation_error ) {
			return array(
				'success' => false,
				'error'   => $validation_error,
			);
		}

		if ( $pipeline_id ) {
			return $this->uploadToPipeline( $file, (int) $pipeline_id );
		}

		return $this->uploadToFlow( $file, $flow_step_id );
	}

	/**
	 * Upload file to pipeline context.
	 *
	 * @param array $file File data.
	 * @param int   $pipeline_id Pipeline ID.
	 * @return array Result with files list.
	 */
	private function uploadToPipeline( array $file, int $pipeline_id ): array {
		$context_files  = $this->db_pipelines->get_pipeline_context_files( $pipeline_id );
		$uploaded_files = $context_files['uploaded_files'] ?? array();

		return array(
			'success' => true,
			'files'   => is_array( $uploaded_files ) ? array_map( array( $this, 'sanitizeFileEntry' ), $uploaded_files ) : array(),
			'scope'   => 'pipeline',
		);
	}

	/**
	 * Upload file to flow.
	 *
	 * @param array  $file File data.
	 * @param string $flow_step_id Flow step ID.
	 * @return array Result with files list.
	 */
	private function uploadToFlow( array $file, string $flow_step_id ): array {
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
			'scope'   => 'flow',
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

		if ( $detected_mime && $wp_filetype['type'] && $detected_mime !== $wp_filetype['type'] ) {
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
	 * List files for pipeline context.
	 *
	 * @param int $pipeline_id Pipeline ID.
	 * @return array Result with files.
	 */
	private function listPipelineFiles( int $pipeline_id ): array {
		$pipeline = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Pipeline %d not found', $pipeline_id ),
			);
		}

		$pipeline_name = sanitize_text_field( $pipeline['pipeline_name'] ?? '' );
		if ( '' === $pipeline_name ) {
			return array(
				'success' => false,
				'error'   => 'Invalid pipeline name',
			);
		}

		$files = $this->file_storage->get_pipeline_files( $pipeline_id, $pipeline_name );

		return array(
			'success' => true,
			'files'   => array_map( array( $this, 'sanitizeFileEntry' ), $files ),
			'scope'   => 'pipeline',
		);
	}

	/**
	 * List files for flow.
	 *
	 * @param string $flow_step_id Flow step ID.
	 * @return array Result with files.
	 */
	private function listFlowFiles( string $flow_step_id ): array {
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

		$context = array(
			'pipeline_id' => $pipeline_id,
			'flow_id'     => $flow_id,
		);

		$files = $this->file_storage->get_all_files( $context );

		return array(
			'success' => true,
			'files'   => array_map( array( $this, 'sanitizeFileEntry' ), $files ),
			'scope'   => 'flow',
		);
	}

	/**
	 * Get file from pipeline context.
	 *
	 * @param string $filename Filename to retrieve.
	 * @param int    $pipeline_id Pipeline ID.
	 * @return array Result with file metadata.
	 */
	private function getFileFromPipeline( string $filename, int $pipeline_id ): array {
		$pipeline = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Pipeline %d not found', $pipeline_id ),
			);
		}

		$pipeline_name = sanitize_text_field( $pipeline['pipeline_name'] ?? '' );
		if ( '' === $pipeline_name ) {
			return array(
				'success' => false,
				'error'   => 'Invalid pipeline name',
			);
		}

		$files = $this->file_storage->get_pipeline_files( $pipeline_id, $pipeline_name );

		foreach ( $files as $file ) {
			if ( ( $file['filename'] ?? '' ) === $filename ) {
				return array(
					'success' => true,
					'file'    => $this->sanitizeFileEntry( $file ),
					'scope'   => 'pipeline',
				);
			}
		}

		return array(
			'success' => false,
			'error'   => sprintf( 'File %s not found in pipeline %d', $filename, $pipeline_id ),
		);
	}

	/**
	 * Get file from flow.
	 *
	 * @param string $filename Filename to retrieve.
	 * @param string $flow_step_id Flow step ID.
	 * @return array Result with file metadata.
	 */
	private function getFileFromFlow( string $filename, string $flow_step_id ): array {
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

		$files = $this->file_storage->get_all_files( $context );

		foreach ( $files as $file ) {
			if ( ( $file['filename'] ?? '' ) === $filename ) {
				return array(
					'success' => true,
					'file'    => $this->sanitizeFileEntry( $file ),
					'scope'   => 'flow',
				);
			}
		}

		return array(
			'success' => false,
			'error'   => sprintf( 'File %s not found in flow step %s', $filename, $flow_step_id ),
		);
	}

	/**
	 * Delete file from pipeline context.
	 *
	 * @param string $filename Filename to delete.
	 * @param int    $pipeline_id Pipeline ID.
	 * @return array Result with deletion status.
	 */
	private function deleteFileFromPipeline( string $filename, int $pipeline_id ): array {
		$context_files  = $this->db_pipelines->get_pipeline_context_files( $pipeline_id );
		$uploaded_files = $context_files['uploaded_files'] ?? array();
		$found          = false;

		foreach ( $uploaded_files as $index => $file ) {
			if ( ( $file['original_name'] ?? '' ) === $filename ) {
				$persistent_path = $file['persistent_path'] ?? '';
				if ( $persistent_path && file_exists( $persistent_path ) ) {
					wp_delete_file( $persistent_path );
				}
				unset( $uploaded_files[ $index ] );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'File %s not found in pipeline %d', $filename, $pipeline_id ),
			);
		}

		$context_files['uploaded_files'] = array_values( $uploaded_files );
		$this->db_pipelines->update_pipeline_context_files( $pipeline_id, $context_files );

		do_action(
			'datamachine_log',
			'info',
			'Pipeline file deleted via ability',
			array(
				'pipeline_id' => $pipeline_id,
				'filename'    => $filename,
			)
		);

		return array(
			'success' => true,
			'scope'   => 'pipeline',
			'message' => sprintf( 'File %s deleted from pipeline %d', $filename, $pipeline_id ),
		);
	}

	/**
	 * Delete file from flow.
	 *
	 * @param string $filename Filename to delete.
	 * @param string $flow_step_id Flow step ID.
	 * @return array Result with deletion status.
	 */
	private function deleteFileFromFlow( string $filename, string $flow_step_id ): array {
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
			'scope'   => 'flow',
			'message' => sprintf( 'File %s deleted from flow step %s', $filename, $flow_step_id ),
		);
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

		if ( isset( $sanitized['url'] ) ) {
			$sanitized['url'] = esc_url_raw( $sanitized['url'] );
		}

		if ( isset( $sanitized['size'] ) ) {
			$sanitized['size_formatted'] = esc_html( size_format( (int) $sanitized['size'] ) );
		}

		return $sanitized;
	}
}
