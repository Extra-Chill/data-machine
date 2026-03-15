<?php
/**
 * Abstract base for media file validation.
 *
 * Provides shared validation logic for image and video files:
 * MIME type detection, file existence/readability checks, size limits,
 * and constraint-based validation. Subclasses define their supported
 * MIME types and media type label.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.42.0
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class MediaValidator {

	/**
	 * Return the list of supported MIME types for this media type.
	 *
	 * @return array<string> Supported MIME types.
	 */
	abstract protected function supported_mime_types(): array;

	/**
	 * Return a human-readable label for this media type (e.g. 'Image', 'Video').
	 *
	 * Used in error messages.
	 *
	 * @return string Media type label.
	 */
	abstract protected function media_label(): string;

	/**
	 * Validate a repository file.
	 *
	 * Checks: file exists, readable, within WP upload size limit, MIME type supported.
	 *
	 * @param string $file_path Path to file in repository.
	 * @return array Validation result with 'valid', 'mime_type', 'size', 'errors'.
	 */
	public function validate_repository_file( string $file_path ): array {
		$label  = $this->media_label();
		$result = array(
			'valid'     => false,
			'mime_type' => null,
			'size'      => 0,
			'errors'    => array(),
		);

		if ( ! file_exists( $file_path ) ) {
			$result['errors'][] = "{$label} file not found in repository";
			return $result;
		}

		if ( ! is_readable( $file_path ) ) {
			$result['errors'][] = "{$label} file not readable";
			return $result;
		}

		// Check file size.
		$file_size     = filesize( $file_path );
		$max_file_size = wp_max_upload_size();
		if ( $file_size > $max_file_size ) {
			$result['errors'][] = "{$label} file too large";
			return $result;
		}

		// Check MIME type.
		$mime_type = $this->detect_mime_type( $file_path );
		if ( ! $mime_type ) {
			$result['errors'][] = 'Could not determine MIME type';
			return $result;
		}

		if ( ! $this->is_supported_mime_type( $mime_type ) ) {
			$result['errors'][] = "Unsupported {$label} format: {$mime_type}";
			return $result;
		}

		// Success.
		$result['valid']     = true;
		$result['mime_type'] = $mime_type;
		$result['size']      = $file_size;

		return $result;
	}

	/**
	 * Check if a MIME type is supported by this validator.
	 *
	 * @param string $mime_type MIME type to check.
	 * @return bool True if supported.
	 */
	public function is_supported_mime_type( string $mime_type ): bool {
		return in_array( $mime_type, $this->supported_mime_types(), true );
	}

	/**
	 * Validate a file against a constraint set.
	 *
	 * Runs basic validation first, then checks each constraint. Metadata
	 * (from ffprobe or similar) is required for duration/resolution/codec checks.
	 *
	 * Supported constraints:
	 *   - max_file_size:   int    Max file size in bytes.
	 *   - allowed_mimes:   array  Allowed MIME types (overrides default list).
	 *   - max_duration:    int    Max duration in seconds (requires metadata).
	 *   - min_duration:    int    Min duration in seconds (requires metadata).
	 *   - allowed_codecs:  array  Allowed codecs (requires metadata).
	 *   - max_width:       int    Max width in pixels (requires metadata).
	 *   - max_height:      int    Max height in pixels (requires metadata).
	 *   - min_width:       int    Min width in pixels (requires metadata).
	 *   - min_height:      int    Min height in pixels (requires metadata).
	 *   - aspect_ratios:   array  Allowed aspect ratios e.g. ['9:16', '1:1'] (requires metadata).
	 *
	 * @param string $file_path   Path to file.
	 * @param array  $constraints Constraint set.
	 * @param array  $metadata    Metadata with keys: duration, width, height, codec.
	 * @return array Result with 'valid', 'results' (per-constraint bool), 'errors'.
	 */
	public function validate_against_constraints( string $file_path, array $constraints, array $metadata = array() ): array {
		$label  = $this->media_label();
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
			$pass                               = $file_size <= (int) $constraints['max_file_size'];
			$result['results']['max_file_size']  = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'%s file size (%s) exceeds maximum (%s)',
					$label,
					size_format( $file_size ),
					size_format( (int) $constraints['max_file_size'] )
				);
			}
		}

		// Allowed MIME types (override default list).
		if ( isset( $constraints['allowed_mimes'] ) && is_array( $constraints['allowed_mimes'] ) ) {
			$pass                               = in_array( $mime_type, $constraints['allowed_mimes'], true );
			$result['results']['allowed_mimes']  = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'%s MIME type %s not in allowed list: %s',
					$label,
					$mime_type,
					implode( ', ', $constraints['allowed_mimes'] )
				);
			}
		}

		// Metadata-dependent constraints.
		if ( ! empty( $metadata ) && empty( $metadata['error'] ) ) {
			$this->validate_metadata_constraints( $result, $constraints, $metadata, $label );
		}

		return $result;
	}

	/**
	 * Validate metadata-dependent constraints (duration, resolution, codec, aspect ratio).
	 *
	 * Mutates $result in place for efficiency.
	 *
	 * @param array  $result      Result array (modified in place).
	 * @param array  $constraints Constraint set.
	 * @param array  $metadata    Media metadata.
	 * @param string $label       Media type label for error messages.
	 */
	protected function validate_metadata_constraints( array &$result, array $constraints, array $metadata, string $label ): void {
		// Duration constraints.
		if ( isset( $constraints['max_duration'] ) && isset( $metadata['duration'] ) ) {
			$pass                              = $metadata['duration'] <= (float) $constraints['max_duration'];
			$result['results']['max_duration']  = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'%s duration (%.1fs) exceeds maximum (%ds)',
					$label,
					$metadata['duration'],
					(int) $constraints['max_duration']
				);
			}
		}

		if ( isset( $constraints['min_duration'] ) && isset( $metadata['duration'] ) ) {
			$pass                              = $metadata['duration'] >= (float) $constraints['min_duration'];
			$result['results']['min_duration']  = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'%s duration (%.1fs) below minimum (%ds)',
					$label,
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
					'%s codec %s not in allowed list: %s',
					$label,
					$metadata['codec'],
					implode( ', ', $constraints['allowed_codecs'] )
				);
			}
		}

		// Resolution constraints.
		if ( isset( $metadata['width'] ) && isset( $metadata['height'] ) ) {
			$this->validate_resolution_constraints( $result, $constraints, $metadata, $label );
		}
	}

	/**
	 * Validate resolution and aspect ratio constraints.
	 *
	 * @param array  $result      Result array (modified in place).
	 * @param array  $constraints Constraint set.
	 * @param array  $metadata    Media metadata with width/height.
	 * @param string $label       Media type label.
	 */
	private function validate_resolution_constraints( array &$result, array $constraints, array $metadata, string $label ): void {
		$width  = (int) $metadata['width'];
		$height = (int) $metadata['height'];

		if ( isset( $constraints['max_width'] ) ) {
			$pass                           = $width <= (int) $constraints['max_width'];
			$result['results']['max_width']  = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf( '%s width (%dpx) exceeds maximum (%dpx)', $label, $width, (int) $constraints['max_width'] );
			}
		}

		if ( isset( $constraints['max_height'] ) ) {
			$pass                            = $height <= (int) $constraints['max_height'];
			$result['results']['max_height']  = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf( '%s height (%dpx) exceeds maximum (%dpx)', $label, $height, (int) $constraints['max_height'] );
			}
		}

		if ( isset( $constraints['min_width'] ) ) {
			$pass                           = $width >= (int) $constraints['min_width'];
			$result['results']['min_width']  = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf( '%s width (%dpx) below minimum (%dpx)', $label, $width, (int) $constraints['min_width'] );
			}
		}

		if ( isset( $constraints['min_height'] ) ) {
			$pass                            = $height >= (int) $constraints['min_height'];
			$result['results']['min_height']  = $pass;
			if ( ! $pass ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf( '%s height (%dpx) below minimum (%dpx)', $label, $height, (int) $constraints['min_height'] );
			}
		}

		// Aspect ratio constraint.
		if ( isset( $constraints['aspect_ratios'] ) && is_array( $constraints['aspect_ratios'] ) && $width > 0 && $height > 0 ) {
			$actual_ratio = $width / $height;
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
					'%s aspect ratio (%.2f) does not match allowed ratios: %s',
					$label,
					$actual_ratio,
					implode( ', ', $constraints['aspect_ratios'] )
				);
			}
		}
	}

	/**
	 * Detect MIME type of a file using multiple methods.
	 *
	 * @param string $file_path File path.
	 * @return string|null MIME type or null on failure.
	 */
	protected function detect_mime_type( string $file_path ): ?string {
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
}
