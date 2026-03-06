<?php
/**
 * Agent File Abilities
 *
 * Abilities API primitives for agent memory file operations.
 * Handles the agent identity layer (SOUL.md, MEMORY.md, custom files)
 * and composes with the user layer (USER.md) for a unified view.
 *
 * @package DataMachine\Abilities\File
 * @since   0.38.0
 */

namespace DataMachine\Abilities\File;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FilesystemHelper;

defined( 'ABSPATH' ) || exit;

class AgentFileAbilities {

	private static bool $registered = false;

	public function __construct() {
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
			$this->registerListAgentFiles();
			$this->registerGetAgentFile();
			$this->registerWriteAgentFile();
			$this->registerDeleteAgentFile();
			$this->registerUploadAgentFile();
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

	private function registerListAgentFiles(): void {
		wp_register_ability(
			'datamachine/list-agent-files',
			array(
				'label'               => __( 'List Agent Files', 'data-machine' ),
				'description'         => __( 'List memory files from the agent identity and user layers.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_id' => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping. Defaults to 0 (resolved to default agent).', 'data-machine' ),
							'default'     => 0,
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
				'execute_callback'    => array( $this, 'executeListAgentFiles' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetAgentFile(): void {
		wp_register_ability(
			'datamachine/get-agent-file',
			array(
				'label'               => __( 'Get Agent File', 'data-machine' ),
				'description'         => __( 'Get a single agent memory file with content.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename' ),
					'properties' => array(
						'filename' => array(
							'type'        => 'string',
							'description' => __( 'Name of the file to retrieve', 'data-machine' ),
						),
						'user_id'  => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping.', 'data-machine' ),
							'default'     => 0,
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
				'execute_callback'    => array( $this, 'executeGetAgentFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

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
						'user_id'  => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping.', 'data-machine' ),
							'default'     => 0,
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

	private function registerDeleteAgentFile(): void {
		wp_register_ability(
			'datamachine/delete-agent-file',
			array(
				'label'               => __( 'Delete Agent File', 'data-machine' ),
				'description'         => __( 'Delete an agent memory file. Protected files (SOUL.md, MEMORY.md) cannot be deleted.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename' ),
					'properties' => array(
						'filename' => array(
							'type'        => 'string',
							'description' => __( 'Name of the file to delete', 'data-machine' ),
						),
						'user_id'  => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping.', 'data-machine' ),
							'default'     => 0,
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
				'execute_callback'    => array( $this, 'executeDeleteAgentFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerUploadAgentFile(): void {
		wp_register_ability(
			'datamachine/upload-agent-file',
			array(
				'label'               => __( 'Upload Agent File', 'data-machine' ),
				'description'         => __( 'Upload a file to the agent memory directory.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'file_data' ),
					'properties' => array(
						'file_data' => array(
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
						'user_id'   => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping.', 'data-machine' ),
							'default'     => 0,
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
				'execute_callback'    => array( $this, 'executeUploadAgentFile' ),
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
	 * List agent memory files from both identity and user layers.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with files list.
	 */
	public function executeListAgentFiles( array $input ): array {
		DirectoryManager::ensure_agent_files();

		$dm      = new DirectoryManager();
		$user_id = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );

		$agent_dir = $dm->get_agent_identity_directory_for_user( $user_id );
		$user_dir  = $dm->get_user_directory( $user_id );

		$files = array();
		$seen  = array();

		// Agent identity layer first (wins on conflicts), then user layer.
		foreach ( array( $agent_dir, $user_dir ) as $dir ) {
			if ( ! file_exists( $dir ) ) {
				continue;
			}

			$handle = opendir( $dir );
			if ( ! $handle ) {
				continue;
			}

			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry || 'index.php' === $entry ) {
					continue;
				}

				if ( isset( $seen[ $entry ] ) ) {
					continue;
				}

				$filepath = "{$dir}/{$entry}";
				if ( is_file( $filepath ) ) {
					$files[]        = array(
						'filename' => $entry,
						'size'     => filesize( $filepath ),
						'modified' => gmdate( 'c', filemtime( $filepath ) ),
						'type'     => 'core',
					);
					$seen[ $entry ] = true;
				}
			}
			closedir( $handle );
		}

		// Include daily memory summary.
		$daily        = new DailyMemory( $user_id );
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
		);
	}

	/**
	 * Get a single agent file with content.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with file data.
	 */
	public function executeGetAgentFile( array $input ): array {
		DirectoryManager::ensure_agent_files();

		$filename = sanitize_file_name( $input['filename'] ?? '' );
		$dm       = new DirectoryManager();
		$user_id  = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );
		$filepath = $this->resolveFilePath( $dm, $user_id, $filename );

		if ( ! $filepath ) {
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
		);
	}

	/**
	 * Write content to an agent file.
	 *
	 * Routes to the correct layer: USER.md → user dir, everything else → agent identity dir.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with write status.
	 */
	public function executeWriteAgentFile( array $input ): array {
		$filename = sanitize_file_name( $input['filename'] ?? '' );
		$content  = $input['content'] ?? '';

		if ( in_array( $filename, FileConstants::PROTECTED_FILES, true ) && '' === trim( $content ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Cannot write empty content to protected file: %s', $filename ),
			);
		}

		$dm         = new DirectoryManager();
		$user_id    = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );
		$target_dir = in_array( $filename, FileConstants::USER_LAYER_FILES, true )
			? $dm->get_user_directory( $user_id )
			: $dm->get_agent_identity_directory_for_user( $user_id );

		if ( ! $dm->ensure_directory_exists( $target_dir ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create agent directory',
			);
		}

		$filepath = "{$target_dir}/{$filename}";

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

		FilesystemHelper::make_group_writable( $filepath );

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
	 * Delete an agent file.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with deletion status.
	 */
	public function executeDeleteAgentFile( array $input ): array {
		$filename = sanitize_file_name( $input['filename'] ?? '' );

		if ( in_array( $filename, FileConstants::PROTECTED_FILES, true ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Cannot delete protected file: %s', $filename ),
			);
		}

		$dm       = new DirectoryManager();
		$user_id  = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );
		$filepath = $this->resolveFilePath( $dm, $user_id, $filename );

		if ( ! $filepath ) {
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
			array(
				'filename' => $filename,
				'user_id'  => $user_id,
			)
		);

		return array(
			'success' => true,
			'message' => sprintf( 'File %s deleted from agent directory', $filename ),
		);
	}

	/**
	 * Upload a file to the agent memory directory.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with updated file list.
	 */
	public function executeUploadAgentFile( array $input ): array {
		$file = $input['file_data'] ?? null;

		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return array(
				'success' => false,
				'error'   => 'No file data provided',
			);
		}

		$dm        = new DirectoryManager();
		$user_id   = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );
		$agent_dir = $dm->get_agent_identity_directory_for_user( $user_id );

		if ( ! $dm->ensure_directory_exists( $agent_dir ) ) {
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
		return $this->executeListAgentFiles( array( 'user_id' => $user_id ) );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Resolve a filename to its absolute path across agent layers.
	 *
	 * Checks the agent identity directory first, then the user directory.
	 *
	 * @param DirectoryManager $dm       Directory manager instance.
	 * @param int              $user_id  Effective user ID.
	 * @param string           $filename Filename to resolve.
	 * @return string|null Absolute file path, or null if not found.
	 */
	private function resolveFilePath( DirectoryManager $dm, int $user_id, string $filename ): ?string {
		$agent_path = $dm->get_agent_identity_directory_for_user( $user_id ) . '/' . $filename;
		if ( file_exists( $agent_path ) ) {
			return $agent_path;
		}

		$user_path = $dm->get_user_directory( $user_id ) . '/' . $filename;
		if ( file_exists( $user_path ) ) {
			return $user_path;
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
