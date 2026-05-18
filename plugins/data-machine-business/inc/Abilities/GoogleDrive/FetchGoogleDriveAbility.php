<?php
/**
 * Fetch Google Drive Ability.
 *
 * Pure business logic — no handler config, no engine data, no pipeline
 * context. Any caller (REST, CLI, chat tool, pipeline handler) can
 * invoke this directly.
 *
 * Resolves auth via the unified `google` OAuth provider — single
 * credential covers every Google handler in this plugin. See the
 * plugin loader for the scope union.
 *
 * Behavior:
 * - Lists files in a Drive folder via files.list with pagination.
 * - Optionally recurses into subfolders.
 * - Optionally filters by MIME type and modifiedTime.
 * - Native Google Docs / Sheets / Slides are exported to text/csv.
 * - Other binary files are streamed to the Data Machine uploads area
 *   when a download target (pipeline/flow context) is provided.
 * - Google Forms / Sites and other non-exportable native types are
 *   returned with `payload.skipped = true` so the caller can decide
 *   whether to log and continue.
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\GoogleDrive
 * @since 0.3.0
 */

namespace DataMachineBusiness\Abilities\GoogleDrive;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FilesystemHelper;

defined( 'ABSPATH' ) || exit;

class FetchGoogleDriveAbility {

	private const API_BASE         = 'https://www.googleapis.com/drive/v3';
	private const LIST_FIELDS      = 'nextPageToken,files(id,name,mimeType,modifiedTime,size,owners,webViewLink,md5Checksum,parents)';
	private const PAGE_SIZE        = 100;
	private const REQUIRED_SCOPE   = 'https://www.googleapis.com/auth/drive.readonly';
	private const MAX_RECURSION    = 25;
	private const RETRY_AFTER_CAP  = 60;

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

		$recursive       = ! empty( $input['recursive'] );
		$mime_filter     = isset( $input['mime_filter'] ) && is_array( $input['mime_filter'] ) ? array_values( array_filter( array_map( 'strval', $input['mime_filter'] ) ) ) : array();
		$modified_since  = isset( $input['modified_since'] ) ? trim( (string) $input['modified_since'] ) : '';
		$download_target = isset( $input['download_target'] ) && is_array( $input['download_target'] ) ? $input['download_target'] : array();

		$auth_provider = self::get_auth_provider();
		if ( ! $auth_provider ) {
			return $this->fail( 'Google authentication provider is not registered.', $logs );
		}

		if ( ! $auth_provider->has_scope( self::REQUIRED_SCOPE ) ) {
			return $this->fail(
				sprintf(
					/* translators: %s: OAuth scope URL. */
					'The connected Google account is missing the Drive scope (%s). Disconnect and reconnect the Google integration in Data Machine settings to grant Drive access.',
					self::REQUIRED_SCOPE
				),
				$logs
			);
		}

		$access_token = $auth_provider->get_service();
		if ( is_wp_error( $access_token ) ) {
			return $this->fail( $access_token->get_error_message(), $logs );
		}
		if ( ! is_string( $access_token ) || '' === $access_token ) {
			return $this->fail( 'Failed to obtain a Google access token.', $logs );
		}

		$files   = array();
		$visited = array();

		$list_result = $this->list_folder_recursive(
			$folder_id,
			$access_token,
			$recursive,
			$mime_filter,
			$modified_since,
			0,
			$visited,
			$logs
		);

		if ( is_wp_error( $list_result ) ) {
			return $this->fail( $list_result->get_error_message(), $logs );
		}

