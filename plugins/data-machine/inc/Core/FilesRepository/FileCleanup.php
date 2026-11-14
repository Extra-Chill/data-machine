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

use DataMachine\Core\JobArtifactSurfaces;

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
	 * Count old job artifact files eligible under a retention scope.
	 *
	 * For the default retention scope every artifact file is covered: unscoped
	 * (legacy) artifacts and artifacts with an unregistered scope age out on the
	 * default window, and artifacts carrying another registered scope age out on
	 * that scope's own window. For any other scope only artifacts explicitly
	 * stamped with that scope match.
	 *
	 * This walks every job directory and is intended for reporting. Scheduled
	 * cleanup uses cleanup_job_artifacts_batch() so a run stays bounded.
	 *
	 * @param string $retention_scope Retention scope to match.
	 * @param int    $retention_days  Files older than this many days are eligible.
	 * @return int Eligible artifact file count.
	 */
	public function count_old_job_artifacts( string $retention_scope, int $retention_days ): int {
		return $this->walk_all_job_artifact_dirs( $retention_scope, $retention_days, false );
	}

	/**
	 * Clean up old job artifact files for a retention scope (unbounded walk).
	 *
	 * Prefer cleanup_job_artifacts_batch() for scheduled work on large installs.
	 *
	 * @param string $retention_scope Retention scope to match.
	 * @param int    $retention_days  Files older than this many days are eligible.
	 * @return int Deleted artifact file count.
	 */
	public function cleanup_old_job_artifacts( string $retention_scope, int $retention_days ): int {
		return $this->walk_all_job_artifact_dirs( $retention_scope, $retention_days, true );
	}

	/**
	 * Clean up old job artifacts in one bounded, resumable batch.
	 *
	 * Job artifact directories are named by numeric job ID. A batch visits at
	 * most $max_dirs directories with an ID greater than $cursor, in ascending
	 * ID order, and stops early once $deadline (a microtime(true) timestamp)
	 * passes. Directory selection is a single streaming readdir() pass with a
	 * bounded heap, so memory stays proportional to $max_dirs rather than to the
	 * total number of job directories. Directories left empty are removed.
	 *
	 * Callers persist `next_cursor` and pass it back on the next run. When the
	 * walk reaches the end of the directory list, `complete` is true and
	 * `next_cursor` wraps to 0 so the following run starts a fresh sweep.
	 *
	 * @param string $retention_scope Retention scope to match.
	 * @param int    $retention_days  Files older than this many days are eligible.
	 * @param int    $max_dirs        Maximum job directories to visit.
	 * @param int    $cursor          Resume after this job ID (0 = start).
	 * @param float  $deadline        Optional wall-clock deadline; 0 disables it.
	 * @return array{deleted:int, dirs_scanned:int, dirs_removed:int, cursor:int, next_cursor:int, complete:bool}
	 */
	public function cleanup_job_artifacts_batch( string $retention_scope, int $retention_days, int $max_dirs, int $cursor = 0, float $deadline = 0.0 ): array {
		$cursor   = max( 0, $cursor );
		$max_dirs = max( 1, $max_dirs );
		$result   = array(
			'deleted'      => 0,
			'dirs_scanned' => 0,
			'dirs_removed' => 0,
			'cursor'       => $cursor,
			'next_cursor'  => 0,
			'complete'     => true,
		);

		$base    = $this->job_artifacts_base_dir();
		$matcher = $this->job_artifact_matcher( $retention_scope, $retention_days );
		if ( null === $matcher || ! is_dir( $base ) ) {
			return $result;
		}

		$job_ids  = $this->select_job_artifact_ids( $base, $cursor, $max_dirs );
		$selected = count( $job_ids );
		$last_id  = $cursor;

		foreach ( $job_ids as $job_id ) {
			if ( $result['dirs_scanned'] > 0 && $deadline > 0 && microtime( true ) >= $deadline ) {
				break;
			}

			$outcome = $this->process_job_artifact_dir( $base . '/' . $job_id, $matcher, true );
			++$result['dirs_scanned'];
			$result['deleted'] += $outcome['matched'];
			if ( $outcome['removed_dir'] ) {
				++$result['dirs_removed'];
			}
			$last_id = $job_id;
		}

		$reached_end           = $selected < $max_dirs && $result['dirs_scanned'] === $selected;
		$result['complete']    = $reached_end;
		$result['next_cursor'] = $reached_end ? 0 : $last_id;

		return $result;
	}

	private function job_artifacts_base_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'datamachine-artifacts/jobs';
	}

	private function walk_all_job_artifact_dirs( string $retention_scope, int $retention_days, bool $delete ): int {
		$base    = $this->job_artifacts_base_dir();
		$matcher = $this->job_artifact_matcher( $retention_scope, $retention_days );
		$count   = 0;

		if ( null === $matcher || ! is_dir( $base ) ) {
			return 0;
		}

		$handle = opendir( $base );
		if ( false === $handle ) {
			return 0;
		}

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Streaming readdir loop.
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry || ! is_dir( $base . '/' . $entry ) ) {
				continue;
			}

			$count += $this->process_job_artifact_dir( $base . '/' . $entry, $matcher, $delete )['matched'];
		}
		closedir( $handle );

		return $count;
	}

	/**
	 * Select the smallest $limit numeric job directory IDs greater than $cursor.
	 *
	 * @return int[] Ascending job IDs.
	 */
	private function select_job_artifact_ids( string $base, int $cursor, int $limit ): array {
		$handle = opendir( $base );
		if ( false === $handle ) {
			return array();
		}

		$heap = new \SplMaxHeap();
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Streaming readdir loop.
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '' === $entry || ! ctype_digit( $entry ) ) {
				continue;
			}

			$job_id = (int) $entry;
			if ( $job_id <= $cursor ) {
				continue;
			}

			if ( $heap->count() < $limit ) {
				$heap->insert( $job_id );
			} elseif ( $job_id < $heap->top() ) {
				$heap->extract();
				$heap->insert( $job_id );
			}
		}
		closedir( $handle );

		$ids = iterator_to_array( $heap, false );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Delete (or count) eligible artifact files in one job directory.
	 *
	 * @param string   $job_dir Job artifact directory.
	 * @param callable $matcher Receives (file path, mtime) and returns eligibility.
	 * @param bool     $delete  Whether to delete eligible files.
	 * @return array{matched:int, removed_dir:bool}
	 */
	private function process_job_artifact_dir( string $job_dir, callable $matcher, bool $delete ): array {
		$outcome = array(
			'matched'     => 0,
			'removed_dir' => false,
		);

		if ( ! is_dir( $job_dir ) ) {
			return $outcome;
		}

		$files = glob( trailingslashit( $job_dir ) . '*.json' );
		foreach ( false === $files ? array() : $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			$mtime = filemtime( $file );
			if ( false === $mtime || ! $matcher( $file, $mtime ) ) {
				continue;
			}

			if ( ! $delete || wp_delete_file( $file ) ) {
				++$outcome['matched'];
			}
		}

		if ( ! $delete ) {
			return $outcome;
		}

		// Orphaned temp files from interrupted atomic writes age out with the job.
		$temp_files = glob( trailingslashit( $job_dir ) . '.tmp-artifact-*' );
		foreach ( false === $temp_files ? array() : $temp_files as $temp_file ) {
			$mtime = is_file( $temp_file ) ? filemtime( $temp_file ) : false;
			if ( false !== $mtime && $matcher( null, $mtime ) ) {
				wp_delete_file( $temp_file );
			}
		}

		// Only remove a directory that is empty and not freshly created, so an
		// in-flight artifact write is never pulled out from under its writer.
		$dir_mtime = filemtime( $job_dir );
		$is_stale  = $outcome['matched'] > 0 || ( false !== $dir_mtime && $matcher( null, $dir_mtime ) );
		if ( $is_stale && $this->is_empty_directory( $job_dir ) ) {
			$outcome['removed_dir'] = $this->remove_directory( $job_dir );
		}

		return $outcome;
	}

	private function is_empty_directory( string $directory ): bool {
		$entries = scandir( $directory );
		return false !== $entries && array() === array_diff( $entries, array( '.', '..' ) );
	}

	/**
	 * Build the eligibility matcher for a retention scope.
	 *
	 * The matcher receives a file path (or null for age-only checks such as
	 * temp files and directories) plus its mtime.
	 *
	 * @return callable|null Null when the scope is invalid.
	 */
	private function job_artifact_matcher( string $retention_scope, int $retention_days ): ?callable {
		$retention_scope = sanitize_key( $retention_scope );
		if ( '' === $retention_scope ) {
			return null;
		}

		$now            = time();
		$default_cutoff = $now - ( max( 1, $retention_days ) * DAY_IN_SECONDS );

		if ( JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE !== $retention_scope ) {
			return function ( ?string $file, int $mtime ) use ( $retention_scope, $default_cutoff ): bool {
				if ( $mtime >= $default_cutoff ) {
					return false;
				}

				return null === $file || $retention_scope === $this->artifact_retention_scope( $file, $retention_scope );
			};
		}

		// Default policy: covers every artifact. Registered non-default scopes
		// keep their own windows; everything else uses the default window.
		$scope_cutoffs = array();
		foreach ( JobArtifactSurfaces::retentionPolicies() as $scope => $policy ) {
			if ( JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE === $scope ) {
				continue;
			}

			$scope_cutoffs[ (string) $scope ] = $now - ( max( 1, (int) ( $policy['max_age_days'] ?? $retention_days ) ) * DAY_IN_SECONDS );
		}

		$all_cutoffs   = array_merge( array( $default_cutoff ), array_values( $scope_cutoffs ) );
		$oldest_cutoff = min( $all_cutoffs );
		$newest_cutoff = max( $all_cutoffs );

		return function ( ?string $file, int $mtime ) use ( $default_cutoff, $scope_cutoffs, $oldest_cutoff, $newest_cutoff ): bool {
			if ( null === $file ) {
				return $mtime < $default_cutoff;
			}

			// Newer than every window: keep without reading the file.
			if ( $mtime >= $newest_cutoff ) {
				return false;
			}

			// Older than every window: eligible whatever its scope, no read needed.
			if ( $mtime < $oldest_cutoff ) {
				return true;
			}

			$scope = $this->artifact_retention_scope( $file, JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE );
			if ( null === $scope ) {
				return false;
			}

			return $mtime < ( $scope_cutoffs[ $scope ] ?? $default_cutoff );
		};
	}

	/**
	 * Read an artifact's declared retention scope.
	 *
	 * @return string|null Sanitized scope ('' when the artifact declares none or
	 *                     is not a JSON object), or null when unreadable.
	 */
	private function artifact_retention_scope( string $file_path, string $retention_scope ): ?string {
		FilesystemHelper::make_group_writable( $file_path, FilesystemHelper::SHARED_FILE_PERMISSIONS );
		if ( ! is_readable( $file_path ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'FileCleanup: Artifact is not readable by the cleanup runtime user.',
				array(
					'file_path'       => $file_path,
					'retention_scope' => $retention_scope,
				)
			);
			return null;
		}

		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $contents ) || '' === $contents ) {
			return '';
		}

		$payload = json_decode( $contents, true );
		if ( ! is_array( $payload ) || ! isset( $payload['retention_scope'] ) ) {
			return '';
		}

		return sanitize_key( (string) $payload['retention_scope'] );
	}
}
