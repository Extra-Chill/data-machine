<?php
/**
 * SocialShareTracker Tests
 *
 * Tests for the post-meta-based social share tracking system.
 *
 * @package DataMachineSocials\Tests\Unit\Tracking
 */

namespace DataMachineSocials\Tests\Unit\Tracking;

use DataMachineSocials\Tracking\SocialShareTracker;
use WP_UnitTestCase;

class SocialShareTrackerTest extends WP_UnitTestCase {

	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = self::factory()->post->create( array( 'post_title' => 'Test Post' ) );
	}

	public function tear_down(): void {
		wp_delete_post( $this->post_id, true );
		parent::tear_down();
	}

	public function test_record_stores_share(): void {
		$result = SocialShareTracker::record(
			$this->post_id,
			'instagram',
			'media_123',
			'https://instagram.com/p/ABC/',
			array( 'media_kind' => 'image' )
		);

		$this->assertTrue( $result );

		$shares = SocialShareTracker::get_shares( $this->post_id );
		$this->assertCount( 1, $shares );
		$this->assertSame( 'instagram', $shares[0]['platform'] );
		$this->assertSame( 'media_123', $shares[0]['platform_post_id'] );
		$this->assertSame( 'https://instagram.com/p/ABC/', $shares[0]['platform_url'] );
		$this->assertSame( 'image', $shares[0]['media_kind'] );
		$this->assertIsInt( $shares[0]['shared_at'] );
	}

	public function test_record_updates_platforms_index(): void {
		SocialShareTracker::record( $this->post_id, 'instagram', 'id1' );
		SocialShareTracker::record( $this->post_id, 'twitter', 'id2' );

		$platforms = SocialShareTracker::get_shared_platforms( $this->post_id );

		$this->assertContains( 'instagram', $platforms );
		$this->assertContains( 'twitter', $platforms );
		$this->assertCount( 2, $platforms );
	}

	public function test_has_been_shared(): void {
		$this->assertFalse( SocialShareTracker::has_been_shared( $this->post_id, 'instagram' ) );

		SocialShareTracker::record( $this->post_id, 'instagram', 'id1' );

		$this->assertTrue( SocialShareTracker::has_been_shared( $this->post_id, 'instagram' ) );
		$this->assertFalse( SocialShareTracker::has_been_shared( $this->post_id, 'twitter' ) );
	}

	public function test_has_any_shares(): void {
		$this->assertFalse( SocialShareTracker::has_any_shares( $this->post_id ) );

		SocialShareTracker::record( $this->post_id, 'bluesky', 'id1' );

		$this->assertTrue( SocialShareTracker::has_any_shares( $this->post_id ) );
	}

	public function test_get_shares_filtered_by_platform(): void {
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig1' );
		SocialShareTracker::record( $this->post_id, 'twitter', 'tw1' );
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig2' );

		$ig_shares = SocialShareTracker::get_shares( $this->post_id, 'instagram' );
		$tw_shares = SocialShareTracker::get_shares( $this->post_id, 'twitter' );
		$all       = SocialShareTracker::get_shares( $this->post_id );

		$this->assertCount( 2, $ig_shares );
		$this->assertCount( 1, $tw_shares );
		$this->assertCount( 3, $all );
	}

	public function test_get_latest_share(): void {
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig_old', 'https://ig.com/old' );

		// Simulate a later share by recording again.
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig_new', 'https://ig.com/new' );

		$latest = SocialShareTracker::get_latest_share( $this->post_id, 'instagram' );

		$this->assertNotNull( $latest );
		$this->assertSame( 'ig_new', $latest['platform_post_id'] );
	}

	public function test_get_latest_share_returns_null_for_unshared(): void {
		$this->assertNull( SocialShareTracker::get_latest_share( $this->post_id, 'instagram' ) );
	}

	public function test_mark_deleted(): void {
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig1' );
		SocialShareTracker::record( $this->post_id, 'twitter', 'tw1' );

		$result = SocialShareTracker::mark_deleted( $this->post_id, 'instagram', 'ig1' );

		$this->assertTrue( $result );

		$shares = SocialShareTracker::get_shares( $this->post_id );
		$ig     = array_values( array_filter( $shares, fn( $s ) => $s['platform'] === 'instagram' ) );

		$this->assertSame( 'deleted', $ig[0]['status'] );
		$this->assertArrayHasKey( 'deleted_at', $ig[0] );

		// Platforms index should no longer include instagram (no active shares).
		$platforms = SocialShareTracker::get_shared_platforms( $this->post_id );
		$this->assertNotContains( 'instagram', $platforms );
		$this->assertContains( 'twitter', $platforms );
	}

	public function test_mark_deleted_returns_false_for_unknown(): void {
		$this->assertFalse( SocialShareTracker::mark_deleted( $this->post_id, 'instagram', 'nonexistent' ) );
	}

	public function test_count_shares(): void {
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig1' );
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig2' );
		SocialShareTracker::record( $this->post_id, 'twitter', 'tw1' );

		$this->assertSame( 3, SocialShareTracker::count_shares( $this->post_id ) );
		$this->assertSame( 2, SocialShareTracker::count_shares( $this->post_id, 'instagram' ) );
		$this->assertSame( 1, SocialShareTracker::count_shares( $this->post_id, 'twitter' ) );
	}

	public function test_count_shares_excludes_deleted(): void {
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig1' );
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig2' );
		SocialShareTracker::mark_deleted( $this->post_id, 'instagram', 'ig1' );

		$this->assertSame( 1, SocialShareTracker::count_shares( $this->post_id, 'instagram' ) );
		$this->assertSame( 2, SocialShareTracker::count_shares( $this->post_id, 'instagram', true ) );
	}

	public function test_clear(): void {
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig1' );
		SocialShareTracker::record( $this->post_id, 'twitter', 'tw1' );

		SocialShareTracker::clear( $this->post_id );

		$this->assertEmpty( SocialShareTracker::get_shares( $this->post_id ) );
		$this->assertEmpty( SocialShareTracker::get_shared_platforms( $this->post_id ) );
		$this->assertFalse( SocialShareTracker::has_any_shares( $this->post_id ) );
	}

	public function test_extract_platform_post_id(): void {
		$this->assertSame( 'media_123', SocialShareTracker::extract_platform_post_id( 'instagram', array( 'media_id' => 'media_123' ) ) );
		$this->assertSame( 'tweet_456', SocialShareTracker::extract_platform_post_id( 'twitter', array( 'tweet_id' => 'tweet_456' ) ) );
		$this->assertSame( 'fb_789', SocialShareTracker::extract_platform_post_id( 'facebook', array( 'post_id' => 'fb_789' ) ) );
		$this->assertSame( 'bsky_1', SocialShareTracker::extract_platform_post_id( 'bluesky', array( 'post_id' => 'bsky_1' ) ) );
		$this->assertSame( 'th_2', SocialShareTracker::extract_platform_post_id( 'threads', array( 'post_id' => 'th_2' ) ) );
		$this->assertSame( 'pin_3', SocialShareTracker::extract_platform_post_id( 'pinterest', array( 'pin_id' => 'pin_3' ) ) );
	}

	public function test_extract_platform_url(): void {
		$this->assertSame( 'https://ig.com/p/1', SocialShareTracker::extract_platform_url( 'instagram', array( 'permalink' => 'https://ig.com/p/1' ) ) );
		$this->assertSame( 'https://x.com/s/1', SocialShareTracker::extract_platform_url( 'twitter', array( 'tweet_url' => 'https://x.com/s/1' ) ) );
		$this->assertSame( 'https://fb.com/p/1', SocialShareTracker::extract_platform_url( 'facebook', array( 'post_url' => 'https://fb.com/p/1' ) ) );
	}

	public function test_record_from_result_success(): void {
		$result = array(
			'success'    => true,
			'media_id'   => 'ig_media_1',
			'permalink'  => 'https://instagram.com/p/XYZ/',
			'media_kind' => 'reel',
		);

		$recorded = SocialShareTracker::record_from_result( $this->post_id, 'instagram', $result );

		$this->assertTrue( $recorded );

		$shares = SocialShareTracker::get_shares( $this->post_id );
		$this->assertCount( 1, $shares );
		$this->assertSame( 'ig_media_1', $shares[0]['platform_post_id'] );
		$this->assertSame( 'https://instagram.com/p/XYZ/', $shares[0]['platform_url'] );
		$this->assertSame( 'reel', $shares[0]['media_kind'] );
	}

	public function test_record_from_result_skips_failures(): void {
		$result = array(
			'success' => false,
			'error'   => 'Something went wrong',
		);

		$recorded = SocialShareTracker::record_from_result( $this->post_id, 'instagram', $result );

		$this->assertFalse( $recorded );
		$this->assertEmpty( SocialShareTracker::get_shares( $this->post_id ) );
	}

	public function test_record_rejects_empty_post_id(): void {
		$this->assertFalse( SocialShareTracker::record( 0, 'instagram', 'id1' ) );
	}

	public function test_record_rejects_empty_platform(): void {
		$this->assertFalse( SocialShareTracker::record( $this->post_id, '', 'id1' ) );
	}

	public function test_multiple_platforms_same_post(): void {
		SocialShareTracker::record( $this->post_id, 'instagram', 'ig1', 'https://ig.com/1' );
		SocialShareTracker::record( $this->post_id, 'twitter', 'tw1', 'https://x.com/1' );
		SocialShareTracker::record( $this->post_id, 'bluesky', 'bs1', 'https://bsky.app/1' );
		SocialShareTracker::record( $this->post_id, 'facebook', 'fb1', 'https://fb.com/1' );
		SocialShareTracker::record( $this->post_id, 'threads', 'th1', 'https://threads.net/1' );
		SocialShareTracker::record( $this->post_id, 'pinterest', 'pn1', 'https://pinterest.com/1' );

		$all       = SocialShareTracker::get_shares( $this->post_id );
		$platforms = SocialShareTracker::get_shared_platforms( $this->post_id );

		$this->assertCount( 6, $all );
		$this->assertCount( 6, $platforms );
	}
}
