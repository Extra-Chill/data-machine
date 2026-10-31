<?php
/**
 * YouTube OAuth provider tests.
 *
 * @package DataMachineSocials\Tests\Unit\Handlers\YouTube
 */

namespace DataMachineSocials\Tests\Unit\Handlers\YouTube;

use DataMachineSocials\Handlers\YouTube\YouTubeAuth;
use WP_UnitTestCase;

class YouTubeAuthTest extends WP_UnitTestCase {

	private YouTubeAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new YouTubeAuth();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		wp_clear_scheduled_hook( $this->auth->get_cron_hook_name() );
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_provider_uses_youtube_slug_and_required_scopes(): void {
		$this->assertSame( 'datamachine_refresh_token_youtube', $this->auth->get_cron_hook_name() );
		$this->assertStringContainsString( 'youtube.upload', YouTubeAuth::SCOPES );
		$this->assertStringContainsString( 'youtube.force-ssl', YouTubeAuth::SCOPES );
	}

	public function test_refreshes_google_access_token_and_preserves_refresh_token(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			$this->assertSame( YouTubeAuth::TOKEN_URL, $url );
			$this->assertSame( 'refresh_token', $args['body']['grant_type'] );
			$this->assertSame( 'youtube_refresh', $args['body']['refresh_token'] );

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'youtube_new_token',
					'expires_in'   => 3600,
				) ),
			);
		}, 10, 3 );

		$this->auth->save_config( array(
			'client_id'     => 'youtube_client',
			'client_secret' => 'youtube_secret',
		) );
		$this->auth->save_account( array(
			'access_token'     => 'youtube_old_token',
			'refresh_token'    => 'youtube_refresh',
			'token_expires_at' => time() - 1,
		) );

		$this->assertSame( 'youtube_new_token', $this->auth->get_valid_access_token() );
		$this->assertSame( 'youtube_refresh', $this->auth->get_account()['refresh_token'] );
	}
}
