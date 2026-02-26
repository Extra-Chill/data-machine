<?php
/**
 * RedditAuth Tests
 *
 * Tests for Reddit OAuth2 provider: do_refresh_token() override,
 * token storage conventions, refresh buffer, and integration with
 * BaseOAuth2Provider lifecycle.
 *
 * @package DataMachineSocials\Tests\Unit\Handlers\Reddit
 */

namespace DataMachineSocials\Tests\Unit\Handlers\Reddit;

use DataMachineSocials\Handlers\Reddit\RedditAuth;
use WP_UnitTestCase;

class RedditAuthTest extends WP_UnitTestCase {

	private RedditAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new RedditAuth();
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

	public function test_provider_slug_is_reddit(): void {
		$this->assertSame( 'datamachine_refresh_token_reddit', $this->auth->get_cron_hook_name() );
	}

	// -------------------------------------------------------------------------
	// get_config_fields()
	// -------------------------------------------------------------------------

	public function test_config_fields_include_required_keys(): void {
		$fields = $this->auth->get_config_fields();

		$this->assertArrayHasKey( 'client_id', $fields );
		$this->assertArrayHasKey( 'client_secret', $fields );
		$this->assertArrayHasKey( 'developer_username', $fields );
		$this->assertTrue( $fields['client_id']['required'] );
		$this->assertTrue( $fields['client_secret']['required'] );
		$this->assertTrue( $fields['developer_username']['required'] );
	}

	// -------------------------------------------------------------------------
	// is_configured()
	// -------------------------------------------------------------------------

	public function test_is_configured_with_full_credentials(): void {
		$this->auth->save_config( array(
			'client_id'     => 'reddit_id',
			'client_secret' => 'reddit_secret',
		) );

		$this->assertTrue( $this->auth->is_configured() );
	}

	public function test_is_configured_returns_false_without_credentials(): void {
		$this->assertFalse( $this->auth->is_configured() );
	}

	// -------------------------------------------------------------------------
	// is_authenticated() — requires both access_token and refresh_token
	// -------------------------------------------------------------------------

	public function test_is_authenticated_with_valid_tokens(): void {
		$this->auth->save_account( array(
			'access_token'    => 'reddit_tok',
			'refresh_token'   => 'reddit_refresh',
			'token_expires_at' => time() + 3600,
		) );

		$this->assertTrue( $this->auth->is_authenticated() );
	}

	public function test_is_authenticated_returns_false_when_expired(): void {
		$this->auth->save_account( array(
			'access_token'    => 'reddit_tok',
			'refresh_token'   => 'reddit_refresh',
			'token_expires_at' => time() - 100,
		) );

		$this->assertFalse( $this->auth->is_authenticated() );
	}

	public function test_is_authenticated_returns_false_without_refresh_token(): void {
		$this->auth->save_account( array(
			'access_token'    => 'reddit_tok',
			'token_expires_at' => time() + 3600,
		) );

		$this->assertFalse( $this->auth->is_authenticated() );
	}

	public function test_is_authenticated_returns_false_with_no_account(): void {
		$this->assertFalse( $this->auth->is_authenticated() );
	}

	// -------------------------------------------------------------------------
	// do_refresh_token() via get_valid_access_token()
	// -------------------------------------------------------------------------

