<?php
/**
 * Google Drive fetch handler.
 *
 * Lists files in a Drive folder and emits one pipeline item per
 * unprocessed file. The actual Drive API work (listing, exporting
 * native Google docs, streaming binaries to disk) lives in
 * FetchGoogleDriveAbility so any caller (REST, CLI, chat tool, this
 * pipeline handler) can drive it.
 *
 * Shares the unified Google OAuth provider (slug: google) with the
 * Sheets handlers. See data-machine-business.php for the scope union
 * wiring.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\GoogleDrive
 * @since 0.3.0
 */

namespace DataMachineBusiness\Handlers\GoogleDrive;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineBusiness\Abilities\GoogleDrive\FetchGoogleDriveAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleDriveFetch extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'google_drive_fetch' );

		self::registerHandler(
			'google_drive_fetch',
			'fetch',
			self::class,
			__( 'Google Drive', 'data-machine-business' ),
			__( 'Fetch files from a Google Drive folder (native Docs exported to text, binaries streamed to disk).', 'data-machine-business' ),
			true,
			\DataMachineBusiness\OAuth\Providers\GoogleAuth::class,
			GoogleDriveFetchSettings::class,
			null,
			// Reuse the unified Google credential (slug: google). Drive
			// scopes are unioned via the `datamachine_google_oauth_scopes`
			// filter in the plugin loader.
			'google'
		);
	}

	/**
	 * Fetch the next unprocessed file from the configured Drive folder.
	 *
	 * Emits one pipeline item per call. Subsequent runs will skip files
	 * already marked processed via ExecutionContext::markItemProcessed().
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$folder_raw = trim( (string) ( $config['googledrive_fetch_folder'] ?? '' ) );
		$folder_id  = GoogleDriveFetchSettings::extract_folder_id( $folder_raw );

		if ( '' === $folder_id ) {
			$context->log( 'error', 'GoogleDrive: Folder ID is required.' );
			return array();
		}

		$mime_filter_raw = (string) ( $config['googledrive_fetch_mime_filter'] ?? '' );
		$mime_filter     = array();
		if ( '' !== $mime_filter_raw ) {
			$mime_filter = array_filter( array_map( 'trim', explode( ',', $mime_filter_raw ) ) );
		}

		$ability = new FetchGoogleDriveAbility();
		$result  = $ability->execute(
			array(
				'folder_id'       => $folder_id,
				'recursive'       => ! empty( $config['googledrive_fetch_recursive'] ),
				'mime_filter'     => $mime_filter,
				'modified_since'  => (string) ( $config['googledrive_fetch_modified_since'] ?? '' ),
				'download_target' => $context->getFileContext(),
			)
		);

		if ( empty( $result['success'] ) ) {
			$context->log( 'error', 'GoogleDrive: ' . ( $result['error'] ?? 'Unknown error' ) );
			return array();
		}

		$files = $result['data']['files'] ?? array();
		if ( empty( $files ) ) {
			$context->log( 'debug', 'GoogleDrive: Folder is empty or no files matched the filter.' );
			return array();
		}

		$context->log(
			'debug',
			'GoogleDrive: Listed folder.',
			array(
				'folder_id'  => $folder_id,
				'file_count' => count( $files ),
			)
		);

		foreach ( $files as $file ) {
			$file_id = $file['id'] ?? '';
			if ( '' === $file_id ) {
				continue;
			}

			$item_identifier = 'google_drive_' . $folder_id . '_' . $file_id . '_' . ( $file['modified_time'] ?? '' );

			if ( $context->isItemProcessed( $item_identifier ) ) {
				continue;
			}

			$context->markItemProcessed( $item_identifier );

			$context->storeEngineData(
				array(
					'source_url' => $file['web_view_link'] ?? '',
					'image_url'  => '',
				)
			);

			$payload = $file['payload'] ?? array();
			$content = '';
			if ( isset( $payload['text'] ) ) {
				$content = (string) $payload['text'];
			} elseif ( isset( $payload['local_path'] ) ) {
				$content = sprintf(
					/* translators: %s: local filesystem path. */
					__( 'Binary file downloaded to: %s', 'data-machine-business' ),
					$payload['local_path']
				);
			} elseif ( ! empty( $payload['skipped'] ) ) {
				$context->log(
					'warning',
					'GoogleDrive: Skipped unsupported native file.',
					array(
						'file_id'   => $file_id,
						'mime_type' => $file['mime_type'] ?? '',
						'name'      => $file['name'] ?? '',
					)
				);
				continue;
			}

			return array(
				'title'    => $file['name'] ?? ( 'Drive file ' . $file_id ),
				'content'  => $content,
				'metadata' => array(
					'source_type'   => 'google_drive_fetch',
					'folder_id'     => $folder_id,
					'file_id'       => $file_id,
					'mime_type'     => $file['mime_type'] ?? '',
					'modified_time' => $file['modified_time'] ?? '',
					'web_view_link' => $file['web_view_link'] ?? '',
					'size'          => $file['size'] ?? null,
					'md5_checksum'  => $file['md5_checksum'] ?? '',
					'owners'        => $file['owners'] ?? array(),
					'payload'       => $payload,
				),
			);
		}

		$context->log( 'debug', 'GoogleDrive: No unprocessed files found.' );
		return array();
	}

	public static function get_label(): string {
		return __( 'Google Drive Fetch', 'data-machine-business' );
	}
}
