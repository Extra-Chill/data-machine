<?php
/**
 * Read Google Drive Doc Ability.
 *
 * One-shot text export of a native Google file (Doc, Sheet, Slide)
 * suitable for direct CLI / chat / REST invocation.
 *
 * Routing by source MIME:
 * - Google Docs        → exports as text/markdown (preferred) or text/plain
 *                        when the caller requests `format=text`. Falls back
 *                        from markdown to plain when older Drive credentials
 *                        reject the markdown exporter.
 * - Google Sheets      → exports as text/csv. CSV is the only useful text
 *                        representation; `format=markdown` is treated as
 *                        CSV for sheets rather than failing.
 * - Google Slides      → exports as text/plain.
 * - Native binary file → WP_Error( googledrive_not_a_document ) telling
 *                        the caller to use `datamachine/download-googledrive`
 *                        instead. This is intentional: text export of an
 *                        MP3 or a PDF doesn't have a meaningful answer.
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\GoogleDrive
 * @since 0.4.0
 */

namespace DataMachineBusiness\Abilities\GoogleDrive;

use DataMachine\Abilities\PermissionHelper;
use DataMachineBusiness\Handlers\GoogleDrive\GoogleDriveClient;

defined( 'ABSPATH' ) || exit;

class ReadGoogleDriveDocAbility {

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
				'datamachine/read-googledrive-doc',
				array(
					'label'               => __( 'Read Google Drive Document', 'data-machine-business' ),
					'description'         => __( 'Export the text content of a Google Doc, Sheet, or Slides file as markdown, plain text, or CSV. Refuses native binary files (use download-googledrive for those).', 'data-machine-business' ),
					'category'            => 'datamachine-fetch',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'file' ),
						'properties' => array(
							'file' => array(
								'type'        => 'string',
								'description' => __( 'Google Drive file ID or full Drive URL (e.g. https://docs.google.com/document/d/1abc...xyz/edit, https://docs.google.com/spreadsheets/d/..., https://drive.google.com/file/d/...). Accepts either form; the URL is parsed server-side.', 'data-machine-business' ),
							),
							'format' => array(
								'type'        => 'string',
								'enum'        => array( 'markdown', 'text', 'csv' ),
								'default'     => 'markdown',
								'description' => __( 'Preferred export format. "markdown" exports Google Docs as text/markdown (falling back to text/plain on older Drive credentials) and is treated as CSV for Google Sheets. "text" exports Docs/Slides as text/plain. "csv" forces text/csv export and is only meaningful for Google Sheets.', 'data-machine-business' ),
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
									'id'            => array( 'type' => 'string' ),
									'name'          => array( 'type' => 'string' ),
									'mime_type'     => array( 'type' => 'string', 'description' => __( 'Source MIME type of the Drive file (e.g. application/vnd.google-apps.document).', 'data-machine-business' ) ),
									'export_mime'   => array( 'type' => 'string', 'description' => __( 'The MIME type the body was exported as (e.g. text/markdown, text/csv, text/plain).', 'data-machine-business' ) ),
									'text'          => array( 'type' => 'string', 'description' => __( 'Exported text content of the document.', 'data-machine-business' ) ),
									'modified_time' => array( 'type' => 'string' ),
									'web_view_link' => array( 'type' => 'string' ),
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

		$file_raw = isset( $input['file'] ) ? (string) $input['file'] : '';
		$file_id  = GoogleDriveClient::extract_file_id( $file_raw );
		if ( '' === $file_id ) {
			return $this->fail( 'file is required (Drive file ID or URL).', $logs );
		}

		$format = isset( $input['format'] ) ? strtolower( trim( (string) $input['format'] ) ) : 'markdown';
		if ( ! in_array( $format, array( 'markdown', 'text', 'csv' ), true ) ) {
			return $this->fail( sprintf( 'Unsupported format "%s". Use one of: markdown, text, csv.', $format ), $logs );
		}

		$access_token = AuthHelper::get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $this->fail( $access_token->get_error_message(), $logs );
		}

		$client = new GoogleDriveClient( $access_token );

		$metadata = $client->get_file_metadata( $file_id, $logs );
		if ( is_wp_error( $metadata ) ) {
			return $this->fail( $metadata->get_error_message(), $logs );
		}

		$mime = (string) ( $metadata['mimeType'] ?? '' );
		$name = (string) ( $metadata['name'] ?? '' );

		if ( ! GoogleDriveClient::is_native_google_type( $mime ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'GoogleDrive: read-doc called on a non-native file.',
				'data'    => array( 'file_id' => $file_id, 'mime_type' => $mime ),
			);
			return array(
				'success' => false,
				'error'   => sprintf( 'File "%s" is not a Google Doc / Sheet / Slides (mime: %s). Use datamachine/download-googledrive to fetch binary files.', $name, $mime ),
				'data'    => array(
					'id'        => $file_id,
					'name'      => $name,
					'mime_type' => $mime,
				),
				'error_code' => 'googledrive_not_a_document',
				'logs'       => $logs,
			);
		}

		$candidates = $this->candidates_for_format( $mime, $format );
		if ( empty( $candidates ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Native Google file "%s" (mime: %s) is not exportable as text.', $name, $mime ),
				'data'    => array(
					'id'        => $file_id,
					'name'      => $name,
					'mime_type' => $mime,
				),
				'error_code' => 'googledrive_not_exportable',
				'logs'       => $logs,
			);
		}

		$last_error = null;
		foreach ( $candidates as $candidate_mime ) {
			$body = $client->export_native( $file_id, $candidate_mime, $logs );
			if ( ! is_wp_error( $body ) ) {
				return array(
					'success' => true,
					'data'    => array(
						'id'            => $file_id,
						'name'          => $name,
						'mime_type'     => $mime,
						'export_mime'   => $candidate_mime,
						'text'          => (string) $body,
						'modified_time' => (string) ( $metadata['modifiedTime'] ?? '' ),
						'web_view_link' => (string) ( $metadata['webViewLink'] ?? '' ),
					),
					'logs'    => $logs,
				);
			}

			$last_error = $body;
			$logs[]     = array(
				'level'   => 'debug',
				'message' => 'GoogleDrive: Export candidate not accepted, trying next.',
				'data'    => array(
					'file_id'        => $file_id,
					'candidate_mime' => $candidate_mime,
					'error'          => $body->get_error_message(),
				),
			);
		}

		$reason = $last_error instanceof \WP_Error ? $last_error->get_error_message() : 'Export failed.';
		return $this->fail( $reason, $logs );
	}

	/**
	 * Resolve the ordered list of export MIMEs for a (source-MIME, format) pair.
	 *
	 * @param string $mime   Source MIME (a native Google type).
	 * @param string $format Caller-requested format: markdown | text | csv.
	 * @return string[]      Ordered candidates to try via files.export.
	 */
	private function candidates_for_format( string $mime, string $format ): array {
		switch ( $mime ) {
			case 'application/vnd.google-apps.document':
				if ( 'text' === $format ) {
					return array( 'text/plain' );
				}
				if ( 'csv' === $format ) {
					// CSV doesn't make sense for a Doc — fall back to text/plain
					// rather than failing, so a chat-driven caller asking for
					// "csv" doesn't get an error on the first non-Sheet file.
					return array( 'text/plain' );
				}
				// markdown (default): try text/markdown first, fall back to
				// text/plain when older Drive credentials reject markdown.
				return array( 'text/markdown', 'text/plain' );

			case 'application/vnd.google-apps.spreadsheet':
				// Sheets are always CSV regardless of requested format.
				// markdown / text requests collapse to CSV because that
				// is the only useful single-export representation.
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

		// Unknown native type — refuse rather than guess.
		return array();
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
