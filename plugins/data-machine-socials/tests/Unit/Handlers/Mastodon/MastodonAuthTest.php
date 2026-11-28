<?php
/**
 * MastodonAuth tests.
 *
 * @package DataMachineSocials\Tests\Unit\Handlers\Mastodon
 */

namespace DataMachineSocials\Tests\Unit\Handlers\Mastodon;

use DataMachineSocials\Handlers\Mastodon\MastodonAuth;
use WP_UnitTestCase;

class MastodonAuthTest extends WP_UnitTestCase {

	private MastodonAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new MastodonAuth();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_provider_uses_mastodon_slug(): void {
		$this->assertSame( 'mastodon', $this->auth->get_provider_slug() );
	}

	public function test_config_fields_require_instance_and_access_token(): void {
		$fields = $this->auth->get_config_fields();

		$this->assertArrayHasKey( 'instance', $fields );
		$this->assertArrayHasKey( 'access_token', $fields );
		$this->assertTrue( $fields['instance']['required'] );
		$this->assertTrue( $fields['access_token']['required'] );
	}

	public function test_instance_is_normalized_without_hard_coded_host(): void {
		$this->auth->save_config( array(
			'instance'     => 'social.example/',
			'access_token' => 'token',
		) );

		$this->assertSame( 'https://social.example', $this->auth->get_instance() );
		$this->assertSame( 'token', $this->auth->get_access_token() );
		$this->assertTrue( $this->auth->is_authenticated() );
	}

	public function test_authentication_requires_both_instance_and_token(): void {
		$this->auth->save_config( array( 'instance' => 'https://social.example' ) );
		$this->assertFalse( $this->auth->is_authenticated() );
	}

	public function test_register_app_posts_to_configured_instance(): void {
		$captured_url  = null;
		$captured_body = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url, &$captured_body ) {
			$captured_url  = $url;
			parse_str( $args['body'], $captured_body );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'client_id' => 'client-id', 'client_secret' => 'client-secret' ) ),
			);
		}, 10, 3 );

		$result = MastodonAuth::register_app( 'https://social.example/' );

		$this->assertSame( 'https://social.example/api/v1/apps', $captured_url );
		$this->assertSame( 'Data Machine Socials', $captured_body['client_name'] );
		$this->assertSame( 'client-id', $result['client_id'] );
	}
}
