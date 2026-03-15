<?php
/**
 * Video Abilities
 *
 * Registers core video primitives as Abilities API endpoints:
 * - datamachine/upload-video: Upload/fetch video, store reference
 * - datamachine/validate-video: Validate against platform constraints
 * - datamachine/video-metadata: Extract duration, resolution, codec, etc.
 *
 * These primitives are platform-agnostic — extensions (e.g., data-machine-socials)
 * consume them for platform-specific video publishing.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.42.0
 */

namespace DataMachine\Abilities\Media;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FileStorage;
use DataMachine\Core\FilesRepository\RemoteFileDownloader;
use DataMachine\Core\FilesRepository\VideoMetadata;
use DataMachine\Core\FilesRepository\VideoValidator;

defined( 'ABSPATH' ) || exit;

class VideoAbilities {

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
			$this->registerUploadVideo();
			$this->registerValidateVideo();
			$this->registerVideoMetadata();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Register datamachine/upload-video ability.
	 */
	private function registerUploadVideo(): void {
		wp_register_ability(
			'datamachine/upload-video',
			array(
				'label'               => 'Upload Video',
				'description'         => 'Upload or fetch a video file, store it in the repository, and return a reference (path, URL, or media ID)',
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'         => array(
							'type'        => 'string',
							'description' => 'Remote URL to fetch video from. Either url or file_path is required.',
						),
						'file_path'   => array(
							'type'        => 'string',
							'description' => 'Local file path of an already-downloaded video. Either url or file_path is required.',
						),
						'filename'    => array(
							'type'        => 'string',
							'description' => 'Desired filename for storage (default: derived from URL or file_path).',
						),
						'pipeline_id' => array(
							'type'        => 'integer',
							'description' => 'Pipeline ID for file organization in the repository.',
						),
						'flow_id'     => array(
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
						'success'   => array( 'type' => 'boolean' ),
						'path'      => array( 'type' => 'string' ),
						'url'       => array( 'type' => 'string' ),
						'filename'  => array( 'type' => 'string' ),
						'size'      => array( 'type' => 'integer' ),
						'mime_type' => array( 'type' => 'string' ),
						'media_id'  => array( 'type' => array( 'integer', 'null' ) ),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUploadVideo' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/validate-video ability.
	 */
	private function registerValidateVideo(): void {
		wp_register_ability(
			'datamachine/validate-video',
			array(
				'label'               => 'Validate Video',
				'description'         => 'Validate a video file against platform-specific constraints (duration, size, codec, aspect ratio, resolution)',
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'path' ),
					'properties' => array(
						'path'        => array(
							'type'        => 'string',
							'description' => 'Absolute path to the video file.',
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
						'success' => array( 'type' => 'boolean' ),
						'valid'   => array( 'type' => 'boolean' ),
						'results' => array( 'type' => 'object' ),
						'errors'  => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( $this, 'executeValidateVideo' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/video-metadata ability.
	 */
	private function registerVideoMetadata(): void {
		wp_register_ability(
			'datamachine/video-metadata',
			array(
				'label'               => 'Video Metadata',
				'description'         => 'Extract video metadata (duration, resolution, codec, bitrate, framerate) using ffprobe with graceful degradation',
				'category'            => 'datamachine',
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

	/**
	 * Execute upload-video ability.
	 *
	 * Accepts either a remote URL (downloads and stores) or a local file path
	 * (validates and registers). Returns the stored reference.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public function executeUploadVideo( array $input ): array {
		$url       = $input['url'] ?? '';
		$file_path = $input['file_path'] ?? '';
		$filename  = $input['filename'] ?? '';

		if ( empty( $url ) && empty( $file_path ) ) {
			return array(
				'success' => false,
				'error'   => 'Either url or file_path is required.',
			);
		}

		$validator = new VideoValidator();

		// Remote URL: download and store.
		if ( ! empty( $url ) ) {
			return $this->uploadFromUrl( $url, $filename, $input, $validator );
		}

		// Local file path: validate and return reference.
		return $this->uploadFromPath( $file_path, $input, $validator );
	}

	/**
	 * Download video from URL and store in repository.
	 *
	 * @param string         $url       Remote URL.
	 * @param string         $filename  Desired filename.
	 * @param array          $input     Full input array.
	 * @param VideoValidator $validator Validator instance.
	 * @return array Result.
	 */
	private function uploadFromUrl( string $url, string $filename, array $input, VideoValidator $validator ): array {
		if ( empty( $filename ) ) {
			$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
			if ( empty( $filename ) || ! VideoValidator::is_video_extension( $filename ) ) {
				$filename = 'video-' . time() . '.mp4';
			}
		}

		$pipeline_id = (int) ( $input['pipeline_id'] ?? 0 );
		$flow_id     = (int) ( $input['flow_id'] ?? 0 );

		$context = array(
			'pipeline_id' => $pipeline_id ?: 'direct',
			'flow_id'     => $flow_id ?: 'video-upload',
		);

		$downloader = new RemoteFileDownloader();
		$file_info  = $downloader->download_remote_file( $url, $filename, $context, array( 'timeout' => 120 ) );

		if ( ! $file_info ) {
			return array(
				'success' => false,
				'error'   => 'Failed to download video from URL.',
			);
		}

		// Validate the downloaded file.
		$validation = $validator->validate_repository_file( $file_info['path'] );
		if ( ! $validation['valid'] ) {
			// Clean up invalid file.
			wp_delete_file( $file_info['path'] );
			return array(
				'success' => false,
				'error'   => 'Downloaded file is not a valid video: ' . implode( '; ', $validation['errors'] ),
			);
		}

		// Optionally sideload to media library.
		$media_id = null;
		if ( ! empty( $input['to_media_library'] ) ) {
			$media_id = $this->sideloadToMediaLibrary( $file_info['path'], $filename );
		}

		return array(
			'success'   => true,
			'path'      => $file_info['path'],
			'url'       => $file_info['url'] ?? '',
			'filename'  => $file_info['filename'],
			'size'      => $file_info['size'],
			'mime_type' => $validation['mime_type'],
			'media_id'  => $media_id,
		);
	}

	/**
	 * Register an existing local file as a video reference.
	 *
	 * @param string         $file_path Local file path.
	 * @param array          $input     Full input array.
	 * @param VideoValidator $validator Validator instance.
	 * @return array Result.
	 */
	private function uploadFromPath( string $file_path, array $input, VideoValidator $validator ): array {
		$validation = $validator->validate_repository_file( $file_path );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => 'File is not a valid video: ' . implode( '; ', $validation['errors'] ),
			);
		}

		$file_storage = new FileStorage();
		$public_url   = $file_storage->get_public_url( $file_path );

		// Optionally sideload to media library.
		$media_id = null;
		if ( ! empty( $input['to_media_library'] ) ) {
			$filename = $input['filename'] ?? basename( $file_path );
			$media_id = $this->sideloadToMediaLibrary( $file_path, $filename );
		}

		return array(
			'success'   => true,
			'path'      => $file_path,
			'url'       => $public_url,
			'filename'  => basename( $file_path ),
			'size'      => $validation['size'],
			'mime_type' => $validation['mime_type'],
			'media_id'  => $media_id,
		);
	}

	/**
	 * Sideload a video file into the WordPress Media Library.
	 *
	 * @param string $file_path Absolute path to video file.
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
				'VideoAbilities: Failed to sideload video to media library',
				array(
					'error'    => $attachment_id->get_error_message(),
					'filename' => $filename,
				)
			);
			return null;
		}

		return (int) $attachment_id;
	}

	/**
	 * Execute validate-video ability.
	 *
	 * @param array $input Ability input.
	 * @return array Validation result.
	 */
	public function executeValidateVideo( array $input ): array {
		$path        = $input['path'] ?? '';
		$constraints = $input['constraints'] ?? array();

		if ( empty( $path ) ) {
			return array(
				'success' => false,
				'valid'   => false,
				'errors'  => array( 'path is required' ),
			);
		}

		$validator = new VideoValidator();

		// If no constraints provided, do basic validation only.
		if ( empty( $constraints ) ) {
			$result = $validator->validate_repository_file( $path );
			return array(
				'success' => true,
				'valid'   => $result['valid'],
				'results' => array(),
				'errors'  => $result['errors'],
			);
		}

		// Constraint-based validation requires metadata for duration/resolution/codec checks.
		$metadata = VideoMetadata::extract( $path );
		$result   = $validator->validate_against_constraints( $path, $constraints, $metadata );

		return array(
			'success' => true,
			'valid'   => $result['valid'],
			'results' => $result['results'],
			'errors'  => $result['errors'],
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
}
