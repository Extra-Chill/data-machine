<?php
/**
 * WebhookTrigger v2 integration tests.
 *
 * Exercises the template-based verifier end-to-end through
 * WebhookTrigger::handle_trigger(), plus the rotate / forget / test ability
 * surfaces. Pure v1 regression coverage lives in WebhookTriggerTest.
 *
 * @package DataMachine\Tests\Unit\Api
 */

namespace DataMachine\Tests\Unit\Api;

use DataMachine\Abilities\Flow\WebhookTriggerAbility;
use DataMachine\Api\WebhookTrigger;
use DataMachine\Core\Database\Flows\Flows;
use WP_REST_Request;
use WP_UnitTestCase;

class WebhookTriggerV2Test extends WP_UnitTestCase {

	private int $flow_id;
	private WebhookTriggerAbility $ability;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$pipeline = wp_get_ability( 'datamachine/create-pipeline' )->execute( array( 'pipeline_name' => 'v2 Pipeline' ) );
		$flow     = wp_get_ability( 'datamachine/create-flow' )->execute( array(
			'pipeline_id' => (int) $pipeline['pipeline_id'],
			'flow_name'   => 'v2 Flow',
		) );
		$this->flow_id = (int) $flow['flow_id'];
		$this->ability = new WebhookTriggerAbility();
	}

	public function tear_down(): void {
		remove_all_filters( 'datamachine_webhook_auth_presets' );
		delete_transient( 'dm_webhook_rate_' . $this->flow_id );
		parent::tear_down();
	}

	/* =================================================================
	 * Preset resolution end-to-end through the REST handler
	 * =================================================================
	 */

	public function test_enable_with_stripe_preset_and_trigger_succeeds(): void {
		$this->register_stripe_preset();

		$enable = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'stripe',
			'generate_secret' => true,
		) );

		$this->assertTrue( $enable['success'] );
		$this->assertSame( 'stripe', $enable['preset'] );
		$secret = $enable['secret'];

		// Build a fresh Stripe-signed request.
		$ts      = time();
		$body    = '{"id":"evt_123","type":"charge.succeeded"}';
		$sig     = hash_hmac( 'sha256', $ts . '.' . $body, $secret );
		$headers = array( 'stripe-signature' => "t={$ts},v1={$sig}" );

		$request  = $this->make_request( $body, $headers );
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assert_not_unauthorized( $response );
	}

	public function test_enable_with_stripe_preset_rejects_wrong_signature(): void {
		$this->register_stripe_preset();
		$this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'stripe',
			'generate_secret' => true,
		) );

		$ts   = time();
		$body = '{"id":"evt_123"}';
		// Signed with a *different* secret.
		$bad_sig = hash_hmac( 'sha256', $ts . '.' . $body, 'WRONG' );
		$headers = array( 'stripe-signature' => "t={$ts},v1={$bad_sig}" );

		$response = WebhookTrigger::handle_trigger( $this->make_request( $body, $headers ) );
		$this->assert_is_unauthorized( $response );
	}

	public function test_enable_with_stripe_preset_rejects_stale_timestamp(): void {
		$this->register_stripe_preset();
		$enable = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'stripe',
			'generate_secret' => true,
		) );
		$secret = $enable['secret'];

		// Timestamp way outside the 300-second tolerance.
		$ts      = time() - 3600;
		$body    = '{"id":"evt"}';
		$sig     = hash_hmac( 'sha256', $ts . '.' . $body, $secret );
		$headers = array( 'stripe-signature' => "t={$ts},v1={$sig}" );

		$response = WebhookTrigger::handle_trigger( $this->make_request( $body, $headers ) );
		$this->assert_is_unauthorized( $response );
	}

	public function test_enable_with_unknown_preset_errors(): void {
		$result = $this->ability->executeEnable( array(
			'flow_id' => $this->flow_id,
			'preset'  => 'no-such-preset',
		) );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unknown preset', $result['error'] );
	}

	/* =================================================================
	 * Rotate + forget lifecycle
	 * =================================================================
	 */

	public function test_rotate_keeps_previous_secret_verifying_until_expiry(): void {
		// Enable with v1 shorthand.
		$enable = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'auth_mode'       => 'hmac_sha256',
			'generate_secret' => true,
		) );
		$old_secret = $enable['secret'];

		// Rotate to a new secret with a 1-hour grace.
		$rotated = $this->ability->executeRotateSecret( array(
			'flow_id'              => $this->flow_id,
			'generate'             => true,
			'previous_ttl_seconds' => 3600,
		) );
		$this->assertTrue( $rotated['success'] );
		$this->assertNotEmpty( $rotated['new_secret'] );
		$this->assertNotEmpty( $rotated['previous_expires_at'] );

		// Request signed with the *old* secret — still works during grace.
		$body = '{"hello":"world"}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, $old_secret );
		$response = WebhookTrigger::handle_trigger( $this->make_request( $body, array(
			'x-hub-signature-256' => $sig,
		) ) );
		$this->assert_not_unauthorized( $response );

		// Request signed with the *new* secret — also works.
		$new_sig = 'sha256=' . hash_hmac( 'sha256', $body, $rotated['new_secret'] );
		$response = WebhookTrigger::handle_trigger( $this->make_request( $body, array(
			'x-hub-signature-256' => $new_sig,
		) ) );
		$this->assert_not_unauthorized( $response );
	}

	public function test_forget_previous_secret_invalidates_it(): void {
		$enable = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'auth_mode'       => 'hmac_sha256',
			'generate_secret' => true,
		) );
		$old_secret = $enable['secret'];

		$this->ability->executeRotateSecret( array(
			'flow_id'              => $this->flow_id,
			'generate'             => true,
			'previous_ttl_seconds' => 3600,
		) );

		$forget = $this->ability->executeForgetSecret( array(
			'flow_id'   => $this->flow_id,
			'secret_id' => 'previous',
		) );
		$this->assertTrue( $forget['success'] );

		// Old secret no longer verifies.
		$body = '{"x":1}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, $old_secret );
		$response = WebhookTrigger::handle_trigger( $this->make_request( $body, array(
			'x-hub-signature-256' => $sig,
		) ) );
		$this->assert_is_unauthorized( $response );
	}

	public function test_forget_unknown_secret_id_errors(): void {
		$this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'auth_mode'       => 'hmac_sha256',
			'generate_secret' => true,
		) );
		$forget = $this->ability->executeForgetSecret( array(
			'flow_id'   => $this->flow_id,
			'secret_id' => 'nonexistent',
		) );
		$this->assertFalse( $forget['success'] );
		$this->assertStringContainsString( 'No secret', $forget['error'] );
	}

	/* =================================================================
	 * Offline test ability
	 * =================================================================
	 */

	public function test_executeTest_ok_path(): void {
		$enable = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'auth_mode'       => 'hmac_sha256',
			'generate_secret' => true,
		) );
		$secret = $enable['secret'];

		$body = '{"hello":"test"}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$result = $this->ability->executeTest( array(
			'flow_id' => $this->flow_id,
			'body'    => $body,
			'headers' => array( 'X-Hub-Signature-256' => $sig ),
		) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'ok', $result['reason'] );
		$this->assertSame( 'current', $result['secret_id'] );
	}

	public function test_executeTest_reports_bad_signature(): void {
		$this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'auth_mode'       => 'hmac_sha256',
			'generate_secret' => true,
		) );

		$result = $this->ability->executeTest( array(
			'flow_id' => $this->flow_id,
			'body'    => '{"x":1}',
			'headers' => array( 'X-Hub-Signature-256' => 'sha256=' . str_repeat( 'f', 64 ) ),
		) );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'bad_signature', $result['reason'] );
	}

	public function test_executeTest_refuses_bearer_flows(): void {
		$this->ability->executeEnable( array( 'flow_id' => $this->flow_id ) ); // bearer default
		$result = $this->ability->executeTest( array(
			'flow_id' => $this->flow_id,
			'body'    => '',
			'headers' => array(),
		) );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'HMAC', $result['error'] );
	}

	/* =================================================================
	 * Status surfaces new v2 fields without leaking secrets
	 * =================================================================
	 */

	public function test_status_exposes_secret_ids_without_values(): void {
		$this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'auth_mode'       => 'hmac_sha256',
			'generate_secret' => true,
		) );
		$this->ability->executeRotateSecret( array(
			'flow_id'              => $this->flow_id,
			'generate'             => true,
			'previous_ttl_seconds' => 3600,
		) );

		$status = $this->ability->executeStatus( array( 'flow_id' => $this->flow_id ) );

		$this->assertTrue( $status['success'] );
		$this->assertArrayHasKey( 'secret_ids', $status );
		$ids = array_column( $status['secret_ids'], 'id' );
		$this->assertContains( 'current', $ids );
		$this->assertContains( 'previous', $ids );

		// Secret *values* must never appear in the response payload.
		$encoded = wp_json_encode( $status );
		$this->assertStringNotContainsString( '"value"', $encoded );
	}

	public function test_status_exposes_preset_name(): void {
		$this->register_stripe_preset();
		$this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'stripe',
			'generate_secret' => true,
		) );
		$status = $this->ability->executeStatus( array( 'flow_id' => $this->flow_id ) );
		$this->assertSame( 'stripe', $status['preset'] );
	}

	/* =================================================================
	 * Helpers
	 * =================================================================
	 */

	private function register_stripe_preset(): void {
		add_filter( 'datamachine_webhook_auth_presets', function ( $p ) {
			$p['stripe'] = array(
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
			);
			return $p;
		} );
	}

	private function make_request( string $body, array $headers ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/datamachine/v1/trigger/' . $this->flow_id );
		$request->set_url_params( array( 'flow_id' => $this->flow_id ) );
		$request->set_param( 'flow_id', $this->flow_id );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $k => $v ) {
			$request->set_header( $k, $v );
		}
		$request->set_body( $body );
		return $request;
	}

	private function assert_is_unauthorized( $response ): void {
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 401, $response->get_error_data()['status'] );
	}

	private function assert_not_unauthorized( $response ): void {
		if ( $response instanceof \WP_Error ) {
			$this->assertNotSame(
				401,
				$response->get_error_data()['status'] ?? null,
				'Expected auth pass, got: ' . $response->get_error_message()
			);
		} else {
			$this->assertTrue( true );
		}
	}
}
