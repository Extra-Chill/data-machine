<?php
/**
 * WebhookVerifier unit tests.
 *
 * Pure unit tests — no WordPress bootstrap required.
 *
 * The core exercise is a data-driven provider matrix: each entry builds a
 * realistic signed request, hands it to the verifier, and asserts the result.
 * If this grid passes end-to-end, the template engine covers every provider
 * in it with zero provider-specific code.
 *
 * @package DataMachine\Tests\Unit\Api
 */

// Stub apply_filters in the verifier's namespace so pure unit tests don't need WordPress.
// Only defined when WordPress isn't already loaded.
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

		/* =================================================================
		 * Provider matrix — the proof-of-coverage
		 * ================================================================= */

		/**
		 * @dataProvider providerMatrix
		 */
		public function test_provider_matrix_accepts_valid_signature( string $name, array $config, array $headers, string $body, int $now, ?int $timestamp ): void {
			$result = WebhookVerifier::verify( $body, $headers, array(), array(), 'https://example.com/wp-json/datamachine/v1/trigger/42', $config, $now );

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
		public function test_provider_matrix_rejects_tampered_body( string $name, array $config, array $headers, string $body, int $now ): void {
			// Some provider templates deliberately don't sign the body (Mailgun signs
			// {timestamp}{token}). For those we verify the matrix proves the engine
			// routes extraction correctly — the rejection is better covered by the
			// wrong-secret test.
			if ( '' === $body || false === strpos( (string) $config['signed_template'], '{body}' ) ) {
				$this->assertTrue( true );
				return;
			}
			$result = WebhookVerifier::verify( $body . 'TAMPER', $headers, array(), array(), 'https://example.com/', $config, $now );
			$this->assertFalse( $result->ok, "[{$name}] tampered body should not verify" );
		}

		/**
		 * @dataProvider providerMatrix
		 */
		public function test_provider_matrix_rejects_wrong_secret( string $name, array $config, array $headers, string $body, int $now ): void {
			$config['secrets'] = array( array( 'id' => 'current', 'value' => 'wrong-secret-value' ) );
			$result = WebhookVerifier::verify( $body, $headers, array(), array(), 'https://example.com/', $config, $now );
			$this->assertFalse( $result->ok, "[{$name}] wrong secret should not verify" );
		}

		/**
		 * Yields one entry per provider, fully self-contained.
		 *
		 * Each entry returns:
		 *   [ name, verifier_config, headers, body, now, extracted_timestamp|null ]
		 */
		public function providerMatrix(): array {
			$secret = self::SECRET;
			$body   = self::BODY;
			$now    = 1700000000;
			$ts     = 1700000000;

			$cases = array();

			$cases['github'] = array(
				'github',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{body}',
					'signature_source' => array(
						'header'   => 'X-Hub-Signature-256',
						'extract'  => array( 'kind' => 'prefix', 'key' => 'sha256=' ),
						'encoding' => 'hex',
					),
					'secrets'          => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array(
					'x-hub-signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
					'x-github-event'      => 'push',
				),
				$body,
				$now,
				null,
			);

			$cases['shopify'] = array(
				'shopify',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{body}',
					'signature_source' => array(
						'header'   => 'X-Shopify-Hmac-Sha256',
						'extract'  => array( 'kind' => 'raw' ),
						'encoding' => 'base64',
					),
					'secrets'          => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array(
					'x-shopify-hmac-sha256' => base64_encode( hash_hmac( 'sha256', $body, $secret, true ) ),
					'x-shopify-topic'       => 'orders/create',
				),
				$body,
				$now,
				null,
			);

			$cases['linear'] = array(
				'linear',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{body}',
					'signature_source' => array(
						'header'   => 'Linear-Signature',
						'extract'  => array( 'kind' => 'raw' ),
						'encoding' => 'hex',
					),
					'secrets'          => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array( 'linear-signature' => hash_hmac( 'sha256', $body, $secret ) ),
				$body,
				$now,
				null,
			);

			$stripe_sig      = hash_hmac( 'sha256', $ts . '.' . $body, $secret );
			$cases['stripe'] = array(
				'stripe',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{timestamp}.{body}',
					'signature_source' => array(
						'header'   => 'Stripe-Signature',
						'extract'  => array( 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ',' ),
						'encoding' => 'hex',
					),
					'timestamp_source' => array(
						'header'  => 'Stripe-Signature',
						'extract' => array( 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ),
						'format'  => 'unix',
					),
					'tolerance_seconds' => 300,
					'secrets'           => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array(
					'stripe-signature' => "t={$ts},v1={$stripe_sig},v0=unused",
				),
				$body,
				$now,
				$ts,
			);

			$slack_sig      = hash_hmac( 'sha256', 'v0:' . $ts . ':' . $body, $secret );
			$cases['slack'] = array(
				'slack',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => 'v0:{timestamp}:{body}',
					'signature_source' => array(
						'header'   => 'X-Slack-Signature',
						'extract'  => array( 'kind' => 'prefix', 'key' => 'v0=' ),
						'encoding' => 'hex',
					),
					'timestamp_source' => array(
						'header'  => 'X-Slack-Request-Timestamp',
						'extract' => array( 'kind' => 'raw' ),
						'format'  => 'unix',
					),
					'tolerance_seconds' => 300,
					'secrets'           => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array(
					'x-slack-signature'         => 'v0=' . $slack_sig,
					'x-slack-request-timestamp' => (string) $ts,
				),
				$body,
				$now,
				$ts,
			);

			$svix_id       = 'msg_abc123';
			$svix_body     = $svix_id . '.' . $ts . '.' . $body;
			$svix_sig      = base64_encode( hash_hmac( 'sha256', $svix_body, $secret, true ) );
			$cases['svix'] = array(
				'svix',
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

			$mg_token         = 'tok_abc';
			$mg_body          = $ts . $mg_token;
			$cases['mailgun'] = array(
				'mailgun',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{timestamp}{header:X-Mailgun-Token}',
					'signature_source' => array(
						'header'   => 'X-Mailgun-Signature',
						'extract'  => array( 'kind' => 'raw' ),
						'encoding' => 'hex',
					),
					'timestamp_source'  => array(
						'header'  => 'X-Mailgun-Timestamp',
						'extract' => array( 'kind' => 'raw' ),
						'format'  => 'unix',
					),
					'tolerance_seconds' => 600,
					'secrets'           => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array(
					'x-mailgun-timestamp' => (string) $ts,
					'x-mailgun-token'     => $mg_token,
					'x-mailgun-signature' => hash_hmac( 'sha256', $mg_body, $secret ),
				),
				$body,
				$now,
				$ts,
			);

			$cases['paypal'] = array(
				'paypal',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{body}',
					'signature_source' => array(
						'header'   => 'Paypal-Transmission-Sig',
						'extract'  => array( 'kind' => 'raw' ),
						'encoding' => 'base64',
					),
					'secrets'          => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array(
					'paypal-transmission-sig' => base64_encode( hash_hmac( 'sha256', $body, $secret, true ) ),
				),
				$body,
				$now,
				null,
			);

			$clerk_id       = 'evt_xyz';
			$clerk_body     = $clerk_id . '.' . $ts . '.' . $body;
			$clerk_sig      = base64_encode( hash_hmac( 'sha256', $clerk_body, $secret, true ) );
			$cases['clerk'] = array(
				'clerk',
				array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{header:svix-id}.{header:svix-timestamp}.{body}',
					'signature_source' => array(
						'header'   => 'svix-signature',
						'extract'  => array( 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ' ', 'pair_separator' => ',' ),
						'encoding' => 'base64',
					),
					'timestamp_source'  => array(
						'header'  => 'svix-timestamp',
						'extract' => array( 'kind' => 'raw' ),
						'format'  => 'unix',
					),
					'tolerance_seconds' => 300,
					'secrets'           => array( array( 'id' => 'current', 'value' => $secret ) ),
				),
				array(
					'svix-id'        => $clerk_id,
					'svix-timestamp' => (string) $ts,
					'svix-signature' => "v1,{$clerk_sig}",
				),
				$body,
				$now,
				$ts,
			);

			return $cases;
		}

		/* =================================================================
		 * Replay / rotation / security edges
		 * ================================================================= */

		public function test_stale_timestamp_rejected(): void {
			$ts     = 1700000000;
			$now    = $ts + 3600;
			$secret = self::SECRET;
			$body   = self::BODY;

			$config  = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{timestamp}.{body}',
				'signature_source' => array(
					'header'   => 'Stripe-Signature',
					'extract'  => array( 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ',' ),
					'encoding' => 'hex',
				),
				'timestamp_source' => array(
					'header'  => 'Stripe-Signature',
					'extract' => array( 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ),
					'format'  => 'unix',
				),
				'tolerance_seconds' => 300,
				'secrets'           => array( array( 'id' => 'current', 'value' => $secret ) ),
			);
			$sig     = hash_hmac( 'sha256', $ts . '.' . $body, $secret );
			$headers = array( 'stripe-signature' => "t={$ts},v1={$sig}" );

			$result = WebhookVerifier::verify( $body, $headers, array(), array(), 'https://example.com/', $config, $now );
			$this->assertFalse( $result->ok );
			$this->assertSame( WebhookVerificationResult::STALE_TIMESTAMP, $result->reason );
			$this->assertSame( 3600, $result->skew_seconds );
		}

		public function test_fresh_timestamp_accepted(): void {
			$ts     = 1700000000;
			$now    = $ts + 60;
			$secret = self::SECRET;
			$body   = self::BODY;

			$config  = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{timestamp}.{body}',
				'signature_source' => array(
					'header'   => 'Stripe-Signature',
					'extract'  => array( 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ',' ),
					'encoding' => 'hex',
				),
				'timestamp_source' => array(
					'header'  => 'Stripe-Signature',
					'extract' => array( 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ),
					'format'  => 'unix',
				),
				'tolerance_seconds' => 300,
				'secrets'           => array( array( 'id' => 'current', 'value' => $secret ) ),
			);
			$sig     = hash_hmac( 'sha256', $ts . '.' . $body, $secret );
			$headers = array( 'stripe-signature' => "t={$ts},v1={$sig}" );

			$result = WebhookVerifier::verify( $body, $headers, array(), array(), 'https://example.com/', $config, $now );
			$this->assertTrue( $result->ok, $result->reason . ' ' . ( $result->detail ?? '' ) );
		}

		public function test_multi_secret_rotation_previous_still_verifies(): void {
			$secret_new = 'NEW-secret-value';
			$secret_old = 'OLD-secret-value';
			$body       = self::BODY;
			$now        = 1700000000;

			$config = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Hub-Signature-256',
					'extract'  => array( 'kind' => 'prefix', 'key' => 'sha256=' ),
					'encoding' => 'hex',
				),
				'secrets' => array(
					array( 'id' => 'current',  'value' => $secret_new ),
					array( 'id' => 'previous', 'value' => $secret_old, 'expires_at' => $now + 3600 ),
				),
			);

			$sig    = 'sha256=' . hash_hmac( 'sha256', $body, $secret_old );
			$result = WebhookVerifier::verify( $body, array( 'x-hub-signature-256' => $sig ), array(), array(), 'https://example.com/', $config, $now );

			$this->assertTrue( $result->ok );
			$this->assertSame( 'previous', $result->secret_id );
		}

		public function test_expired_previous_secret_is_skipped(): void {
			$secret_new = 'NEW';
			$secret_old = 'OLD';
			$body       = self::BODY;
			$now        = 1700000000;

			$config = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Hub-Signature-256',
					'extract'  => array( 'kind' => 'prefix', 'key' => 'sha256=' ),
					'encoding' => 'hex',
				),
				'secrets' => array(
					array( 'id' => 'current',  'value' => $secret_new ),
					array( 'id' => 'previous', 'value' => $secret_old, 'expires_at' => $now - 1 ),
				),
			);

			$sig    = 'sha256=' . hash_hmac( 'sha256', $body, $secret_old );
			$result = WebhookVerifier::verify( $body, array( 'x-hub-signature-256' => $sig ), array(), array(), 'https://example.com/', $config, $now );

			$this->assertFalse( $result->ok );
			$this->assertSame( WebhookVerificationResult::BAD_SIGNATURE, $result->reason );
		}

		public function test_missing_signature_header_reported(): void {
			$config = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Hub-Signature-256',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
				'secrets'          => array( array( 'id' => 'current', 'value' => self::SECRET ) ),
			);
			$result = WebhookVerifier::verify( self::BODY, array(), array(), array(), 'https://example.com/', $config );
			$this->assertFalse( $result->ok );
			$this->assertSame( WebhookVerificationResult::MISSING_HEADER, $result->reason );
		}

		public function test_payload_too_large(): void {
			$config = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Hub-Signature-256',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
				'secrets'          => array( array( 'id' => 'current', 'value' => self::SECRET ) ),
				'max_body_bytes'   => 16,
			);
			$big    = str_repeat( 'a', 128 );
			$result = WebhookVerifier::verify(
				$big,
				array( 'x-hub-signature-256' => hash_hmac( 'sha256', $big, self::SECRET ) ),
				array(),
				array(),
				'https://example.com/',
				$config
			);
			$this->assertFalse( $result->ok );
			$this->assertSame( WebhookVerificationResult::PAYLOAD_TOO_LARGE, $result->reason );
		}

		public function test_no_active_secrets_fails_fast(): void {
			$now    = 1700000000;
			$config = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Hub-Signature-256',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
				'secrets' => array(
					array( 'id' => 'expired', 'value' => 'x', 'expires_at' => $now - 10 ),
				),
			);
			$result = WebhookVerifier::verify( self::BODY, array( 'x-hub-signature-256' => 'abc' ), array(), array(), 'https://example.com/', $config, $now );
			$this->assertFalse( $result->ok );
			$this->assertSame( WebhookVerificationResult::NO_ACTIVE_SECRET, $result->reason );
		}

		public function test_malformed_template_rejected(): void {
			$config = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{unknown_placeholder}',
				'signature_source' => array(
					'header'   => 'X',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
				'secrets'          => array( array( 'id' => 'c', 'value' => self::SECRET ) ),
			);
			// Use a valid-shape hex signature so we reach the template render step.
			$result = WebhookVerifier::verify(
				self::BODY,
				array( 'x' => str_repeat( 'a', 64 ) ),
				array(),
				array(),
				'https://example.com/',
				$config
			);
			$this->assertFalse( $result->ok );
			$this->assertSame( WebhookVerificationResult::MALFORMED_TEMPLATE, $result->reason );
		}

		public function test_unknown_mode_routes_to_filter_registry(): void {
			$config = array( 'mode' => 'ed25519' );
			$result = WebhookVerifier::verify( '', array(), array(), array(), '', $config );
			$this->assertFalse( $result->ok );
			$this->assertSame( WebhookVerificationResult::UNKNOWN_MODE, $result->reason );
		}

		public function test_url_and_param_placeholders(): void {
			// Twilio-ish: signed string = url + concat post params in template order.
			$url    = 'https://example.com/twilio/webhook';
			$secret = self::SECRET;
			$signed = $url . '+15005550006' . '+15005550001';

			$config  = array(
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
			$headers = array(
				'x-twilio-signature' => base64_encode( hash_hmac( 'sha1', $signed, $secret, true ) ),
			);
			$post    = array( 'From' => '+15005550006', 'To' => '+15005550001' );

			$result = WebhookVerifier::verify( '', $headers, array(), $post, $url, $config );
			$this->assertTrue( $result->ok, $result->reason . ' detail=' . ( $result->detail ?? '' ) );
		}

		public function test_unix_ms_timestamp_parsed(): void {
			$ts_sec = 1700000000;
			$ts_ms  = $ts_sec * 1000;
			$body   = self::BODY;
			$now    = $ts_sec;
			$sig    = hash_hmac( 'sha256', $ts_sec . '.' . $body, self::SECRET );

			$config  = array(
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
			$headers = array( 'x-sig' => $sig, 'x-ts' => (string) $ts_ms );

			$result = WebhookVerifier::verify( $body, $headers, array(), array(), 'https://example.com/', $config, $now );
			$this->assertTrue( $result->ok, $result->reason );
			$this->assertSame( $ts_sec, $result->timestamp );
		}
	}
}
