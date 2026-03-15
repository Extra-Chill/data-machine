<?php
/**
 * Video validation utilities for Data Machine.
 *
 * Validates video files from repository files (not URLs) to ensure they can be
 * processed by publish handlers. Supports constraint-based validation where
 * extensions pass platform-specific requirements.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.42.0
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VideoValidator {

	/**
	 * Supported video MIME types
	 */
	private const SUPPORTED_MIME_TYPES = array(
		'video/mp4',
		'video/quicktime',
		'video/webm',
		'video/x-msvideo',
		'video/x-ms-wmv',
		'video/mpeg',
		'video/3gpp',
		'video/x-matroska',
	);

	/**
	 * Validate repository video file.
	 *
	 * Basic validation: file exists, readable, valid MIME type, within size limit.
	 *
	 * @param string $file_path Path to video file in repository.
	 * @return array Validation result with 'valid', 'mime_type', 'size', 'errors'.
	 */
	public function validate_repository_file( string $file_path ): array {
		$result = array(
			'valid'     => false,
			'mime_type' => null,
			'size'      => 0,
			'errors'    => array(),
		);

		if ( ! file_exists( $file_path ) ) {
			$result['errors'][] = 'Video file not found in repository';
			return $result;
		}

		if ( ! is_readable( $file_path ) ) {
			$result['errors'][] = 'Video file not readable';
			return $result;
		}

		// Check file size.
		$file_size     = filesize( $file_path );
		$max_file_size = wp_max_upload_size();
		if ( $file_size > $max_file_size ) {
			$result['errors'][] = 'Video file too large';
			return $result;
		}

		// Check MIME type.
		$mime_type = $this->get_file_mime_type( $file_path );
		if ( ! $mime_type ) {
			$result['errors'][] = 'Could not determine MIME type';
			return $result;
		}

		if ( ! in_array( $mime_type, self::SUPPORTED_MIME_TYPES, true ) ) {
			$result['errors'][] = 'Unsupported video format: ' . $mime_type;
			return $result;
		}

		// Success.
		$result['valid']     = true;
		$result['mime_type'] = $mime_type;
		$result['size']      = $file_size;

		return $result;
	}

	/**
	 * Validate video file against platform-specific constraints.
	 *
	 * Extensions pass their requirements (max duration, file size, codecs, aspect ratios)
	 * and this method validates the video against all constraints. Requires video metadata
	 * (from VideoMetadata::extract) for duration/resolution/codec checks.
	 *
	 * @param string $file_path   Path to video file.
	 * @param array  $constraints Constraint set:
	 *   - max_duration:    int    Max duration in seconds.
	 *   - min_duration:    int    Min duration in seconds.
	 *   - max_file_size:   int    Max file size in bytes.
	 *   - allowed_codecs:  array  Allowed video codecs (e.g., ['h264']).
	 *   - allowed_mimes:   array  Allowed MIME types (overrides default list).
	 *   - aspect_ratios:   array  Allowed aspect ratios (e.g., ['9:16', '1:1']).
	 *   - max_width:       int    Max video width in pixels.
	 *   - max_height:      int    Max video height in pixels.
	 *   - min_width:       int    Min video width in pixels.
	 *   - min_height:      int    Min video height in pixels.
	 * @param array  $metadata    Video metadata from VideoMetadata::extract().
	 * @return array Validation result with 'valid', 'results' (per-constraint), 'errors'.
	 */
	public function validate_against_constraints( string $file_path, array $constraints, array $metadata = array() ): array {
		$result = array(
			'valid'   => true,
			'results' => array(),
			'errors'  => array(),
		);

		// Basic file validation first.
		$basic = $this->validate_repository_file( $file_path );
		if ( ! $basic['valid'] ) {
			$result['valid']  = false;
			$result['errors'] = $basic['errors'];
			return $result;
		}

		$file_size = $basic['size'];
		$mime_type = $basic['mime_type'];

		// Max file size.
		if ( isset( $constraints['max_file_size'] ) ) {
			$pass                              = $file_size <= (int) $constraints['max_file_size'];
			$result['results']['max_file_size'] = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'Video file size (%s) exceeds maximum (%s)',
					size_format( $file_size ),
					size_format( (int) $constraints['max_file_size'] )
				);
			}
		}

		// Allowed MIME types (override default list).
		if ( isset( $constraints['allowed_mimes'] ) && is_array( $constraints['allowed_mimes'] ) ) {
			$pass                              = in_array( $mime_type, $constraints['allowed_mimes'], true );
			$result['results']['allowed_mimes'] = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'Video MIME type %s not in allowed list: %s',
					$mime_type,
					implode( ', ', $constraints['allowed_mimes'] )
				);
			}
		}

		// Metadata-dependent constraints.
		if ( ! empty( $metadata ) && empty( $metadata['error'] ) ) {
			// Duration constraints.
			if ( isset( $constraints['max_duration'] ) && isset( $metadata['duration'] ) ) {
				$pass                               = $metadata['duration'] <= (float) $constraints['max_duration'];
				$result['results']['max_duration']   = $pass;
				if ( ! $pass ) {
					$result['valid']    = false;
					$result['errors'][] = sprintf(
						'Video duration (%.1fs) exceeds maximum (%ds)',
						$metadata['duration'],
						(int) $constraints['max_duration']
					);
				}
			}

			if ( isset( $constraints['min_duration'] ) && isset( $metadata['duration'] ) ) {
				$pass                               = $metadata['duration'] >= (float) $constraints['min_duration'];
				$result['results']['min_duration']   = $pass;
				if ( ! $pass ) {
					$result['valid']    = false;
					$result['errors'][] = sprintf(
						'Video duration (%.1fs) below minimum (%ds)',
						$metadata['duration'],
						(int) $constraints['min_duration']
					);
				}
			}

			// Codec constraint.
			if ( isset( $constraints['allowed_codecs'] ) && is_array( $constraints['allowed_codecs'] ) && isset( $metadata['codec'] ) ) {
				$codec_lower   = strtolower( $metadata['codec'] );
				$allowed_lower = array_map( 'strtolower', $constraints['allowed_codecs'] );
				$pass          = in_array( $codec_lower, $allowed_lower, true );
				$result['results']['allowed_codecs'] = $pass;
				if ( ! $pass ) {
					$result['valid']    = false;
					$result['errors'][] = sprintf(
						'Video codec %s not in allowed list: %s',
						$metadata['codec'],
						implode( ', ', $constraints['allowed_codecs'] )
					);
				}
			}

			// Resolution constraints.
			if ( isset( $metadata['width'] ) && isset( $metadata['height'] ) ) {
				if ( isset( $constraints['max_width'] ) ) {
					$pass                           = $metadata['width'] <= (int) $constraints['max_width'];
					$result['results']['max_width']  = $pass;
					if ( ! $pass ) {
						$result['valid']    = false;
						$result['errors'][] = sprintf( 'Video width (%dpx) exceeds maximum (%dpx)', $metadata['width'], (int) $constraints['max_width'] );
					}
				}

				if ( isset( $constraints['max_height'] ) ) {
					$pass                            = $metadata['height'] <= (int) $constraints['max_height'];
					$result['results']['max_height']  = $pass;
					if ( ! $pass ) {
						$result['valid']    = false;
						$result['errors'][] = sprintf( 'Video height (%dpx) exceeds maximum (%dpx)', $metadata['height'], (int) $constraints['max_height'] );
					}
				}

				if ( isset( $constraints['min_width'] ) ) {
					$pass                           = $metadata['width'] >= (int) $constraints['min_width'];
					$result['results']['min_width']  = $pass;
					if ( ! $pass ) {
						$result['valid']    = false;
						$result['errors'][] = sprintf( 'Video width (%dpx) below minimum (%dpx)', $metadata['width'], (int) $constraints['min_width'] );
					}
				}

				if ( isset( $constraints['min_height'] ) ) {
					$pass                            = $metadata['height'] >= (int) $constraints['min_height'];
					$result['results']['min_height']  = $pass;
					if ( ! $pass ) {
						$result['valid']    = false;
						$result['errors'][] = sprintf( 'Video height (%dpx) below minimum (%dpx)', $metadata['height'], (int) $constraints['min_height'] );
					}
				}

				// Aspect ratio constraint.
				if ( isset( $constraints['aspect_ratios'] ) && is_array( $constraints['aspect_ratios'] ) && $metadata['width'] > 0 && $metadata['height'] > 0 ) {
					$actual_ratio = $metadata['width'] / $metadata['height'];
					$pass         = false;

					foreach ( $constraints['aspect_ratios'] as $ratio_str ) {
						$parts = explode( ':', $ratio_str );
						if ( count( $parts ) === 2 && (int) $parts[1] > 0 ) {
							$target_ratio = (int) $parts[0] / (int) $parts[1];
							// Allow 5% tolerance for aspect ratio matching.
							if ( abs( $actual_ratio - $target_ratio ) / $target_ratio < 0.05 ) {
								$pass = true;
								break;
							}
						}
					}

					$result['results']['aspect_ratios'] = $pass;
					if ( ! $pass ) {
						$result['valid']    = false;
						$result['errors'][] = sprintf(
							'Video aspect ratio (%.2f) does not match allowed ratios: %s',
							$actual_ratio,
							implode( ', ', $constraints['aspect_ratios'] )
						);
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Get MIME type of file using multiple methods.
	 *
	 * @param string $file_path File path.
	 * @return string|null MIME type or null on failure.
	 */
	private function get_file_mime_type( string $file_path ): ?string {
		// Method 1: finfo (most reliable).
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = finfo_file( $finfo, $file_path );
				finfo_close( $finfo );
				if ( $mime && strpos( $mime, '/' ) !== false ) {
					return $mime;
				}
			}
		}

		// Method 2: WordPress wp_check_filetype.
		$filetype = wp_check_filetype( $file_path );
		if ( ! empty( $filetype['type'] ) ) {
			return $filetype['type'];
		}

		// Method 3: mime_content_type (if available).
		if ( function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $file_path );
			if ( $mime && strpos( $mime, '/' ) !== false ) {
				return $mime;
			}
		}

		return null;
	}

	/**
	 * Check if MIME type is a supported video format.
	 *
	 * @param string $mime_type MIME type to check.
	 * @return bool True if supported.
	 */
	public function is_supported_mime_type( string $mime_type ): bool {
		return in_array( $mime_type, self::SUPPORTED_MIME_TYPES, true );
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
