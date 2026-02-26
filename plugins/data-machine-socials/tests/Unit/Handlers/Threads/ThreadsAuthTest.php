<?php
/**
 * ThreadsAuth Tests
 *
 * Tests for Threads OAuth2 provider: do_refresh_token() override,
 * token storage conventions, and integration with BaseOAuth2Provider lifecycle.
 *
 * @package DataMachineSocials\Tests\Unit\Handlers\Threads
 */

namespace DataMachineSocials\Tests\Unit\Handlers\Threads;

use DataMachineSocials\Handlers\Threads\ThreadsAuth;
use WP_UnitTestCase;

class ThreadsAuthTest extends WP_UnitTestCase {

	private ThreadsAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new ThreadsAuth();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		wp_clear_scheduled_hook( $this->auth->get_cron_hook_name() );
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Provider identity
	// -------------------------------------------------------------------------

	public function test_provider_slug_is_threads(): void {
		$this->assertSame( 'datamachine_refresh_token_threads', $this->auth->get_cron_hook_name() );
	}

	public function test_refresh_url_constant(): void {
		$this->assertSame( 'https://graph.threads.net/refresh_access_token', ThreadsAuth::REFRESH_URL );
	}

	// -------------------------------------------------------------------------
	// is_configured()
	// -------------------------------------------------------------------------

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

	// -------------------------------------------------------------------------
	// is_authenticated() — inherited from base class
	// -------------------------------------------------------------------------

	public function test_is_authenticated_returns_true_with_valid_token(): void {
		$this->auth->save_account( array(
			'access_token'    => 'th_tok_123',
			'token_expires_at' => time() + 3600,
		) );

		$this->assertTrue( $this->auth->is_authenticated() );
	}

	public function test_is_authenticated_returns_false_when_expired(): void {
		$this->auth->save_account( array(
			'access_token'    => 'th_tok_123',
			'token_expires_at' => time() - 100,
		) );

		$this->assertFalse( $this->auth->is_authenticated() );
	}

	// -------------------------------------------------------------------------
	// get_page_id()
	// -------------------------------------------------------------------------

	public function test_get_page_id_returns_stored_id(): void {
		$this->auth->save_account( array(
			'access_token' => 'tok',
			'page_id'      => '67890',
		) );

		$this->assertSame( '67890', $this->auth->get_page_id() );
	}

	public function test_get_page_id_returns_null_with_no_account(): void {
		$this->assertNull( $this->auth->get_page_id() );
	}

	// -------------------------------------------------------------------------
	// do_refresh_token() via get_valid_access_token()
	// -------------------------------------------------------------------------

	public function test_refresh_calls_threads_api_with_th_refresh_token(): void {
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'th_refreshed_tok',
					'expires_in'   => 5184000,
				) ),
			);
		}, 10, 3 );

		$this->auth->save_account( array(
			'access_token'    => 'th_old_tok',
			'token_expires_at' => time() - 100,
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertSame( 'th_refreshed_tok', $token );
		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'graph.threads.net/refresh_access_token', $captured_url );
		$this->assertStringContainsString( 'grant_type=th_refresh_token', $captured_url );
		$this->assertStringContainsString( 'access_token=th_old_tok', $captured_url );
	}

	public function test_successful_refresh_updates_stored_account(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'th_new_tok',
					'expires_in'   => 5184000,
				) ),
			);
		} );

		$this->auth->save_account( array(
			'access_token'    => 'th_old_tok',
			'page_id'         => '67890',
			'page_name'       => 'Extra Chill',
			'token_expires_at' => time() - 100,
		) );

		$this->auth->get_valid_access_token();

		$account = $this->auth->get_account();
		$this->assertSame( 'th_new_tok', $account['access_token'] );
		$this->assertSame( '67890', $account['page_id'] );
		$this->assertSame( 'Extra Chill', $account['page_name'] );
		$this->assertGreaterThan( time() + 5000000, $account['token_expires_at'] );
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
			'access_token'    => 'th_dead_tok',
			'token_expires_at' => time() - 100,
		) );

		$this->assertNull( $this->auth->get_valid_access_token() );
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
			'access_token'    => 'th_fresh_tok',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertSame( 'th_fresh_tok', $token );
		$this->assertFalse( $refresh_called );
	}

	// -------------------------------------------------------------------------
	// remove_account() with token revocation
	// -------------------------------------------------------------------------

	public function test_remove_account_clears_data(): void {
		// Mock the revocation call.
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"success":true}',
			);
		} );

		$this->auth->save_account( array(
			'access_token'    => 'th_tok',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );
		$this->auth->schedule_proactive_refresh();

		$this->auth->remove_account();

		$this->assertEmpty( $this->auth->get_account() );
		$this->assertFalse( wp_next_scheduled( $this->auth->get_cron_hook_name() ) );
	}
}
