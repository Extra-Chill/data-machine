<?php
/**
 * Video metadata extraction for Data Machine.
 *
 * Extracts video properties (duration, resolution, codec, bitrate, framerate)
 * using ffprobe when available, with graceful degradation to basic PHP-only
 * detection when ffprobe is not installed.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.42.0
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VideoMetadata {

	/**
	 * Cached ffprobe availability check.
	 *
	 * @var bool|null
	 */
	private static ?bool $ffprobe_available = null;

	/**
	 * Extract video metadata from a file.
	 *
	 * Returns structured metadata including duration, resolution, codec, etc.
	 * Uses ffprobe for full extraction; falls back to basic PHP detection.
	 *
	 * @param string $file_path Absolute path to video file.
	 * @return array Metadata array:
	 *   - duration:    float|null  Duration in seconds.
	 *   - width:       int|null    Width in pixels.
	 *   - height:      int|null    Height in pixels.
	 *   - codec:       string|null Video codec name (e.g., 'h264').
	 *   - bitrate:     int|null    Overall bitrate in bits/s.
	 *   - framerate:   float|null  Frames per second.
	 *   - mime_type:   string|null MIME type.
	 *   - file_size:   int         File size in bytes.
	 *   - format:      string|null Container format name (e.g., 'mov,mp4,m4a,3gp,3g2,mj2').
	 *   - ffprobe:     bool        Whether ffprobe was used for extraction.
	 *   - error:       string|null Error message if extraction failed.
	 */
	public static function extract( string $file_path ): array {
		$result = array(
			'duration'  => null,
			'width'     => null,
			'height'    => null,
			'codec'     => null,
			'bitrate'   => null,
			'framerate' => null,
			'mime_type' => null,
			'file_size' => 0,
			'format'    => null,
			'ffprobe'   => false,
			'error'     => null,
		);

		if ( ! file_exists( $file_path ) ) {
			$result['error'] = 'File not found';
			return $result;
		}

		if ( ! is_readable( $file_path ) ) {
			$result['error'] = 'File not readable';
			return $result;
		}

		$result['file_size'] = filesize( $file_path );

		// Detect MIME type via PHP.
		$result['mime_type'] = self::detect_mime_type( $file_path );

		// Try ffprobe for full metadata.
		if ( self::is_ffprobe_available() ) {
			$ffprobe_data = self::extract_via_ffprobe( $file_path );
			if ( null !== $ffprobe_data ) {
				$result = array_merge( $result, $ffprobe_data );
				$result['ffprobe'] = true;
				return $result;
			}
		}

		// Graceful degradation: basic info only.
		$result['error'] = 'ffprobe not available — metadata limited to MIME type and file size';

		return $result;
	}

	/**
	 * Check if ffprobe is available on the system.
	 *
	 * @return bool True if ffprobe can be executed.
	 */
	public static function is_ffprobe_available(): bool {
		if ( null !== self::$ffprobe_available ) {
			return self::$ffprobe_available;
		}

		$output     = array();
		$return_var = -1;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		@exec( 'which ffprobe 2>/dev/null', $output, $return_var );

		self::$ffprobe_available = ( 0 === $return_var && ! empty( $output ) );

		return self::$ffprobe_available;
	}

	/**
	 * Extract metadata using ffprobe.
	 *
	 * @param string $file_path Path to video file.
	 * @return array|null Metadata array or null on failure.
	 */
	private static function extract_via_ffprobe( string $file_path ): ?array {
		$escaped_path = escapeshellarg( $file_path );

		// Get both format and stream info in one call.
		$command = sprintf(
			'ffprobe -v quiet -print_format json -show_format -show_streams -select_streams v:0 %s 2>/dev/null',
			$escaped_path
		);

		$output     = array();
		$return_var = -1;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		@exec( $command, $output, $return_var );

		if ( 0 !== $return_var || empty( $output ) ) {
			return null;
		}

		$json = json_decode( implode( "\n", $output ), true );
		if ( json_last_error() !== JSON_ERROR_NONE || empty( $json ) ) {
			return null;
		}

		$format = $json['format'] ?? array();
		$stream = $json['streams'][0] ?? array();

		$result = array(
			'duration' => null,
			'width'    => null,
			'height'   => null,
			'codec'    => null,
			'bitrate'  => null,
			'framerate' => null,
			'format'   => null,
		);

		// Duration (from format, more reliable than stream).
		if ( isset( $format['duration'] ) ) {
			$result['duration'] = (float) $format['duration'];
		} elseif ( isset( $stream['duration'] ) ) {
			$result['duration'] = (float) $stream['duration'];
		}

		// Resolution.
		if ( isset( $stream['width'] ) ) {
			$result['width'] = (int) $stream['width'];
		}
		if ( isset( $stream['height'] ) ) {
			$result['height'] = (int) $stream['height'];
		}

		// Codec.
		if ( isset( $stream['codec_name'] ) ) {
			$result['codec'] = $stream['codec_name'];
		}

		// Bitrate.
		if ( isset( $format['bit_rate'] ) ) {
			$result['bitrate'] = (int) $format['bit_rate'];
		}

		// Framerate (r_frame_rate is a fraction like "30/1" or "30000/1001").
		if ( isset( $stream['r_frame_rate'] ) ) {
			$parts = explode( '/', $stream['r_frame_rate'] );
			if ( count( $parts ) === 2 && (int) $parts[1] > 0 ) {
				$result['framerate'] = round( (int) $parts[0] / (int) $parts[1], 2 );
			}
		}

		// Format name.
		if ( isset( $format['format_name'] ) ) {
			$result['format'] = $format['format_name'];
		}

		return $result;
	}

	/**
	 * Detect MIME type of a file.
	 *
	 * @param string $file_path File path.
	 * @return string|null MIME type or null.
	 */
	private static function detect_mime_type( string $file_path ): ?string {
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

		$filetype = wp_check_filetype( $file_path );
		if ( ! empty( $filetype['type'] ) ) {
			return $filetype['type'];
		}

		return null;
	}

	/**
	 * Reset the ffprobe availability cache.
	 *
	 * Useful for testing or when ffprobe is installed at runtime.
	 */
	public static function reset_cache(): void {
		self::$ffprobe_available = null;
	}
}
