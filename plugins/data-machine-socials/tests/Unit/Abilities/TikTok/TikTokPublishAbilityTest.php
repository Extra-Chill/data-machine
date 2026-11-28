<?php
/**
 * TikTok Publish Ability Tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\TikTok
 */

namespace DataMachineSocials\Tests\Unit\Abilities\TikTok;

use DataMachineSocials\Abilities\TikTok\TikTokPublishAbility;
use WP_UnitTestCase;

class TikTokPublishAbilityTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_fetch_post_status_uses_json_bearer_request(): void {
		$captured = array();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = array( 'args' => $args, 'url' => $url );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data'  => array( 'status' => 'PUBLISH_COMPLETE' ),
						'error' => array( 'code' => 'ok', 'message' => '' ),
					)
				),
			);
		}, 10, 3 );

		$result = TikTokPublishAbility::fetch_post_status( 'access_token', 'publish_id' );

		$this->assertSame( 'PUBLISH_COMPLETE', $result['status'] );
		$this->assertSame( 'Bearer access_token', $captured['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json; charset=UTF-8', $captured['args']['headers']['Content-Type'] );
		$this->assertSame( array( 'publish_id' => 'publish_id' ), json_decode( $captured['args']['body'], true ) );
		$this->assertStringContainsString( '/v2/post/publish/status/fetch/', $captured['url'] );
	}
}
