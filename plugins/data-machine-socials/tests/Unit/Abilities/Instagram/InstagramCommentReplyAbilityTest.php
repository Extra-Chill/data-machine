<?php
/**
 * InstagramCommentReplyAbility Tests
 *
 * Tests for replying to Instagram comments.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachineSocials\Abilities\Instagram\InstagramCommentReplyAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramCommentReplyAbilityTest extends WP_UnitTestCase {

	private InstagramCommentReplyAbility $ability;
	private InstagramAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );

		$this->auth = new InstagramAuth();

		\DataMachine\Abilities\AuthAbilities::clearCache();
		add_filter( 'datamachine_auth_providers', function ( $providers ) {
			$providers['instagram'] = $this->auth;
			return $providers;
		} );

		$this->ability = new InstagramCommentReplyAbility();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		\DataMachine\Abilities\AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	private function authenticate( string $token = 'ig_test_tok', string $user_id = '12345' ): void {
		$this->auth->save_account( array(
			'access_token'     => $token,
			'user_id'          => $user_id,
			'username'         => 'extrachill',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );
	}

	public function test_reply_comment_success(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			$this->assertStringContainsString( 'graph.facebook.com/v23.0/comment_123/replies', $url );
			$this->assertSame( 'Thanks for the support!', $args['body']['message'] );
			$this->assertSame( 'ig_test_tok', $args['body']['access_token'] );

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'id' => 'reply_456' ) ),
			);
		}, 10, 3 );

		$result = $this->ability->execute( array(
			'comment_id' => 'comment_123',
			'message'    => 'Thanks for the support!',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'reply_456', $result['data']['reply_id'] );
		$this->assertSame( 'comment_123', $result['data']['comment_id'] );
	}

	public function test_reply_requires_comment_id(): void {
		$this->authenticate();

		$result = $this->ability->execute( array(
			'message' => 'Thanks!',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'comment_id is required', $result['error'] );
	}

	public function test_reply_requires_message(): void {
		$this->authenticate();

		$result = $this->ability->execute( array(
			'comment_id' => 'comment_123',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'message is required', $result['error'] );
	}

	public function test_reply_returns_api_error_message(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 400 ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'message' => 'Unsupported post request.',
						),
					)
				),
			);
		} );

		$result = $this->ability->execute( array(
			'comment_id' => 'comment_123',
			'message'    => 'Thanks!',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unsupported post request.', $result['error'] );
	}

	public function test_reply_returns_error_when_not_authenticated(): void {
		$result = $this->ability->execute( array(
			'comment_id' => 'comment_123',
			'message'    => 'Thanks!',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'access token', strtolower( $result['error'] ) );
	}
}
