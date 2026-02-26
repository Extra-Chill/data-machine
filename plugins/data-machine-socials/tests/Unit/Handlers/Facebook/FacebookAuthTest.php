<?php
/**
 * FacebookAuth Tests
 *
 * Tests for Facebook OAuth2 provider: dual token system, do_refresh_token()
 * override with page credential re-fetch, and get_valid_user_access_token().
 *
 * @package DataMachineSocials\Tests\Unit\Handlers\Facebook
 */

namespace DataMachineSocials\Tests\Unit\Handlers\Facebook;

use DataMachineSocials\Handlers\Facebook\FacebookAuth;
use WP_UnitTestCase;

class FacebookAuthTest extends WP_UnitTestCase {

	private FacebookAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new FacebookAuth();
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

	public function test_provider_slug_is_facebook(): void {
		$this->assertSame( 'datamachine_refresh_token_facebook', $this->auth->get_cron_hook_name() );
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
	// is_authenticated() — dual token system
	// -------------------------------------------------------------------------

	public function test_is_authenticated_requires_both_tokens(): void {
		$this->auth->save_account( array(
			'user_access_token' => 'user_tok',
			'page_access_token' => 'page_tok',
			'token_expires_at'  => time() + 3600,
		) );

		$this->assertTrue( $this->auth->is_authenticated() );
	}

	public function test_is_authenticated_fails_without_user_token(): void {
		$this->auth->save_account( array(
			'page_access_token' => 'page_tok',
			'token_expires_at'  => time() + 3600,
		) );

		$this->assertFalse( $this->auth->is_authenticated() );
	}

	public function test_is_authenticated_fails_without_page_token(): void {
		$this->auth->save_account( array(
			'user_access_token' => 'user_tok',
			'token_expires_at'  => time() + 3600,
		) );

		$this->assertFalse( $this->auth->is_authenticated() );
	}

	public function test_is_authenticated_fails_when_expired(): void {
		$this->auth->save_account( array(
			'user_access_token' => 'user_tok',
			'page_access_token' => 'page_tok',
			'token_expires_at'  => time() - 100,
		) );

		$this->assertFalse( $this->auth->is_authenticated() );
	}

	// -------------------------------------------------------------------------
	// get_page_id()
	// -------------------------------------------------------------------------

	public function test_get_page_id_returns_stored_id(): void {
		$this->auth->save_account( array(
			'user_access_token' => 'tok',
			'page_access_token' => 'tok',
			'page_id'           => 'pg_123',
		) );

		$this->assertSame( 'pg_123', $this->auth->get_page_id() );
	}

	// -------------------------------------------------------------------------
	// do_refresh_token() — user token refresh + page credential re-fetch
	// -------------------------------------------------------------------------

	public function test_refresh_exchanges_user_token_and_refetches_page(): void {
		$captured_urls = array();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_urls ) {
			$captured_urls[] = $url;

			// First call: fb_exchange_token for user token refresh.
			if ( strpos( $url, 'fb_exchange_token' ) !== false ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'access_token' => 'fb_new_user_tok',
						'expires_in'   => 5184000,
					) ),
				);
			}

			// Second call: /me/accounts for page credentials.
			if ( strpos( $url, '/me/accounts' ) !== false ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'data' => array(
							array(
								'id'           => 'pg_new_456',
								'name'         => 'Extra Chill Page',
								'access_token' => 'fb_new_page_tok',
							),
						),
					) ),
				);
			}

			return $preempt;
		}, 10, 3 );

		$this->auth->save_config( array(
			'app_id'     => 'fb_app_id',
			'app_secret' => 'fb_app_secret',
		) );

		$this->auth->save_account( array(
			'user_access_token' => 'fb_old_user_tok',
			'page_access_token' => 'fb_old_page_tok',
			'page_id'           => 'pg_old_123',
			'page_name'         => 'Old Page',
			'token_expires_at'  => time() - 100,
		) );

		$token = $this->auth->get_user_access_token();

		$this->assertSame( 'fb_new_user_tok', $token );

		// Verify both API calls were made.
		$this->assertCount( 2, $captured_urls );
		$this->assertStringContainsString( 'fb_exchange_token', $captured_urls[0] );
		$this->assertStringContainsString( '/me/accounts', $captured_urls[1] );

		// Verify stored account was fully updated.
		$account = $this->auth->get_account();
		$this->assertSame( 'fb_new_user_tok', $account['user_access_token'] );
		$this->assertSame( 'fb_new_page_tok', $account['page_access_token'] );
		$this->assertSame( 'pg_new_456', $account['page_id'] );
		$this->assertSame( 'Extra Chill Page', $account['page_name'] );
		$this->assertGreaterThan( time() + 5000000, $account['token_expires_at'] );
	}

	public function test_refresh_requires_config(): void {
		// No config saved — should fail.
		$this->auth->save_account( array(
			'user_access_token' => 'fb_tok',
			'page_access_token' => 'fb_page_tok',
			'token_expires_at'  => time() - 100,
		) );

		$token = $this->auth->get_user_access_token();

		// Refresh fails, token is hard-expired, returns null.
		$this->assertNull( $token );
	}

	public function test_refresh_includes_client_credentials(): void {
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			if ( strpos( $url, 'fb_exchange_token' ) !== false ) {
				$captured_url = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'access_token' => 'fb_new_tok',
						'expires_in'   => 5184000,
					) ),
				);
			}
			// Page fetch.
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'data' => array( array( 'id' => 'pg', 'name' => 'Page', 'access_token' => 'pt' ) ),
				) ),
			);
		}, 10, 3 );

		$this->auth->save_config( array(
			'app_id'     => 'my_app_id',
			'app_secret' => 'my_app_secret',
		) );

		$this->auth->save_account( array(
			'user_access_token' => 'old_tok',
			'page_access_token' => 'old_page',
			'token_expires_at'  => time() - 100,
		) );

		$this->auth->get_user_access_token();

		$this->assertStringContainsString( 'client_id=my_app_id', $captured_url );
		$this->assertStringContainsString( 'client_secret=my_app_secret', $captured_url );
	}

	// -------------------------------------------------------------------------
	// get_page_access_token() triggers refresh
	// -------------------------------------------------------------------------

	public function test_get_page_access_token_triggers_refresh(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( strpos( $url, 'fb_exchange_token' ) !== false ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'access_token' => 'fb_new_user',
						'expires_in'   => 5184000,
					) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'data' => array( array(
						'id'           => 'pg',
						'name'         => 'Page',
						'access_token' => 'fb_refreshed_page_tok',
					) ),
				) ),
			);
		}, 10, 3 );

		$this->auth->save_config( array(
			'app_id'     => 'id',
			'app_secret' => 'secret',
		) );

		$this->auth->save_account( array(
			'user_access_token' => 'old_user',
			'page_access_token' => 'old_page',
			'token_expires_at'  => time() - 100,
		) );

		$page_token = $this->auth->get_page_access_token();

		$this->assertSame( 'fb_refreshed_page_tok', $page_token );
	}

	public function test_get_page_access_token_returns_null_when_not_authenticated(): void {
		$this->assertNull( $this->auth->get_page_access_token() );
	}

	// -------------------------------------------------------------------------
	// No refresh when tokens are fresh
	// -------------------------------------------------------------------------

	public function test_no_refresh_when_tokens_are_fresh(): void {
		$http_called = false;

		add_filter( 'pre_http_request', function () use ( &$http_called ) {
			$http_called = true;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
			);
		} );

		$this->auth->save_account( array(
			'user_access_token' => 'fresh_user',
			'page_access_token' => 'fresh_page',
			'token_expires_at'  => time() + ( 30 * DAY_IN_SECONDS ),
		) );

		$user_token = $this->auth->get_user_access_token();
		$page_token = $this->auth->get_page_access_token();

		$this->assertSame( 'fresh_user', $user_token );
		$this->assertSame( 'fresh_page', $page_token );
		$this->assertFalse( $http_called );
	}

	// -------------------------------------------------------------------------
	// remove_account() with token revocation
	// -------------------------------------------------------------------------

	public function test_remove_account_revokes_and_clears(): void {
		$revocation_called = false;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$revocation_called ) {
			if ( strpos( $url, '/me/permissions' ) !== false ) {
				$revocation_called = true;
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"success":true}',
			);
		}, 10, 3 );

		$this->auth->save_account( array(
			'user_access_token' => 'user_tok',
			'page_access_token' => 'page_tok',
			'token_expires_at'  => time() + ( 30 * DAY_IN_SECONDS ),
		) );
		$this->auth->schedule_proactive_refresh();

		$this->auth->remove_account();

		$this->assertTrue( $revocation_called );
		$this->assertEmpty( $this->auth->get_account() );
		$this->assertFalse( wp_next_scheduled( $this->auth->get_cron_hook_name() ) );
	}
}
