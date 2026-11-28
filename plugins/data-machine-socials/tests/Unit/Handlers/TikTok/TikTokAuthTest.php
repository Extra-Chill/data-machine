<?php
/**
 * TikTokAuth Tests.
 *
 * @package DataMachineSocials\Tests\Unit\Handlers\TikTok
 */

namespace DataMachineSocials\Tests\Unit\Handlers\TikTok;

use DataMachineSocials\Handlers\TikTok\TikTokAuth;
use WP_UnitTestCase;

class TikTokAuthTest extends WP_UnitTestCase {

	private TikTokAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new TikTokAuth();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		wp_clear_scheduled_hook( $this->auth->get_cron_hook_name() );
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_provider_slug_is_tiktok(): void {
		$this->assertSame( 'datamachine_refresh_token_tiktok', $this->auth->get_cron_hook_name() );
	}

	public function test_is_configured_requires_client_key_and_secret(): void {
		$this->assertFalse( $this->auth->is_configured() );
		$this->auth->save_config( array( 'client_key' => 'client_key' ) );
		$this->assertFalse( $this->auth->is_configured() );
		$this->auth->save_config( array( 'client_key' => 'client_key', 'client_secret' => 'client_secret' ) );
		$this->assertTrue( $this->auth->is_configured() );
	}

	public function test_refresh_uses_refresh_grant_and_persists_rotated_token(): void {
		$captured_body = array();
		add_filter( 'pre_http_request', function ( $preempt, $args ) use ( &$captured_body ) {
			$captured_body = $args['body'];
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'access_token'       => 'new_access_token',
						'refresh_token'      => 'rotated_refresh_token',
						'expires_in'         => 86400,
						'refresh_expires_in' => 31536000,
					)
				),
			);
		}, 10, 2 );

		$this->auth->save_config( array( 'client_key' => 'client_key', 'client_secret' => 'client_secret' ) );
		$this->auth->save_account(
			array(
				'access_token'    => 'old_access_token',
				'refresh_token'   => 'old_refresh_token',
				'token_expires_at' => time() - 1,
			)
		);

		$this->assertSame( 'new_access_token', $this->auth->get_valid_access_token() );
		$this->assertSame( 'refresh_token', $captured_body['grant_type'] );
		$this->assertSame( 'old_refresh_token', $captured_body['refresh_token'] );
		$this->assertSame( 'rotated_refresh_token', $this->auth->get_account()['refresh_token'] );
	}
}
