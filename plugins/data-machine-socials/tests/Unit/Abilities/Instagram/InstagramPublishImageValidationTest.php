<?php
/**
 * Instagram feed image validation and carousel regression tests (#299).
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachine\Abilities\AuthAbilities;
use DataMachineSocials\Abilities\Instagram\InstagramPublishAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramPublishImageValidationTest extends WP_UnitTestCase {

	private InstagramAuth $auth;
	private int $auth_calls = 0;
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		AuthAbilities::clearCache();
		$this->auth = new InstagramAuth();
		add_filter( 'datamachine_auth_providers', function ( $providers ) {
			++$this->auth_calls;
			$providers['instagram'] = $this->auth;
			return $providers;
		} );
		// Never allow a regression to send a real HTTP request.
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			$this->requests[] = array( 'url' => $url, 'args' => $args );
			return new \WP_Error( 'unexpected_http', 'Unexpected HTTP request in image validation test' );
		}, 10, 3 );
		new InstagramPublishAbility();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	/** @dataProvider unsupportedImageProvider */
	public function test_rejects_non_jpeg_before_auth_or_http( string $file, bool $carousel ): void {
		$url = 'https://example.com/' . $file;
		$images = $carousel ? array( 'https://example.com/valid.jpg', $url ) : array( $url );
		$result = InstagramPublishAbility::execute_publish( array( 'content' => 'Invalid image', 'image_urls' => $images ) );

		$this->assertWPError( $result );
		$this->assertSame( 'unsupported_image_format', $result->get_error_code() );
		$this->assertStringContainsString( 'JPEG (.jpg or .jpeg)', $result->get_error_message() );
		$this->assertStringContainsString( $url, $result->get_error_message() );
		$this->assertSame( 0, $this->auth_calls );
		$this->assertSame( array(), $this->requests );
	}

	public function unsupportedImageProvider(): array {
		$cases = array();
		foreach ( array( 'photo.webp', 'photo.PNG', 'photo.gif', 'photo', 'photo.webp?name=photo.jpg' ) as $file ) {
			$cases[ $file . ' single' ] = array( $file, false );
			$cases[ $file . ' carousel' ] = array( $file, true );
		}
		return $cases;
	}

	/** @dataProvider jpegProvider */
	public function test_jpeg_url_path_passes_validation( string $file ): void {
		$result = InstagramPublishAbility::execute_publish( array(
			'content' => 'JPEG', 'image_urls' => array( 'https://example.com/' . $file ),
		) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
		$this->assertGreaterThan( 0, $this->auth_calls );
		$this->assertSame( array(), $this->requests );
	}

	public function jpegProvider(): array {
		return array(
			array( 'photo.jpg' ),
			array( 'photo.jpeg' ),
			array( 'photo.JPEG?token=abc#fragment' ),
			array( 'photo.JPG?format=webp' ),
		);
	}

	public function test_malformed_urls_and_oversized_carousels_fail_before_auth(): void {
		foreach ( array( array( 'not-a-url.jpg' ), array_fill( 0, 11, 'https://example.com/photo.jpg' ) ) as $images ) {
			$result = InstagramPublishAbility::execute_publish( array( 'content' => 'Invalid', 'image_urls' => $images ) );
			$this->assertWPError( $result );
			$this->assertSame( 'missing_param', $result->get_error_code() );
		}
		$this->assertSame( 0, $this->auth_calls );
		$this->assertSame( array(), $this->requests );
	}

	/** @dataProvider carouselSizeProvider */
	public function test_jpeg_images_create_children_and_carousel_in_order( int $count ): void {
		$this->auth->save_account( array(
			'access_token' => 'ig_test_tok', 'user_id' => '12345', 'username' => 'extrachill',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );
		$bodies = array();
		$published = array();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$bodies, &$published ) {
			if ( str_ends_with( $url, '/12345/media' ) && 'POST' === $args['method'] ) {
				$bodies[] = $args['body'];
				$data = array( 'id' => 'container_' . count( $bodies ) );
			} elseif ( str_contains( $url, 'status_code' ) ) {
				$data = array( 'status_code' => 'FINISHED' );
			} elseif ( str_ends_with( $url, '/12345/media_publish' ) ) {
				$published[] = $args['body']['creation_id'];
				$data = array( 'id' => 'media_1' );
			} elseif ( str_contains( $url, 'permalink' ) ) {
				$data = array( 'id' => 'media_1', 'permalink' => 'https://www.instagram.com/p/test/' );
			} else {
				$this->fail( 'Unexpected Graph API request: ' . $url );
			}
			$this->assertStringStartsWith( InstagramPublishAbility::GRAPH_API_URL . '/', $url );
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
		}, 20, 3 );

		$images = array_slice( array( 'https://example.com/1.jpg', 'https://example.com/2.jpeg', 'https://example.com/3.jpg', 'https://example.com/4.jpeg' ), 0, $count );
		$result = InstagramPublishAbility::execute_publish( array( 'content' => 'Gallery', 'image_urls' => $images ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'carousel', $result['media_kind'] );
		$this->assertCount( $count + 1, $bodies );
		$children = array();
		foreach ( $images as $index => $image ) {
			$this->assertSame( $image, $bodies[ $index ]['image_url'] );
			$this->assertSame( 'true', $bodies[ $index ]['is_carousel_item'] );
			$children[] = 'container_' . ( $index + 1 );
		}
		$this->assertSame( 'CAROUSEL', $bodies[ $count ]['media_type'] );
		$this->assertSame( implode( ',', $children ), $bodies[ $count ]['children'] );
		$this->assertSame( array( 'container_' . ( $count + 1 ) ), $published );
	}

	public function carouselSizeProvider(): array {
		return array( 'two images' => array( 2 ), 'four images' => array( 4 ) );
	}
}
