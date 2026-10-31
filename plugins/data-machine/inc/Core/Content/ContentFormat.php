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
	 * Register generic content-format conversion filters.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'datamachine_content_format_convert', array( self::class, 'convertFilter' ), 10, 5 );
	}

	/**
	 * Filter callback for generic content-format conversion requests.
	 *
	 * @param  mixed  $converted Existing conversion result from earlier filters.
	 * @param  string $content   Source content.
	 * @param  string $from      Source format slug.
	 * @param  string $to        Target format slug.
	 * @param  array  $context   Optional conversion context.
	 * @return string|\WP_Error|null Converted content, error, or null.
	 */
	public static function convertFilter( $converted, string $content, string $from, string $to, array $context = array() ) {
		if ( is_string( $converted ) || is_wp_error( $converted ) ) {
			return $converted;
		}

		return self::convertWithRuntimeTransformer( $content, $from, $to, $context );
	}

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
	public static function convert( string $content, string $from, string $to, array $context = array() ) {
		$filtered = apply_filters( 'datamachine_content_format_convert', null, $content, $from, $to, $context );
		if ( is_string( $filtered ) || is_wp_error( $filtered ) ) {
			return $filtered;
		}

		return self::convertWithRuntimeTransformer( $content, $from, $to, $context );
	}

	/**
	 * Convert content through the active runtime transformer.
	 *
	 * @param  string $content Source content.
	 * @param  string $from    Source format slug.
	 * @param  string $to      Target format slug.
	 * @param  array  $context Optional conversion context.
	 * @return string|\WP_Error Converted content or error.
	 */
	private static function convertWithRuntimeTransformer( string $content, string $from, string $to, array $context = array() ) {
		$from = sanitize_key( $from );
		$to   = sanitize_key( $to );

		if ( function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
			try {
				$result = blocks_engine_php_transformer_convert_format( $content, $from, $to, $context );
			} catch ( \Throwable $throwable ) {
				return new \WP_Error(
					'datamachine_content_format_blocks_engine_exception',
					sprintf( 'Blocks Engine PHP Transformer failed converting post content from %s to %s: %s', $from, $to, $throwable->getMessage() )
				);
			}

			return self::contentFromBlocksEngineResult( $result, $from, $to );
		}

		if ( $from === $to ) {
			return $content;
		}

		return new \WP_Error(
			'datamachine_content_format_transformer_missing',
			sprintf( 'Blocks Engine PHP Transformer is required to convert post content from %s to %s.', $from, $to )
		);
	}

	/**
	 * Extract converted content from a Blocks Engine PHP Transformer result envelope.
	 *
	 * @param  mixed  $result Result envelope.
	 * @param  string $from   Source format slug.
	 * @param  string $to     Target format slug.
	 * @return string|\WP_Error Converted content or error.
	 */
	private static function contentFromBlocksEngineResult( $result, string $from, string $to ) {
		if ( ! is_array( $result ) ) {
			return new \WP_Error(
				'datamachine_content_format_blocks_engine_invalid_result',
				sprintf( 'Blocks Engine PHP Transformer returned a non-array result converting post content from %s to %s.', $from, $to )
			);
		}

		if ( 'success' !== ( $result['status'] ?? '' ) ) {
			$diagnostic = is_array( $result['diagnostics'][0] ?? null ) ? $result['diagnostics'][0] : array();
			$code       = sanitize_key( (string) ( $diagnostic['code'] ?? 'blocks_engine_conversion_failed' ) );
			$message    = (string) ( $diagnostic['message'] ?? sprintf( 'Blocks Engine PHP Transformer failed converting post content from %s to %s.', $from, $to ) );

			return new \WP_Error( 'datamachine_content_format_' . $code, $message );
		}

		if ( 'blocks' === $to && is_string( $result['serialized_blocks'] ?? null ) && '' !== $result['serialized_blocks'] ) {
			return $result['serialized_blocks'];
		}

		foreach ( $result['documents'] ?? array() as $document ) {
			if ( is_array( $document ) && ( $document['format'] ?? null ) === $to && is_string( $document['content'] ?? null ) ) {
				return $document['content'];
			}
		}

		return new \WP_Error(
			'datamachine_content_format_blocks_engine_missing_content',
			sprintf( 'Blocks Engine PHP Transformer did not return %s content converting post content from %s to %s.', $to, $from, $to )
		);
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
		$source_format = sanitize_key( $source_format );
		$stored_format = self::storedFormat( $post_type );

		if ( $source_format === $stored_format && 'blocks' !== $stored_format ) {
			return $content;
		}

		return self::convert( $content, $source_format, $stored_format );
	}
}
