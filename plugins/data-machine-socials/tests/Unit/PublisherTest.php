<?php
/**
 * Publisher replay tests.
 *
 * @package DataMachineSocials\Tests\Unit
 */

use DataMachineSocials\Publisher;
use DataMachineSocials\Tracking\SocialShareTracker;

final class PublisherTest extends WP_UnitTestCase {

	public function test_delegated_operation_reuses_authoritative_share_receipt(): void {
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$operation_ref = 'dop_' . str_repeat( 'b', 64 );
		SocialShareTracker::record(
			$post_id,
			'instagram',
			'ig-existing',
			'https://instagram.test/ig-existing',
			array(
				'media_kind'    => 'image',
				'operation_ref' => $operation_ref,
			)
		);

		$result = Publisher::cross_post(
			array(
				'post_id'                 => $post_id,
				'platforms'               => array( 'instagram' ),
				'caption'                 => 'Approved caption.',
				'images'                  => array( array( 'url' => 'https://example.test/image.jpg' ) ),
				'delegated_operation_ref' => $operation_ref,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['results'][0]['replayed'] );
		$this->assertSame( 'ig-existing', $result['results'][0]['platform_post_id'] );
		$this->assertSame( 1, SocialShareTracker::count_shares( $post_id, 'instagram' ) );
	}
}
