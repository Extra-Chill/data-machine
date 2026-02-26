<?php
/**
 * InstagramReadAbility Tests
 *
 * Tests for Instagram read ability: listing media, getting single posts,
 * fetching comments, error handling, and pagination.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachineSocials\Abilities\Instagram\InstagramReadAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramReadAbilityTest extends WP_UnitTestCase {

	private InstagramReadAbility $ability;
	private InstagramAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );

		$this->auth = new InstagramAuth();

		// Register the auth provider via filter so AuthAbilities can find it.
		\DataMachine\Abilities\AuthAbilities::clearCache();
		add_filter( 'datamachine_auth_providers', function ( $providers ) {
			$providers['instagram'] = $this->auth;
			return $providers;
		} );

		$this->ability = new InstagramReadAbility();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		\DataMachine\Abilities\AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helper: set up authenticated provider
	// -------------------------------------------------------------------------

	private function authenticate( string $token = 'ig_test_tok', string $user_id = '12345' ): void {
		$this->auth->save_account( array(
			'access_token'     => $token,
			'user_id'          => $user_id,
			'username'         => 'extrachill',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );
	}

	private function mock_api_response( int $status_code, array $body ): void {
		add_filter( 'pre_http_request', function () use ( $status_code, $body ) {
			return array(
				'response' => array( 'code' => $status_code ),
				'body'     => wp_json_encode( $body ),
			);
		} );
	}

	// -------------------------------------------------------------------------
	// Action: list
	// -------------------------------------------------------------------------

	public function test_list_returns_media_on_success(): void {
		$this->authenticate();

		$media_items = array(
			array(
				'id'             => '111',
				'caption'        => 'Test post 1',
				'media_type'     => 'IMAGE',
				'permalink'      => 'https://www.instagram.com/p/abc/',
				'timestamp'      => '2026-02-20T12:00:00+0000',
				'like_count'     => 42,
				'comments_count' => 5,
			),
			array(
				'id'             => '222',
				'caption'        => 'Test post 2',
				'media_type'     => 'CAROUSEL_ALBUM',
				'permalink'      => 'https://www.instagram.com/p/def/',
				'timestamp'      => '2026-02-19T12:00:00+0000',
				'like_count'     => 100,
				'comments_count' => 12,
			),
		);

		$this->mock_api_response( 200, array(
			'data'   => $media_items,
			'paging' => array(
				'cursors' => array( 'after' => 'cursor_abc' ),
				'next'    => 'https://graph.instagram.com/12345/media?after=cursor_abc',
			),
		) );

		$result = $this->ability->execute( array( 'action' => 'list' ) );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['data']['media'] );
		$this->assertSame( 2, $result['data']['count'] );
		$this->assertTrue( $result['data']['has_next'] );
		$this->assertSame( 'cursor_abc', $result['data']['cursors']['after'] );
	}

	public function test_list_defaults_action_to_list(): void {
		$this->authenticate();

		$this->mock_api_response( 200, array(
			'data'   => array(),
			'paging' => array(),
		) );

		$result = $this->ability->execute( array() );

		$this->assertTrue( $result['success'] );
		$this->assertEmpty( $result['data']['media'] );
		$this->assertSame( 0, $result['data']['count'] );
	}

	public function test_list_respects_limit_parameter(): void {
		$this->authenticate();
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array(), 'paging' => array() ) ),
			);
		}, 10, 3 );

		$this->ability->execute( array( 'action' => 'list', 'limit' => 10 ) );

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'limit=10', $captured_url );
	}

	public function test_list_caps_limit_at_100(): void {
		$this->authenticate();
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array(), 'paging' => array() ) ),
			);
		}, 10, 3 );

		$this->ability->execute( array( 'action' => 'list', 'limit' => 500 ) );

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'limit=100', $captured_url );
	}

	public function test_list_passes_after_cursor(): void {
		$this->authenticate();
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array(), 'paging' => array() ) ),
			);
		}, 10, 3 );

		$this->ability->execute( array( 'action' => 'list', 'after' => 'cursor_xyz' ) );

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'after=cursor_xyz', $captured_url );
	}

	public function test_list_has_next_false_without_next_page(): void {
		$this->authenticate();

		$this->mock_api_response( 200, array(
			'data'   => array( array( 'id' => '111' ) ),
			'paging' => array(
				'cursors' => array( 'after' => 'end' ),
			),
		) );

		$result = $this->ability->execute( array( 'action' => 'list' ) );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['data']['has_next'] );
	}

	// -------------------------------------------------------------------------
	// Action: get
	// -------------------------------------------------------------------------

	public function test_get_returns_single_post_details(): void {
		$this->authenticate();

		$post_data = array(
			'id'                 => '111',
			'caption'            => 'A beautiful sunset',
			'media_type'         => 'IMAGE',
			'media_url'          => 'https://scontent.cdninstagram.com/v/img.jpg',
			'permalink'          => 'https://www.instagram.com/p/abc/',
			'timestamp'          => '2026-02-20T12:00:00+0000',
			'like_count'         => 42,
			'comments_count'     => 5,
			'media_product_type' => 'FEED',
			'is_shared_to_feed'  => true,
		);

		$this->mock_api_response( 200, $post_data );

		$result = $this->ability->execute( array(
			'action'   => 'get',
			'media_id' => '111',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '111', $result['data']['id'] );
		$this->assertSame( 'A beautiful sunset', $result['data']['caption'] );
		$this->assertSame( 42, $result['data']['like_count'] );
	}

	public function test_get_requires_media_id(): void {
		$this->authenticate();

		$result = $this->ability->execute( array( 'action' => 'get' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'media_id is required', $result['error'] );
	}

	public function test_get_sends_detail_fields(): void {
		$this->authenticate();
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'id' => '111' ) ),
			);
		}, 10, 3 );

		$this->ability->execute( array( 'action' => 'get', 'media_id' => '111' ) );

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'media_product_type', $captured_url );
		$this->assertStringContainsString( 'is_shared_to_feed', $captured_url );
	}

	// -------------------------------------------------------------------------
	// Action: comments
	// -------------------------------------------------------------------------

	public function test_comments_returns_post_comments(): void {
		$this->authenticate();

		$comments = array(
			array(
				'id'         => 'c1',
				'text'       => 'Great post!',
				'timestamp'  => '2026-02-20T13:00:00+0000',
				'username'   => 'fan123',
				'like_count' => 3,
			),
			array(
				'id'         => 'c2',
				'text'       => 'Love this!',
				'timestamp'  => '2026-02-20T14:00:00+0000',
				'username'   => 'musiclover',
				'like_count' => 1,
			),
		);

		$this->mock_api_response( 200, array(
			'data'   => $comments,
			'paging' => array(
				'cursors' => array( 'after' => 'comment_cursor' ),
				'next'    => 'https://graph.instagram.com/111/comments?after=comment_cursor',
			),
		) );

		$result = $this->ability->execute( array(
			'action'   => 'comments',
			'media_id' => '111',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['data']['comments'] );
		$this->assertSame( 2, $result['data']['count'] );
		$this->assertTrue( $result['data']['has_next'] );
	}

	public function test_comments_requires_media_id(): void {
		$this->authenticate();

		$result = $this->ability->execute( array( 'action' => 'comments' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'media_id is required', $result['error'] );
	}

	// -------------------------------------------------------------------------
	// Auth errors
	// -------------------------------------------------------------------------

	public function test_returns_error_when_not_authenticated(): void {
		// No call to authenticate() — provider has no token.
		$result = $this->ability->execute( array( 'action' => 'list' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'access token', strtolower( $result['error'] ) );
	}

	public function test_returns_error_when_token_expired_and_refresh_fails(): void {
		// Set expired token with no refresh mock (will fail).
		$this->auth->save_account( array(
			'access_token'     => 'ig_expired_tok',
			'user_id'          => '12345',
			'token_expires_at' => time() - 100,
		) );

		// Mock failed refresh.
		$this->mock_api_response( 400, array(
			'error' => array( 'message' => 'Token is invalid' ),
		) );

		$result = $this->ability->execute( array( 'action' => 'list' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'access token', strtolower( $result['error'] ) );
	}

	public function test_returns_error_when_user_id_missing(): void {
		// Authenticated but no user_id.
		$this->auth->save_account( array(
			'access_token'     => 'ig_test_tok',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );

		$result = $this->ability->execute( array( 'action' => 'list' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'user ID', $result['error'] );
	}

	public function test_returns_error_when_provider_unavailable(): void {
		// Remove the provider filter.
		remove_all_filters( 'datamachine_auth_providers' );
		\DataMachine\Abilities\AuthAbilities::clearCache();

		$result = $this->ability->execute( array( 'action' => 'list' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'auth provider', strtolower( $result['error'] ) );
	}

	// -------------------------------------------------------------------------
	// API error handling
	// -------------------------------------------------------------------------

	public function test_handles_api_http_error(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function () {
			return new \WP_Error( 'http_request_failed', 'Connection timed out' );
		} );

		$result = $this->ability->execute( array( 'action' => 'list' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'failed', strtolower( $result['error'] ) );
	}

	public function test_handles_api_error_response(): void {
		$this->authenticate();

		$this->mock_api_response( 400, array(
			'error' => array(
				'message' => 'Invalid user id',
				'type'    => 'OAuthException',
				'code'    => 110,
			),
		) );

		$result = $this->ability->execute( array( 'action' => 'list' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid user id', $result['error'] );
	}

	// -------------------------------------------------------------------------
	// Unknown action
	// -------------------------------------------------------------------------

	public function test_returns_error_for_unknown_action(): void {
		$this->authenticate();

		$result = $this->ability->execute( array( 'action' => 'delete' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unknown action', $result['error'] );
	}

	// -------------------------------------------------------------------------
	// API URL construction
	// -------------------------------------------------------------------------

	public function test_list_calls_correct_api_url(): void {
		$this->authenticate( 'ig_tok_url_test', '99999' );
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array(), 'paging' => array() ) ),
			);
		}, 10, 3 );

		$this->ability->execute( array( 'action' => 'list' ) );

		$this->assertStringContainsString( 'graph.instagram.com/99999/media', $captured_url );
		$this->assertStringContainsString( 'access_token=ig_tok_url_test', $captured_url );
	}

	public function test_get_calls_correct_api_url(): void {
		$this->authenticate();
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'id' => '777' ) ),
			);
		}, 10, 3 );

		$this->ability->execute( array( 'action' => 'get', 'media_id' => '777' ) );

		$this->assertStringContainsString( 'graph.instagram.com/777', $captured_url );
	}

	public function test_comments_calls_correct_api_url(): void {
		$this->authenticate();
		$captured_url = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array(), 'paging' => array() ) ),
			);
		}, 10, 3 );

		$this->ability->execute( array( 'action' => 'comments', 'media_id' => '888' ) );

		$this->assertStringContainsString( 'graph.instagram.com/888/comments', $captured_url );
	}
}
