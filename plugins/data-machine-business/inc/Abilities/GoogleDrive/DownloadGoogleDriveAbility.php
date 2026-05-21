<?php
/**
 * Download Google Drive Ability.
 *
 * One-shot download of a binary Drive file to local disk for direct
 * CLI / chat / REST invocation. The destination is configurable; the
 * default lives under the WordPress uploads area at
 * `<uploads>/drive-imports/<YYYY>/<MM>/` so subsequent abilities (e.g.
 * a future media-library import) can pick the file up by absolute path.
 *
 * Refuses native Google types (Doc / Sheet / Slide) — those go through
 * `datamachine/read-googledrive-doc` instead.
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\GoogleDrive
 * @since 0.4.0
 */

namespace DataMachineBusiness\Abilities\GoogleDrive;

use DataMachine\Abilities\PermissionHelper;
use DataMachineBusiness\Handlers\GoogleDrive\GoogleDriveClient;

defined( 'ABSPATH' ) || exit;

class DownloadGoogleDriveAbility {

	private const DEFAULT_SUBDIR = 'drive-imports';

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
				'datamachine/download-googledrive',
				array(
					'label'               => __( 'Download Google Drive File', 'data-machine-business' ),
					'description'         => __( 'Download a binary Google Drive file to local disk. Defaults to the WordPress uploads area under drive-imports/<YYYY>/<MM>/. Refuses native Google Docs/Sheets/Slides — use read-googledrive-doc for those.', 'data-machine-business' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'file' ),
						'properties' => array(
							'file' => array(
								'type'        => 'string',
								'description' => __( 'Google Drive file ID or full Drive URL (e.g. https://drive.google.com/file/d/1abc...xyz/view). Accepts either form; the URL is parsed server-side.', 'data-machine-business' ),
							),
							'destination' => array(
								'type'        => 'string',
								'description' => __( 'Optional destination. Accepts an absolute server path (resolves to a directory the downloaded file is placed inside) OR a subdirectory relative to the WordPress uploads root. When omitted, the file lands in <wp-uploads>/drive-imports/<YYYY>/<MM>/.', 'data-machine-business' ),
							),
							'overwrite' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'When false (default), the ability refuses to overwrite an existing file at the resolved destination and returns googledrive_file_exists. When true, the existing file is replaced.', 'data-machine-business' ),
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
									'mime_type'     => array( 'type' => 'string' ),
									'size'          => array( 'type' => array( 'integer', 'null' ) ),
									'local_path'    => array( 'type' => 'string', 'description' => __( 'Absolute server path where the file was written. A follow-up ability (e.g. media-library import) can pick the file up by this path.', 'data-machine-business' ) ),
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

		$overwrite       = ! empty( $input['overwrite'] );
		$destination_raw = isset( $input['destination'] ) ? trim( (string) $input['destination'] ) : '';

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

		if ( GoogleDriveClient::is_native_google_type( $mime ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'GoogleDrive: download called on a native Google type.',
				'data'    => array( 'file_id' => $file_id, 'mime_type' => $mime ),
			);
			return array(
				'success' => false,
				'error'   => sprintf( 'File "%s" is a native Google type (mime: %s). Use datamachine/read-googledrive-doc to export its text content.', $name, $mime ),
				'data'    => array(
					'id'        => $file_id,
					'name'      => $name,
					'mime_type' => $mime,
				),
				'error_code' => 'googledrive_not_a_binary',
				'logs'       => $logs,
			);
		}

		$directory_result = $this->resolve_destination_directory( $destination_raw );
		if ( is_wp_error( $directory_result ) ) {
			return $this->fail( $directory_result->get_error_message(), $logs );
		}
		$directory = $directory_result;

		$safe_name        = sanitize_file_name( $name ?: ( 'drive-' . $file_id ) );
		if ( '' === $safe_name ) {
			$safe_name = 'drive-' . $file_id;
		}
		$destination_path = trailingslashit( $directory ) . $safe_name;

		if ( file_exists( $destination_path ) && ! $overwrite ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'GoogleDrive: Destination file exists and overwrite=false.',
				'data'    => array( 'destination' => $destination_path ),
			);
			return array(
				'success' => false,
				'error'   => sprintf( 'File already exists at %s. Pass overwrite=true to replace it.', $destination_path ),
				'data'    => array(
					'id'         => $file_id,
					'name'       => $name,
					'mime_type'  => $mime,
					'local_path' => $destination_path,
				),
				'error_code' => 'googledrive_file_exists',
				'logs'       => $logs,
			);
		}