	public function test_refresh_calls_reddit_api_with_basic_auth(): void {
		$captured_args = null;
		$captured_url  = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_args, &$captured_url ) {
			$captured_args = $args;
			$captured_url  = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'reddit_new_tok',
					'expires_in'   => 3600,
				) ),
			);
		}, 10, 3 );

		$this->auth->save_config( array(
			'client_id'          => 'my_client_id',
			'client_secret'      => 'my_client_secret',
			'developer_username' => 'testuser',
		) );
		$this->auth->save_account( array(
			'access_token'    => 'reddit_old_tok',
			'refresh_token'   => 'reddit_refresh_tok',
			'token_expires_at' => time() - 100, // Expired — triggers refresh.
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertSame( 'reddit_new_tok', $token );
		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'reddit.com/api/v1/access_token', $captured_url );

		// Verify Basic Auth header
		$this->assertArrayHasKey( 'headers', $captured_args );
		$this->assertArrayHasKey( 'Authorization', $captured_args['headers'] );
		$expected_auth = 'Basic ' . base64_encode( 'my_client_id:my_client_secret' );
		$this->assertSame( $expected_auth, $captured_args['headers']['Authorization'] );

		// Verify refresh_token in body
		$this->assertSame( 'refresh_token', $captured_args['body']['grant_type'] );
		$this->assertSame( 'reddit_refresh_tok', $captured_args['body']['refresh_token'] );
	}

	public function test_successful_refresh_updates_stored_account(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'reddit_new_tok',
					'expires_in'   => 3600,
				) ),
			);
		} );

		$this->auth->save_config( array(
			'client_id'          => 'id',
			'client_secret'      => 'secret',
			'developer_username' => 'user',
		) );
		$this->auth->save_account( array(
			'access_token'    => 'reddit_old_tok',
			'refresh_token'   => 'reddit_refresh_tok',
			'username'        => 'testuser',
			'token_expires_at' => time() - 100,
		) );

		$this->auth->get_valid_access_token();

		$account = $this->auth->get_account();
		$this->assertSame( 'reddit_new_tok', $account['access_token'] );
		$this->assertSame( 'reddit_refresh_tok', $account['refresh_token'] );
		$this->assertSame( 'testuser', $account['username'] );
		$this->assertGreaterThan( time() + 3000, $account['token_expires_at'] );
		$this->assertArrayHasKey( 'last_refreshed_at', $account );
	}

	public function test_refresh_stores_new_refresh_token_from_reddit(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token'  => 'reddit_new_tok',
					'refresh_token' => 'reddit_rotated_refresh',
					'expires_in'    => 3600,
				) ),
			);
		} );

		$this->auth->save_config( array(
			'client_id'          => 'id',
			'client_secret'      => 'secret',
			'developer_username' => 'user',
		) );
		$this->auth->save_account( array(
			'access_token'    => 'reddit_old_tok',
			'refresh_token'   => 'reddit_old_refresh',
			'token_expires_at' => time() - 100,
		) );

		$this->auth->get_valid_access_token();

		$account = $this->auth->get_account();
		$this->assertSame( 'reddit_rotated_refresh', $account['refresh_token'] );
	}

	public function test_failed_refresh_returns_null_when_expired(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 403 ),
				'body'     => wp_json_encode( array( 'error' => 'invalid_grant' ) ),
			);
		} );

		$this->auth->save_config( array(
			'client_id'          => 'id',
			'client_secret'      => 'secret',
			'developer_username' => 'user',
		) );
		$this->auth->save_account( array(
			'access_token'    => 'reddit_dead_tok',
			'refresh_token'   => 'reddit_dead_refresh',
			'token_expires_at' => time() - 100,
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertNull( $token );
	}

	public function test_refresh_fails_without_config(): void {
		$this->auth->save_account( array(
			'access_token'    => 'reddit_tok',
			'refresh_token'   => 'reddit_refresh',
			'token_expires_at' => time() - 100,
		) );

		// No config saved — refresh should fail.
		$token = $this->auth->get_valid_access_token();

		$this->assertNull( $token );
	}

	public function test_refresh_fails_without_refresh_token(): void {
		$this->auth->save_config( array(
			'client_id'          => 'id',
			'client_secret'      => 'secret',
			'developer_username' => 'user',
		) );
		$this->auth->save_account( array(
			'access_token'    => 'reddit_tok',
			'token_expires_at' => time() - 100,
			// No refresh_token.
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
			'access_token'    => 'reddit_fresh_tok',
			'refresh_token'   => 'reddit_refresh',
			'token_expires_at' => time() + 3600, // Well within 1-hour token with 5-min buffer.
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertSame( 'reddit_fresh_tok', $token );
		$this->assertFalse( $refresh_called );
	}

	public function test_refresh_triggered_within_buffer_window(): void {
		$refresh_called = false;

		add_filter( 'pre_http_request', function () use ( &$refresh_called ) {
			$refresh_called = true;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'reddit_refreshed',
					'expires_in'   => 3600,
				) ),
			);
		} );

		$this->auth->save_config( array(
			'client_id'          => 'id',
			'client_secret'      => 'secret',
			'developer_username' => 'user',
		) );
		// Token expires in 120 seconds — within the 300-second buffer.
		$this->auth->save_account( array(
			'access_token'    => 'reddit_expiring_tok',
			'refresh_token'   => 'reddit_refresh',
			'token_expires_at' => time() + 120,
		) );

		$token = $this->auth->get_valid_access_token();

		$this->assertTrue( $refresh_called );
		$this->assertSame( 'reddit_refreshed', $token );
	}

	// -------------------------------------------------------------------------
	// Proactive refresh scheduling
	// -------------------------------------------------------------------------

	public function test_successful_refresh_schedules_cron(): void {
		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'access_token' => 'reddit_new_tok',
					'expires_in'   => 3600,
				) ),
			);
		} );

		$this->auth->save_config( array(
			'client_id'          => 'id',
			'client_secret'      => 'secret',
			'developer_username' => 'user',
		) );
		$this->auth->save_account( array(
			'access_token'    => 'reddit_old_tok',
			'refresh_token'   => 'reddit_refresh',
			'token_expires_at' => time() - 100,
		) );

		$this->auth->get_valid_access_token();

		$next = wp_next_scheduled( $this->auth->get_cron_hook_name() );
		$this->assertNotFalse( $next );
	}

	// -------------------------------------------------------------------------
	// remove_account() cleanup
	// -------------------------------------------------------------------------

	public function test_remove_account_clears_data_and_cron(): void {
		$this->auth->save_account( array(
			'access_token'    => 'reddit_tok',
			'refresh_token'   => 'reddit_refresh',
			'token_expires_at' => time() + 3600,
		) );
		$this->auth->schedule_proactive_refresh();

		$this->auth->remove_account();

		$this->assertEmpty( $this->auth->get_account() );
		$this->assertFalse( wp_next_scheduled( $this->auth->get_cron_hook_name() ) );
	}

	// -------------------------------------------------------------------------
	// get_account_details()
	// -------------------------------------------------------------------------

	public function test_get_account_details_returns_null_without_account(): void {
		$this->assertNull( $this->auth->get_account_details() );
	}

	public function test_get_account_details_returns_full_account(): void {
		$this->auth->save_account( array(
			'access_token'    => 'reddit_tok',
			'refresh_token'   => 'reddit_refresh',
			'username'        => 'testuser',
			'token_expires_at' => time() + 3600,
		) );

		$details = $this->auth->get_account_details();
		$this->assertIsArray( $details );
		$this->assertSame( 'reddit_tok', $details['access_token'] );
		$this->assertSame( 'testuser', $details['username'] );
	}
}
