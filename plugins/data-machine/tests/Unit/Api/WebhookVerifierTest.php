<?php
/**
 * WebhookVerifier unit tests.
 *
 * Pure unit tests — no WordPress bootstrap required.
 *
 * The provider matrix proves that one engine + zero provider-specific code
 * can verify signatures from every major HMAC-family sender. DM core does
 * not know any of these provider names; the test file does, because the
 * test's job is to *prove* provider-agnostic coverage.
 *
 * @package DataMachine\Tests\Unit\Api
 */

// Stub apply_filters in the verifier's namespace so pure-unit tests don't need WP.
namespace DataMachine\Api {
	if ( ! function_exists( __NAMESPACE__ . '\\apply_filters' ) && ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value ) { // phpcs:ignore
			return $value;
		}
	}
}

namespace DataMachine\Tests\Unit\Api {

	use DataMachine\Api\WebhookVerifier;
	use DataMachine\Api\WebhookVerificationResult;
	use PHPUnit\Framework\TestCase;

	class WebhookVerifierTest extends TestCase {

		private const SECRET = 'super-secret-value';
		private const BODY   = '{"action":"opened","number":1}';

		/**
		 * @dataProvider providerMatrix
		 */
		public function test_matrix_valid_signature_accepted( string $name, array $config, array $headers, string $body, int $now, ?int $timestamp ): void {
			$result = WebhookVerifier::verify( $body, $headers, array(), array(), 'https://example.com/', $config, $now );
			$this->assertTrue(
				$result->ok,
				sprintf( '[%s] expected ok, got reason=%s detail=%s', $name, $result->reason, $result->detail ?? '' )
			);
			if ( null !== $timestamp ) {
				$this->assertSame( $timestamp, $result->timestamp, "[{$name}] timestamp should be extracted" );
			}
		}

		/**
		 * @dataProvider providerMatrix
		 */
		public function test_matrix_tampered_body_rejected( string $name, array $config, array $headers, string $body, int $now ): void {
			if ( '' === $body || false === strpos( (string) $config['signed_template'], '{body}' ) ) {
				$this->assertTrue( true ); // Template doesn't sign the body — wrong-secret test covers this.
				return;
			}
			$result = WebhookVerifier::verify( $body . 'TAMPER', $headers, array(), array(), 'https://example.com/', $config, $now );
			$this->assertFalse( $result->ok, "[{$name}] tampered body should not verify" );
		}

		/**
		 * @dataProvider providerMatrix
		 */
		public function test_matrix_wrong_secret_rejected( string $name, array $config, array $headers, string $body, int $now ): void {
			$config['secrets'] = array( array( 'id' => 'current', 'value' => 'wrong-secret-value' ) );
			$result            = WebhookVerifier::verify( $body, $headers, array(), array(), 'https://example.com/', $config, $now );
			$this->assertFalse( $result->ok, "[{$name}] wrong secret should not verify" );
		}

