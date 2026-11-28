<?php
/**
 * TumblrReadAbility tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Tumblr
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Tumblr;

use DataMachineSocials\Abilities\Tumblr\TumblrReadAbility;
use DataMachineSocials\Handlers\Tumblr\TumblrAuth;
use WP_UnitTestCase;

class TumblrReadAbilityTest extends WP_UnitTestCase {

	private TumblrReadAbility $ability;

	private TumblrAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new TumblrAuth();
		$this->auth->save_account(
			array(
				'access_token'     => 'tumblr_test_token',
				'refresh_token'    => 'tumblr_refresh_token',
				'token_expires_at' => time() + HOUR_IN_SECONDS,
			)
		);

		\DataMachine\Abilities\AuthAbilities::clearCache();
		add_filter( 'datamachine_auth_providers', function ( $providers ) {
			$providers['tumblr'] = $this->auth;
			return $providers;
		} );
		$this->ability = new TumblrReadAbility();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		\DataMachine\Abilities\AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_tagged_discovers_posts(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			$this->assertStringContainsString( '/v2/tagged?tag=live+music', $url );
			$this->assertSame( 'Bearer tumblr_test_token', $args['headers']['Authorization'] );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'meta'     => array( 'status' => 200, 'msg' => 'OK' ),
						'response' => array(
							'posts' => array(
								array( 'id_string' => '123', 'blog_name' => 'music-blog' ),
							),
						),
					)
				),
			);
		}, 10, 3 );

		$result = $this->ability->execute( array( 'action' => 'tagged', 'tag' => 'live music' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['data']['count'] );
		$this->assertSame( '123', $result['data']['posts'][0]['id_string'] );
	}

	public function test_posts_requires_blog_identifier(): void {
		$result = $this->ability->execute( array( 'action' => 'posts' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_param', $result->get_error_code() );
	}
}
