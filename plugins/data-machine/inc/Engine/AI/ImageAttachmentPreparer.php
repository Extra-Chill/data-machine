<?php
/**
 * Image attachment sniffing, conversion, and size-capping for AI provider requests.
 *
 * Providers reject images whose real content doesn't match one of a small
 * set of supported formats (image/jpeg, image/png, image/gif, image/webp)
 * and enforce a per-image byte budget. Extensions lie — a scraped file named
 * "*.jpg" can be AVIF, HEIC, or anything else — so this class sniffs the real
 * format from file content via wp_get_image_mime(), converts unsupported but
 * decodable formats to JPEG via wp_get_image_editor(), and downscales or
 * drops images that exceed the size budget. An optional image must never
 * fail the whole AI step: callers fall back to text-only when prepare()
 * returns null.
 *
 * @package DataMachine\Engine\AI
 * @since 0.177.6
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class ImageAttachmentPreparer {

	/**
	 * Image MIME types every configured AI provider accepts. Matches the
	 * "Supported: image/jpeg, image/png, image/gif, image/webp" list surfaced
	 * in provider 400 responses.
	 */
	private const SUPPORTED_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Conservative per-image byte budget shared across configured providers
	 * (matches the tightest known per-image API limit). Filterable so a
	 * specific provider/model combination can raise or lower it.
	 */
	private const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

	/** Long-edge pixel cap used when downscaling an oversized image. */
	private const DOWNSCALE_MAX_DIMENSION = 2048;

	/** JPEG quality used for both format conversion and downscale re-encodes. */
	private const JPEG_QUALITY = 82;

	/**
	 * Prepare a local image file for attachment to an AI provider request.
	 *
	 * @param string $file_path     Local file path, already confirmed to exist by the caller.
	 * @param string $sniffed_mime  Real MIME type from wp_get_image_mime() (magic-byte sniffed, not extension-derived).
	 * @return array{file_path:string,mime_type:string}|null Prepared attachment, or null if it must be dropped.
	 */
	public static function prepare( string $file_path, string $sniffed_mime ): ?array {
		$working_path   = $file_path;
		$mime_type      = $sniffed_mime;
		$owns_temp_file = false;

		if ( ! in_array( $mime_type, self::SUPPORTED_MIME_TYPES, true ) ) {
			$converted = self::reencodeAsJpeg( $working_path, $mime_type );
			if ( null === $converted ) {
				self::logDropped( $file_path, $mime_type, 'unsupported image format could not be converted' );
				return null;
			}

			$working_path   = $converted;
			$mime_type      = 'image/jpeg';
			$owns_temp_file = true;
		}

		$max_bytes = self::maxBytes();
		$size      = @filesize( $working_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filesize() warns on races/permission issues; treat as unknown size, not a fatal error.

		if ( false !== $size && $size > $max_bytes ) {
			$downscaled = self::reencodeAsJpeg( $working_path, $mime_type, true );

			if ( $owns_temp_file ) {
				wp_delete_file( $working_path );
			}

			if ( null === $downscaled ) {
				self::logDropped( $file_path, $mime_type, sprintf( 'oversized (%d bytes) and could not be downscaled', $size ) );
				return null;
			}

			$working_path   = $downscaled;
			$mime_type      = 'image/jpeg';
			$owns_temp_file = true;

			$final_size = @filesize( $working_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see rationale above.
			if ( false !== $final_size && $final_size > $max_bytes ) {
				wp_delete_file( $working_path );
				self::logDropped( $file_path, $mime_type, sprintf( 'still oversized (%d bytes) after downscale', $final_size ) );
				return null;
			}
		}

		if ( $owns_temp_file ) {
			// The converted/downscaled file is consumed later — the File DTO reads
			// it lazily when the provider request is actually dispatched, not at
			// construction time — so it can't be deleted synchronously here.
			// Schedule cleanup for end-of-request instead of leaking it.
			self::scheduleTempCleanup( $working_path );
		}

		return array(
			'file_path' => $working_path,
			'mime_type' => $mime_type,
		);
	}

	/**
	 * Schedule a temp file we created for deletion at end of the current request.
	 *
	 * @param string $path Absolute path to the temp file to delete on shutdown.
	 */
	private static function scheduleTempCleanup( string $path ): void {
		register_shutdown_function(
			static function () use ( $path ) {
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		);
	}

	/**
	 * Re-encode an image as JPEG, optionally downscaling to the long-edge cap first.
	 *
	 * Uses wp_get_image_editor() with the real sniffed MIME type explicitly
	 * passed in args so implementation selection (Imagick vs GD) is made
	 * against the actual format, not an extension-derived guess.
	 *
	 * @param string $file_path Source file path.
	 * @param string $mime_type Real MIME type of the source file.
	 * @param bool   $downscale Whether to constrain to DOWNSCALE_MAX_DIMENSION first.
	 * @return string|null Absolute path to the re-encoded temp file, or null on failure.
	 */
	private static function reencodeAsJpeg( string $file_path, string $mime_type, bool $downscale = false ): ?string {
		$editor = wp_get_image_editor( $file_path, array( 'mime_type' => $mime_type ) );
		if ( is_wp_error( $editor ) ) {
			return null;
		}

		if ( $downscale ) {
			$current_size = $editor->get_size();
			$needs_resize = $current_size['width'] > self::DOWNSCALE_MAX_DIMENSION || $current_size['height'] > self::DOWNSCALE_MAX_DIMENSION;

			// Only resize when the image actually exceeds the cap — WP core's
			// resize() returns a WP_Error ('error_getting_dimensions') rather than
			// a no-op when there's nothing to resize, which would otherwise be
			// misread as a hard conversion failure and drop an image that only
			// needed a quality-only re-encode to fit the byte budget.
			if ( $needs_resize ) {
				$resized = $editor->resize( self::DOWNSCALE_MAX_DIMENSION, self::DOWNSCALE_MAX_DIMENSION, false );
				if ( is_wp_error( $resized ) ) {
					return null;
				}
			}
		}

		$editor->set_quality( self::JPEG_QUALITY );

		$tmp_file = wp_tempnam( 'datamachine-ai-image' );
		if ( ! $tmp_file ) {
			return null;
		}

		$saved = $editor->save( $tmp_file . '.jpg', 'image/jpeg' );
		wp_delete_file( $tmp_file );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return null;
		}

		return (string) $saved['path'];
	}

	/**
	 * Resolve the effective per-image byte budget.
	 *
	 * @return int Maximum bytes for a prepared image attachment.
	 */
	private static function maxBytes(): int {
		/**
		 * Filters the per-image byte budget used when preparing an image for
		 * an AI provider request. Defaults to the tightest known provider limit.
		 *
		 * @since 0.177.6
		 *
		 * @param int $max_bytes Maximum bytes for a single prepared image attachment.
		 */
		return (int) apply_filters( 'datamachine_ai_image_attachment_max_bytes', self::DEFAULT_MAX_BYTES );
	}

	/**
	 * Log a diagnostic for a dropped image attachment.
	 *
	 * @param string $file_path Original source file path.
	 * @param string $mime_type MIME type at the point of drop.
	 * @param string $reason    Human-readable drop reason.
	 */
	private static function logDropped( string $file_path, string $mime_type, string $reason ): void {
		do_action(
			'datamachine_log',
			'warning',
			'AI request: dropped image attachment, continuing text-only',
			array(
				'file_path' => $file_path,
				'mime_type' => $mime_type,
				'reason'    => $reason,
			)
		);
	}
}
