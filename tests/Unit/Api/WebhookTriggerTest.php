<?php
/**
 * WebhookTrigger + WebhookTriggerAbility integration tests.
 *
 * Exercises the full end-to-end flow through the REST handler using the
 * template-based verifier. Includes:
 * - Bearer regression (unchanged from v1).
 * - HMAC via preset (core ships zero presets; we register one in test setup).
 * - HMAC via explicit template.
 * - Silent v1→v2 migration for legacy flows.
 * - Rotation lifecycle.
 * - Status never leaks secrets.
 *
 * @package DataMachine\Tests\Unit\Api
 */

namespace DataMachine\Tests\Unit\Api;

use DataMachine\Abilities\Flow\WebhookTriggerAbility;
use DataMachine\Api\WebhookTrigger;
use DataMachine\Core\Database\Flows\Flows;
use WP_REST_Request;
use WP_UnitTestCase;

class WebhookTriggerTest extends WP_UnitTestCase {

	private int $flow_id;
	private WebhookTriggerAbility $ability;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$pipeline      = wp_get_ability( 'datamachine/create-pipeline' )->execute( array( 'pipeline_name' => 'Test Pipeline' ) );
		$flow          = wp_get_ability( 'datamachine/create-flow' )->execute( array(
			'pipeline_id' => (int) $pipeline['pipeline_id'],
			'flow_name'   => 'Test Flow',
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
	 * Ability surface
	 * =================================================================
	 */

	public function test_enable_defaults_to_bearer(): void {
		$result = $this->ability->executeEnable( array( 'flow_id' => $this->flow_id ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'bearer', $result['auth_mode'] );
		$this->assertNotEmpty( $result['token'] );
	}

	public function test_enable_hmac_requires_preset_or_template(): void {
		$result = $this->ability->executeEnable( array(
			'flow_id'   => $this->flow_id,
			'auth_mode' => 'hmac',
		) );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'preset', $result['error'] );
	}

	public function test_enable_hmac_with_unknown_preset_errors(): void {
		$result = $this->ability->executeEnable( array(
			'flow_id' => $this->flow_id,
			'preset'  => 'does-not-exist',
		) );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unknown preset', $result['error'] );
	}

	public function test_enable_hmac_with_preset_generates_secret(): void {
		$this->register_example_preset();
		$result = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'example',
			'generate_secret' => true,
		) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'hmac', $result['auth_mode'] );
		$this->assertNotEmpty( $result['secret'] );

		// The stored config carries the full resolved template (no preset name leaks in).
		$config = $this->get_scheduling_config();
		$this->assertSame( 'hmac', $config['webhook_auth_mode'] );
		$this->assertSame( '{body}', $config['webhook_auth']['signed_template'] );
		$this->assertArrayNotHasKey( 'webhook_auth_preset', $config, 'preset name must not leak into stored config' );
	}

