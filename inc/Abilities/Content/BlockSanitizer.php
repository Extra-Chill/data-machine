<?php
/**
 * Block Sanitizer
 *
 * Shared sanitization for block content editing abilities.
 * Single source of truth for how blocks get sanitized before saving.
 *
 * @package DataMachine\Abilities\Content
 * @since 0.28.0
 */

namespace DataMachine\Abilities\Content;

defined( 'ABSPATH' ) || exit;

class BlockSanitizer {

	/**
	 * Sanitize all block innerHTML/innerContent and serialize.
	 *
	 * Recursively sanitizes innerHTML, innerContent strings, and innerBlocks
	 * using wp_kses_post before serializing back to block markup.
	 *
	 * @param array $blocks Parsed blocks array from parse_blocks().
	 * @return string Serialized block content.
	 */
	public static function sanitizeAndSerialize( array $blocks ): string {
		return serialize_blocks( self::sanitizeBlocks( $blocks ) );
	}

	/**
	 * Recursively sanitize an array of blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Sanitized blocks.
	 */
	public static function sanitizeBlocks( array $blocks ): array {
		return array_map( array( self::class, 'sanitizeBlock' ), $blocks );
	}

	/**
	 * Sanitize a single block and its children.
	 *
	 * @param array $block Single parsed block.
	 * @return array Sanitized block.
	 */
	private static function sanitizeBlock( array $block ): array {
		if ( isset( $block['innerHTML'] ) && '' !== $block['innerHTML'] ) {
			$block['innerHTML'] = wp_kses_post( $block['innerHTML'] );
		}

		if ( ! empty( $block['innerContent'] ) ) {
			$block['innerContent'] = array_map(
				function ( $content ) {
					if ( is_string( $content ) && '' !== $content ) {
						return wp_kses_post( $content );
					}
					return $content; // null entries represent inner block positions.
				},
				$block['innerContent']
			);
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = self::sanitizeBlocks( $block['innerBlocks'] );
		}

		return $block;
	}
}
