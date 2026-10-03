<?php
/**
 * Strict relative paths for bundle file maps.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

final class BundleRelativePath {

	/**
	 * Join a validated bundle-relative path to a trusted filesystem root.
	 */
	public static function contained_join( string $root, string $relative_path, string $label = 'bundle' ): string {
		self::validate( $relative_path, $label );

		return rtrim( $root, '/\\' ) . '/' . $relative_path;
	}

	/** Validate one portable bundle path without normalizing unsafe input. */
	public static function validate( string $relative_path, string $label = 'bundle' ): void {
		if (
			'' === $relative_path
			|| str_contains( $relative_path, "\0" )
			|| str_contains( $relative_path, '\\' )
			|| str_starts_with( $relative_path, '/' )
			|| preg_match( '/^[A-Za-z]:/', $relative_path )
			|| str_contains( $relative_path, '//' )
		) {
			throw new BundleValidationException( sprintf( 'Invalid %s file path: %s', esc_html( $label ), esc_html( $relative_path ) ) );
		}

		foreach ( explode( '/', $relative_path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw new BundleValidationException( sprintf( 'Invalid %s file path: %s', esc_html( $label ), esc_html( $relative_path ) ) );
			}
		}
	}
}
