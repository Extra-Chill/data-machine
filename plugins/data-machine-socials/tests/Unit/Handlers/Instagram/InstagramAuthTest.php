<?php
/**
 * InstagramAuth Tests
 *
 * Tests for Instagram OAuth2 provider: do_refresh_token() override,
 * token storage conventions, and integration with BaseOAuth2Provider lifecycle.
 *
 * @package DataMachineSocials\Tests\Unit\Handlers\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Handlers\Instagram;

use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramAuthTest extends WP_UnitTestCase {

	private InstagramAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new InstagramAuth();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		wp_clear_scheduled_hook( $this->auth->get_cron_hook_name() );
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	/*
	 * -------------------------------------------------------------------------
	 * Provider identity
	 * -------------------------------------------------------------------------
	 */

	public function test_provider_slug_is_instagram(): void {
		$this->assertSame( 'datamachine_refresh_token_instagram', $this->auth->get_cron_hook_name() );
	}

	public function test_graph_api_url_constant(): void {
		$this->assertSame( 'https://graph.instagram.com', InstagramAuth::GRAPH_API_URL );
	}

	/*
	 * -------------------------------------------------------------------------
	 * is_configured()
	 * -------------------------------------------------------------------------
	 */

	public function test_is_configured_returns_true_with_app_credentials(): void {
		$this->auth->save_config( array(
			'app_id'     => 'id_123',
			'app_secret' => 'secret_456',
		) );

		$this->assertTrue( $this->auth->is_configured() );
	}

	public function test_is_configured_returns_false_without_credentials(): void {
		$this->assertFalse( $this->auth->is_configured() );
	}

	public function test_is_configured_returns_false_with_partial_credentials(): void {
		$this->auth->save_config( array( 'app_id' => 'id_123' ) );

		$this->assertFalse( $this->auth->is_configured() );
	}

	/*
	 * -------------------------------------------------------------------------
	 * is_authenticated() — inherited from base class
	 * -------------------------------------------------------------------------
	 */

	public function test_is_authenticated_returns_true_with_valid_token(): void {
		$this->auth->save_account( array(
			'access_token'    => 'ig_tok_123',
			'token_expires_at' => time() + 3600,
		) );

		$this->assertTrue( $this->auth->is_authenticated() );
	}

	public function test_is_authenticated_returns_false_when_expired(): void {
		$this->auth->save_account( array(
			'access_token'    => 'ig_tok_123',
			'token_expires_at' => time() - 100,
		) );

		$this->assertFalse( $this->auth->is_authenticated() );
	}

	public function test_is_authenticated_returns_false_with_no_account(): void {
		$this->assertFalse( $this->auth->is_authenticated() );
	}

	/*
	 * -------------------------------------------------------------------------
	 * get_user_id() and get_username()
	 * -------------------------------------------------------------------------
	 */

	public function test_get_user_id_returns_stored_id(): void {
		$this->auth->save_account( array(
			'access_token' => 'tok',
			'user_id'      => '12345',
		) );

		$this->assertSame( '12345', $this->auth->get_user_id() );
	}

	public function test_get_user_id_returns_null_with_no_account(): void {
		$this->assertNull( $this->auth->get_user_id() );
	}

	public function test_get_username_returns_stored_username(): void {
		$this->auth->save_account( array(
			'access_token' => 'tok',
			'username'     => 'extrachill',
		) );

		$this->assertSame( 'extrachill', $this->auth->get_username() );
	}

	/*
	 * -------------------------------------------------------------------------
	 * do_refresh_token() via get_valid_access_token()
	 * -------------------------------------------------------------------------
	 */

	public function test_refresh_calls_instagram_api_with_ig_refresh_token(): void {
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'ig_refreshed_tok',
					'expires_in'   => 5184000,
				) ),
			);
		}, 10, 3 );

		$this->auth->save_account( array(
			'access_token'    => 'ig_old_tok',
			'token_expires_at' => time() - 100, // Expired — triggers refresh.
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertSame( 'ig_refreshed_tok', $token );
		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'graph.instagram.com/refresh_access_token', $captured_url );
		$this->assertStringContainsString( 'grant_type=ig_refresh_token', $captured_url );
		$this->assertStringContainsString( 'access_token=ig_old_tok', $captured_url );
	}

	public function test_legacy_refresh_uses_fb_exchange_token_grant(): void {
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'fb_refreshed_tok',
					'expires_in'   => 5184000,
				) ),
			);
		}, 10, 3 );

		$this->auth->save_config( array(
			'app_id'     => 'app_123',
			'app_secret' => 'secret_456',
		) );
		$this->auth->save_account( array(
			'access_token'    => 'fb_flavored_tok',
			'token_expires_at' => time() - 100, // Expired — triggers refresh.
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertSame( 'fb_refreshed_tok', $token );
		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'graph.facebook.com/v23.0/oauth/access_token', $captured_url );
		$this->assertStringContainsString( 'grant_type=fb_exchange_token', $captured_url );
		$this->assertStringContainsString( 'fb_exchange_token=fb_flavored_tok', $captured_url );
	}

	public function test_refresh_falls_back_to_ig_refresh_token_when_fb_exchange_fails(): void {
		$urls = array();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$urls ) {
			$urls[] = $url;
			if ( str_contains( $url, 'graph.facebook.com' ) ) {
				return array(
					'response' => array( 'code' => 400 ),
					'body'     => wp_json_encode( array(
						'error' => array( 'message' => 'Invalid OAuth access token' ),
					) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'ig_refreshed_tok',
					'expires_in'   => 5184000,
				) ),
			);
		}, 10, 3 );

		$this->auth->save_config( array(
			'app_id'     => 'app_123',
			'app_secret' => 'secret_456',
		) );
		$this->auth->save_account( array(
			'access_token'    => 'ig_basic_display_tok',
			'token_expires_at' => time() - 100,
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertSame( 'ig_refreshed_tok', $token );
		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( 'graph.facebook.com', $urls[0] );
		$this->assertStringContainsString( 'grant_type=fb_exchange_token', $urls[0] );
		$this->assertStringContainsString( 'graph.instagram.com/refresh_access_token', $urls[1] );
		$this->assertStringContainsString( 'grant_type=ig_refresh_token', $urls[1] );
	}

	public function test_successful_refresh_updates_stored_account(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'ig_new_tok',
					'expires_in'   => 5184000,
				) ),
			);
		} );

		$this->auth->save_account( array(
			'access_token'    => 'ig_old_tok',
			'user_id'         => '12345',
			'username'        => 'extrachill',
			'token_expires_at' => time() - 100,
		) );

		$this->auth->get_valid_access_token();

		$account = $this->auth->get_account();
		$this->assertSame( 'ig_new_tok', $account['access_token'] );
		$this->assertSame( '12345', $account['user_id'] );
		$this->assertSame( 'extrachill', $account['username'] );
		$this->assertGreaterThan( time() + 5000000, $account['token_expires_at'] );
		$this->assertArrayHasKey( 'last_refreshed_at', $account );
	}

	public function test_failed_refresh_returns_null_when_expired(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 400 ),
				'body'     => wp_json_encode( array(
					'error' => array( 'message' => 'Token is invalid' ),
				) ),
			);
		} );

		$this->auth->save_account( array(
			'access_token'    => 'ig_dead_tok',
			'token_expires_at' => time() - 100,
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertNull( $token );
	}

	public function test_no_refresh_when_token_is_fresh(): void {
		$refresh_called = false;

		add_filter( 'pre_http_request', function () use ( &$refresh_called ) {
			$refresh_called = true;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
			);
		} );

		$this->auth->save_account( array(
			'access_token'    => 'ig_fresh_tok',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertSame( 'ig_fresh_tok', $token );
		$this->assertFalse( $refresh_called );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Page access token (non-expiring)
	 * -------------------------------------------------------------------------
	 */

	public function test_page_token_takes_precedence_over_expired_user_token(): void {
		$http_called = false;

		add_filter( 'pre_http_request', function () use ( &$http_called ) {
			$http_called = true;
			return array(
				'response' => array( 'code' => 500 ),
				'body'     => '{}',
			);
		} );

		$this->auth->save_account( array(
			'access_token'      => 'fb_user_tok',
			'page_access_token' => 'page_tok_abc',
			'page_id'           => '998877',
			'token_expires_at'  => time() - 100, // User token long expired.
		) );

		$this->assertSame( 'page_tok_abc', $this->auth->get_valid_access_token() );
		$this->assertFalse( $http_called );
	}

	public function test_get_page_accessors_return_stored_values(): void {
		$this->auth->save_account( array(
			'access_token'      => 'fb_user_tok',
			'page_access_token' => 'page_tok_abc',
			'page_id'           => '998877',
		) );

		$this->assertSame( '998877', $this->auth->get_page_id() );
		$this->assertSame( 'page_tok_abc', $this->auth->get_page_access_token() );
	}

	public function test_get_page_accessors_return_null_without_page_token(): void {
		$this->auth->save_account( array( 'access_token' => 'tok' ) );

		$this->assertNull( $this->auth->get_page_id() );
		$this->assertNull( $this->auth->get_page_access_token() );
	}

	public function test_is_authenticated_with_page_token_and_expired_user_token(): void {
		$this->auth->save_account( array(
			'access_token'      => 'fb_user_tok',
			'page_access_token' => 'page_tok_abc',
			'token_expires_at'  => time() - 100,
		) );

		$this->assertTrue( $this->auth->is_authenticated() );
	}

	public function test_page_token_account_skips_proactive_refresh(): void {
		$this->auth->save_account( array(
			'access_token'      => 'fb_user_tok',
			'page_access_token' => 'page_tok_abc',
			'token_expires_at'  => time() + ( 30 * DAY_IN_SECONDS ),
		) );

		// Pre-schedule via the legacy path to prove the no-op clears it.
		wp_schedule_single_event( time() + 100, $this->auth->get_cron_hook_name() );

		$this->assertFalse( $this->auth->schedule_proactive_refresh() );
		$this->assertFalse( wp_next_scheduled( $this->auth->get_cron_hook_name() ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Proactive refresh scheduling
	 * -------------------------------------------------------------------------
	 */

	public function test_successful_refresh_schedules_cron(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'ig_new_tok',
					'expires_in'   => 5184000,
				) ),
			);
		} );

		$this->auth->save_account( array(
			'access_token'    => 'ig_old_tok',
			'token_expires_at' => time() - 100,
		) );

		$this->auth->get_valid_access_token();

		$next = wp_next_scheduled( $this->auth->get_cron_hook_name() );
		$this->assertNotFalse( $next );
	}

	/*
	 * -------------------------------------------------------------------------
	 * get_live_page_subscription_status() (#265)
	 * -------------------------------------------------------------------------
	 */

	public function test_live_page_subscription_status_returns_null_without_page_context(): void {
		$this->auth->save_account( array( 'access_token' => 'tok' ) );

		$this->assertNull( $this->auth->get_live_page_subscription_status() );
	}

	public function test_live_page_subscription_status_reports_subscribed_with_fields(): void {
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'data' => array(
						array(
							'subscribed_fields' => array( 'messages', 'messaging_postbacks' ),
							'id'                => 'app_123',
						),
					),
				) ),
			);
		}, 10, 3 );

		$this->auth->save_account( array(
			'access_token'      => 'fb_user_tok',
			'page_access_token' => 'page_tok_abc',
			'page_id'           => '998877',
		) );

		$status = $this->auth->get_live_page_subscription_status();

		$this->assertNotNull( $status );
		$this->assertTrue( $status['subscribed'] );
		$this->assertSame( array( 'messages', 'messaging_postbacks' ), $status['fields'] );
		$this->assertStringContainsString( '998877/subscribed_apps', $captured_url );
		$this->assertStringContainsString( 'access_token=page_tok_abc', $captured_url );
	}

	public function test_live_page_subscription_status_reports_not_subscribed_when_empty(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
			);
		} );

		$this->auth->save_account( array(
			'access_token'      => 'fb_user_tok',
			'page_access_token' => 'page_tok_abc',
			'page_id'           => '998877',
		) );

		$status = $this->auth->get_live_page_subscription_status();

		$this->assertNotNull( $status );
		$this->assertFalse( $status['subscribed'] );
		$this->assertSame( array(), $status['fields'] );
	}

	public function test_live_page_subscription_status_returns_null_on_http_failure(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 500 ),
				'body'     => '{}',
			);
		} );

		$this->auth->save_account( array(
			'access_token'      => 'fb_user_tok',
			'page_access_token' => 'page_tok_abc',
			'page_id'           => '998877',
		) );

		$this->assertNull( $this->auth->get_live_page_subscription_status() );
	}

	/*
	 * -------------------------------------------------------------------------
	 * remove_account() cleanup
	 * -------------------------------------------------------------------------
	 */

	public function test_remove_account_clears_data_and_cron(): void {
		$this->auth->save_account( array(
			'access_token'    => 'ig_tok',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );
		$this->auth->schedule_proactive_refresh();

		$this->auth->remove_account();

		$this->assertEmpty( $this->auth->get_account() );
		$this->assertFalse( wp_next_scheduled( $this->auth->get_cron_hook_name() ) );
	}
}
