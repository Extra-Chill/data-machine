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