	public function test_enable_hmac_with_explicit_template(): void {
		$template = array(
			'mode'             => 'hmac',
			'algo'             => 'sha256',
			'signed_template'  => '{body}',
			'signature_source' => array(
				'header'   => 'X-Sig',
				'extract'  => array( 'kind' => 'raw' ),
				'encoding' => 'hex',
			),
		);
		$result = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'template'        => $template,
			'generate_secret' => true,
		) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'hmac', $result['auth_mode'] );
	}

	public function test_enable_hmac_template_overrides_deep_merge(): void {
		$this->register_example_preset();
		$result = $this->ability->executeEnable( array(
			'flow_id'            => $this->flow_id,
			'preset'             => 'example',
			'generate_secret'    => true,
			'template_overrides' => array(
				'tolerance_seconds' => 60,
			),
		) );
		$this->assertTrue( $result['success'] );
		$config = $this->get_scheduling_config();
		$this->assertSame( 60, $config['webhook_auth']['tolerance_seconds'] );
	}

	public function test_status_never_returns_secret_values(): void {
		$this->register_example_preset();
		$this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'example',
			'generate_secret' => true,
		) );
		$status = $this->ability->executeStatus( array( 'flow_id' => $this->flow_id ) );

		$this->assertTrue( $status['success'] );
		$this->assertSame( 'hmac', $status['auth_mode'] );
		$this->assertArrayHasKey( 'template', $status );
		$this->assertArrayHasKey( 'secret_ids', $status );
		$this->assertArrayNotHasKey( 'secret', $status );
		$this->assertArrayNotHasKey( 'webhook_secret', $status );

		$encoded = wp_json_encode( $status );
		$this->assertStringNotContainsString( '"value"', $encoded );
	}

	public function test_set_secret_rejects_flow_without_template(): void {
		// Enable in bearer mode so there's no HMAC template yet.
		$this->ability->executeEnable( array( 'flow_id' => $this->flow_id ) );

		$result = $this->ability->executeSetSecret( array(
			'flow_id'  => $this->flow_id,
			'generate' => true,
		) );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'template', $result['error'] );
	}

	public function test_rotate_keeps_previous_secret_verifying(): void {
		$this->register_example_preset();
		$enable     = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'example',
			'generate_secret' => true,
		) );
		$old_secret = $enable['secret'];

		$rotated = $this->ability->executeRotateSecret( array(
			'flow_id'              => $this->flow_id,
			'generate'             => true,
			'previous_ttl_seconds' => 3600,
		) );
		$this->assertTrue( $rotated['success'] );

		// A request signed with the OLD secret still verifies during the grace window.
		$body = '{"x":1}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, $old_secret );
		$res  = WebhookTrigger::handle_trigger( $this->make_request( $body, array( 'x-hub-signature-256' => $sig ) ) );
		$this->assert_not_unauthorized( $res );

		// And one signed with the NEW secret also verifies.
		$new_sig = 'sha256=' . hash_hmac( 'sha256', $body, $rotated['new_secret'] );
		$res     = WebhookTrigger::handle_trigger( $this->make_request( $body, array( 'x-hub-signature-256' => $new_sig ) ) );
		$this->assert_not_unauthorized( $res );
	}

	public function test_forget_previous_immediately_invalidates(): void {
		$this->register_example_preset();
		$enable     = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'example',
			'generate_secret' => true,
		) );
		$old_secret = $enable['secret'];

		$this->ability->executeRotateSecret( array(
			'flow_id'              => $this->flow_id,
			'generate'             => true,
			'previous_ttl_seconds' => 3600,
		) );

		$this->ability->executeForgetSecret( array(
			'flow_id'   => $this->flow_id,
			'secret_id' => 'previous',
		) );

		$body = '{"x":1}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, $old_secret );
		$res  = WebhookTrigger::handle_trigger( $this->make_request( $body, array( 'x-hub-signature-256' => $sig ) ) );
		$this->assert_is_unauthorized( $res );
	}

	/* =================================================================
	 * REST handler — end to end
	 * =================================================================
	 */

	public function test_bearer_flow_still_works(): void {
		$enable = $this->ability->executeEnable( array( 'flow_id' => $this->flow_id ) );
		$token  = $enable['token'];

		$res = WebhookTrigger::handle_trigger( $this->make_request( '', array( 'authorization' => 'Bearer ' . $token ) ) );
		$this->assert_not_unauthorized( $res );
	}

	public function test_bearer_wrong_token_returns_401(): void {
		$this->ability->executeEnable( array( 'flow_id' => $this->flow_id ) );
		$res = WebhookTrigger::handle_trigger( $this->make_request( '', array( 'authorization' => 'Bearer ' . str_repeat( 'a', 64 ) ) ) );
		$this->assert_is_unauthorized( $res );
	}

	public function test_hmac_valid_signature_passes(): void {
		$this->register_example_preset();
		$enable = $this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'example',
			'generate_secret' => true,
		) );
		$secret = $enable['secret'];

		$body = '{"action":"opened"}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		$res  = WebhookTrigger::handle_trigger( $this->make_request( $body, array( 'x-hub-signature-256' => $sig ) ) );
		$this->assert_not_unauthorized( $res );
	}

	public function test_hmac_invalid_signature_returns_401(): void {
		$this->register_example_preset();
		$this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'example',
			'generate_secret' => true,
		) );
		$res = WebhookTrigger::handle_trigger( $this->make_request(
			'{"x":1}',
			array( 'x-hub-signature-256' => 'sha256=' . str_repeat( '0', 64 ) )
		) );
		$this->assert_is_unauthorized( $res );
	}

	public function test_hmac_missing_signature_header_returns_401(): void {
		$this->register_example_preset();
		$this->ability->executeEnable( array(
			'flow_id'         => $this->flow_id,
			'preset'          => 'example',
			'generate_secret' => true,
		) );
		$res = WebhookTrigger::handle_trigger( $this->make_request( '{"x":1}', array() ) );
		$this->assert_is_unauthorized( $res );
	}

	public function test_hmac_without_template_returns_401_not_github_default(): void {
		// Simulate a flow that claims HMAC mode but has no template — should
		// NOT silently fall back to GitHub-style defaults; should cleanly 401.
		$db     = new Flows();
		$config = array(
			'webhook_enabled'   => true,
			'webhook_auth_mode' => 'hmac',
			'webhook_secrets'   => array( array( 'id' => 'current', 'value' => 'x' ) ),
		);
		$db->update_flow( $this->flow_id, array( 'scheduling_config' => $config ) );

		$body = '{"x":1}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, 'x' );
		$res  = WebhookTrigger::handle_trigger( $this->make_request( $body, array( 'x-hub-signature-256' => $sig ) ) );
		$this->assert_is_unauthorized( $res );
	}

	/* =================================================================
	 * Silent v1 → v2 migration
	 * =================================================================
	 */

	public function test_v1_legacy_flow_migrates_silently_and_still_authenticates(): void {
		// Set a flow to the legacy v1 shape directly in the DB, bypassing the ability.
		$db     = new Flows();
		$secret = 'legacy-secret-value';
		$config = array(
			'webhook_enabled'          => true,
			'webhook_auth_mode'        => 'hmac_sha256',
			'webhook_signature_header' => 'X-Hub-Signature-256',
			'webhook_signature_format' => 'sha256=hex',
			'webhook_secret'           => $secret,
		);
		$db->update_flow( $this->flow_id, array( 'scheduling_config' => $config ) );

		// First request — should succeed.
		$body = '{"legacy":true}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		$res  = WebhookTrigger::handle_trigger( $this->make_request( $body, array( 'x-hub-signature-256' => $sig ) ) );
		$this->assert_not_unauthorized( $res );

		// Config must now be in canonical v2 shape — legacy fields gone, v2 fields present.
		$new = $this->get_scheduling_config();
		$this->assertSame( 'hmac', $new['webhook_auth_mode'] );
		$this->assertArrayHasKey( 'webhook_auth', $new );
		$this->assertArrayNotHasKey( 'webhook_signature_header', $new );
		$this->assertArrayNotHasKey( 'webhook_signature_format', $new );
		$this->assertArrayNotHasKey( 'webhook_secret', $new );

		$this->assertArrayHasKey( 'webhook_secrets', $new );
		$this->assertSame( 'current', $new['webhook_secrets'][0]['id'] );
		$this->assertSame( $secret, $new['webhook_secrets'][0]['value'] );
	}

	/* =================================================================
	 * Safe headers — pattern-based deny-list, no provider names
	 * =================================================================
	 */

	public function test_safe_headers_strip_known_sensitive_patterns(): void {
		$this->ability->executeEnable( array( 'flow_id' => $this->flow_id ) );
		// Bearer flow — get safe headers to verify the deny-list is pattern based.
		$request = new WP_REST_Request( 'POST', '/datamachine/v1/trigger/' . $this->flow_id );
		$request->set_param( 'flow_id', $this->flow_id );
		$request->set_header( 'authorization', 'Bearer x' );
		$request->set_header( 'cookie', 'session=abc' );
		$request->set_header( 'x-my-secret', 'hush' );
		$request->set_header( 'x-random-signature', 'hush' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-github-event', 'push' );
		// Set the body so that the v2 path runs through the handler.
		$request->set_body( '' );

		$reflect = new \ReflectionClass( WebhookTrigger::class );
		$method  = $reflect->getMethod( 'get_safe_headers' );
		$method->setAccessible( true );
		$out = $method->invoke( null, $request );

		// Sensitive headers: filtered out.
		$this->assertArrayNotHasKey( 'authorization', $out );
		$this->assertArrayNotHasKey( 'cookie', $out );
		$this->assertArrayNotHasKey( 'x-my-secret', $out );
		$this->assertArrayNotHasKey( 'x-random-signature', $out );

		// Non-sensitive headers: kept. Provider-specific names like x-github-event
		// are kept because they don't match the deny pattern — not because we
		// hardcoded their names anywhere.
		$this->assertArrayHasKey( 'content-type', $out );
		$this->assertArrayHasKey( 'x-github-event', $out );
	}

	/* =================================================================
	 * Helpers
	 * =================================================================
	 */

	/**
	 * Register an example preset used by multiple tests. The preset is named
	 * `example` — deliberately generic — because DM core doesn't know about
	 * any particular provider.
	 */
	private function register_example_preset(): void {
		add_filter( 'datamachine_webhook_auth_presets', function ( $p ) {
			$p['example'] = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Hub-Signature-256',
					'extract'  => array( 'kind' => 'prefix', 'key' => 'sha256=' ),
					'encoding' => 'hex',
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

	private function get_scheduling_config(): array {
		$db   = new Flows();
		$flow = $db->get_flow( $this->flow_id );
		return $flow['scheduling_config'] ?? array();
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