		foreach ( $list_result as $raw ) {
			// Folders are returned by the recursion helper as well so the
			// caller can introspect structure, but for the fetch handler
			// we only emit leaf files.
			if ( $this->is_folder( $raw ) ) {
				continue;
			}

			$payload = $this->materialize_file( $raw, $access_token, $download_target, $logs );

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
	 * Recursively list files in a folder.
	 *
	 * @param string   $folder_id      Drive folder ID.
	 * @param string   $access_token   OAuth bearer token.
	 * @param bool     $recursive      Whether to recurse into subfolders.
	 * @param string[] $mime_filter    Optional MIME types to include.
	 * @param string   $modified_since Optional ISO-8601 timestamp.
	 * @param int      $depth          Current recursion depth (caps at MAX_RECURSION).
	 * @param array    $visited        Visited folder IDs to break cycles.
	 * @param array    $logs           Logs reference.
	 * @return array|\WP_Error
	 */
	private function list_folder_recursive(
		string $folder_id,
		string $access_token,
		bool $recursive,
		array $mime_filter,
		string $modified_since,
		int $depth,
		array &$visited,
		array &$logs
	) {
		if ( isset( $visited[ $folder_id ] ) ) {
			return array();
		}
		$visited[ $folder_id ] = true;

		if ( $depth > self::MAX_RECURSION ) {
			$logs[] = array(
				'level'   => 'warning',
				'message' => 'GoogleDrive: Max recursion depth reached, skipping deeper folders.',
				'data'    => array( 'folder_id' => $folder_id, 'depth' => $depth ),
			);
			return array();
		}

		$query_parts = array(
			sprintf( "'%s' in parents", $this->escape_query_literal( $folder_id ) ),
			'trashed = false',
		);

		if ( '' !== $modified_since ) {
			$query_parts[] = sprintf( "modifiedTime > '%s'", $this->escape_query_literal( $modified_since ) );
		}

		// Note: MIME filter is applied client-side as well so that
		// subfolders (mimeType = application/vnd.google-apps.folder) are
		// not excluded by a too-narrow server-side filter when recursive
		// is on.

		$page_token = '';
		$collected  = array();

		do {
			$params = array(
				'q'                         => implode( ' and ', $query_parts ),
				'pageSize'                  => self::PAGE_SIZE,
				'fields'                    => self::LIST_FIELDS,
				'supportsAllDrives'         => 'true',
				'includeItemsFromAllDrives' => 'true',
			);
			if ( '' !== $page_token ) {
				$params['pageToken'] = $page_token;
			}

			$response = $this->drive_get( self::API_BASE . '/files', $params, $access_token, $logs );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$files      = $response['files'] ?? array();
			$page_token = $response['nextPageToken'] ?? '';

			foreach ( $files as $file ) {
				if ( $this->is_folder( $file ) ) {
					if ( $recursive ) {
						$nested = $this->list_folder_recursive(
							$file['id'] ?? '',
							$access_token,
							$recursive,
							$mime_filter,
							$modified_since,
							$depth + 1,
							$visited,
							$logs
						);
						if ( is_wp_error( $nested ) ) {
							return $nested;
						}
						foreach ( $nested as $nested_file ) {
							$collected[] = $nested_file;
						}
					}
					continue;
				}

				if ( ! empty( $mime_filter ) ) {
					$file_mime = $file['mimeType'] ?? '';
					if ( ! in_array( $file_mime, $mime_filter, true ) ) {
						continue;
					}
				}

				$collected[] = $file;
			}
		} while ( '' !== $page_token );

		return $collected;
	}

	/**
	 * Convert a raw Drive file record into a payload (text, local_path, or skipped).
	 *
	 * @param array  $file            Raw Drive file metadata.
	 * @param string $access_token    OAuth bearer.
	 * @param array  $download_target Pipeline/flow context for binary downloads. Empty = skip download.
	 * @param array  $logs            Logs reference.
	 * @return array Payload descriptor.
	 */
	private function materialize_file( array $file, string $access_token, array $download_target, array &$logs ): array {
		$mime    = $file['mimeType'] ?? '';
		$file_id = $file['id'] ?? '';
		$name    = $file['name'] ?? '';

		// Native Google types use files.export; everything else uses files.get?alt=media.
		$export_candidates = $this->export_candidates_for_native( $mime );

		if ( null !== $export_candidates ) {
			if ( empty( $export_candidates ) ) {
				// Native but not exportable (Forms, Sites, etc.).
				return array(
					'skipped' => true,
					'reason'  => 'Native Google file type is not exportable as text.',
				);
			}

			$last_error = null;
			foreach ( $export_candidates as $candidate_mime ) {
				$url      = self::API_BASE . '/files/' . rawurlencode( $file_id ) . '/export';
				$response = $this->drive_get_raw( $url, array( 'mimeType' => $candidate_mime ), $access_token, $logs );

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
		if ( empty( $download_target['pipeline_id'] ) && empty( $download_target['flow_id'] ) ) {
			$logs[] = array(
				'level'   => 'debug',
				'message' => 'GoogleDrive: No download target provided, listing binary file without downloading.',
				'data'    => array( 'file_id' => $file_id, 'name' => $name ),
			);
			return array(
				'binary_only' => true,
			);
		}

		$saved = $this->download_binary( $file_id, $name, $access_token, $download_target, $logs );
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

		return $saved;
	}

	/**
	 * Map a native Google MIME type to its preferred export target MIMEs.
	 *
	 * Returns an ORDERED list of MIME candidates to try via files.export.
	 * The first candidate that the Drive API accepts wins. This lets us
	 * prefer the LLM-friendlier text/markdown for Google Docs while
	 * falling back to text/plain if Drive rejects it (older accounts or
	 * documents with features the markdown exporter doesn't handle).
	 *
	 * Returns:
	 * - null if the file is NOT a native Google type (caller falls back
	 *   to files.get?alt=media for binary download).
	 * - [] (empty array) if it IS a native type but not exportable as
	 *   text (Forms, Sites, etc.).
	 * - non-empty list of MIME candidates otherwise.
	 *
	 * @param string $mime Source MIME.
	 * @return string[]|null
	 */
	private function export_candidates_for_native( string $mime ): ?array {
		switch ( $mime ) {
			case 'application/vnd.google-apps.document':
				// Markdown is the LLM-preferred format. Drive started
				// supporting it as a Docs export target in 2024 but older
				// project credentials still 4xx on it — fall back to
				// text/plain so the export still succeeds.
				return array( 'text/markdown', 'text/plain' );
			case 'application/vnd.google-apps.spreadsheet':
				return array( 'text/csv' );
			case 'application/vnd.google-apps.presentation':
				return array( 'text/plain' );
			case 'application/vnd.google-apps.form':
			case 'application/vnd.google-apps.site':
			case 'application/vnd.google-apps.drawing':
			case 'application/vnd.google-apps.map':
			case 'application/vnd.google-apps.shortcut':
			case 'application/vnd.google-apps.folder':
				return array();
		}

		if ( 0 === strpos( $mime, 'application/vnd.google-apps.' ) ) {
			// Unknown native type — refuse rather than guess.
			return array();
		}

		return null;
	}

	private function is_folder( array $file ): bool {
		return ( $file['mimeType'] ?? '' ) === 'application/vnd.google-apps.folder';
	}

	/**
	 * Download a binary Drive file into the flow-scoped uploads dir.
	 *
	 * Authenticated downloads need a bearer header, so we cannot reuse
	 * WordPress's download_url() helper. We stream via wp_remote_get to
	 * a temp file, then move into the flow directory. This is local-only
	 * pending a generic authenticated-download helper in core (see
	 * Extra-Chill/data-machine issue tracking TBD).
	 *
	 * @return array|\WP_Error
	 */
	private function download_binary( string $file_id, string $name, string $access_token, array $download_target, array &$logs ) {
		$pipeline_id = $download_target['pipeline_id'] ?? null;
		$flow_id     = $download_target['flow_id'] ?? null;
		if ( null === $pipeline_id || null === $flow_id ) {
			return new \WP_Error( 'googledrive_no_target', 'Download target missing pipeline_id/flow_id.' );
		}

		$dir_manager = new DirectoryManager();
		$directory   = $dir_manager->get_flow_files_directory( $pipeline_id, $flow_id );

		if ( ! $dir_manager->ensure_directory_exists( $directory ) ) {
			return new \WP_Error( 'googledrive_dir_failed', 'Failed to create flow files directory.' );
		}

		$url      = self::API_BASE . '/files/' . rawurlencode( $file_id );
		$response = wp_remote_get(
			add_query_arg(
				array(
					'alt'               => 'media',
					'supportsAllDrives' => 'true',
				),
				$url
			),
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				// Avoid loading the whole binary into PHP memory when possible.
				'stream'   => true,
				'filename' => trailingslashit( get_temp_dir() ) . wp_unique_filename( get_temp_dir(), 'gdrive-' . $file_id . '-' . sanitize_file_name( $name ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$temp_path   = $response['filename'] ?? '';

		if ( 200 !== (int) $status_code ) {
			if ( $temp_path && file_exists( $temp_path ) ) {
				wp_delete_file( $temp_path );
			}
			return $this->error_from_status( $status_code, $response );
		}

		if ( '' === $temp_path || ! file_exists( $temp_path ) ) {
			return new \WP_Error( 'googledrive_stream_failed', 'Drive returned 200 but temp file is missing.' );
		}

		$safe_name   = sanitize_file_name( $name ?: ( 'drive-' . $file_id ) );
		$destination = trailingslashit( $directory ) . wp_unique_filename( $directory, $safe_name );

		$fs = FilesystemHelper::get();
		if ( $fs ) {
			$copied = $fs->copy( $temp_path, $destination, true );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Filesystem API unavailable, fall back to native move.
			$copied = @rename( $temp_path, $destination );
		}

		if ( file_exists( $temp_path ) ) {
			wp_delete_file( $temp_path );
		}

		if ( ! $copied || ! file_exists( $destination ) ) {
			return new \WP_Error( 'googledrive_move_failed', 'Failed to move downloaded file into flow directory.' );
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'GoogleDrive: Binary file downloaded.',
			'data'    => array( 'file_id' => $file_id, 'destination' => $destination ),
		);

		return array(
			'local_path' => $destination,
			'filename'   => basename( $destination ),
			'size'       => filesize( $destination ),
		);
	}

	/**
	 * GET a JSON-returning Drive API endpoint.
	 *
	 * @return array|\WP_Error Decoded JSON on success.
	 */
	private function drive_get( string $url, array $params, string $access_token, array &$logs ) {
		$full_url = add_query_arg( $params, $url );
		$response = wp_remote_get(
			$full_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			return $this->error_from_status( $status_code, $response, $body );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'googledrive_decode_failed', 'Drive returned an unparseable response body.' );
		}

		return $decoded;
	}

	/**
	 * GET a Drive API endpoint that returns raw (non-JSON) content.
	 *
	 * Used for files.export.
	 *
	 * @return string|\WP_Error Raw body on success.
	 */
	private function drive_get_raw( string $url, array $params, string $access_token, array &$logs ) {
		$full_url = add_query_arg( $params, $url );
		$response = wp_remote_get(
			$full_url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			return $this->error_from_status( $status_code, $response, $body );
		}

		return $body;
	}

	/**
	 * Translate a non-200 Drive response into a WP_Error.
	 *
	 * Distinguishes scope-missing (403 with insufficient scope reason),
	 * not-found (404), and rate-limited (429, with respect for
	 * Retry-After) errors.
	 *
	 * @param int          $status_code HTTP status.
	 * @param array|object $response    wp_remote_* response.
	 * @param string|null  $body        Body string if already retrieved.
	 * @return \WP_Error
	 */
	private function error_from_status( int $status_code, $response, ?string $body = null ): \WP_Error {
		if ( null === $body ) {
			$body = (string) wp_remote_retrieve_body( $response );
		}

		$decoded = json_decode( $body, true );
		$message = '';
		$reason  = '';
		if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
			$message = (string) ( $decoded['error']['message'] ?? '' );
			$errors  = $decoded['error']['errors'] ?? array();
			if ( is_array( $errors ) && ! empty( $errors[0]['reason'] ) ) {
				$reason = (string) $errors[0]['reason'];
			}
		}

		if ( 401 === $status_code ) {
			return new \WP_Error( 'googledrive_unauthorized', 'Google Drive returned 401 Unauthorized. Re-authenticate the Google integration.' );
		}

		if ( 403 === $status_code ) {
			if ( in_array( $reason, array( 'insufficientPermissions', 'insufficientScopes', 'forbidden' ), true ) && false !== stripos( $message . ' ' . $reason, 'scope' ) ) {
				return new \WP_Error(
					'googledrive_scope_missing',
					sprintf(
						'Google Drive denied the request because the OAuth token lacks the required scope (%s). Disconnect and reconnect the Google integration to grant Drive access.',
						self::REQUIRED_SCOPE
					)
				);
			}
			return new \WP_Error(
				'googledrive_forbidden',
				$message ?: 'Google Drive denied access to this resource. The authenticated user may not have permission to view the folder.'
			);
		}

		if ( 404 === $status_code ) {
			return new \WP_Error( 'googledrive_not_found', $message ?: 'Drive resource not found.' );
		}

		if ( 429 === $status_code ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			if ( $retry_after > 0 ) {
				$retry_after = min( $retry_after, self::RETRY_AFTER_CAP );
				return new \WP_Error(
					'googledrive_rate_limited',
					sprintf( 'Google Drive rate limit hit. Retry after %d seconds.', $retry_after ),
					array( 'retry_after' => $retry_after )
				);
			}
			return new \WP_Error( 'googledrive_rate_limited', 'Google Drive rate limit hit.' );
		}

		return new \WP_Error(
			'googledrive_http_error',
			sprintf( 'Google Drive API returned HTTP %d: %s', $status_code, $message ?: 'Unknown error' )
		);
	}

	/**
	 * Escape a literal for inclusion inside a Drive `q=` query string.
	 *
	 * Drive's query language quotes literals with single quotes and
	 * escapes single quotes and backslashes inside them.
	 */
	private function escape_query_literal( string $value ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
	}

	private function fail( string $message, array $logs ): array {
		$logs[] = array( 'level' => 'error', 'message' => $message );
		return array(
			'success' => false,
			'error'   => $message,
			'logs'    => $logs,
		);
	}

	/**
	 * @return \DataMachineBusiness\OAuth\Providers\GoogleAuth|null
	 */
	private static function get_auth_provider(): ?\DataMachineBusiness\OAuth\Providers\GoogleAuth {
		$providers = apply_filters( 'datamachine_auth_providers', array() );
		$provider  = $providers['google'] ?? null;
		return $provider instanceof \DataMachineBusiness\OAuth\Providers\GoogleAuth ? $provider : null;
	}
}
