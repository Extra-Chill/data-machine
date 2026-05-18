<?php
/**
 * Google Drive HTTP client.
 *
 * Plain class that wraps the Google Drive v3 REST surface needed by
 * every Drive-touching ability in this plugin. Owns HTTP, pagination,
 * native-export, binary streaming, error mapping, and URL parsing.
 *
 * Why this exists: PR #8 landed an inline copy of all this logic inside
 * `FetchGoogleDriveAbility`. With multiple one-shot abilities (list,
 * read-doc, download) being added on top of the same Drive API surface
 * we extract the shared logic so there is exactly one place that knows
 * how to talk to Drive. The pipeline ability and the new one-shot
 * abilities all call into this client.
 *
 * The client is stateless beyond the access token. Callers obtain the
 * token from the `google` OAuth provider (`GoogleAuth::get_service()`)
 * and pass it in. Logs are appended to a caller-supplied array so the
 * ability layer can surface them in its result envelope.
 *
 * Layer purity: this file is the only place in the plugin that knows
 * the Drive API URL, query language, export MIME table, or error
 * shape. Higher layers stay free of HTTP concerns.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\GoogleDrive
 * @since 0.4.0
 */

namespace DataMachineBusiness\Handlers\GoogleDrive;

defined( 'ABSPATH' ) || exit;

class GoogleDriveClient {

	public const API_BASE        = 'https://www.googleapis.com/drive/v3';
	public const LIST_FIELDS     = 'nextPageToken,files(id,name,mimeType,modifiedTime,size,owners,webViewLink,md5Checksum,parents)';
	public const PAGE_SIZE       = 100;
	public const REQUIRED_SCOPE  = 'https://www.googleapis.com/auth/drive.readonly';
	public const MAX_RECURSION   = 25;
	public const RETRY_AFTER_CAP = 60;

	/**
	 * Regex used to extract a file or folder ID from a Drive URL.
	 *
	 * Matches `/folders/<id>`, `/file/d/<id>`, and `/document/d/<id>`,
	 * `/spreadsheets/d/<id>`, `/presentation/d/<id>` variants.
	 */
	private const FOLDER_URL_PATTERN = '#/folders/([a-zA-Z0-9_-]+)#';
	private const FILE_URL_PATTERN   = '#/(?:file|document|spreadsheets|presentation|drawings)/d/([a-zA-Z0-9_-]+)#';

	private string $access_token;

	public function __construct( string $access_token ) {
		$this->access_token = $access_token;
	}

	/**
	 * Recursively list files in a folder, draining all pagination pages.
	 *
	 * Returns a flat array of raw Drive file records (NOT folder records
	 * — folders are walked when `recursive` is true and dropped from the
	 * result). When `recursive` is false, only the immediate children of
	 * `$folder_id` are returned.
	 *
	 * MIME filtering is applied client-side after pagination so that
	 * subfolders are not excluded by a too-narrow server-side filter
	 * when recursion is on.
	 *
	 * @param string $folder_id Drive folder ID.
	 * @param array  $opts      Options: bool 'recursive', string[] 'mime_filter', string 'modified_since'.
	 * @param array  $logs      Logs reference.
	 * @return array|\WP_Error  Array of raw file records, or WP_Error on HTTP failure.
	 */
	public function list_folder( string $folder_id, array $opts, array &$logs ) {
		$recursive      = ! empty( $opts['recursive'] );
		$mime_filter    = isset( $opts['mime_filter'] ) && is_array( $opts['mime_filter'] )
			? array_values( array_filter( array_map( 'strval', $opts['mime_filter'] ) ) )
			: array();
		$modified_since = isset( $opts['modified_since'] ) ? trim( (string) $opts['modified_since'] ) : '';

		$visited = array();
		return $this->list_folder_recursive(
			$folder_id,
			$recursive,
			$mime_filter,
			$modified_since,
			0,
			$visited,
			$logs
		);
	}

