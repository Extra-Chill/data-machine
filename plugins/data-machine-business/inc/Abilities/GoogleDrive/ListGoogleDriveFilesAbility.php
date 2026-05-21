<?php
/**
 * List Google Drive Files Ability.
 *
 * One-shot enumeration of every file in a Drive folder, suitable for
 * direct CLI / chat / REST invocation. Drains all pagination pages
 * server-side and returns a flat array of file records — no
 * `nextPageToken` ever leaks to the caller.
 *
 * Sibling of `datamachine/fetch-googledrive`: the fetch ability is
 * pipeline-shaped (dedupes via ExecutionContext, downloads binaries
 * into flow-scoped uploads) while this ability is a pure listing
 * surface (no I/O beyond the API call, no dedup state).
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\GoogleDrive
 * @since 0.4.0
 */

namespace DataMachineBusiness\Abilities\GoogleDrive;

use DataMachine\Abilities\PermissionHelper;
use DataMachineBusiness\Handlers\GoogleDrive\GoogleDriveClient;

defined( 'ABSPATH' ) || exit;

class ListGoogleDriveFilesAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/list-googledrive-files',
				array(
					'label'               => __( 'List Google Drive Files', 'data-machine-business' ),
					'description'         => __( 'List every file in a Google Drive folder. Drains all pagination pages and returns metadata only — does not export Docs or download binaries.', 'data-machine-business' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'folder' ),
						'properties' => array(
							'folder' => array(
								'type'        => 'string',
								'description' => __( 'Google Drive folder ID or full folder URL (e.g. https://drive.google.com/drive/folders/1abc...xyz). Accepts either form; the URL is parsed server-side.', 'data-machine-business' ),
							),
							'recursive' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'When true, recurse into subfolders and return files from every level. When false (default), only return immediate children of the folder.', 'data-machine-business' ),
							),
							'mime_filter' => array(
								'type'        => 'string',
								'description' => __( 'Optional comma-separated list of MIME types to include (e.g. "application/pdf,audio/mpeg"). Filter is applied after pagination so subfolders are not excluded during recursion. Leave empty to include every MIME type.', 'data-machine-business' ),
							),
							'modified_since' => array(
								'type'        => 'string',
								'description' => __( 'Optional ISO-8601 timestamp (e.g. "2026-01-01T00:00:00Z"). When set, only files with modifiedTime greater than this value are returned.', 'data-machine-business' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array(
								'type'       => 'object',
								'properties' => array(
									'folder_id' => array(
										'type'        => 'string',
										'description' => __( 'The Drive folder ID that was listed (parsed from input).', 'data-machine-business' ),
									),
									'count' => array(
										'type'        => 'integer',
										'description' => __( 'Number of file records returned.', 'data-machine-business' ),
									),
									'files' => array(
										'type'        => 'array',
										'description' => __( 'Array of Drive file records. Each entry includes id, name, mime_type, modified_time, web_view_link, size, parents.', 'data-machine-business' ),
										'items'       => array(
											'type'       => 'object',
											'properties' => array(
												'id'             => array( 'type' => 'string' ),
												'name'           => array( 'type' => 'string' ),
												'mime_type'      => array( 'type' => 'string' ),
												'modified_time'  => array( 'type' => 'string' ),
												'web_view_link'  => array( 'type' => 'string' ),
												'size'           => array( 'type' => array( 'integer', 'null' ) ),
												'parents'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
											),
										),
									),
								),
							),
							'error'   => array( 'type' => 'string' ),
							'logs'    => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result envelope.
	 */
	public function execute( array $input ): array {
		$logs = array();

		$folder_raw = isset( $input['folder'] ) ? (string) $input['folder'] : '';
		$folder_id  = GoogleDriveClient::extract_folder_id( $folder_raw );
		if ( '' === $folder_id ) {
			return $this->fail( 'folder is required (Drive folder ID or URL).', $logs );
		}

		$mime_filter = $this->parse_mime_filter( $input['mime_filter'] ?? '' );

		$access_token = AuthHelper::get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $this->fail( $access_token->get_error_message(), $logs );
		}

		$client = new GoogleDriveClient( $access_token );

		$raw_files = $client->list_folder(
			$folder_id,
			array(
				'recursive'      => ! empty( $input['recursive'] ),
				'mime_filter'    => $mime_filter,
				'modified_since' => isset( $input['modified_since'] ) ? trim( (string) $input['modified_since'] ) : '',
			),
			$logs
		);

		if ( is_wp_error( $raw_files ) ) {
			return $this->fail( $raw_files->get_error_message(), $logs );
		}

		$files = array();
		foreach ( $raw_files as $raw ) {
			$files[] = array(
				'id'            => (string) ( $raw['id'] ?? '' ),
				'name'          => (string) ( $raw['name'] ?? '' ),
				'mime_type'     => (string) ( $raw['mimeType'] ?? '' ),
				'modified_time' => (string) ( $raw['modifiedTime'] ?? '' ),
				'web_view_link' => (string) ( $raw['webViewLink'] ?? '' ),
				'size'          => isset( $raw['size'] ) ? (int) $raw['size'] : null,
				'parents'       => isset( $raw['parents'] ) && is_array( $raw['parents'] ) ? array_values( array_map( 'strval', $raw['parents'] ) ) : array(),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'folder_id' => $folder_id,
				'count'     => count( $files ),
				'files'     => $files,
			),
			'logs'    => $logs,
		);
	}

	/**
	 * Accept either a comma-separated string or an array of MIME types.
	 *
	 * @param mixed $raw Input value.
	 * @return string[]
	 */
	private function parse_mime_filter( $raw ): array {
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$raw = trim( (string) $raw );
			if ( '' === $raw ) {
				return array();
			}
			$parts = explode( ',', $raw );
		}

		$clean = array();
		foreach ( $parts as $mime ) {
			$mime = is_string( $mime ) ? trim( $mime ) : '';
			if ( '' === $mime ) {
				continue;
			}
			$clean[] = $mime;
		}

		return array_values( array_unique( $clean ) );
	}

	private function fail( string $message, array $logs ): array {
		$logs[] = array( 'level' => 'error', 'message' => $message );
		return array(
			'success' => false,
			'error'   => $message,
			'logs'    => $logs,
		);
	}
}