		public function providerMatrix(): array {
			$secret = self::SECRET;
			$body   = self::BODY;
			$now    = 1700000000;
			$ts     = 1700000000;
			$cases  = array();

			// GitHub-style: sha256=<hex> prefixed header.
			$cases['prefixed_hex'] = array(
				'prefixed_hex',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{body}',
					'signature_source' => array(
						'header'   => 'X-Signature-256',
						'extract'  => array( 'kind' => 'prefix', 'key' => 'sha256=' ),
						'encoding' => 'hex',
					),
					'secrets'          => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array( 'x-signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ) ),
				$body,
				$now,
				null,
			);

			// Shopify-style: base64 in a single header.
			$cases['base64_header'] = array(
				'base64_header',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{body}',
					'signature_source' => array(
						'header'   => 'X-Hmac-Sha256',
						'extract'  => array( 'kind' => 'raw' ),
						'encoding' => 'base64',
					),
					'secrets'          => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array( 'x-hmac-sha256' => base64_encode( hash_hmac( 'sha256', $body, $secret, true ) ) ),
				$body,
				$now,
				null,
			);

			// Linear-style: raw hex.
			$cases['raw_hex'] = array(
				'raw_hex',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{body}',
					'signature_source' => array(
						'header'   => 'X-Webhook-Signature',
						'extract'  => array( 'kind' => 'raw' ),
						'encoding' => 'hex',
					),
					'secrets'          => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array( 'x-webhook-signature' => hash_hmac( 'sha256', $body, $secret ) ),
				$body,
				$now,
				null,
			);

			// Stripe-style: t=<ts>,v1=<hex> composite, signed "{ts}.{body}".
			$stripe_sig               = hash_hmac( 'sha256', $ts . '.' . $body, $secret );
			$cases['kv_timestamped']  = array(
				'kv_timestamped',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{timestamp}.{body}',
					'signature_source' => array(
						'header'   => 'X-Composite-Signature',
						'extract'  => array( 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ',' ),
						'encoding' => 'hex',
					),
					'timestamp_source' => array(
						'header'  => 'X-Composite-Signature',
						'extract' => array( 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ),
						'format'  => 'unix',
					),
					'tolerance_seconds' => 300,
					'secrets'           => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array( 'x-composite-signature' => "t={$ts},v1={$stripe_sig}" ),
				$body,
				$now,
				$ts,
			);

			// Slack-style: v0=<hex> plus separate timestamp header, signed "v0:{ts}:{body}".
			$slack_sig                 = hash_hmac( 'sha256', 'v0:' . $ts . ':' . $body, $secret );
			$cases['separate_timestamp'] = array(
				'separate_timestamp',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => 'v0:{timestamp}:{body}',
					'signature_source' => array(
						'header'   => 'X-Signature',
						'extract'  => array( 'kind' => 'prefix', 'key' => 'v0=' ),
						'encoding' => 'hex',
					),
					'timestamp_source' => array(
						'header'  => 'X-Request-Timestamp',
						'extract' => array( 'kind' => 'raw' ),
						'format'  => 'unix',
					),
					'tolerance_seconds' => 300,
					'secrets'           => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array(
					'x-signature'         => 'v0=' . $slack_sig,
					'x-request-timestamp' => (string) $ts,
				),
				$body,
				$now,
				$ts,
			);

			// Svix/Standard-Webhooks style: space-separated v1,<base64> with id + timestamp.
			$svix_id                 = 'msg_abc123';
			$svix_signed             = $svix_id . '.' . $ts . '.' . $body;
			$svix_sig                = base64_encode( hash_hmac( 'sha256', $svix_signed, $secret, true ) );
			$cases['id_timestamped'] = array(
				'id_timestamped',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{id}.{timestamp}.{body}',
					'signature_source' => array(
						'header'   => 'Webhook-Signature',
						'extract'  => array( 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ' ', 'pair_separator' => ',' ),
						'encoding' => 'base64',
					),
					'timestamp_source'  => array(
						'header'  => 'Webhook-Timestamp',
						'extract' => array( 'kind' => 'raw' ),
						'format'  => 'unix',
					),
					'id_source'         => array( 'header' => 'Webhook-Id' ),
					'tolerance_seconds' => 300,
					'secrets'           => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array(
					'webhook-id'        => $svix_id,
					'webhook-timestamp' => (string) $ts,
					'webhook-signature' => "v1,{$svix_sig}",
				),
				$body,
				$now,
				$ts,
			);

			return $cases;
		}

		/**
		 * Twilio-style URL + param test — signed string excludes the body, so
		 * it needs its own entry that passes a specific URL + post params to
		 * `verify()`. The provider matrix uses a fixed URL, so this test sits
		 * alongside.
		 */
		public function test_url_and_param_placeholders(): void {
			$secret        = self::SECRET;
			$url           = 'https://example.com/twilio';
			$from          = '+15005550006';
			$to            = '+15005550001';
			$signed        = $url . $from . $to;
			$twilio_sig    = base64_encode( hash_hmac( 'sha1', $signed, $secret, true ) );

			$config = array(
				'mode'             => 'hmac',
				'algo'             => 'sha1',
				'signed_template'  => '{url}{param:From}{param:To}',
				'signature_source' => array(
					'header'   => 'X-Twilio-Signature',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'base64',
				),
				'secrets'          => array( array( 'id' => 'current', 'value' => $secret ) ),
			);

			$result = WebhookVerifier::verify(
				'',
				array( 'x-twilio-signature' => $twilio_sig ),
				array(),
				array( 'From' => $from, 'To' => $to ),
				$url,
				$config
			);

			$this->assertTrue( $result->ok, $result->reason . ' ' . ( $result->detail ?? '' ) );
		}

		/* -------- Security / rotation edges -------- */

		public function test_stale_timestamp_rejected(): void {
			$ts  = 1700000000;
			$now = $ts + 3600;
			$cfg = $this->stripe_like_config( $ts );
			$res = WebhookVerifier::verify( self::BODY, array( 'x-composite-signature' => "t={$ts},v1=" . hash_hmac( 'sha256', $ts . '.' . self::BODY, self::SECRET ) ), array(), array(), 'https://example.com/', $cfg, $now );
			$this->assertFalse( $res->ok );
			$this->assertSame( WebhookVerificationResult::STALE_TIMESTAMP, $res->reason );
			$this->assertSame( 3600, $res->skew_seconds );
		}

		public function test_fresh_timestamp_accepted(): void {
			$ts  = 1700000000;
			$now = $ts + 60;
			$cfg = $this->stripe_like_config( $ts );
			$res = WebhookVerifier::verify( self::BODY, array( 'x-composite-signature' => "t={$ts},v1=" . hash_hmac( 'sha256', $ts . '.' . self::BODY, self::SECRET ) ), array(), array(), 'https://example.com/', $cfg, $now );
			$this->assertTrue( $res->ok );
		}

		public function test_multi_secret_rotation_previous_verifies(): void {
			$now = 1700000000;
			$cfg = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Sig',
					'extract'  => array( 'kind' => 'prefix', 'key' => 'sha256=' ),
					'encoding' => 'hex',
				),
				'secrets' => array(
					array( 'id' => 'current',  'value' => 'NEW' ),
					array( 'id' => 'previous', 'value' => 'OLD', 'expires_at' => $now + 3600 ),
				),
			);
			$sig = 'sha256=' . hash_hmac( 'sha256', self::BODY, 'OLD' );
			$res = WebhookVerifier::verify( self::BODY, array( 'x-sig' => $sig ), array(), array(), '', $cfg, $now );
			$this->assertTrue( $res->ok );
			$this->assertSame( 'previous', $res->secret_id );
		}

