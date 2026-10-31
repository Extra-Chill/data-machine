<?php
/**
 * YouTube upload and search ability tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\YouTube
 */

namespace DataMachineSocials\Tests\Unit\Abilities\YouTube;

use DataMachineSocials\Abilities\YouTube\YouTubeSearchAbility;
use DataMachineSocials\Abilities\YouTube\YouTubeUploadAbility;
use DataMachineSocials\Handlers\YouTube\YouTubeAuth;
use WP_UnitTestCase;

class YouTubeUploadAbilityTest extends WP_UnitTestCase {

	private YouTubeAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new YouTubeAuth();
		$this->auth->save_account( array(
			'access_token'     => 'youtube_token',
			'refresh_token'    => 'youtube_refresh',
			'token_expires_at' => time() + HOUR_IN_SECONDS,
		) );
		\DataMachine\Abilities\AuthAbilities::clearCache();
		add_filter( 'datamachine_auth_providers', function ( $providers ) {
			$providers['youtube'] = $this->auth;
			return $providers;
		} );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		\DataMachine\Abilities\AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_upload_uses_resumable_protocol_and_defaults_to_private(): void {
		$file = wp_tempnam( 'youtube-upload-test' );
		file_put_contents( $file, 'test video bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		$expected_mime = mime_content_type( $file );

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $expected_mime ) {
			if ( str_contains( $url, 'upload/youtube/v3/videos?uploadType=resumable' ) ) {
				$this->assertSame( 'POST', $args['method'] );
				$metadata = json_decode( $args['body'], true );
				$this->assertSame( 'Pilot recap', $metadata['snippet']['title'] );
				$this->assertSame( 'private', $metadata['status']['privacyStatus'] );
				$this->assertSame( $expected_mime, $args['headers']['X-Upload-Content-Type'] );

				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'location' => 'https://upload.example.test/session' ),
					'body'     => '',
				);
			}

			if ( 'https://upload.example.test/session' === $url ) {
				$this->assertSame( 'PUT', $args['method'] );
				$this->assertSame( 'test video bytes', $args['body'] );

				return array(
					'response' => array( 'code' => 201 ),
					'body'     => wp_json_encode( array( 'id' => 'video_123' ) ),
				);
			}

			return $preempt;
		}, 10, 3 );

		$result = YouTubeUploadAbility::execute_upload( array(
			'title'           => 'Pilot recap',
			'video_file_path' => $file,
		) );

		unlink( $file );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'video_123', $result['video_id'] );
		$this->assertSame( 'private', $result['privacy_status'] );
	}

	public function test_search_normalizes_video_results(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			$this->assertStringContainsString( '/youtube/v3/search?', $url );
			$this->assertSame( 'Bearer youtube_token', $args['headers']['Authorization'] );

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'items' => array(
						array(
							'id'      => array( 'videoId' => 'result_123' ),
							'snippet' => array(
								'title'        => 'Live set',
								'description'  => 'A live show',
								'channelTitle' => 'Extra Chill',
							),
						),
					),
				) ),
			);
		}, 10, 3 );

		$ability = new YouTubeSearchAbility();
		$result  = $ability->execute_search( array( 'query' => 'Charleston music' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'result_123', $result['results'][0]['id'] );
		$this->assertSame( 'https://www.youtube.com/watch?v=result_123', $result['results'][0]['url'] );
	}
}
