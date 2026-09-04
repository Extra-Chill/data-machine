<?php
/**
 * Remote file download operations using WordPress native functions.
 *
 * Replaces manual wp_remote_get() + file_put_contents() with WordPress's
 * download_url() function for better integration and automatic temp file handling.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.2.1
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteFileDownloader {

	/**
	 * Directory manager instance
	 *
	 * @var DirectoryManager
	 */
	private $directory_manager;

	/**
	 * File storage instance
	 *
	 * @var FileStorage
	 */
	private $file_storage;

	public function __construct() {
		$this->directory_manager = new DirectoryManager();
		$this->file_storage      = new FileStorage();
	}

	/**
	 * Download and store remote file using WordPress native download_url()
	 *
	 * @param string $url Remote file URL
	 * @param string $filename Desired filename
	 * @param array  $context Context array with pipeline/flow metadata
	 * @param array  $options Additional options (timeout)
	 * @return array|null File information on success, null on failure
	 */
	public function download_remote_file( string $url, string $filename, array $context, array $options = array() ): ?array {
		if ( empty( $url ) || empty( $filename ) ) {
			do_action(
				'datamachine_log',
				'error',
				'RemoteFileDownloader: Missing required parameters.',
				array(
					'has_url'      => ! empty( $url ),
					'has_filename' => ! empty( $filename ),
				)
			);
			return null;
		}

		$timeout = $options['timeout'] ?? 30;

		// Ensure directory exists
		$directory = $this->directory_manager->get_flow_files_directory(
			$context['pipeline_id'],
			$context['flow_id']
		);

		if ( ! $this->directory_manager->ensure_directory_exists( $directory ) ) {
			return null;
		}

		// Use WordPress native download function
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp_file = download_url( $url, $timeout );

		if ( is_wp_error( $temp_file ) ) {
			do_action(
				'datamachine_log',
				'error',
				'RemoteFileDownloader: Failed to download remote file.',
				array(
					'url'   => esc_url_raw( $url ),
					'error' => $temp_file->get_error_message(),
				)
			);
			return null;
		}

		// Move to final destination
		$safe_filename = sanitize_file_name( $filename );
		$destination   = "{$directory}/{$safe_filename}";

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			wp_delete_file( $temp_file );
			do_action(
				'datamachine_log',
				'error',
				'RemoteFileDownloader: Filesystem not available.',
				array(
					'url' => esc_url_raw( $url ),
				)
			);
			return null;
		}

		$copied = $fs->copy( $temp_file, $destination, true );

		if ( ! $copied ) {
			wp_delete_file( $temp_file );
			do_action(
				'datamachine_log',
				'error',
				'RemoteFileDownloader: Failed to move downloaded file.',
				array(
					'url'         => esc_url_raw( $url ),
					'destination' => $destination,
				)
			);
			return null;
		}

		wp_delete_file( $temp_file );

		// Generate public URL using FileStorage utility
		$file_url = $this->file_storage->get_public_url( $destination );

		return array(
			'path'       => $destination,
			'filename'   => $safe_filename,
			'size'       => filesize( $destination ),
			'url'        => $file_url,
			'source_url' => $url,
		);
	}
}
