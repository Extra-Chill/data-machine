<?php
/**
 * WebhookSignatureVerifier tests.
 *
 * Pure unit tests — no WordPress bootstrap required. The verifier is a
 * static helper that only depends on hash_hmac / hash_equals / base64_decode.
 *
 * @package DataMachine\Tests\Unit\Api
 */

namespace DataMachine\Tests\Unit\Api;

use DataMachine\Api\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

class WebhookSignatureVerifierTest extends TestCase {

	private const SECRET = 'super-secret-value';
	private const BODY   = '{"action":"opened","number":1}';

	public function test_valid_prefixed_hex_signature_is_accepted(): void {
		$sig = 'sha256=' . hash_hmac( 'sha256', self::BODY, self::SECRET );

		$this->assertTrue(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, $sig, self::SECRET, 'sha256=hex' )
		);
	}

	public function test_valid_hex_signature_is_accepted(): void {
		$sig = hash_hmac( 'sha256', self::BODY, self::SECRET );

		$this->assertTrue(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, $sig, self::SECRET, 'hex' )
		);
	}

	public function test_valid_base64_signature_is_accepted(): void {
		$sig = base64_encode( hash_hmac( 'sha256', self::BODY, self::SECRET, true ) );

		$this->assertTrue(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, $sig, self::SECRET, 'base64' )
		);
	}

	public function test_uppercase_hex_is_accepted(): void {
		$sig = 'sha256=' . strtoupper( hash_hmac( 'sha256', self::BODY, self::SECRET ) );

		$this->assertTrue(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, $sig, self::SECRET, 'sha256=hex' )
		);
	}

	public function test_invalid_signature_is_rejected(): void {
		$this->assertFalse(
			WebhookSignatureVerifier::verify_hmac_sha256(
				self::BODY,
				'sha256=' . str_repeat( 'a', 64 ),
				self::SECRET,
				'sha256=hex'
			)
		);
	}

	public function test_wrong_secret_is_rejected(): void {
		$sig = 'sha256=' . hash_hmac( 'sha256', self::BODY, self::SECRET );

		$this->assertFalse(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, $sig, 'different-secret', 'sha256=hex' )
		);
	}

	public function test_missing_sha256_prefix_is_rejected_for_prefixed_format(): void {
		$sig = hash_hmac( 'sha256', self::BODY, self::SECRET );

		$this->assertFalse(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, $sig, self::SECRET, 'sha256=hex' )
		);
	}

	public function test_format_mismatch_is_rejected(): void {
		// Base64 signature but declare hex format.
		$sig = base64_encode( hash_hmac( 'sha256', self::BODY, self::SECRET, true ) );

		$this->assertFalse(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, $sig, self::SECRET, 'hex' )
		);
	}

	public function test_empty_secret_always_rejects(): void {
		$sig = hash_hmac( 'sha256', self::BODY, '' );

		$this->assertFalse(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, $sig, '', 'hex' )
		);
	}

	public function test_empty_signature_is_rejected(): void {
		$this->assertFalse(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, '', self::SECRET, 'sha256=hex' )
		);
	}

	public function test_empty_body_is_handled(): void {
		$sig = 'sha256=' . hash_hmac( 'sha256', '', self::SECRET );

		$this->assertTrue(
			WebhookSignatureVerifier::verify_hmac_sha256( '', $sig, self::SECRET, 'sha256=hex' )
		);
	}

	public function test_body_tampering_is_rejected(): void {
		$sig      = 'sha256=' . hash_hmac( 'sha256', self::BODY, self::SECRET );
		$tampered = self::BODY . '{"extra":"data"}';

		$this->assertFalse(
			WebhookSignatureVerifier::verify_hmac_sha256( $tampered, $sig, self::SECRET, 'sha256=hex' )
		);
	}

	public function test_unknown_format_is_rejected(): void {
		$sig = hash_hmac( 'sha256', self::BODY, self::SECRET );

		$this->assertFalse(
			WebhookSignatureVerifier::verify_hmac_sha256( self::BODY, $sig, self::SECRET, 'ed25519' )
		);
	}

	public function test_supported_formats_exposes_all_formats(): void {
		$formats = WebhookSignatureVerifier::supported_formats();

		$this->assertContains( 'sha256=hex', $formats );
		$this->assertContains( 'hex', $formats );
		$this->assertContains( 'base64', $formats );
	}

	public function test_uses_hash_equals_for_timing_safety(): void {
		// Static inspection: the implementation must delegate to hash_equals.
		// Reading the source is the most reliable check for a static helper.
		$source = file_get_contents( __DIR__ . '/../../../inc/Api/WebhookSignatureVerifier.php' );
		$this->assertStringContainsString( 'hash_equals(', $source );
	}
}
