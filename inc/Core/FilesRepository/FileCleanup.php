<?php
/**
 * File cleanup and retention policy management.
 *
 * Handles deletion operations using WordPress Filesystem API (wp_delete_file, WP_Filesystem).
 * Manages retention policies, job cleanup, and pipeline directory removal.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.2.1
 */

namespace DataMachine\Core\FilesRepository;

use DataMachine\Core\PluginSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FileCleanup {

	/**
	 * Repository directory name
	 */
	private const REPOSITORY_DIR = 'datamachine-files';

	/**
	 * Directory manager instance
	 *
	 * @var DirectoryManager
	 */
	private $directory_manager;

	public function __construct() {
		$this->directory_manager = new DirectoryManager();
	}

	/**
	 * Remove a directory recursively.
	 */
	private function remove_directory( string $directory_path ): bool {
		if ( ! is_dir( $directory_path ) ) {
			return true;
		}

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			do_action(
				'datamachine_log',
				'error',
				'FilesRepository: Filesystem not available.',
				array(
					'directory_path' => $directory_path,
				)
			);
			return false;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory_path, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				$fs->rmdir( $file->getRealPath() );
			} else {
				$fs->delete( $file->getRealPath() );
			}
		}

		$deleted = $fs->rmdir( $directory_path );

		if ( ! $deleted ) {
			do_action(
				'datamachine_log',
				'error',
				'FilesRepository: Failed to delete directory.',
				array(
					'directory_path' => $directory_path,
				)
			);
		}

		return $deleted;
	}

	/**
	 * Delete entire pipeline directory and all contents
	 *
	 * Removes pipeline directory including context files, flow directories,
	 * and all nested job data. Uses WordPress filesystem API for safe deletion.
	 *
	 * @param int $pipeline_id Pipeline ID
	 * @return bool True if directory deleted or doesn't exist, false on failure
	 */
	public function delete_pipeline_directory( int $pipeline_id ): bool {
		$pipeline_dir = $this->directory_manager->get_pipeline_directory( $pipeline_id );

		if ( ! is_dir( $pipeline_dir ) ) {
			return true;
		}

		$deleted = $this->remove_directory( $pipeline_dir );

		if ( $deleted ) {
			do_action(
				'datamachine_log',
				'info',
				'Pipeline directory deleted successfully.',
				array(
					'pipeline_id'    => $pipeline_id,
					'directory_path' => $pipeline_dir,
				)
			);
		}

		return $deleted;
	}

	/**
	 * Clean up job data packets for a specific job
	 *
	 * @param int   $job_id Job ID
	 * @param array $context Context array with pipeline/flow metadata
	 * @return int Number of directories deleted (0 or 1)
	 */
	public function cleanup_job_data_packets( int $job_id, array $context ): int {
		$job_dir = $this->directory_manager->get_job_directory(
			$context['pipeline_id'],
			$context['flow_id'],
			$job_id
		);

		if ( ! is_dir( $job_dir ) ) {
			return 0;
		}

		return $this->remove_directory( $job_dir ) ? 1 : 0;
	}

	/**
	 * Clean up old files (hierarchical traversal)
	 *
	 * @param int $retention_days Files older than this many days will be deleted
	 * @return int Number of files deleted
	 */
	public function cleanup_old_files( int $retention_days = 7 ): int {
		$upload_dir    = wp_upload_dir();
		$base          = trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR;
		$cutoff_time   = time() - ( $retention_days * DAY_IN_SECONDS );
		$deleted_count = 0;

		if ( ! is_dir( $base ) ) {
			return 0;
		}

		// Traverse: pipeline → flow → files
		$pipeline_dirs = glob( "{$base}/pipeline-*", GLOB_ONLYDIR );

		foreach ( $pipeline_dirs as $pipeline_dir ) {
			$flow_dirs = glob( "{$pipeline_dir}/flow-*", GLOB_ONLYDIR );

			foreach ( $flow_dirs as $flow_dir ) {
				// Clean up flow files (not context!)
				$flow_id   = basename( $flow_dir );
				$files_dir = "{$flow_dir}/{$flow_id}-files";

				if ( is_dir( $files_dir ) ) {
					$files = glob( "{$files_dir}/*" );
					foreach ( $files as $file ) {
						if ( is_file( $file ) && filemtime( $file ) < $cutoff_time && wp_delete_file( $file ) ) {
							++$deleted_count;
						}
					}

					// Remove empty files directory
					if ( empty( glob( "{$files_dir}/*" ) ) ) {
						$this->remove_directory( $files_dir );
					}
				}

				// Clean up old job directories
				$jobs_dir = "{$flow_dir}/jobs";

				if ( is_dir( $jobs_dir ) ) {
					$job_dirs = glob( "{$jobs_dir}/job-*", GLOB_ONLYDIR );
					foreach ( $job_dirs as $job_dir ) {
						$files   = glob( "{$job_dir}/*" );
						$all_old = true;

						foreach ( $files as $file ) {
							if ( is_file( $file ) && filemtime( $file ) >= $cutoff_time ) {
								$all_old = false;
								break;
							}
						}

						if ( $all_old && ! empty( $files ) ) {
							$this->remove_directory( $job_dir );
						}
					}

					// Remove empty jobs directory
					if ( empty( glob( "{$jobs_dir}/*" ) ) ) {
						$this->remove_directory( $jobs_dir );
					}
				}
			}
		}

		return $deleted_count;
	}
}

/**
 * Register scheduled cleanup action for old files
 */
add_action(
	'datamachine_cleanup_old_files',
	function () {
		$file_cleanup   = new FileCleanup();
		$retention_days = PluginSettings::get( 'file_retention_days', 7 );

		$deleted_count = $file_cleanup->cleanup_old_files( $retention_days );

		do_action(
			'datamachine_log',
			'debug',
			'FilesRepository: Cleanup completed',
			array(
				'files_deleted'  => $deleted_count,
				'retention_days' => $retention_days,
			)
		);
	}
);

/**
 * Schedule cleanup after Action Scheduler is fully initialized.
 * Only check in admin context to avoid database queries on every frontend request.
 */
add_action(
	'action_scheduler_init',
	function () {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! as_next_scheduled_action( 'datamachine_cleanup_old_files', array(), 'datamachine-files' ) ) {
			as_schedule_recurring_action(
				time() + WEEK_IN_SECONDS,
				WEEK_IN_SECONDS,
				'datamachine_cleanup_old_files',
				array(),
				'datamachine-files'
			);
		}
	}
);
