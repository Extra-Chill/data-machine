<?php
/**
 * InstagramPublishAbility Story Tests
 *
 * Tests for publishing Stories to Instagram via the publish ability.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachineSocials\Abilities\Instagram\InstagramPublishAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramPublishStoryTest extends WP_UnitTestCase {

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

	public function test_story_image_publish_success(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			// Container creation.
			if ( str_contains( $url, '12345/media' ) && 'POST' === $args['method'] ) {
				$this->assertSame( 'STORIES', $args['body']['media_type'] );
				$this->assertSame( 'https://example.com/story.jpg', $args['body']['image_url'] );
				$this->assertArrayNotHasKey( 'caption', $args['body'] );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'container_story' ) ),
				);
			}

			// Status poll.
			if ( str_contains( $url, 'container_story' ) && str_contains( $url, 'status_code' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'status_code' => 'FINISHED' ) ),
				);
			}

			// Publish.
			if ( str_contains( $url, '12345/media_publish' ) ) {
				$this->assertSame( 'container_story', $args['body']['creation_id'] );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_story_1' ) ),
				);
			}

			// Permalink.
			if ( str_contains( $url, 'media_story_1' ) && str_contains( $url, 'permalink' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'id'        => 'media_story_1',
						'permalink' => 'https://www.instagram.com/stories/extrachill/123/',
					) ),
				);
			}

			return $preempt;
		}, 10, 3 );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'        => 'Story content (not sent to API)',
			'media_kind'     => 'story',
			'story_image_url' => 'https://example.com/story.jpg',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'media_story_1', $result['media_id'] );
		$this->assertSame( 'story', $result['media_kind'] );
	}

	public function test_story_video_publish_success(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			// Container creation.
			if ( str_contains( $url, '12345/media' ) && 'POST' === $args['method'] ) {
				$this->assertSame( 'STORIES', $args['body']['media_type'] );
				$this->assertSame( 'https://example.com/story.mp4', $args['body']['video_url'] );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'container_vstory' ) ),
				);
			}

			// Status poll.
			if ( str_contains( $url, 'container_vstory' ) && str_contains( $url, 'status_code' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'status_code' => 'FINISHED' ) ),
				);
			}

			// Publish.
			if ( str_contains( $url, '12345/media_publish' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_vstory_1' ) ),
				);
			}

			// Permalink.
			if ( str_contains( $url, 'media_vstory_1' ) && str_contains( $url, 'permalink' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'media_vstory_1' ) ),
				);
			}

			return $preempt;
		}, 10, 3 );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'Video story',
			'media_kind' => 'story',
			'video_url'  => 'https://example.com/story.mp4',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'media_vstory_1', $result['media_id'] );
		$this->assertSame( 'story', $result['media_kind'] );
	}

	public function test_story_requires_media_source(): void {
		$this->authenticate();

		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'No media',
			'media_kind' => 'story',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'story_image_url or video_url is required', $result['error'] );
	}

	public function test_story_rejects_invalid_image_url(): void {
		$this->authenticate();

		$result = InstagramPublishAbility::execute_publish( array(
			'content'         => 'Bad URL',
			'media_kind'      => 'story',
			'story_image_url' => 'not-a-url',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid story image URL', $result['error'] );
	}

	public function test_story_returns_error_when_not_authenticated(): void {
		$result = InstagramPublishAbility::execute_publish( array(
			'content'         => 'Not authed',
			'media_kind'      => 'story',
			'story_image_url' => 'https://example.com/story.jpg',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not authenticated', strtolower( $result['error'] ) );
	}

	public function test_story_container_creation_failure(): void {
		$this->authenticate();

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( str_contains( $url, '12345/media' ) && 'POST' === $args['method'] ) {
				return array(
					'response' => array( 'code' => 400 ),
					'body'     => wp_json_encode( array(
						'error' => array( 'message' => 'Invalid image dimensions for Stories' ),
					) ),
				);
			}
			return $preempt;
		}, 10, 3 );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'         => 'Bad story',
			'media_kind'      => 'story',
			'story_image_url' => 'https://example.com/bad.jpg',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid image dimensions', $result['error'] );
	}
}
