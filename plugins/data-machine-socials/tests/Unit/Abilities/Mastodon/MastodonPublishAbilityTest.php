<?php
/**
 * MastodonPublishAbility tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Mastodon
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Mastodon;

use DataMachine\Abilities\AuthAbilities;
use DataMachineSocials\Abilities\Mastodon\MastodonPublishAbility;
use DataMachineSocials\Handlers\Mastodon\MastodonAuth;
use WP_UnitTestCase;

class MastodonPublishAbilityTest extends WP_UnitTestCase {

	private MastodonAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new MastodonAuth();
		$this->auth->save_config( array(
			'instance'     => 'https://social.example',
			'access_token' => 'access-token',
		) );
		add_filter( 'datamachine_auth_providers', function ( $providers ) {
			$providers['mastodon'] = $this->auth;
			return $providers;
		} );
		AuthAbilities::clearCache();
	}

	public function tear_down(): void {
		remove_all_filters( 'datamachine_auth_providers' );
		remove_all_filters( 'pre_http_request' );
		AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_publishes_json_status_to_configured_instance(): void {
		$captured_url     = null;
		$captured_headers = null;
		$captured_body    = null;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url, &$captured_headers, &$captured_body ) {
			$captured_url     = $url;
			$captured_headers = $args['headers'];
			parse_str( $args['body'], $captured_body );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'id'  => '123',
					'url' => 'https://social.example/@account/123',
				) ),
			);
		}, 10, 3 );

		$result = MastodonPublishAbility::execute_publish( array(
			'content'    => 'New show announced',
			'source_url' => 'https://extrachill.com/event',
			'visibility' => 'unlisted',
		) );

		$this->assertSame( 'https://social.example/api/v1/statuses', $captured_url );
		$this->assertSame( 'Bearer access-token', $captured_headers['Authorization'] );
		$this->assertSame( 'New show announced\n\nhttps://extrachill.com/event', $captured_body['status'] );
		$this->assertSame( 'unlisted', $captured_body['visibility'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( '123', $result['post_id'] );
	}
}
