<?php
/**
 * InstagramPublishAbility location tagging and publish-step error tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachineSocials\Abilities\Instagram\InstagramPublishAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramPublishLocationTest extends WP_UnitTestCase {

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

		$this->auth->save_account( array(
			'access_token'     => 'ig_test_tok',
			'user_id'          => '12345',
			'username'         => 'extrachill',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		\DataMachine\Abilities\AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	/**
	 * Mock the Graph API, recording container bodies.
	 *
	 * @param array $bodies        Collected container POST bodies.
	 * @param bool  $publish_fails Whether media_publish returns no ID.
	 */
	private function mock_graph( array &$bodies, bool $publish_fails = false ): void {
		$counter = 0;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$bodies, &$counter, $publish_fails ) {
			if ( str_ends_with( $url, '/12345/media' ) && 'POST' === $args['method'] ) {
				$bodies[] = $args['body'];
				$counter++;
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'id' => 'container_' . $counter ) ) );
			}
			if ( str_contains( $url, 'status_code' ) ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status_code' => 'FINISHED' ) ) );
			}
			if ( str_contains( $url, '12345/media_publish' ) ) {
				$body = $publish_fails ? array( 'error' => array( 'message' => 'Fatal' ) ) : array( 'id' => 'media_1' );
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
			}
			if ( str_contains( $url, 'permalink' ) ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'id' => 'media_1', 'permalink' => 'https://www.instagram.com/p/X/' ) ) );
			}
			return $preempt;
		}, 10, 3 );
	}

	public function test_single_image_sends_location_id(): void {
		$bodies = array();
		$this->mock_graph( $bodies );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'       => 'Show tonight',
			'image_urls'    => array( 'https://example.com/flyer.jpg' ),
			'collaborators' => array( 'theburgundylounge' ),
			'location_id'   => '100083277275578',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '100083277275578', $bodies[0]['location_id'] );
		$this->assertSame( 'theburgundylounge', $bodies[0]['collaborators'] );
	}

	public function test_carousel_sends_location_id_on_parent_only(): void {
		$bodies = array();
		$this->mock_graph( $bodies );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'     => 'Gallery',
			'image_urls'  => array( 'https://example.com/1.jpg', 'https://example.com/2.jpg' ),
			'location_id' => '42',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 3, $bodies );
		$this->assertArrayNotHasKey( 'location_id', $bodies[0] );
		$this->assertArrayNotHasKey( 'location_id', $bodies[1] );
		$this->assertSame( 'CAROUSEL', $bodies[2]['media_type'] );
		$this->assertSame( '42', $bodies[2]['location_id'] );
	}

	public function test_reel_sends_location_id(): void {
		$bodies = array();
		$this->mock_graph( $bodies );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'     => 'Reel',
			'media_kind'  => 'reel',
			'video_url'   => 'https://example.com/v.mp4',
			'location_id' => '42',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '42', $bodies[0]['location_id'] );
	}

	public function test_omits_location_id_when_not_given(): void {
		$bodies = array();
		$this->mock_graph( $bodies );

		InstagramPublishAbility::execute_publish( array(
			'content'    => 'No location',
			'image_urls' => array( 'https://example.com/flyer.jpg' ),
		) );

		$this->assertArrayNotHasKey( 'location_id', $bodies[0] );
	}

	public function test_rejects_non_numeric_location_id(): void {
		$result = InstagramPublishAbility::execute_publish( array(
			'content'     => 'Bad',
			'image_urls'  => array( 'https://example.com/flyer.jpg' ),
			'location_id' => 'starlight',
		) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_location', $result->get_error_code() );
	}

	public function test_rejects_location_on_story(): void {
		$result = InstagramPublishAbility::execute_publish( array(
			'content'         => 'Story',
			'media_kind'      => 'story',
			'story_image_url' => 'https://example.com/s.jpg',
			'location_id'     => '42',
		) );

		$this->assertWPError( $result );
		$this->assertSame( 'unsupported_location', $result->get_error_code() );
	}

	public function test_publish_step_failure_is_publish_unconfirmed(): void {
		$bodies = array();
		$this->mock_graph( $bodies, true );

		$result = InstagramPublishAbility::execute_publish( array(
			'content'    => 'Will fail',
			'image_urls' => array( 'https://example.com/flyer.jpg' ),
		) );

		$this->assertWPError( $result );
		$this->assertSame( 'publish_unconfirmed', $result->get_error_code() );
	}
}
