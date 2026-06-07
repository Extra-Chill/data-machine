<?php
/**
 * Media Abilities
 *
 * Registers core media primitives as Abilities API endpoints:
 * - datamachine/upload-media: Upload/fetch image or video, store reference
 * - datamachine/validate-media: Validate against platform constraints
 * - datamachine/video-metadata: Extract video duration, resolution, codec, etc.
 *
 * Upload and validate work with any supported media type (image or video).
 * The correct validator is selected automatically based on detected MIME type.
 * Video metadata extraction is video-specific (requires ffprobe).
 *
 * @package DataMachine\Abilities\Media
 * @since 0.42.0
 */

namespace DataMachine\Abilities\Media;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\FileStorage;
use DataMachine\Core\FilesRepository\ImageValidator;
use DataMachine\Core\FilesRepository\MediaValidator;
use DataMachine\Core\FilesRepository\RemoteFileDownloader;
use DataMachine\Core\FilesRepository\VideoMetadata;
use DataMachine\Core\FilesRepository\VideoValidator;

defined( 'ABSPATH' ) || exit;

class MediaAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerUploadMedia();
			$this->registerValidateMedia();
			$this->registerVideoMetadata();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Register datamachine/upload-media ability.
	 */
	private function registerUploadMedia(): void {
		wp_register_ability(
			'datamachine/upload-media',
			array(
				'label'               => 'Upload Media',
				'description'         => 'Upload or fetch a media file (image or video), store it in the repository, and return a reference (path, URL, or media ID). Automatically detects media type from MIME.',
				'category'            => 'datamachine-media',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'              => array(
							'type'        => 'string',
							'description' => 'Remote URL to fetch media from. Either url or file_path is required.',
						),
						'file_path'        => array(
							'type'        => 'string',
							'description' => 'Local file path of an already-downloaded media file. Either url or file_path is required.',
						),
						'filename'         => array(
							'type'        => 'string',
							'description' => 'Desired filename for storage (default: derived from URL or file_path).',
						),
						'pipeline_id'      => array(
							'type'        => 'integer',
							'description' => 'Pipeline ID for file organization in the repository.',
						),
						'flow_id'          => array(
							'type'        => 'integer',
							'description' => 'Flow ID for file organization in the repository.',
						),
						'to_media_library' => array(
							'type'        => 'boolean',
							'description' => 'If true, sideload into WordPress Media Library instead of FilesRepository (default: false).',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'path'       => array( 'type' => 'string' ),
						'url'        => array( 'type' => 'string' ),
						'filename'   => array( 'type' => 'string' ),
						'size'       => array( 'type' => 'integer' ),
						'mime_type'  => array( 'type' => 'string' ),
						'media_type' => array( 'type' => 'string' ),
						'media_id'   => array( 'type' => array( 'integer', 'null' ) ),
						'error'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUploadMedia' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/validate-media ability.
	 */
	private function registerValidateMedia(): void {
		wp_register_ability(
			'datamachine/validate-media',
			array(
				'label'               => 'Validate Media',
				'description'         => 'Validate a media file (image or video) against platform-specific constraints (duration, size, codec, aspect ratio, resolution). Auto-detects media type.',
				'category'            => 'datamachine-media',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'path' ),
					'properties' => array(
						'path'        => array(
							'type'        => 'string',
							'description' => 'Absolute path to the media file.',
						),
						'media_type'  => array(
							'type'        => 'string',
							'description' => 'Force media type: "image" or "video". If omitted, auto-detected from file.',
						),
						'constraints' => array(
							'type'        => 'object',
							'description' => 'Platform constraint set. Keys: max_duration, min_duration, max_file_size, allowed_codecs, allowed_mimes, aspect_ratios, max_width, max_height, min_width, min_height.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'valid'      => array( 'type' => 'boolean' ),
						'media_type' => array( 'type' => 'string' ),
						'results'    => array( 'type' => 'object' ),
						'errors'     => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( $this, 'executeValidateMedia' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/video-metadata ability.
	 *
	 * Video-specific: uses ffprobe for extraction. Image metadata extraction
	 * would be a separate ability if needed (EXIF, getimagesize, etc.).
	 */
	private function registerVideoMetadata(): void {
		wp_register_ability(
			'datamachine/video-metadata',
			array(
				'label'               => 'Video Metadata',
				'description'         => 'Extract video metadata (duration, resolution, codec, bitrate, framerate) using ffprobe with graceful degradation',
				'category'            => 'datamachine-media',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'path' ),
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Absolute path to the video file.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'duration'  => array( 'type' => array( 'number', 'null' ) ),
						'width'     => array( 'type' => array( 'integer', 'null' ) ),
						'height'    => array( 'type' => array( 'integer', 'null' ) ),
						'codec'     => array( 'type' => array( 'string', 'null' ) ),
						'bitrate'   => array( 'type' => array( 'integer', 'null' ) ),
						'framerate' => array( 'type' => array( 'number', 'null' ) ),
						'mime_type' => array( 'type' => array( 'string', 'null' ) ),
						'file_size' => array( 'type' => 'integer' ),
						'format'    => array( 'type' => array( 'string', 'null' ) ),
						'ffprobe'   => array( 'type' => 'boolean' ),
						'error'     => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'execute_callback'    => array( $this, 'executeVideoMetadata' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	// ── Execute callbacks ──────────────────────────────────────────────

	/**
	 * Execute upload-media ability.
	 *
	 * Accepts either a remote URL (downloads and stores) or a local file path
	 * (validates and registers). Auto-detects whether the file is an image or
	 * video from its MIME type and validates accordingly.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public function executeUploadMedia( array $input ): array {
		$url       = $input['url'] ?? '';
		$file_path = $input['file_path'] ?? '';
		$filename  = $input['filename'] ?? '';

		if ( empty( $url ) && empty( $file_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Either url or file_path is required.',
			);
		}

		// Remote URL: download first, then validate.
		if ( ! empty( $url ) ) {
			return $this->uploadFromUrl( $url, $filename, $input );
		}

		// Local file path: validate and return reference.
		return $this->uploadFromPath( $file_path, $input );
	}

	/**
	 * Execute validate-media ability.
	 *
	 * @param array $input Ability input.
	 * @return array Validation result.
	 */
	public function executeValidateMedia( array $input ): array {
		$path        = $input['path'] ?? '';
		$constraints = $input['constraints'] ?? array();
		$media_type  = $input['media_type'] ?? '';

		if ( empty( $path ) ) {
			return array(
				'success' => false,
				'valid'   => false,
				'errors'  => array( 'path is required' ),
			);
		}

		$validator = $this->resolveValidator( $path, $media_type );
		$detected  = $validator instanceof VideoValidator ? 'video' : 'image';

		// If no constraints provided, do basic validation only.
		if ( empty( $constraints ) ) {
			$result = $validator->validate_repository_file( $path );
			return array(
				'success'    => true,
				'valid'      => $result['valid'],
				'media_type' => $detected,
				'results'    => array(),
				'errors'     => $result['errors'],
			);
		}

		// Constraint-based validation. For video, get metadata for duration/codec checks.
		$metadata = array();
		if ( 'video' === $detected ) {
			$metadata = VideoMetadata::extract( $path );
		}

		$result = $validator->validate_against_constraints( $path, $constraints, $metadata );

		return array(
			'success'    => true,
			'valid'      => $result['valid'],
			'media_type' => $detected,
			'results'    => $result['results'],
			'errors'     => $result['errors'],
		);
	}

	/**
	 * Execute video-metadata ability.
	 *
	 * @param array $input Ability input.
	 * @return array Metadata result.
	 */
	public function executeVideoMetadata( array $input ): array {
		$path = $input['path'] ?? '';

		if ( empty( $path ) ) {
			return array(
				'success' => false,
				'error'   => 'path is required',
			);
		}

		$metadata            = VideoMetadata::extract( $path );
		$metadata['success'] = empty( $metadata['error'] ) || $metadata['ffprobe'];

		return $metadata;
	}

	// ── Internal helpers ───────────────────────────────────────────────

	/**
	 * Download media from URL and store in repository.
	 *
	 * @param string $url      Remote URL.
	 * @param string $filename Desired filename.
	 * @param array  $input    Full input array.
	 * @return array Result.
	 */
	private function uploadFromUrl( string $url, string $filename, array $input ): array {
		if ( empty( $filename ) ) {
			$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
			if ( empty( $filename ) ) {
				$filename = 'media-' . time();
			}
		}

		$pipeline_id = (int) ( $input['pipeline_id'] ?? 0 );
		$flow_id     = (int) ( $input['flow_id'] ?? 0 );

		$context = array(
			'pipeline_id' => $pipeline_id ? $pipeline_id : 'direct',
			'flow_id'     => $flow_id ? $flow_id : 'media-upload',
		);

		$downloader = new RemoteFileDownloader();
		$file_info  = $downloader->download_remote_file( $url, $filename, $context, array( 'timeout' => 120 ) );

		if ( ! $file_info ) {
			return array(
				'success' => false,
				'error'   => 'Failed to download media from URL.',
			);
		}

		// Auto-detect media type and validate.
		$validator  = $this->resolveValidator( $file_info['path'] );
		$media_type = $validator instanceof VideoValidator ? 'video' : 'image';
		$validation = $validator->validate_repository_file( $file_info['path'] );

		if ( ! $validation['valid'] ) {
			wp_delete_file( $file_info['path'] );
			return array(
				'success' => false,
				'error'   => "Downloaded file is not a valid {$media_type}: " . implode( '; ', $validation['errors'] ),
			);
		}

		// Optionally sideload to media library.
		$media_id = null;
		if ( ! empty( $input['to_media_library'] ) ) {
			$media_id = $this->sideloadToMediaLibrary( $file_info['path'], $filename );
		}

		return array(
			'success'    => true,
			'path'       => $file_info['path'],
			'url'        => $file_info['url'] ?? '',
			'filename'   => $file_info['filename'],
			'size'       => $file_info['size'],
			'mime_type'  => $validation['mime_type'],
			'media_type' => $media_type,
			'media_id'   => $media_id,
		);
	}

	/**
	 * Register an existing local file as a media reference.
	 *
	 * @param string $file_path Local file path.
	 * @param array  $input     Full input array.
	 * @return array Result.
	 */
	private function uploadFromPath( string $file_path, array $input ): array {
		$validator  = $this->resolveValidator( $file_path );
		$media_type = $validator instanceof VideoValidator ? 'video' : 'image';
		$validation = $validator->validate_repository_file( $file_path );

		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => "File is not a valid {$media_type}: " . implode( '; ', $validation['errors'] ),
			);
		}

		$file_storage = new FileStorage();
		$public_url   = $file_storage->get_public_url( $file_path );

		// Optionally sideload to media library.
		$media_id = null;
		if ( ! empty( $input['to_media_library'] ) ) {
			$fn       = $input['filename'] ?? basename( $file_path );
			$media_id = $this->sideloadToMediaLibrary( $file_path, $fn );
		}

		return array(
			'success'    => true,
			'path'       => $file_path,
			'url'        => $public_url,
			'filename'   => basename( $file_path ),
			'size'       => $validation['size'],
			'mime_type'  => $validation['mime_type'],
			'media_type' => $media_type,
			'media_id'   => $media_id,
		);
	}

	/**
	 * Resolve the correct MediaValidator subclass for a file.
	 *
	 * Checks file extension first (fast), then falls back to image validator
	 * as the default since images are the more common media type.
	 *
	 * @param string $file_path  File path.
	 * @param string $media_type Optional forced media type ('image' or 'video').
	 * @return MediaValidator Appropriate validator instance.
	 */
	private function resolveValidator( string $file_path, string $media_type = '' ): MediaValidator {
		if ( 'video' === $media_type ) {
			return new VideoValidator();
		}

		if ( 'image' === $media_type ) {
			return new ImageValidator();
		}

		// Auto-detect from extension.
		if ( VideoValidator::is_video_extension( $file_path ) ) {
			return new VideoValidator();
		}

		// Default to image — the more common media type.
		return new ImageValidator();
	}

	/**
	 * Sideload a media file into the WordPress Media Library.
	 *
	 * @param string $file_path Absolute path to media file.
	 * @param string $filename  Desired filename.
	 * @return int|null Attachment ID or null on failure.
	 */
	private function sideloadToMediaLibrary( string $file_path, string $filename ): ?int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_array = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $file_path,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			do_action(
				'datamachine_log',
				'error',
				'MediaAbilities: Failed to sideload media to library',
				array(
					'error'    => $attachment_id->get_error_message(),
					'filename' => $filename,
				)
			);
			return null;
		}

		return (int) $attachment_id;
	}
}
