<?php
/**
 * Webhook signature verification helpers for channel and bridge callbacks.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Webhook_Signature {

	/**
	 * Verify an HMAC SHA-256 signature header against a raw request body.
	 *
	 * @param string $body    Raw request body.
	 * @param string $header  Signature header value.
	 * @param string $secret  Shared webhook secret. Empty secrets are rejected.
	 * @param array<mixed>  $options Optional settings: expected_prefix, allow_raw_hex.
	 * @return bool True when the signature is valid.
	 */
	public static function verify_hmac_sha256( string $body, string $header, string $secret, array $options = array() ): bool {
		if ( '' === $secret ) {
			return false;
		}

		$expected_prefix = $options['expected_prefix'] ?? 'sha256=';
		$allow_raw_hex   = $options['allow_raw_hex'] ?? false;

		$signature = self::extract_signature(
			$header,
			is_string( $expected_prefix ) ? $expected_prefix : 'sha256=',
			is_bool( $allow_raw_hex ) ? $allow_raw_hex : false
		);

		if ( null === $signature ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $body, $secret );
		return hash_equals( $expected, $signature );
	}

	private static function extract_signature( string $header, string $expected_prefix, bool $allow_raw_hex ): ?string {
		$header = trim( $header );
		if ( '' === $header ) {
			return null;
		}

		if ( '' !== $expected_prefix && str_starts_with( $header, $expected_prefix ) ) {
			return self::normalize_hex( substr( $header, strlen( $expected_prefix ) ) );
		}

		if ( $allow_raw_hex ) {
			return self::normalize_hex( $header );
		}

		return null;
	}

	private static function normalize_hex( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) {
			return null;
		}
		return $value;
	}
}
