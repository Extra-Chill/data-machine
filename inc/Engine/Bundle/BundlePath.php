<?php
/**
 * Bundle-relative path normalization.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes paths that must remain inside a portable bundle.
 */
final class BundlePath {

	public static function normalize_relative( string $path, string $field, string $label ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = preg_replace( '#/+#', '/', $path );
		$path = ltrim( is_string( $path ) ? $path : '', '/' );

		if ( '' === $path ) {
			throw new BundleValidationException( sprintf( '%s %s must be a non-empty string.', esc_html( $label ), esc_html( $field ) ) );
		}

		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				throw new BundleValidationException( sprintf( '%s %s must be bundle-local.', esc_html( $label ), esc_html( $field ) ) );
			}
		}

		return $path;
	}
}
