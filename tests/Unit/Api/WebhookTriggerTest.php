<?php
/**
 * WebhookTrigger + WebhookTriggerAbility tests.
 *
 * Covers both the Bearer regression path and the new HMAC-SHA256 auth mode,
 * exercised end-to-end through `WebhookTrigger::handle_trigger()`.
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

	private int $pipeline_id;
	private int $flow_id;
	private WebhookTriggerAbility $webhook_ability;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$pipeline = wp_get_ability( 'datamachine/create-pipeline' )
			->execute( array( 'pipeline_name' => 'WebhookTrigger test pipeline' ) );
		$this->pipeline_id = (int) $pipeline['pipeline_id'];

		$flow = wp_get_ability( 'datamachine/create-flow' )
			->execute( array( 'pipeline_id' => $this->pipeline_id, 'flow_name' => 'WebhookTrigger test flow' ) );
		$this->flow_id = (int) $flow['flow_id'];

		$this->webhook_ability = new WebhookTriggerAbility();
	}

	public function tear_down(): void {
		delete_transient( 'dm_webhook_rate_' . $this->flow_id );
		parent::tear_down();
	}

	/* -----------------------------------------------------------------
	 * Ability-level behavior
	 * -----------------------------------------------------------------
	 */

	public function test_enable_defaults_to_bearer_mode(): void {
		$result = $this->webhook_ability->executeEnable( array( 'flow_id' => $this->flow_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'bearer', $result['auth_mode'] );
		$this->assertNotEmpty( $result['token'] );
		$this->assertArrayNotHasKey( 'secret', $result );
	}

	public function test_enable_with_hmac_generates_secret(): void {
		$result = $this->webhook_ability->executeEnable(
			array(
				'flow_id'         => $this->flow_id,
				'auth_mode'       => 'hmac_sha256',
				'generate_secret' => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'hmac_sha256', $result['auth_mode'] );
		$this->assertNotEmpty( $result['secret'] );
		$this->assertSame( 'X-Hub-Signature-256', $result['signature_header'] );
		$this->assertSame( 'sha256=hex', $result['signature_format'] );
	}

	public function test_enable_with_hmac_accepts_explicit_secret_and_custom_header(): void {
		$result = $this->webhook_ability->executeEnable(
			array(
				'flow_id'          => $this->flow_id,
				'auth_mode'        => 'hmac_sha256',
				'secret'           => 'explicit-shopify-secret',
				'signature_header' => 'X-Shopify-Hmac-Sha256',
				'signature_format' => 'base64',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'explicit-shopify-secret', $result['secret'] );
		$this->assertSame( 'X-Shopify-Hmac-Sha256', $result['signature_header'] );
		$this->assertSame( 'base64', $result['signature_format'] );
	}

	public function test_status_never_returns_secret(): void {
		$this->webhook_ability->executeEnable(
			array(
				'flow_id'         => $this->flow_id,
				'auth_mode'       => 'hmac_sha256',
				'generate_secret' => true,
			)
		);

		$status = $this->webhook_ability->executeStatus( array( 'flow_id' => $this->flow_id ) );

		$this->assertTrue( $status['success'] );
		$this->assertTrue( $status['webhook_enabled'] );
		$this->assertSame( 'hmac_sha256', $status['auth_mode'] );
		$this->assertSame( 'X-Hub-Signature-256', $status['signature_header'] );
		$this->assertSame( 'sha256=hex', $status['signature_format'] );
		$this->assertArrayNotHasKey( 'secret', $status );
		$this->assertArrayNotHasKey( 'webhook_secret', $status );
	}

	public function test_set_secret_rotates_and_switches_to_hmac(): void {
		$this->webhook_ability->executeEnable( array( 'flow_id' => $this->flow_id ) ); // bearer

		$result = $this->webhook_ability->executeSetSecret(
			array(
				'flow_id'  => $this->flow_id,
				'generate' => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'hmac_sha256', $result['auth_mode'] );
		$this->assertNotEmpty( $result['secret'] );

		$status = $this->webhook_ability->executeStatus( array( 'flow_id' => $this->flow_id ) );
		$this->assertSame( 'hmac_sha256', $status['auth_mode'] );
	}

	public function test_set_secret_requires_input(): void {
		$result = $this->webhook_ability->executeSetSecret( array( 'flow_id' => $this->flow_id ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'secret', $result['error'] );
	}

	public function test_regenerate_rejects_hmac_mode(): void {
		$this->webhook_ability->executeEnable(
			array(
				'flow_id'         => $this->flow_id,
				'auth_mode'       => 'hmac_sha256',
				'generate_secret' => true,
			)
		);

		$result = $this->webhook_ability->executeRegenerate( array( 'flow_id' => $this->flow_id ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'bearer', $result['error'] );
	}

	public function test_disable_clears_hmac_fields(): void {
		$this->webhook_ability->executeEnable(
			array(
				'flow_id'         => $this->flow_id,
				'auth_mode'       => 'hmac_sha256',
				'generate_secret' => true,
			)
		);

		$this->webhook_ability->executeDisable( array( 'flow_id' => $this->flow_id ) );

		$config = $this->get_scheduling_config();
		$this->assertArrayNotHasKey( 'webhook_secret', $config );
		$this->assertArrayNotHasKey( 'webhook_auth_mode', $config );
		$this->assertArrayNotHasKey( 'webhook_signature_header', $config );
	}

	/* -----------------------------------------------------------------
	 * handle_trigger() — Bearer regression path
	 * -----------------------------------------------------------------
	 */

	public function test_bearer_flow_still_works(): void {
		$result = $this->webhook_ability->executeEnable( array( 'flow_id' => $this->flow_id ) );
		$token  = $result['token'];

		$request = $this->make_request( array(), array( 'Authorization' => 'Bearer ' . $token ) );
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assert_not_unauthorized( $response );
	}

	public function test_bearer_missing_token_returns_401(): void {
		$this->webhook_ability->executeEnable( array( 'flow_id' => $this->flow_id ) );

		$request  = $this->make_request();
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assert_is_unauthorized( $response );
	}

	public function test_bearer_wrong_token_returns_401(): void {
		$this->webhook_ability->executeEnable( array( 'flow_id' => $this->flow_id ) );

		$request = $this->make_request(
			array(),
			array( 'Authorization' => 'Bearer ' . str_repeat( 'a', 64 ) )
		);
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assert_is_unauthorized( $response );
	}

	/* -----------------------------------------------------------------
	 * handle_trigger() — HMAC-SHA256 path
	 * -----------------------------------------------------------------
	 */

	public function test_hmac_valid_signature_passes(): void {
		$enable = $this->webhook_ability->executeEnable(
			array(
				'flow_id'         => $this->flow_id,
				'auth_mode'       => 'hmac_sha256',
				'generate_secret' => true,
			)
		);
		$secret = $enable['secret'];
		$body   = '{"action":"opened","number":1}';
		$sig    = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$request  = $this->make_request_raw( $body, array( 'X-Hub-Signature-256' => $sig ) );
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assert_not_unauthorized( $response );
	}

	public function test_hmac_invalid_signature_returns_401(): void {
		$this->webhook_ability->executeEnable(
			array(
				'flow_id'         => $this->flow_id,
				'auth_mode'       => 'hmac_sha256',
				'generate_secret' => true,
			)
		);

		$body = '{"action":"opened"}';
		$bad  = 'sha256=' . str_repeat( '0', 64 );

		$request  = $this->make_request_raw( $body, array( 'X-Hub-Signature-256' => $bad ) );
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assert_is_unauthorized( $response );
	}

	public function test_hmac_missing_signature_header_returns_401(): void {
		$this->webhook_ability->executeEnable(
			array(
				'flow_id'         => $this->flow_id,
				'auth_mode'       => 'hmac_sha256',
				'generate_secret' => true,
			)
		);

		$request  = $this->make_request_raw( '{"x":1}' );
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assert_is_unauthorized( $response );
	}

	public function test_hmac_oversized_body_returns_413(): void {
		$enable = $this->webhook_ability->executeEnable(
			array(
				'flow_id'         => $this->flow_id,
				'auth_mode'       => 'hmac_sha256',
				'generate_secret' => true,
			)
		);
		$secret = $enable['secret'];

		// Lower the max to something tiny.
		$config                            = $this->get_scheduling_config();
		$config['webhook_max_body_bytes']  = 16;
		$this->update_scheduling_config( $config );

		$body = str_repeat( 'a', 128 );
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$request  = $this->make_request_raw( $body, array( 'X-Hub-Signature-256' => $sig ) );
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'payload_too_large', $response->get_error_code() );
		$this->assertSame( 413, $response->get_error_data()['status'] );
	}

	public function test_hmac_wrong_body_signature_rejected(): void {
		$enable = $this->webhook_ability->executeEnable(
			array(
				'flow_id'         => $this->flow_id,
				'auth_mode'       => 'hmac_sha256',
				'generate_secret' => true,
			)
		);
		$secret = $enable['secret'];

		$signed_body = '{"a":1}';
		$sent_body   = '{"a":2}'; // tampered
		$sig         = 'sha256=' . hash_hmac( 'sha256', $signed_body, $secret );

		$request  = $this->make_request_raw( $sent_body, array( 'X-Hub-Signature-256' => $sig ) );
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assert_is_unauthorized( $response );
	}

	public function test_missing_auth_mode_defaults_to_bearer(): void {
		// Manually enable webhook without setting webhook_auth_mode — mimics flows
		// created before HMAC support landed.
		$db     = new Flows();
		$config = array(
			'webhook_enabled'    => true,
			'webhook_token'      => WebhookTriggerAbility::generate_token(),
			'webhook_created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		$db->update_flow( $this->flow_id, array( 'scheduling_config' => $config ) );

		$request  = $this->make_request( array(), array( 'Authorization' => 'Bearer ' . $config['webhook_token'] ) );
		$response = WebhookTrigger::handle_trigger( $request );

		$this->assert_not_unauthorized( $response );
	}

	/* -----------------------------------------------------------------
	 * Helpers
	 * -----------------------------------------------------------------
	 */

	private function make_request( array $body = array(), array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/datamachine/v1/trigger/' . $this->flow_id );
		$request->set_url_params( array( 'flow_id' => $this->flow_id ) );
		$request->set_param( 'flow_id', $this->flow_id );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		if ( ! empty( $body ) ) {
			$json = wp_json_encode( $body );
			$request->set_body( $json );
		}
		return $request;
	}

	private function make_request_raw( string $body, array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/datamachine/v1/trigger/' . $this->flow_id );
		$request->set_url_params( array( 'flow_id' => $this->flow_id ) );
		$request->set_param( 'flow_id', $this->flow_id );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		$request->set_body( $body );
		return $request;
	}

	private function get_scheduling_config(): array {
		$db   = new Flows();
		$flow = $db->get_flow( $this->flow_id );
		return $flow['scheduling_config'] ?? array();
	}

	private function update_scheduling_config( array $config ): void {
		$db = new Flows();
		$db->update_flow( $this->flow_id, array( 'scheduling_config' => $config ) );
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
				'Expected request to authenticate, got: ' . $response->get_error_message()
			);
		} else {
			$this->assertTrue( true ); // successful response
		}
	}
}
