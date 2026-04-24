<?php
/**
 * Webhook Signature Verifier — DEPRECATED.
 *
 * Kept only as a transitional shim for external callers that imported this
 * class during the short-lived v1 (single-format HMAC) window. The production
 * verification path now lives in {@see \DataMachine\Api\WebhookVerifier},
 * which supports a provider-agnostic template grammar.
 *
 * @package DataMachine\Api
 * @since 0.79.0
 * @deprecated 0.79.0 Use WebhookVerifier instead.
 * @see https://github.com/Extra-Chill/data-machine/issues/1179
 */

namespace DataMachine\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @deprecated 0.79.0 Use WebhookVerifier instead.
 */
class WebhookSignatureVerifier {

	/**
	 * Supported signature formats.
	 */
	const FORMAT_PREFIXED_HEX = 'sha256=hex';
	const FORMAT_HEX          = 'hex';
	const FORMAT_BASE64       = 'base64';

	/**
	 * Verify an HMAC-SHA256 signature against a raw body.
	 *
	 * Formats:
	 * - `sha256=hex` (default) — GitHub-style `sha256=<hex>` header value.
	 * - `hex`                  — raw hex digest (e.g. Linear).
	 * - `base64`               — base64-encoded raw digest (e.g. Shopify).
	 *
	 * Returns false for any malformed input instead of throwing, so callers
	 * can treat all auth failures uniformly (generic 401).
	 *
	 * @param string $raw_body            Raw request body bytes (as signed by the sender).
	 * @param string $provided_signature  Signature value from the request header.
	 * @param string $secret              Shared HMAC secret.
	 * @param string $format              Signature format: 'sha256=hex' | 'hex' | 'base64'.
	 * @return bool True if the signature is valid, false otherwise.
	 */
	public static function verify_hmac_sha256(
		string $raw_body,
		string $provided_signature,
		string $secret,
		string $format = self::FORMAT_PREFIXED_HEX
	): bool {
		// Empty secret is a misconfiguration — never authenticate.
		if ( '' === $secret ) {
			return false;
		}

		if ( '' === $provided_signature ) {
			return false;
		}

		$provided = trim( $provided_signature );

		switch ( $format ) {
			case self::FORMAT_PREFIXED_HEX:
				if ( 0 !== strpos( $provided, 'sha256=' ) ) {
					return false;
				}
				$provided = substr( $provided, 7 );
				$expected = hash_hmac( 'sha256', $raw_body, $secret, false );
				// Both are lowercase hex — enforce that to keep comparisons consistent.
				if ( ! ctype_xdigit( $provided ) ) {
					return false;
				}
				return hash_equals( $expected, strtolower( $provided ) );

			case self::FORMAT_HEX:
				if ( ! ctype_xdigit( $provided ) ) {
					return false;
				}
				$expected = hash_hmac( 'sha256', $raw_body, $secret, false );
				return hash_equals( $expected, strtolower( $provided ) );

			case self::FORMAT_BASE64:
				// Required for HMAC signature decoding (Shopify, others). Not user input obfuscation.
				$decoded = base64_decode( $provided, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( false === $decoded ) {
					return false;
				}
				$expected = hash_hmac( 'sha256', $raw_body, $secret, true );
				return hash_equals( $expected, $decoded );

			default:
				return false;
		}
	}

	/**
	 * List of supported signature format identifiers.
	 *
	 * @return string[]
	 */
	public static function supported_formats(): array {
		return array(
			self::FORMAT_PREFIXED_HEX,
			self::FORMAT_HEX,
			self::FORMAT_BASE64,
		);
	}
}
