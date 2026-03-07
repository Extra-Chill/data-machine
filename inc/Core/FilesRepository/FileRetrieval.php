<?php
/**
 * File Retrieval
 *
 * Handles data retrieval from file storage.
 * Separated from FileStorage for single responsibility principle.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.2.1
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FileRetrieval {

	private DirectoryManager $directory_manager;

	public function __construct() {
		$this->directory_manager = new DirectoryManager();
	}

	/**
	 * Retrieve data packet by job ID.
	 *
	 * @param int   $job_id Job ID
	 * @param array $context Context array (pipeline_id, pipeline_name, flow_id, flow_name)
	 * @return array Retrieved data or empty array if no file exists
	 */
	public function retrieve_data_by_job_id( int $job_id, array $context ): array {
		$fs        = FilesystemHelper::get();
		$directory = $this->directory_manager->get_job_directory(
			$context['pipeline_id'],
			$context['flow_id'],
			$job_id
		);

		$file_path = "{$directory}/data.json";

		if ( ! file_exists( $file_path ) ) {
			return array();
		}

		$json_data = $fs->get_contents( $file_path );
		if ( false === $json_data ) {
			return array();
		}

		$data = json_decode( $json_data, true );
		return is_array( $data ) ? $data : array();
	}
}