		// If overwrite is requested and the target exists, remove it
		// first so download_binary's rename() never fails with EEXIST on
		// platforms where rename() does not overwrite atomically.
		if ( file_exists( $destination_path ) && $overwrite ) {
			wp_delete_file( $destination_path );
		}

		$saved = $client->download_binary( $file_id, $destination_path, $logs );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $saved->get_error_message(), $logs );
		}

		return array(
			'success' => true,
			'data'    => array(
				'id'            => $file_id,
				'name'          => $name,
				'mime_type'     => $mime,
				'size'          => isset( $saved['size'] ) ? (int) $saved['size'] : null,
				'local_path'    => (string) $saved['local_path'],
				'web_view_link' => (string) ( $metadata['webViewLink'] ?? '' ),
			),
			'logs'    => $logs,
		);
	}

	/**
	 * Resolve the destination directory and ensure it exists.
	 *
	 * Accepts:
	 * - empty / missing → default to <wp-uploads>/drive-imports/<YYYY>/<MM>/
	 * - absolute path   → used verbatim as the directory (parent must exist
	 *                     or be creatable). Treated as a directory; the file
	 *                     name is always derived from the Drive name.
	 * - relative path   → joined under the WordPress uploads root.
	 *
	 * @param string $raw Caller-supplied destination.
	 * @return string|\WP_Error Absolute directory path on success.
	 */
	private function resolve_destination_directory( string $raw ) {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'googledrive_uploads_unavailable', sprintf( 'WordPress uploads directory unavailable: %s', $uploads['error'] ) );
		}

		$uploads_basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $uploads_basedir ) {
			return new \WP_Error( 'googledrive_uploads_unavailable', 'WordPress uploads basedir is empty.' );
		}

		if ( '' === $raw ) {
			$dir = trailingslashit( $uploads_basedir )
				. self::DEFAULT_SUBDIR . '/'
				. gmdate( 'Y' ) . '/'
				. gmdate( 'm' );
		} elseif ( $this->is_absolute_path( $raw ) ) {
			$dir = rtrim( $raw, '/\\' );
		} else {
			// Relative paths sit under uploads/. Strip leading separators
			// and parent-directory shenanigans before joining.
			$clean = ltrim( $raw, '/\\' );
			$clean = str_replace( array( '..', "\0" ), '', $clean );
			if ( '' === $clean ) {
				return new \WP_Error( 'googledrive_invalid_destination', 'Destination resolved to an empty path.' );
			}
			$dir = trailingslashit( $uploads_basedir ) . $clean;
			$dir = rtrim( $dir, '/\\' );
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'googledrive_dir_failed', sprintf( 'Failed to create destination directory: %s', $dir ) );
		}

		if ( ! is_writable( $dir ) ) {
			return new \WP_Error( 'googledrive_dir_not_writable', sprintf( 'Destination directory is not writable: %s', $dir ) );
		}

		return $dir;
	}

	private function is_absolute_path( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}
		if ( '/' === $path[0] ) {
			return true;
		}
		// Windows: drive letter + colon (`C:\...`) or `\\server\share`.
		if ( preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) || 0 === strpos( $path, '\\\\' ) ) {
			return true;
		}
		return false;
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
