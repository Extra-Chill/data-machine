<?php
/**
 * Instagram per-photo tagging contract and container body tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachine\Abilities\AuthAbilities;
use DataMachineSocials\Abilities\Instagram\InstagramPublishAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramPublishUserTagsTest extends WP_UnitTestCase {

	private array $bodies = array();
	private array $requests = array();
	private int $fail_slide = 0;
	private int $failure_status = 400;
	private string $container_status = 'FINISHED';

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		AuthAbilities::clearCache();
		$auth = new InstagramAuth();
		$auth->save_account( array(
			'access_token' => 'test_token', 'user_id' => '12345', 'username' => 'extrachill',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );
		add_filter( 'datamachine_auth_providers', function ( $providers ) use ( $auth ) {
			$providers['instagram'] = $auth;
			return $providers;
		} );
		new InstagramPublishAbility();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			$this->requests[] = $url;
			$code = 200;
			if ( str_ends_with( $url, '/12345/media' ) ) {
				$this->bodies[] = $args['body'];
				$data = array( 'id' => 'container_' . count( $this->bodies ) );
				if ( count( $this->bodies ) === $this->fail_slide ) {
					$code = $this->failure_status;
					$data = array( 'error' => array( 'message' => 'Invalid user tag: rejected_artist' ) );
				}
			} elseif ( str_contains( $url, 'status_code' ) ) {
				$data = array( 'status_code' => $this->container_status );
			} elseif ( str_ends_with( $url, '/12345/media_publish' ) ) {
				$data = array( 'id' => 'media_1' );
			} elseif ( str_contains( $url, 'permalink' ) ) {
				$data = array( 'permalink' => 'https://www.instagram.com/p/test/' );
			} else {
				$this->fail( 'Unexpected HTTP request: ' . $url );
			}
			return array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $data ) );
		}, 10, 3 );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	private function tag( string $username = 'venue' ): array {
		return array( 'username' => $username, 'x' => 0.25, 'y' => 0.75 );
	}

	private function input(): array {
		return array(
			'content' => 'Weekly roundup',
			'image_urls' => array( 'https://example.com/1.jpg', 'https://example.com/2.jpg', 'https://example.com/3.jpg' ),
			'carousel_user_tags' => array( array( $this->tag() ), array(), array( $this->tag( 'artist' ) ) ),
		);
	}

	/** @dataProvider mediaKindProvider */
	public function test_tags_are_sent_only_on_their_child( string $media_kind ): void {
		$input = $this->input();
		$input['media_kind'] = $media_kind;
		$input['location_id'] = '42';
		$input['collaborators'] = array( 'collaborator' );
		$result = InstagramPublishAbility::execute_publish( $input );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 4, $this->bodies );
		foreach ( array( 0, 1, 2 ) as $index ) {
			$this->assertSame( $input['image_urls'][ $index ], $this->bodies[ $index ]['image_url'] );
			$this->assertSame( 'true', $this->bodies[ $index ]['is_carousel_item'] );
			$this->assertArrayNotHasKey( 'caption', $this->bodies[ $index ] );
			$this->assertArrayNotHasKey( 'collaborators', $this->bodies[ $index ] );
			$this->assertArrayNotHasKey( 'location_id', $this->bodies[ $index ] );
		}
		$this->assertSame( $input['carousel_user_tags'][0], json_decode( $this->bodies[0]['user_tags'], true ) );
		$this->assertArrayNotHasKey( 'user_tags', $this->bodies[1] );
		$this->assertSame( $input['carousel_user_tags'][2], json_decode( $this->bodies[2]['user_tags'], true ) );
		$this->assertArrayNotHasKey( 'user_tags', $this->bodies[3] );
		$this->assertSame( 'CAROUSEL', $this->bodies[3]['media_type'] );
		$this->assertSame( 'container_1,container_2,container_3', $this->bodies[3]['children'] );
		$this->assertSame( 'Weekly roundup', $this->bodies[3]['caption'] );
		$this->assertSame( '42', $this->bodies[3]['location_id'] );
		$this->assertSame( 'collaborator', $this->bodies[3]['collaborators'] );
	}

	public function mediaKindProvider(): array {
		return array( array( 'image' ), array( 'carousel' ) );
	}

	public function test_single_image_user_tags_unchanged(): void {
		$tags = array( $this->tag() );
		$result = InstagramPublishAbility::execute_publish( array(
			'content' => 'Single', 'image_urls' => array( 'https://example.com/1.jpg' ), 'user_tags' => $tags,
		) );
		$this->assertNotWPError( $result );
		$this->assertSame( $tags, json_decode( $this->bodies[0]['user_tags'], true ) );
		$this->assertArrayNotHasKey( 'is_carousel_item', $this->bodies[0] );
	}

	public function test_twenty_tags_allowed_on_each_slide(): void {
		$input = $this->input();
		$tags = array();
		for ( $i = 0; $i < 20; ++$i ) {
			$tags[] = $this->tag( 'artist_' . $i );
		}
		$input['carousel_user_tags'] = array( $tags, $tags, $tags );
		$result = InstagramPublishAbility::execute_publish( $input );
		$this->assertNotWPError( $result );
		foreach ( array( 0, 1, 2 ) as $index ) {
			$this->assertCount( 20, json_decode( $this->bodies[ $index ]['user_tags'], true ) );
		}
	}

	public function test_invalid_tags_fail_before_http(): void {
		$base = $this->input();
		$cases = array(
			array( 'carousel_user_tags' => array( array() ) ),
			array( 'carousel_user_tags' => 'invalid' ),
			array( 'carousel_user_tags' => array( array(), null, array() ) ),
			array( 'carousel_user_tags' => array( array(), array_fill( 0, 21, $this->tag() ), array() ) ),
			array( 'carousel_user_tags' => array( array(), array( array( 'username' => 'artist', 'x' => 1.1, 'y' => 0 ) ), array() ) ),
			array( 'carousel_user_tags' => array( array(), array( array( 'username' => 'artist', 'x' => '0.5', 'y' => 0 ) ), array() ) ),
			array( 'carousel_user_tags' => array( array(), array( array( 'x' => 0, 'y' => 0 ) ), array() ) ),
			array( 'carousel_user_tags' => array( 1 => array(), 2 => array(), 3 => array() ) ),
			array( 'user_tags' => array( $this->tag() ) ),
			array( 'media_kind' => 'reel' ),
			array( 'media_kind' => 'story' ),
			array( 'image_urls' => array( 'https://example.com/1.jpg' ) ),
		);
		foreach ( $cases as $case ) {
			$result = InstagramPublishAbility::execute_publish( array_replace( $base, $case ) );
			$this->assertWPError( $result );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
		$result = InstagramPublishAbility::execute_publish( array(
			'content' => 'Single', 'image_urls' => array( 'https://example.com/1.jpg' ),
			'user_tags' => array_fill( 0, 21, $this->tag() ),
		) );
		$this->assertWPError( $result );
		$this->assertSame( array(), $this->requests );
	}

	/** @dataProvider failureStatusProvider */
	public function test_rejected_child_names_slide_and_usernames_and_stops( int $status ): void {
		$this->fail_slide = 2;
		$this->failure_status = $status;
		$input = $this->input();
		$input['carousel_user_tags'][1] = array( $this->tag( 'valid_venue' ), $this->tag( 'rejected_artist' ) );
		$result = InstagramPublishAbility::execute_publish( $input );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'slide 2', $result->get_error_message() );
		$this->assertStringContainsString( 'rejected_artist', $result->get_error_message() );
		$this->assertSame( 2, $result->get_error_data()['slide'] );
		$this->assertSame( array( 'valid_venue', 'rejected_artist' ), $result->get_error_data()['usernames'] );
		$this->assertCount( 2, $this->bodies );
		$this->assertCount( 3, $this->requests ); // First child, status, rejected child. No publish.
	}

	public function failureStatusProvider(): array {
		return array( 'HTTP rejection' => array( 400 ), 'Graph error in successful HTTP response' => array( 200 ) );
	}

	public function test_async_child_error_keeps_tag_context(): void {
		$this->container_status = 'ERROR';
		$result = InstagramPublishAbility::execute_publish( $this->input() );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'slide 1', $result->get_error_message() );
		$this->assertStringContainsString( '@venue', $result->get_error_message() );
		$this->assertCount( 1, $this->bodies );
	}
}
