<?php
/**
 * Fetch Google Drive Ability.
 *
 * Pipeline-shaped Drive fetcher — accepts a download target (pipeline
 * + flow IDs) so binary files can be streamed to the flow-scoped
 * uploads directory. Any caller (REST, CLI, chat tool, pipeline
 * handler) can invoke this directly, but binary downloads only happen
 * when a flow context is supplied. Callers that want a one-shot
 * download into the WP uploads area should call the dedicated
 * `datamachine/download-googledrive` ability instead.
 *
 * Resolves auth via the unified `google` OAuth provider. All HTTP /
 * pagination / export / error mapping lives in
 * `Handlers\GoogleDrive\GoogleDriveClient` — this class is just the
 * pipeline glue.
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\GoogleDrive
 * @since 0.3.0
 */

namespace DataMachineBusiness\Abilities\GoogleDrive;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachineBusiness\Abilities\GoogleDrive\AuthHelper;
use DataMachineBusiness\Handlers\GoogleDrive\GoogleDriveClient;

defined( 'ABSPATH' ) || exit;

class FetchGoogleDriveAbility {

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
				'datamachine/fetch-googledrive',
				array(
					'label'               => __( 'Fetch Google Drive Folder', 'data-machine-business' ),
					'description'         => __( 'List files in a Google Drive folder and download or export their contents.', 'data-machine-business' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'folder_id' ),
						'properties' => array(
							'folder_id'       => array(
								'type'        => 'string',
								'description' => __( 'Google Drive folder ID (or full URL — see the GoogleDriveFetchSettings::extract_folder_id helper).', 'data-machine-business' ),
							),
							'recursive'       => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'When true, recurse into subfolders.', 'data-machine-business' ),
							),
							'mime_filter'     => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Optional list of MIME types to include.', 'data-machine-business' ),
							),
							'modified_since'  => array(
								'type'        => 'string',
								'description' => __( 'Optional ISO-8601 timestamp — only files modifiedTime > this are returned.', 'data-machine-business' ),
							),
							'download_target' => array(
								'type'        => 'object',
								'description' => __( 'Optional pipeline/flow context used to scope binary downloads. When omitted, binary files are listed but not downloaded.', 'data-machine-business' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
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
	 * @param array $input Input parameters (see input_schema).
	 * @return array Result with success, data, error, logs.
	 */
	public function execute( array $input ): array {
		$logs = array();

		$folder_id = isset( $input['folder_id'] ) ? trim( (string) $input['folder_id'] ) : '';
		if ( '' === $folder_id ) {
			return $this->fail( 'folder_id is required.', $logs );
		}

		$download_target = isset( $input['download_target'] ) && is_array( $input['download_target'] ) ? $input['download_target'] : array();

		$access_token = AuthHelper::get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $this->fail( $access_token->get_error_message(), $logs );
		}

		$client = new GoogleDriveClient( $access_token );

		$list_result = $client->list_folder(
			$folder_id,
			array(
				'recursive'      => ! empty( $input['recursive'] ),
				'mime_filter'    => isset( $input['mime_filter'] ) && is_array( $input['mime_filter'] )
					? array_values( array_filter( array_map( 'strval', $input['mime_filter'] ) ) )
					: array(),
				'modified_since' => isset( $input['modified_since'] ) ? trim( (string) $input['modified_since'] ) : '',
			),
			$logs
		);

		if ( is_wp_error( $list_result ) ) {
			return $this->fail( $list_result->get_error_message(), $logs );
		}

		$files = array();
		foreach ( $list_result as $raw ) {
			$payload = $this->materialize_file( $raw, $client, $download_target, $logs );

			$files[] = array(
				'id'            => $raw['id'] ?? '',
				'name'          => $raw['name'] ?? '',
				'mime_type'     => $raw['mimeType'] ?? '',
				'modified_time' => $raw['modifiedTime'] ?? '',
				'size'          => isset( $raw['size'] ) ? (int) $raw['size'] : null,
				'owners'        => $raw['owners'] ?? array(),
				'web_view_link' => $raw['webViewLink'] ?? '',
				'md5_checksum'  => $raw['md5Checksum'] ?? '',
				'payload'       => $payload,
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'folder_id' => $folder_id,
				'files'     => $files,
				'count'     => count( $files ),
			),
			'logs'    => $logs,
		);
	}

	/**
	 * Convert a raw Drive file record into a payload (text, local_path, or skipped).
	 *
	 * @param array             $file            Raw Drive file metadata.
	 * @param GoogleDriveClient $client          Drive client (pre-authenticated).
	 * @param array             $download_target Pipeline/flow context. Empty = skip binary download.
	 * @param array             $logs            Logs reference.
	 * @return array Payload descriptor.
	 */
	private function materialize_file( array $file, GoogleDriveClient $client, array $download_target, array &$logs ): array {
		$mime    = $file['mimeType'] ?? '';
		$file_id = $file['id'] ?? '';
		$name    = $file['name'] ?? '';

		$export_candidates = GoogleDriveClient::export_candidates_for_native( $mime );

		if ( null !== $export_candidates ) {
			if ( empty( $export_candidates ) ) {
				return array(
					'skipped' => true,
					'reason'  => 'Native Google file type is not exportable as text.',
				);
			}

			$last_error = null;
			foreach ( $export_candidates as $candidate_mime ) {
				$response = $client->export_native( $file_id, $candidate_mime, $logs );

				if ( ! is_wp_error( $response ) ) {
					return array(
						'text'        => $response,
						'export_mime' => $candidate_mime,
					);
				}

				$last_error = $response;
				$logs[]     = array(
					'level'   => 'debug',
					'message' => 'GoogleDrive: Export candidate not accepted, trying next.',
					'data'    => array(
						'file_id'        => $file_id,
						'candidate_mime' => $candidate_mime,
						'error'          => $response->get_error_message(),
					),
				);
			}

			$reason = $last_error instanceof \WP_Error ? $last_error->get_error_message() : 'Export failed.';
			$logs[] = array(
				'level'   => 'warning',
				'message' => 'GoogleDrive: All export candidates failed for native file.',
				'data'    => array( 'file_id' => $file_id, 'error' => $reason ),
			);
			return array(
				'skipped' => true,
				'reason'  => $reason,
			);
		}

		// Binary file. Stream to disk if we have somewhere to put it.
		if ( empty( $download_target['pipeline_id'] ) || empty( $download_target['flow_id'] ) ) {
			$logs[] = array(
				'level'   => 'debug',
				'message' => 'GoogleDrive: No download target provided, listing binary file without downloading.',
				'data'    => array( 'file_id' => $file_id, 'name' => $name ),
			);
			return array(
				'binary_only' => true,
			);
		}

		$pipeline_id = (int) $download_target['pipeline_id'];
		$flow_id     = (int) $download_target['flow_id'];

		$dir_manager = new DirectoryManager();
		$directory   = $dir_manager->get_flow_files_directory( $pipeline_id, $flow_id );

		if ( ! $dir_manager->ensure_directory_exists( $directory ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'GoogleDrive: Failed to create flow files directory.',
				'data'    => array( 'directory' => $directory ),
			);
			return array(
				'skipped' => true,
				'reason'  => 'Failed to create flow files directory.',
			);
		}

		$safe_name   = sanitize_file_name( $name ?: ( 'drive-' . $file_id ) );
		$destination = trailingslashit( $directory ) . wp_unique_filename( $directory, $safe_name );

		$saved = $client->download_binary( $file_id, $destination, $logs );
		if ( is_wp_error( $saved ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'GoogleDrive: Binary download failed.',
				'data'    => array( 'file_id' => $file_id, 'error' => $saved->get_error_message() ),
			);
			return array(
				'skipped' => true,
				'reason'  => $saved->get_error_message(),
			);
		}

		return array(
			'local_path' => $saved['local_path'],
			'filename'   => basename( $saved['local_path'] ),
			'size'       => $saved['size'],
		);
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
