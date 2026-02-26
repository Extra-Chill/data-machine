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
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FileCleanup;
use DataMachine\Core\FilesRepository\FileStorage;
use DataMachine\Core\FilesRepository\FilesystemHelper;

defined( 'ABSPATH' ) || exit;

class FileAbilities {

	/**
	 * Core agent identity files that cannot be deleted.
	 *
	 * @var string[]
	 */
	const PROTECTED_FILES = array( 'SOUL.md', 'MEMORY.md' );

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
			$this->registerWriteAgentFile();
			$this->registerDeleteFile();
			$this->registerCleanupFiles();
			$this->registerUploadFile();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
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
				'description'         => __( 'List files for a flow or agent scope. Provide either flow_step_id or scope="agent".', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'flow_step_id' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Flow step ID for flow-level files (e.g., "1-2" for pipeline 1, flow 2)', 'data-machine' ),
						),
						'scope'        => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Scope for file operations. Use "agent" for agent directory files.', 'data-machine' ),
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
						'scope'        => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Scope for file operations. Use "agent" for agent directory files.', 'data-machine' ),
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
	 * Register datamachine/write-agent-file ability.
	 */
	private function registerWriteAgentFile(): void {
		wp_register_ability(
			'datamachine/write-agent-file',
			array(
				'label'               => __( 'Write Agent File', 'data-machine' ),
				'description'         => __( 'Write or update content for an agent memory file. Protected files cannot be blanked.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename', 'content' ),
					'properties' => array(
						'filename' => array(
							'type'        => 'string',
							'description' => __( 'Name of the agent file to write', 'data-machine' ),
						),
						'content'  => array(
							'type'        => 'string',
							'description' => __( 'Content to write to the file', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'filename' => array( 'type' => 'string' ),
						'error'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeWriteAgentFile' ),
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
				'description'         => __( 'Delete a specific file from flow or agent scope.', 'data-machine' ),
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
						'scope'        => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Scope for file operations. Use "agent" for agent directory files.', 'data-machine' ),
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
				'description'         => __( 'Upload a file to flow or agent scope.', 'data-machine' ),
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
						'scope'        => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Scope for file operations. Use "agent" for agent directory files.', 'data-machine' ),
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
		$scope        = $input['scope'] ?? null;

		if ( 'agent' === $scope ) {
			return $this->listAgentFiles();
		}

		if ( ! $flow_step_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either flow_step_id or scope="agent"',
			);
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
		$scope        = $input['scope'] ?? null;

		if ( empty( $filename ) ) {
			return array(
				'success' => false,
				'error'   => 'filename is required',
			);
		}

		$filename = sanitize_file_name( $filename );

		if ( 'agent' === $scope ) {
			return $this->getAgentFile( $filename );
		}

		if ( ! $flow_step_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either flow_step_id or scope="agent"',
			);
		}

		return $this->getFileFromFlow( $filename, $flow_step_id );
	}

	/**
	 * Execute write-agent-file ability.
	 *
	 * @param array $input Input parameters with 'filename' and 'content'.
	 * @return array Result with write status.
	 */
	public function executeWriteAgentFile( array $input ): array {
		$filename = $input['filename'] ?? null;
		$content  = $input['content'] ?? null;

		if ( empty( $filename ) ) {
			return array(
				'success' => false,
				'error'   => 'filename is required',
			);
		}

		if ( null === $content ) {
			return array(
				'success' => false,
				'error'   => 'content is required',
			);
		}

		$filename = sanitize_file_name( $filename );

		return $this->writeAgentFile( $filename, $content );
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
		$scope        = $input['scope'] ?? null;

		if ( empty( $filename ) ) {
			return array(
				'success' => false,
				'error'   => 'filename is required',
			);
		}

		$filename = sanitize_file_name( $filename );

		if ( 'agent' === $scope ) {
			return $this->deleteAgentFile( $filename );
		}

		if ( ! $flow_step_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either flow_step_id or scope="agent"',
			);
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
		$scope        = $input['scope'] ?? null;

		if ( 'agent' !== $scope && ! $flow_step_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either flow_step_id or scope="agent"',
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

		if ( 'agent' === $scope ) {
			return $this->uploadToAgent( $file );
		}

		return $this->uploadToFlow( $file, $flow_step_id );
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
	 * List files in the agent directory.
	 *
	 * @return array Result with files.
	 */
	private function listAgentFiles(): array {
		// Self-heal: ensure agent files exist before listing.
		DirectoryManager::ensure_agent_files();

		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();

		if ( ! file_exists( $agent_dir ) ) {
			return array(
				'success' => true,
				'files'   => array(),
				'scope'   => 'agent',
			);
		}

		$files  = array();
		$handle = opendir( $agent_dir );

		if ( $handle ) {
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry || 'index.php' === $entry ) {
					continue;
				}

				$filepath = "{$agent_dir}/{$entry}";
				if ( is_file( $filepath ) ) {
					$files[] = array(
						'filename' => $entry,
						'size'     => filesize( $filepath ),
						'modified' => gmdate( 'c', filemtime( $filepath ) ),
						'type'     => 'core',
					);
				}
			}
			closedir( $handle );
		}

		// Include daily memory summary if the directory exists.
		$daily        = new DailyMemory();
		$daily_result = $daily->list_all();

		if ( ! empty( $daily_result['months'] ) ) {
			$total_days = 0;
			foreach ( $daily_result['months'] as $days ) {
				$total_days += count( $days );
			}

			$files[] = array(
				'filename'    => 'daily',
				'type'        => 'daily_summary',
				'month_count' => count( $daily_result['months'] ),
				'day_count'   => $total_days,
				'months'      => $daily_result['months'],
			);
		}

		return array(
			'success' => true,
			'files'   => array_map( array( $this, 'sanitizeFileEntry' ), $files ),
			'scope'   => 'agent',
		);
	}

	/**
	 * Get a file from the agent directory.
	 *
	 * @param string $filename Filename to retrieve.
	 * @return array Result with file data.
	 */
	private function getAgentFile( string $filename ): array {
		// Self-heal: ensure agent files exist before retrieval.
		DirectoryManager::ensure_agent_files();

		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();
		$filepath          = "{$agent_dir}/{$filename}";

		if ( ! file_exists( $filepath ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'File %s not found in agent directory', $filename ),
			);
		}

		return array(
			'success' => true,
			'file'    => $this->sanitizeFileEntry(
				array(
					'filename' => $filename,
					'size'     => filesize( $filepath ),
					'modified' => gmdate( 'c', filemtime( $filepath ) ),
					'content'  => file_get_contents( $filepath ),
				)
			),
			'scope'   => 'agent',
		);
	}

	/**
	 * Delete a file from the agent directory.
	 *
	 * @param string $filename Filename to delete.
	 * @return array Result with deletion status.
	 */
	private function deleteAgentFile( string $filename ): array {
		if ( in_array( $filename, self::PROTECTED_FILES, true ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Cannot delete protected file: %s', $filename ),
			);
		}

		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();
		$filepath          = "{$agent_dir}/{$filename}";

		if ( ! file_exists( $filepath ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'File %s not found in agent directory', $filename ),
			);
		}

		wp_delete_file( $filepath );

		do_action(
			'datamachine_log',
			'info',
			'Agent file deleted via ability',
			array( 'filename' => $filename )
		);

		return array(
			'success' => true,
			'scope'   => 'agent',
			'message' => sprintf( 'File %s deleted from agent directory', $filename ),
		);
	}

	/**
	 * Write content to an agent file.
	 *
	 * Protected files (SOUL.md, MEMORY.md) cannot be blanked with empty content.
	 *
	 * @param string $filename Filename to write.
	 * @param string $content  Content to write.
	 * @return array Result with write status.
	 */
	private function writeAgentFile( string $filename, string $content ): array {
		if ( in_array( $filename, self::PROTECTED_FILES, true ) && '' === trim( $content ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Cannot write empty content to protected file: %s', $filename ),
			);
		}

		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();

		if ( ! $directory_manager->ensure_directory_exists( $agent_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create agent directory',
			);
		}

		$filepath = "{$agent_dir}/{$filename}";

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return array(
				'success' => false,
				'error'   => 'Filesystem not available',
			);
		}

		$written = $fs->put_contents( $filepath, $content, FS_CHMOD_FILE );

		if ( ! $written ) {
			return array(
				'success' => false,
				'error'   => 'Failed to write file',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Agent file written via ability',
			array( 'filename' => $filename )
		);

		return array(
			'success'  => true,
			'filename' => $filename,
		);
	}

	/**
	 * Upload a file to the agent directory.
	 *
	 * @param array $file File data.
	 * @return array Result with files list.
	 */
	private function uploadToAgent( array $file ): array {
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();

		if ( ! $directory_manager->ensure_directory_exists( $agent_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create agent directory',
			);
		}

		$destination = "{$agent_dir}/{$file['name']}";

		$fs = FilesystemHelper::get();
		if ( ! $fs || ! $fs->copy( $file['tmp_name'], $destination, true ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to store file in agent directory',
			);
		}

		// Return updated file list.
		return $this->listAgentFiles();
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
