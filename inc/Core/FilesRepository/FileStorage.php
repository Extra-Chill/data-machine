<?php
/**
 * Local file storage operations for Data Machine.
 *
 * Handles file CRUD operations, data packet persistence, and public URL generation.
 * All methods use WordPress native functions (wp_delete_file, wp_json_encode, sanitize_file_name).
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.2.1
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FileStorage {

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
	 * Store file in flow files directory
	 *
	 * @param string $source_path Source file path
	 * @param string $filename Original filename
	 * @param array  $context Context array with pipeline/flow metadata
	 * @return string|false Repository file path on success, false on failure
	 */
	public function store_file( string $source_path, string $filename, array $context ): string|false {
		$directory = $this->directory_manager->get_flow_files_directory(
			$context['pipeline_id'],
			$context['flow_id']
		);

		if ( ! $this->directory_manager->ensure_directory_exists( $directory ) ) {
			return false;
		}

		if ( ! file_exists( $source_path ) ) {
			do_action(
				'datamachine_log',
				'error',
				'FileStorage: Source file not found.',
				array(
					'source_path' => $source_path,
				)
			);
			return false;
		}

		$safe_filename = sanitize_file_name( $filename );
		$destination   = "{$directory}/{$safe_filename}";

		$fs = FilesystemHelper::get();
		if ( ! $fs || ! $fs->copy( $source_path, $destination, true ) ) {
			do_action(
				'datamachine_log',
				'error',
				'FileStorage: Failed to copy file.',
				array(
					'source'      => $source_path,
					'destination' => $destination,
				)
			);
			return false;
		}

		return $destination;
	}

	/**
	 * Get all files in flow files directory
	 *
	 * @param array $context Context array with pipeline/flow metadata
	 * @return array Array of file information
	 */
	public function get_all_files( array $context ): array {
		$directory = $this->directory_manager->get_flow_files_directory(
			$context['pipeline_id'],
			$context['flow_id']
		);

		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$files     = glob( "{$directory}/*" );
		$file_list = array();

		foreach ( $files as $file_path ) {
			if ( is_file( $file_path ) ) {
				$filename = basename( $file_path );

				if ( 'index.php' === $filename ) {
					continue;
				}

				$file_list[] = array(
					'filename' => $filename,
					'path'     => $file_path,
					'size'     => filesize( $file_path ),
					'modified' => filemtime( $file_path ),
				);
			}
		}

		return $file_list;
	}

	/**
	 * Delete file from flow files directory
	 *
	 * @param string $filename Filename to delete
	 * @param array  $context Context array with pipeline/flow metadata
	 * @return bool True on success, false on failure
	 */
	public function delete_file( string $filename, array $context ): bool {
		$directory = $this->directory_manager->get_flow_files_directory(
			$context['pipeline_id'],
			$context['flow_id']
		);

		$safe_filename = sanitize_file_name( $filename );
		$file_path     = "{$directory}/{$safe_filename}";

		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		return wp_delete_file( $file_path );
	}

	/**
	 * Generate public URL for repository file
	 *
	 * @param string $file_path Full filesystem path to file
	 * @return string Public URL to file
	 */
	public function get_public_url( string $file_path ): string {
		$upload_dir    = wp_upload_dir();
		$base_url      = trailingslashit( $upload_dir['baseurl'] ) . self::REPOSITORY_DIR;
		$relative_path = str_replace(
			trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR,
			'',
			$file_path
		);
		return $base_url . $relative_path;
	}

	/**
	 * Store data packet as JSON file
	 *
	 * @param array $data Data packet array
	 * @param int   $job_id Job ID
	 * @param array $context Context array with pipeline/flow metadata
	 * @return array|false Reference object on success, false on failure
	 */
	public function store_data_packet( array $data, int $job_id, array $context ): array|false {
		$directory = $this->directory_manager->get_job_directory(
			$context['pipeline_id'],
			$context['flow_id'],
			$job_id
		);

		if ( ! $this->directory_manager->ensure_directory_exists( $directory ) ) {
			do_action(
				'datamachine_log',
				'error',
				'FileStorage: Failed to create job directory.',
				array(
					'job_id' => $job_id,
				)
			);
			return false;
		}

		$file_path = "{$directory}/data.json";

		// Load existing data for accumulation
		$accumulated_data = array();
		$fs               = FilesystemHelper::get();
		if ( $fs && file_exists( $file_path ) ) {
			$existing_json = $fs->get_contents( $file_path );
			if ( false !== $existing_json ) {
				$accumulated_data = json_decode( $existing_json, true );
				if ( ! is_array( $accumulated_data ) ) {
					$accumulated_data = array();
				}
			}
		}

		// Merge new data (newest first)
		if ( ! empty( $data ) ) {
			$accumulated_data = array_merge( $data, $accumulated_data );
		}

		// Serialize and write
		$json_data = wp_json_encode( $accumulated_data, JSON_UNESCAPED_UNICODE );
		if ( false === $json_data ) {
			do_action(
				'datamachine_log',
				'error',
				'FileStorage: Failed to encode data packet.',
				array(
					'job_id' => $job_id,
				)
			);
			return false;
		}

		if ( ! $fs || ! $fs->put_contents( $file_path, $json_data ) ) {
			do_action(
				'datamachine_log',
				'error',
				'FileStorage: Failed to write data packet.',
				array(
					'job_id'    => $job_id,
					'file_path' => $file_path,
				)
			);
			return false;
		}

		return array(
			'is_data_reference' => true,
			'job_id'            => $job_id,
			'file_path'         => $file_path,
			'stored_at'         => time(),
		);
	}

	/**
	 * Retrieve data packet from JSON file
	 *
	 * @param array $reference Reference object from store_data_packet()
	 * @return array|null Retrieved data or null on failure
	 */
	public function retrieve_data_packet( array $reference ): ?array {
		if ( ! isset( $reference['is_data_reference'] ) || ! $reference['is_data_reference'] ) {
			return $reference['data'] ?? $reference;
		}

		$file_path = $reference['file_path'] ?? '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			do_action(
				'datamachine_log',
				'error',
				'FileStorage: Data packet file not found.',
				array(
					'file_path' => $file_path,
				)
			);
			return null;
		}

		$fs        = FilesystemHelper::get();
		$json_data = $fs ? $fs->get_contents( $file_path ) : false;
		if ( false === $json_data ) {
			do_action(
				'datamachine_log',
				'error',
				'FileStorage: Failed to read data packet.',
				array(
					'file_path' => $file_path,
				)
			);
			return null;
		}

		$data = json_decode( $json_data, true );
		if ( null === $data && json_last_error() !== JSON_ERROR_NONE ) {
			do_action(
				'datamachine_log',
				'error',
				'FileStorage: Failed to decode data packet.',
				array(
					'json_error' => json_last_error_msg(),
				)
			);
			return null;
		}

		return $data;
	}
}
