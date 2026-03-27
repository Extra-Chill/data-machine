<?php
/**
 * Fetch Files Ability
 *
 * Abilities API primitive for fetching files from the file repository.
 * Centralizes file retrieval, deduplication checking, and metadata extraction.
 *
 * @package DataMachine\Abilities\Fetch
 * @since 0.28.4
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\Traits\HasCheckPermission;

defined( 'ABSPATH' ) || exit;

class FetchFilesAbility {

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
			wp_register_ability(
				'datamachine/fetch-files',
				array(
					'label'               => __( 'Fetch Files', 'data-machine' ),
					'description'         => __( 'Fetch files from the file repository with deduplication support', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'file_context' ),
						'properties' => array(
							'uploaded_files'  => array(
								'type'        => 'array',
								'default'     => array(),
								'description' => __( 'Array of uploaded files to process', 'data-machine' ),
								'items'       => array(
									'type'       => 'object',
									'required'   => array( 'original_name', 'persistent_path' ),
									'properties' => array(
										'original_name'   => array( 'type' => 'string' ),
										'persistent_path' => array( 'type' => 'string' ),
										'size'            => array( 'type' => 'integer' ),
										'mime_type'       => array( 'type' => 'string' ),
										'uploaded_at'     => array( 'type' => 'string' ),
									),
								),
							),
							'file_context'    => array(
								'type'        => 'string',
								'description' => __( 'Context identifier for file repository isolation', 'data-machine' ),
							),
							'processed_items' => array(
								'type'        => 'array',
								'default'     => array(),
								'description' => __( 'Array of already processed file identifiers to skip', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'data'            => array( 'type' => 'object' ),
							'error'           => array( 'type' => 'string' ),
							'logs'            => array( 'type' => 'array' ),
							'item_identifier' => array( 'type' => 'string' ),
							'is_image'        => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Permission callback for ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute file fetch ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with fetched file data or error.
	 */
	public function execute( array $input ): array {
		$logs   = array();
		$config = $this->normalizeConfig( $input );

		$uploaded_files  = $config['uploaded_files'];
		$file_context    = $config['file_context'];
		$processed_items = $config['processed_items'];

		// If no uploaded files provided, fetch from repository
		if ( empty( $uploaded_files ) ) {
			$repo_files = $this->getFilesFromRepository( $file_context );
			if ( empty( $repo_files ) ) {
				$logs[] = array(
					'level'   => 'debug',
					'message' => 'Files: No files available in repository.',
					'data'    => array( 'file_context' => $file_context ),
				);
				return array(
					'success' => true,
					'data'    => array(),
					'logs'    => $logs,
				);
			}
			$uploaded_files = $repo_files;
		}

		// Collect all unprocessed files
		$eligible_items = array();

		foreach ( $uploaded_files as $file ) {
			$file_identifier = $file['persistent_path'] ?? '';

			if ( empty( $file_identifier ) ) {
				continue;
			}

			if ( in_array( $file_identifier, $processed_items, true ) ) {
				continue;
			}

			// Verify file exists
			if ( ! file_exists( $file['persistent_path'] ) ) {
				$logs[] = array(
					'level'   => 'warning',
					'message' => 'Files: File not found, skipping.',
					'data'    => array( 'file_path' => $file['persistent_path'] ),
				);
				continue;
			}

			$mime_type = $file['mime_type'] ?? 'application/octet-stream';
			$is_image  = strpos( $mime_type, 'image/' ) === 0;

			$file_info = array(
				'file_path' => $file['persistent_path'],
				'file_name' => $file['original_name'],
				'mime_type' => $mime_type,
				'file_size' => $file['size'] ?? 0,
			);

			$metadata = array(
				'source_type'       => 'files',
				'item_identifier'   => $file_identifier,
				'original_id'       => $file_identifier,
				'original_title'    => $file['original_name'],
				'original_date_gmt' => $file['uploaded_at'] ?? gmdate( 'Y-m-d H:i:s' ),
				'source_url'        => '',
			);

			if ( $is_image ) {
				$metadata['image_file_path'] = $file['persistent_path'];
			}

			$eligible_items[] = array(
				'title'     => $file['original_name'],
				'content'   => 'File: ' . $file['original_name'] . "\nType: " . $mime_type . "\nSize: " . ( $file['size'] ?? 0 ) . ' bytes',
				'metadata'  => $metadata,
				'file_info' => $file_info,
			);
		}

		if ( empty( $eligible_items ) ) {
			$logs[] = array(
				'level'   => 'debug',
				'message' => 'Files: No unprocessed files available.',
				'data'    => array( 'total_files' => count( $uploaded_files ) ),
			);

			return array(
				'success' => true,
				'data'    => array(),
				'logs'    => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'info',
			'message' => sprintf( 'Files: Found %d unprocessed files.', count( $eligible_items ) ),
			'data'    => array( 'eligible' => count( $eligible_items ) ),
		);

		return array(
			'success' => true,
			'data'    => array( 'items' => $eligible_items ),
			'logs'    => $logs,
		);
	}

	/**
	 * Normalize input configuration with defaults.
	 *
	 * @param array $input Raw input.
	 * @return array Normalized configuration.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'uploaded_files'  => array(),
			'file_context'    => '',
			'processed_items' => array(),
		);

		return array_merge( $defaults, $input );
	}

	/**
	 * Get files from the file repository.
	 *
	 * @param string $file_context Context for file isolation.
	 * @return array List of files from repository.
	 */
	private function getFilesFromRepository( string $file_context ): array {
		$files = array();

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'data-machine/files/' . $file_context;

		if ( ! is_dir( $base_dir ) ) {
			return $files;
		}

		$file_list = glob( $base_dir . '/*' );
		if ( ! is_array( $file_list ) ) {
			return $files;
		}

		foreach ( $file_list as $file_path ) {
			if ( ! is_file( $file_path ) ) {
				continue;
			}

			$file_info = wp_check_filetype( $file_path );
			$files[]   = array(
				'original_name'   => basename( $file_path ),
				'persistent_path' => $file_path,
				'size'            => filesize( $file_path ),
				'mime_type'       => $file_info['type'] ?? 'application/octet-stream',
				'uploaded_at'     => gmdate( 'Y-m-d H:i:s', filemtime( $file_path ) ),
			);
		}

		return $files;
	}
}
