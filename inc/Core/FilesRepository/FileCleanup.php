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
		return $this->walk_old_files( $retention_days, true, false );
	}

	/**
	 * Count old files and job directories eligible for cleanup.
	 *
	 * @param int $retention_days Files older than this many days are eligible.
	 * @return int Number of eligible files/directories.
	 */
	public function count_old_files( int $retention_days = 7 ): int {
		return $this->walk_old_files( $retention_days, false, true );
	}

	private function walk_old_files( int $retention_days, bool $delete, bool $count_job_dirs ): int {
		$upload_dir    = wp_upload_dir();
		$base          = trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR;
		$cutoff_time   = time() - ( $retention_days * DAY_IN_SECONDS );
		$matched_count = 0;

		if ( ! is_dir( $base ) ) {
			return 0;
		}

		// Traverse: pipeline -> flow -> files.
		$pipeline_dirs = glob( "{$base}/pipeline-*", GLOB_ONLYDIR );
		$pipeline_dirs = is_array( $pipeline_dirs ) ? $pipeline_dirs : array();

		foreach ( $pipeline_dirs as $pipeline_dir ) {
			$flow_dirs = glob( "{$pipeline_dir}/flow-*", GLOB_ONLYDIR );
			$flow_dirs = is_array( $flow_dirs ) ? $flow_dirs : array();

			foreach ( $flow_dirs as $flow_dir ) {
				// Clean up flow files (not context!)
				$flow_id   = basename( $flow_dir );
				$files_dir = "{$flow_dir}/{$flow_id}-files";

				if ( is_dir( $files_dir ) ) {
					$files = glob( "{$files_dir}/*" );
					$files = is_array( $files ) ? $files : array();
					foreach ( $files as $file ) {
						if ( ! is_file( $file ) || filemtime( $file ) >= $cutoff_time ) {
							continue;
						}

						if ( ! $delete || wp_delete_file( $file ) ) {
							++$matched_count;
						}
					}

					// Remove empty files directory
					if ( $delete && empty( glob( "{$files_dir}/*" ) ) ) {
						$this->remove_directory( $files_dir );
					}
				}

				// Clean up old job directories
				$jobs_dir = "{$flow_dir}/jobs";

				if ( is_dir( $jobs_dir ) ) {
					$job_dirs = glob( "{$jobs_dir}/job-*", GLOB_ONLYDIR );
					$job_dirs = is_array( $job_dirs ) ? $job_dirs : array();
					foreach ( $job_dirs as $job_dir ) {
						$files   = glob( "{$job_dir}/*" );
						$files   = is_array( $files ) ? $files : array();
						$all_old = true;

						foreach ( $files as $file ) {
							if ( is_file( $file ) && filemtime( $file ) >= $cutoff_time ) {
								$all_old = false;
								break;
							}
						}

						if ( $all_old && ! empty( $files ) ) {
							if ( $delete ) {
								$this->remove_directory( $job_dir );
							}

							if ( $count_job_dirs ) {
								++$matched_count;
							}
						}
					}

					// Remove empty jobs directory
					if ( $delete && empty( glob( "{$jobs_dir}/*" ) ) ) {
						$this->remove_directory( $jobs_dir );
					}
				}
			}
		}

		return $matched_count;
	}

	/**
	 * Count old artifact files for a retention scope.
	 *
	 * @param string $retention_scope Retention scope to match.
	 * @param int    $retention_days  Files older than this many days are eligible.
	 * @return int Eligible artifact file count.
	 */
	public function count_old_job_artifacts( string $retention_scope, int $retention_days ): int {
		return $this->walk_old_job_artifacts( $retention_scope, $retention_days, false );
	}

	/**
	 * Clean up old artifact files for a retention scope.
	 *
	 * @param string $retention_scope Retention scope to match.
	 * @param int    $retention_days  Files older than this many days are eligible.
	 * @return int Deleted artifact file count.
	 */
	public function cleanup_old_job_artifacts( string $retention_scope, int $retention_days ): int {
		return $this->walk_old_job_artifacts( $retention_scope, $retention_days, true );
	}

	private function walk_old_job_artifacts( string $retention_scope, int $retention_days, bool $delete ): int {
		$upload_dir      = wp_upload_dir();
		$base            = trailingslashit( $upload_dir['basedir'] ) . 'datamachine-artifacts/jobs';
		$cutoff_time     = time() - ( $retention_days * DAY_IN_SECONDS );
		$retention_scope = sanitize_key( $retention_scope );
		$count           = 0;

		if ( '' === $retention_scope || ! is_dir( $base ) ) {
			return 0;
		}

		$job_dirs = glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR );
		if ( false === $job_dirs ) {
			return 0;
		}

		foreach ( $job_dirs as $job_dir ) {
			$files = glob( trailingslashit( $job_dir ) . '*.json' );
			if ( false === $files ) {
				continue;
			}

			foreach ( $files as $file ) {
				if ( ! is_file( $file ) || filemtime( $file ) >= $cutoff_time || ! $this->artifact_matches_retention_scope( $file, $retention_scope ) ) {
					continue;
				}

				if ( ! $delete || wp_delete_file( $file ) ) {
					++$count;
				}
			}

			if ( $delete && empty( glob( trailingslashit( $job_dir ) . '*' ) ) ) {
				$this->remove_directory( $job_dir );
			}
		}

		return $count;
	}

	private function artifact_matches_retention_scope( string $file_path, string $retention_scope ): bool {
		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $contents ) || '' === $contents ) {
			return false;
		}

		$payload = json_decode( $contents, true );
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$scope = isset( $payload['retention_scope'] ) ? sanitize_key( (string) $payload['retention_scope'] ) : '';
		if ( $retention_scope === $scope ) {
			return true;
		}

		return false;
	}
}
