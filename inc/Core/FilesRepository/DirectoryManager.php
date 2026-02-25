<?php
/**
 * Directory path management for hierarchical file storage.
 *
 * Provides pipeline → flow → job directory structure with WordPress-native
 * path operations. All paths use wp_upload_dir() as base with organized
 * subdirectory hierarchy.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.2.1
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DirectoryManager {

	/**
	 * Repository directory name
	 */
	private const REPOSITORY_DIR = 'datamachine-files';

	/**
	 * Whether agent file scaffolding has been verified this request.
	 *
	 * @var bool
	 */
	private static bool $agent_files_ensured = false;

	/**
	 * Ensure default agent files exist (SOUL.md, USER.md, MEMORY.md).
	 *
	 * Lazy self-healing: runs at most once per PHP request on the first
	 * call. If any registered memory files are missing, recreates them
	 * from scaffold defaults without overwriting existing files.
	 *
	 * Call this from any read path that depends on agent files existing
	 * (directives, REST API, CLI, etc.). The static flag makes repeated
	 * calls free.
	 *
	 * @since 0.32.0
	 * @return void
	 */
	public static function ensure_agent_files(): void {
		if ( self::$agent_files_ensured ) {
			return;
		}

		self::$agent_files_ensured = true;

		if ( function_exists( 'datamachine_ensure_default_memory_files' ) ) {
			datamachine_ensure_default_memory_files();
		}
	}

	/**
	 * Reset the ensure-agent-files flag. For testing only.
	 *
	 * @since 0.32.0
	 * @return void
	 */
	public static function reset_ensure_flag(): void {
		self::$agent_files_ensured = false;
	}

	/**
	 * Get pipeline directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @return string Full path to pipeline directory
	 */
	public function get_pipeline_directory( int|string $pipeline_id ): string {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR;
		return "{$base}/pipeline-{$pipeline_id}";
	}

	/**
	 * Get flow directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @param int|string $flow_id Flow ID or 'direct' for direct execution
	 * @return string Full path to flow directory
	 */
	public function get_flow_directory( int|string $pipeline_id, int|string $flow_id ): string {
		$pipeline_dir = $this->get_pipeline_directory( $pipeline_id );
		return "{$pipeline_dir}/flow-{$flow_id}";
	}

	/**
	 * Get job directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @param int|string $flow_id Flow ID or 'direct' for direct execution
	 * @param int|string $job_id Job ID
	 * @return string Full path to job directory
	 */
	public function get_job_directory( int|string $pipeline_id, int|string $flow_id, int|string $job_id ): string {
		$flow_dir = $this->get_flow_directory( $pipeline_id, $flow_id );
		return "{$flow_dir}/jobs/job-{$job_id}";
	}

	/**
	 * Get flow files directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @param int|string $flow_id Flow ID or 'direct' for direct execution
	 * @return string Full path to flow files directory
	 */
	public function get_flow_files_directory( int|string $pipeline_id, int|string $flow_id ): string {
		$flow_dir = $this->get_flow_directory( $pipeline_id, $flow_id );
		return "{$flow_dir}/flow-{$flow_id}-files";
	}

	/**
	 * Get pipeline context directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @param string     $pipeline_name Pipeline name (unused, for signature compatibility)
	 * @return string Full path to pipeline context directory
	 */
	public function get_pipeline_context_directory( int|string $pipeline_id, string $pipeline_name ): string {
		$pipeline_dir = $this->get_pipeline_directory( $pipeline_id );
		return "{$pipeline_dir}/context";
	}

	/**
	 * Get agent directory path
	 *
	 * @return string Full path to agent directory
	 */
	public function get_agent_directory(): string {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR;
		return "{$base}/agent";
	}

	/**
	 * Get workspace directory path.
	 *
	 * Returns the managed workspace for agent file operations (cloning repos, etc.).
	 * Path resolution order:
	 * 1. DATAMACHINE_WORKSPACE_PATH constant (if defined)
	 * 2. /var/lib/datamachine/workspace (if writable or creatable)
	 *
	 * The workspace must be outside the web root. No uploads fallback is
	 * provided because the workspace supports write operations — placing
	 * writable agent files inside wp-content/uploads would create a
	 * remote code execution risk.
	 *
	 * If neither option resolves, an empty string is returned and
	 * Workspace::ensure_exists() will fail with a clear error.
	 *
	 * @since 0.31.0
	 * @return string Full path to workspace directory, or empty string if unavailable.
	 */
	public function get_workspace_directory(): string {
		// 1. Explicit constant override.
		if ( defined( 'DATAMACHINE_WORKSPACE_PATH' ) ) {
			return rtrim( DATAMACHINE_WORKSPACE_PATH, '/' );
		}

		// 2. System-level default (outside web root).
		$system_path = '/var/lib/datamachine/workspace';
		$system_base = dirname( $system_path );
		if ( is_writable( $system_base ) || ( ! file_exists( $system_base ) && is_writable( dirname( $system_base ) ) ) ) {
			return $system_path;
		}

		// No fallback. Log the issue so admins know how to fix it.
		do_action(
			'datamachine_log',
			'error',
			'Workspace unavailable: /var/lib/datamachine/ is not writable. Define DATAMACHINE_WORKSPACE_PATH in wp-config.php to set a custom path outside the web root.',
			array()
		);

		return '';
	}

	/**
	 * Ensure directory exists
	 *
	 * @param string $directory Directory path
	 * @return bool True if exists or was created
	 */
	public function ensure_directory_exists( string $directory ): bool {
		if ( ! file_exists( $directory ) ) {
			$created = wp_mkdir_p( $directory );
			if ( ! $created ) {
				do_action(
					'datamachine_log',
					'error',
					'DirectoryManager: Failed to create directory.',
					array(
						'path' => $directory,
					)
				);
				return false;
			}
		}
		return true;
	}
}
