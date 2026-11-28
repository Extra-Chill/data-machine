<?php
/**
 * Video validation utilities for Data Machine.
 *
 * Thin subclass of MediaValidator for video-specific MIME types.
 * Validates video files from repository files (not URLs) to ensure they can be
 * processed by publish handlers. Inherits constraint-based validation from the
 * base class.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.42.0
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VideoValidator extends MediaValidator {

	/**
	 * Supported video MIME types.
	 *
	 * @return array<string>
	 */
	protected function supported_mime_types(): array {
		return array(
			'video/mp4',
			'video/quicktime',
			'video/webm',
			'video/x-msvideo',
			'video/x-ms-wmv',
			'video/mpeg',
			'video/3gpp',
			'video/x-matroska',
		);
	}

	/**
	 * Human-readable label for error messages.
	 *
	 * @return string
	 */
	protected function media_label(): string {
		return 'Video';
	}

	/**
	 * Check if a file path looks like a video based on extension.
	 *
	 * @param string $file_path File path to check.
	 * @return bool True if the extension suggests a video file.
	 */
	public static function is_video_extension( string $file_path ): bool {
		$video_extensions = array( 'mp4', 'mov', 'webm', 'avi', 'wmv', 'mpeg', 'mpg', '3gp', 'mkv' );
		$extension        = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		return in_array( $extension, $video_extensions, true );
	}
}
