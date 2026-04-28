<?php
/**
 * Content format conversion helpers for post content boundaries.
 *
 * @package DataMachine\Core\Content
 */

namespace DataMachine\Core\Content;

defined( 'ABSPATH' ) || exit;

class ContentFormat {


	/**
	 * Return the canonical storage format for a post type.
	 *
	 * @param  string $post_type Post type slug.
	 * @return string Format slug.
	 */
	public static function storedFormat( string $post_type ): string {
		$format = apply_filters( 'datamachine_post_content_format', 'blocks', $post_type );

		return sanitize_key( is_string( $format ) && '' !== $format ? $format : 'blocks' );
	}

	/**
	 * Convert content between two explicit formats.
	 *
	 * @param  string $content Source content.
	 * @param  string $from    Source format slug.
	 * @param  string $to      Target format slug.
	 * @return string|\WP_Error Converted content or error.
	 */
	public static function convert( string $content, string $from, string $to ) {
		$from = sanitize_key( $from );
		$to   = sanitize_key( $to );

		if ( function_exists( 'bfb_normalize' ) ) {
			$normalized = bfb_normalize( $content, $from );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			if ( ! is_string( $normalized ) ) {
				return new \WP_Error(
					'datamachine_content_format_invalid_normalization_result',
					sprintf( 'Block Format Bridge returned a non-string result normalizing post content as %s.', $from )
				);
			}

			$content = $normalized;
		}

		if ( $from === $to ) {
			return $content;
		}

		if ( ! function_exists( 'bfb_convert' ) ) {
			return new \WP_Error(
				'datamachine_content_format_bfb_missing',
				sprintf( 'Block Format Bridge is required to convert post content from %s to %s.', $from, $to )
			);
		}

		$converted = bfb_convert( $content, $from, $to );

		if ( is_wp_error( $converted ) ) {
			return $converted;
		}

		if ( ! is_string( $converted ) ) {
			return new \WP_Error(
				'datamachine_content_format_invalid_result',
				sprintf( 'Block Format Bridge returned a non-string result converting post content from %s to %s.', $from, $to )
			);
		}

		return $converted;
	}

	/**
	 * Convert stored post content to block markup for block-level tools.
	 *
	 * @param  string $content   Stored post content.
	 * @param  string $post_type Post type slug.
	 * @return string|\WP_Error Block markup or error.
	 */
	public static function storedToBlocks( string $content, string $post_type ) {
		return self::convert( $content, self::storedFormat( $post_type ), 'blocks' );
	}

	/**
	 * Convert block markup to the post type's stored format.
	 *
	 * @param  string $content   Block markup.
	 * @param  string $post_type Post type slug.
	 * @return string|\WP_Error Stored-format content or error.
	 */
	public static function blocksToStored( string $content, string $post_type ) {
		return self::convert( $content, 'blocks', self::storedFormat( $post_type ) );
	}

	/**
	 * Convert caller-provided content into the post type's stored format.
	 *
	 * @param  string $content       Source content.
	 * @param  string $source_format Source format slug.
	 * @param  string $post_type     Post type slug.
	 * @return string|\WP_Error Stored-format content or error.
	 */
	public static function sourceToStored( string $content, string $source_format, string $post_type ) {
		return self::convert( $content, $source_format, self::storedFormat( $post_type ) );
	}
}