		public function test_expired_previous_secret_skipped(): void {
			$now = 1700000000;
			$cfg = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Sig',
					'extract'  => array( 'kind' => 'prefix', 'key' => 'sha256=' ),
					'encoding' => 'hex',
				),
				'secrets' => array(
					array( 'id' => 'current',  'value' => 'NEW' ),
					array( 'id' => 'previous', 'value' => 'OLD', 'expires_at' => $now - 1 ),
				),
			);
			$sig = 'sha256=' . hash_hmac( 'sha256', self::BODY, 'OLD' );
			$res = WebhookVerifier::verify( self::BODY, array( 'x-sig' => $sig ), array(), array(), '', $cfg, $now );
			$this->assertFalse( $res->ok );
			$this->assertSame( WebhookVerificationResult::BAD_SIGNATURE, $res->reason );
		}

		public function test_missing_header_reported(): void {
			$cfg = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Absent',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
				'secrets'          => array( array( 'id' => 'current', 'value' => self::SECRET ) ),
			);
			$res = WebhookVerifier::verify( self::BODY, array(), array(), array(), '', $cfg );
			$this->assertFalse( $res->ok );
			$this->assertSame( WebhookVerificationResult::MISSING_HEADER, $res->reason );
		}

		public function test_payload_too_large(): void {
			$cfg = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Sig',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
				'secrets'          => array( array( 'id' => 'current', 'value' => self::SECRET ) ),
				'max_body_bytes'   => 16,
			);
			$big = str_repeat( 'a', 128 );
			$res = WebhookVerifier::verify( $big, array( 'x-sig' => hash_hmac( 'sha256', $big, self::SECRET ) ), array(), array(), '', $cfg );
			$this->assertFalse( $res->ok );
			$this->assertSame( WebhookVerificationResult::PAYLOAD_TOO_LARGE, $res->reason );
		}

		public function test_no_active_secrets_fails_fast(): void {
			$now = 1700000000;
			$cfg = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Sig',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
				'secrets' => array(
					array( 'id' => 'expired', 'value' => 'x', 'expires_at' => $now - 10 ),
				),
			);
			$res = WebhookVerifier::verify( self::BODY, array( 'x-sig' => 'abc' ), array(), array(), '', $cfg, $now );
			$this->assertFalse( $res->ok );
			$this->assertSame( WebhookVerificationResult::NO_ACTIVE_SECRET, $res->reason );
		}

		public function test_malformed_template_rejected(): void {
			$cfg = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{unknown_placeholder}',
				'signature_source' => array(
					'header'   => 'X-Sig',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
				'secrets'          => array( array( 'id' => 'current', 'value' => self::SECRET ) ),
			);
			$res = WebhookVerifier::verify( self::BODY, array( 'x-sig' => str_repeat( 'a', 64 ) ), array(), array(), '', $cfg );
			$this->assertFalse( $res->ok );
			$this->assertSame( WebhookVerificationResult::MALFORMED_TEMPLATE, $res->reason );
		}

		public function test_unknown_mode_goes_to_filter_registry(): void {
			$res = WebhookVerifier::verify( '', array(), array(), array(), '', array( 'mode' => 'ed25519' ) );
			$this->assertFalse( $res->ok );
			$this->assertSame( WebhookVerificationResult::UNKNOWN_MODE, $res->reason );
		}

		public function test_unix_ms_timestamp_parsed(): void {
			$ts_sec = 1700000000;
			$now    = $ts_sec;
			$cfg    = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{timestamp}.{body}',
				'signature_source' => array(
					'header'   => 'X-Sig',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
				'timestamp_source' => array(
					'header'  => 'X-Ts',
					'extract' => array( 'kind' => 'raw' ),
					'format'  => 'unix_ms',
				),
				'tolerance_seconds' => 5,
				'secrets'           => array( array( 'id' => 'current', 'value' => self::SECRET ) ),
			);
			$res = WebhookVerifier::verify(
				self::BODY,
				array(
					'x-sig' => hash_hmac( 'sha256', $ts_sec . '.' . self::BODY, self::SECRET ),
					'x-ts'  => (string) ( $ts_sec * 1000 ),
				),
				array(),
				array(),
				'',
				$cfg,
				$now
			);
			$this->assertTrue( $res->ok );
			$this->assertSame( $ts_sec, $res->timestamp );
		}

		private function stripe_like_config( int $ts ): array {
			return array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{timestamp}.{body}',
				'signature_source' => array(
					'header'   => 'X-Composite-Signature',
					'extract'  => array( 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ',' ),
					'encoding' => 'hex',
				),
				'timestamp_source' => array(
					'header'  => 'X-Composite-Signature',
					'extract' => array( 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ),
					'format'  => 'unix',
				),
				'tolerance_seconds' => 300,
				'secrets'           => array( array( 'id' => 'current', 'value' => self::SECRET ) ),
			);
		}
	}
}
