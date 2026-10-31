<?php
/**
 * Shared Instagram username contract for event taxonomy profiles.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

defined( 'ABSPATH' ) || exit;

class InstagramHandle {
	/** Normalize a username or profile URL; empty string explicitly clears it. */
	public static function normalize( mixed $value ): string|\WP_Error {
		if ( ! is_string( $value ) ) {
			return self::invalid();
		}
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '~^(?:https?://)?(?:www\.)?instagram\.com/~i', '', $value );
		$value = rtrim( $value, '/' );
		$value = preg_replace( '/^@/', '', $value );
		if ( '' !== $value && ! preg_match( '/^[a-z0-9._]{1,30}$/D', $value ) ) {
			return self::invalid();
		}
		return $value;
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'instagram_invalid', 'Instagram must be a username (1–30 letters, digits, periods or underscores), profile URL, or empty string.', array( 'status' => 400 ) );
	}

	/** Read the same public field from either supported taxonomy. */
	public static function read( int $term_id, string $taxonomy ): string {
		$key   = 'venue' === $taxonomy ? '_venue_instagram' : 'instagram';
		$value = self::normalize( get_term_meta( $term_id, $key, true ) );
		return is_wp_error( $value ) ? '' : $value;
	}
}
