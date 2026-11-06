<?php
/**
 * Small shared image-template primitives.
 *
 * @package DataMachine\Abilities\Media
 */
namespace DataMachine\Abilities\Media\Templates;

use DataMachine\Abilities\Media\GDRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateHelpers {
	/** Resolve a provider-owned temporary QR file; caller deletes it after drawing. */
	public static function qr_image_path( string $url, int $size ): ?string {
		if ( '' === $url ) {
			return null;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidHookName
		$path = apply_filters( 'datamachine/image_generation/qr_image_path', null, $url, $size );
		return is_string( $path ) && is_readable( $path ) ? $path : null;
	}

	/** Shrink until every word (or the whole unwrapped line) fits the width. */
	public static function fit_text( GDRenderer $r, string $text, int $size, string $font, int $width, bool $wrap = true, int $minimum = 1 ): int {
		$parts = $wrap ? preg_split( '/\s+/', trim( $text ) ) : array( $text );
		while ( $size > $minimum ) {
			$widest = 0;
			foreach ( (array) $parts as $part ) {
				$widest = max( $widest, $r->measure_text_width( (string) $part, $size, $font ) );
			}
			if ( $widest <= $width ) {
				break;
			}
			--$size;
		}
		return $size;
	}
}