	/**
	 * Fetch a single file's metadata.
	 *
	 * @param string $file_id Drive file ID.
	 * @param array  $logs    Logs reference.
	 * @return array|\WP_Error Raw file record, or WP_Error.
	 */
	public function get_file_metadata( string $file_id, array &$logs ) {
		$url      = self::API_BASE . '/files/' . rawurlencode( $file_id );
		$response = $this->drive_get(
			$url,
			array(
				'fields'            => 'id,name,mimeType,modifiedTime,size,owners,webViewLink,md5Checksum,parents',
				'supportsAllDrives' => 'true',
			),
			$logs
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Export a native Google file (Doc, Sheet, Slide) to a text MIME.
	 *
	 * @param string $file_id     Drive file ID.
	 * @param string $target_mime Target export MIME (e.g. text/markdown).
	 * @param array  $logs        Logs reference.
	 * @return string|\WP_Error   Exported body on success.
	 */
	public function export_native( string $file_id, string $target_mime, array &$logs ) {
		$url = self::API_BASE . '/files/' . rawurlencode( $file_id ) . '/export';
		return $this->drive_get_raw( $url, array( 'mimeType' => $target_mime ), $logs );
	}

	/**
	 * Map a native Google MIME type to its preferred export target MIMEs.
	 *
	 * Returns an ORDERED list of MIME candidates to try via files.export.
	 * The first candidate that Drive accepts wins.
	 *
	 * Returns:
	 * - null if the file is NOT a native Google type (caller falls back
	 *   to download_binary()).
	 * - [] (empty array) if it IS a native type but not exportable as
	 *   text (Forms, Sites, etc.).
	 * - non-empty list of candidate MIMEs otherwise.
	 *
	 * @param string $mime Source MIME.
	 * @return string[]|null
	 */
	public static function export_candidates_for_native( string $mime ): ?array {
		switch ( $mime ) {
			case 'application/vnd.google-apps.document':
				// Markdown is the LLM-preferred format. Drive added it as a
				// Docs export target in 2024 but older project credentials
				// can still 4xx on it — fall back to text/plain.
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

	/**
	 * True when the given MIME is a native Google type (Doc/Sheet/Slide/etc.).
	 */
	public static function is_native_google_type( string $mime ): bool {
		return 0 === strpos( $mime, 'application/vnd.google-apps.' );
	}

	/**
	 * True when the given file record represents a Drive folder.
	 *
	 * @param array $file Raw Drive file record.
	 */
	public static function is_folder( array $file ): bool {
		return ( $file['mimeType'] ?? '' ) === 'application/vnd.google-apps.folder';
	}

	/**
	 * Stream a binary Drive file to disk at the given destination path.
	 *
	 * Native Google types are NOT supported here — the caller is
	 * expected to route them through export_native(). Caller owns the
	 * destination path (parent directory must exist and be writable).
	 *
	 * @param string $file_id          Drive file ID.
	 * @param string $destination_path Absolute path to write to.
	 * @param array  $logs             Logs reference.
	 * @return array|\WP_Error         { size, local_path } on success.
	 */
	public function download_binary( string $file_id, string $destination_path, array &$logs ) {
		$parent_dir = dirname( $destination_path );
		if ( ! is_dir( $parent_dir ) || ! is_writable( $parent_dir ) ) {
			return new \WP_Error(
				'googledrive_dir_not_writable',
				sprintf( 'Destination directory is not writable: %s', $parent_dir )
			);
		}

		$url      = self::API_BASE . '/files/' . rawurlencode( $file_id );
		$temp     = trailingslashit( get_temp_dir() ) . wp_unique_filename( get_temp_dir(), 'gdrive-' . $file_id );
		$response = wp_remote_get(
			add_query_arg(
				array(
					'alt'               => 'media',
					'supportsAllDrives' => 'true',
				),
				$url
			),
			array(
				'timeout'  => 60,
				'headers'  => array(
					'Authorization' => 'Bearer ' . $this->access_token,
				),
				// Stream to avoid loading the full binary into PHP memory.
				'stream'   => true,
				'filename' => $temp,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$temp_path   = $response['filename'] ?? $temp;

		if ( 200 !== $status_code ) {
			if ( $temp_path && file_exists( $temp_path ) ) {
				wp_delete_file( $temp_path );
			}
			return $this->error_from_status( $status_code, $response );
		}

		if ( '' === $temp_path || ! file_exists( $temp_path ) ) {
			return new \WP_Error( 'googledrive_stream_failed', 'Drive returned 200 but temp file is missing.' );
		}

		$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
		if ( $fs ) {
			$moved = $fs->copy( $temp_path, $destination_path, true );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Filesystem API unavailable, native move fallback.
			$moved = @rename( $temp_path, $destination_path );
		}

		if ( file_exists( $temp_path ) ) {
			wp_delete_file( $temp_path );
		}

		if ( ! $moved || ! file_exists( $destination_path ) ) {
			return new \WP_Error( 'googledrive_move_failed', 'Failed to move downloaded file to destination.' );
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'GoogleDrive: Binary file downloaded.',
			'data'    => array( 'file_id' => $file_id, 'destination' => $destination_path ),
		);

		return array(
			'local_path' => $destination_path,
			'size'       => filesize( $destination_path ),
		);
	}

	/**
	 * Extract a folder ID from a Drive URL, or return a raw ID unchanged.
	 *
	 * Accepts:
	 * - `1abc...xyz` → returned as-is after stripping non-ID chars.
	 * - `https://drive.google.com/drive/folders/1abc...xyz` → `1abc...xyz`
	 * - `https://drive.google.com/drive/folders/1abc...xyz?usp=sharing` → `1abc...xyz`
	 *
	 * @param string $input Raw folder ID or full Drive URL.
	 * @return string Folder ID, or empty string when nothing usable was provided.
	 */
	public static function extract_folder_id( string $input ): string {
		$input = trim( $input );
		if ( '' === $input ) {
			return '';
		}

		if ( preg_match( self::FOLDER_URL_PATTERN, $input, $matches ) ) {
			return $matches[1];
		}

		// Strip query strings accidentally appended to raw IDs and keep
		// only the canonical Drive ID character class.
		$cleaned = preg_replace( '/[^a-zA-Z0-9_-]/', '', $input );
		return is_string( $cleaned ) ? $cleaned : '';
	}

	/**
	 * Extract a file ID from a Drive file URL, or return a raw ID unchanged.
	 *
	 * Accepts:
	 * - `1abc...xyz` → returned as-is after stripping non-ID chars.
	 * - `https://docs.google.com/document/d/1abc...xyz/edit` → `1abc...xyz`
	 * - `https://drive.google.com/file/d/1abc...xyz/view` → `1abc...xyz`
	 * - `https://docs.google.com/spreadsheets/d/1abc...xyz/edit` → `1abc...xyz`
	 * - `https://docs.google.com/presentation/d/1abc...xyz/edit` → `1abc...xyz`
	 *
	 * @param string $input Raw file ID or full Drive URL.
	 * @return string File ID, or empty string when nothing usable was provided.
	 */
	public static function extract_file_id( string $input ): string {
		$input = trim( $input );
		if ( '' === $input ) {
			return '';
		}

		if ( preg_match( self::FILE_URL_PATTERN, $input, $matches ) ) {
			return $matches[1];
		}

		$cleaned = preg_replace( '/[^a-zA-Z0-9_-]/', '', $input );
		return is_string( $cleaned ) ? $cleaned : '';
	}

	// ---------------------------------------------------------------------
	// Internal helpers.
	// ---------------------------------------------------------------------

	/**
	 * @param string   $folder_id
	 * @param bool     $recursive
	 * @param string[] $mime_filter
	 * @param string   $modified_since
	 * @param int      $depth
	 * @param array    $visited
	 * @param array    $logs
	 * @return array|\WP_Error
	 */
	private function list_folder_recursive(
		string $folder_id,
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

			$response = $this->drive_get( self::API_BASE . '/files', $params, $logs );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$files      = $response['files'] ?? array();
			$page_token = $response['nextPageToken'] ?? '';

			foreach ( $files as $file ) {
				if ( self::is_folder( $file ) ) {
					if ( $recursive ) {
						$nested = $this->list_folder_recursive(
							$file['id'] ?? '',
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
	 * GET a JSON-returning Drive API endpoint.
	 *
	 * @return array|\WP_Error Decoded JSON on success.
	 */
	private function drive_get( string $url, array $params, array &$logs ) {
		$full_url = add_query_arg( $params, $url );
		$response = wp_remote_get(
			$full_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
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
	private function drive_get_raw( string $url, array $params, array &$logs ) {
		$full_url = add_query_arg( $params, $url );
		$response = wp_remote_get(
			$full_url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
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
	 * Distinguishes scope-missing (403 with insufficient-scope reason),
	 * forbidden (403 otherwise), not-found (404), rate-limited (429
	 * with Retry-After respect), and generic HTTP errors.
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
			return new \WP_Error(
				'googledrive_unauthorized',
				'Google Drive returned 401 Unauthorized. Re-authenticate the Google integration.'
			);
		}

		if ( 403 === $status_code ) {
			if ( in_array( $reason, array( 'insufficientPermissions', 'insufficientScopes', 'forbidden' ), true )
				&& false !== stripos( $message . ' ' . $reason, 'scope' )
			) {
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
}
