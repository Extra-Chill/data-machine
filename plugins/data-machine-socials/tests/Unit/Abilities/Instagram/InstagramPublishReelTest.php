<?php
/**
 * InstagramPublishAbility Reel Tests
 *
 * Tests for publishing Reels (video) to Instagram via the publish ability.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachineSocials\Abilities\Instagram\InstagramPublishAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramPublishReelTest extends WP_UnitTestCase {

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

		// Ensure ability is registered.
		new InstagramPublishAbility();
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

	public function test_reel_publish_success(): void {
		$this->authenticate();

		$request_count = 0;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$request_count ) {
			$request_count++;

			// Step 1: Container creation.
			if ( str_contains( $url, '12345/media' ) && 'POST' === $args['method'] ) {
				$this->assertSame( 'REELS', $args['body']['media_type'] );
				$this->assertSame( 'https://example.com/video.mp4', $args['body']['video_url'] );
				$this->assertSame( 'Check this reel!', $args['body']['caption'] );
				$this->assertSame( 'true', $args['body']['share_to_feed'] );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'container_789' ) ),
				);
			}

			// Step 2: Container status poll.
			if ( str_contains( $url, 'container_789' ) && str_contains( $url, 'status_code' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'status_code' => 'FINISHED' ) ),
				);
			}

			// Step 3: Publish.
			if ( str_contains( $url, '12345/media_publish' ) ) {
				$this->assertSame( 'container_789', $args['body']['creation_id'] );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_999' ) ),
				);
			}

			// Step 4: Permalink fetch.
			if ( str_contains( $url, 'media_999' ) && str_contains( $url, 'permalink' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'id'        => 'media_999',
						'permalink' => 'https://www.instagram.com/reel/ABC123/',
					) ),
				);
			}

			return $preempt;
		}, 10, 3 );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'Check this reel!',
			'media_kind' => 'reel',
			'video_url'  => 'https://example.com/video.mp4',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'media_999', $result['media_id'] );
		$this->assertSame( 'reel', $result['media_kind'] );
		$this->assertSame( 'https://www.instagram.com/reel/ABC123/', $result['permalink'] );
	}

	public function test_reel_requires_video_url(): void {
		$this->authenticate();

		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'Missing video',
			'media_kind' => 'reel',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'video_url is required', $result['error'] );
	}

	public function test_reel_rejects_invalid_video_url(): void {
		$this->authenticate();

		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'Bad URL',
			'media_kind' => 'reel',
			'video_url'  => 'not-a-url',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid video URL', $result['error'] );
	}

	public function test_reel_returns_error_when_not_authenticated(): void {
		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'Not logged in',
			'media_kind' => 'reel',
			'video_url'  => 'https://example.com/video.mp4',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not authenticated', strtolower( $result['error'] ) );
	}

	public function test_reel_container_creation_failure(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( str_contains( $url, '12345/media' ) && 'POST' === $args['method'] ) {
				return array(
					'response' => array( 'code' => 400 ),
					'body'     => wp_json_encode( array(
						'error' => array( 'message' => 'Invalid video format' ),
					) ),
				);
			}
			return $preempt;
		}, 10, 3 );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'Bad video',
			'media_kind' => 'reel',
			'video_url'  => 'https://example.com/video.avi',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid video format', $result['error'] );
	}

	public function test_reel_video_processing_timeout(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			// Container creation succeeds.
			if ( str_contains( $url, '12345/media' ) && 'POST' === $args['method'] ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'container_slow' ) ),
				);
			}

			// Status poll always returns IN_PROGRESS (simulates timeout).
			if ( str_contains( $url, 'container_slow' ) && str_contains( $url, 'status_code' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'status_code' => 'IN_PROGRESS' ) ),
				);
			}

			return $preempt;
		}, 10, 3 );

		// Use small retries for fast test.
		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'Slow video',
			'media_kind' => 'reel',
			'video_url'  => 'https://example.com/slow.mp4',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'processing failed or timed out', $result['error'] );
	}

	public function test_reel_with_cover_url(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			// Container creation — verify cover_url is passed.
			if ( str_contains( $url, '12345/media' ) && 'POST' === $args['method'] ) {
				$this->assertSame( 'https://example.com/cover.jpg', $args['body']['cover_url'] );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'container_cover' ) ),
				);
			}

			// Status poll.
			if ( str_contains( $url, 'container_cover' ) && str_contains( $url, 'status_code' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'status_code' => 'FINISHED' ) ),
				);
			}

			// Publish.
			if ( str_contains( $url, '12345/media_publish' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_cover' ) ),
				);
			}

			// Permalink.
			if ( str_contains( $url, 'media_cover' ) && str_contains( $url, 'permalink' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_cover', 'permalink' => 'https://instagram.com/reel/cover/' ) ),
				);
			}

			return $preempt;
		}, 10, 3 );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'With cover',
			'media_kind' => 'reel',
			'video_url'  => 'https://example.com/video.mp4',
			'cover_url'  => 'https://example.com/cover.jpg',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'reel', $result['media_kind'] );
	}

	public function test_reel_share_to_feed_false(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( str_contains( $url, '12345/media' ) && 'POST' === $args['method'] ) {
				$this->assertSame( 'false', $args['body']['share_to_feed'] );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'container_nofeed' ) ),
				);
			}

			if ( str_contains( $url, 'container_nofeed' ) && str_contains( $url, 'status_code' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'status_code' => 'FINISHED' ) ),
				);
			}

			if ( str_contains( $url, '12345/media_publish' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_nofeed' ) ),
				);
			}

			if ( str_contains( $url, 'media_nofeed' ) && str_contains( $url, 'permalink' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_nofeed', 'permalink' => 'https://instagram.com/reel/nofeed/' ) ),
				);
			}

			return $preempt;
		}, 10, 3 );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'       => 'No feed',
			'media_kind'    => 'reel',
			'video_url'     => 'https://example.com/video.mp4',
			'share_to_feed' => false,
		) );

		$this->assertTrue( $result['success'] );
	}

	public function test_image_publish_still_works(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			// Container creation.
			if ( str_contains( $url, '12345/media' ) && 'POST' === $args['method'] && ! isset( $args['body']['media_type'] ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'container_img' ) ),
				);
			}

			// Status poll.
			if ( str_contains( $url, 'container_img' ) && str_contains( $url, 'status_code' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'status_code' => 'FINISHED' ) ),
				);
			}

			// Publish.
			if ( str_contains( $url, '12345/media_publish' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_img' ) ),
				);
			}

			// Permalink.
			if ( str_contains( $url, 'media_img' ) && str_contains( $url, 'permalink' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_img', 'permalink' => 'https://instagram.com/p/IMG/' ) ),
				);
			}

			return $preempt;
		}, 10, 3 );

		// Default media_kind (image) should still work.
		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'Image post',
			'image_urls' => array( 'https://example.com/photo.jpg' ),
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'image', $result['media_kind'] );
	}
}
