<?php
/**
 * Tests for handler-owned publish composer contracts.
 *
 * @package DataMachineSocials\Tests\Unit
 */

use DataMachine\Abilities\HandlerAbilities;
use DataMachineSocials\PublishComposerContract;
use DataMachineSocials\RestApi;

final class PublishComposerContractTest extends WP_UnitTestCase {

	public function test_every_registered_publish_handler_has_a_routable_composer_contract(): void {
		$handlers  = ( new HandlerAbilities() )->getAllHandlers();
		$platforms = array();

		foreach ( $handlers as $slug => $handler ) {
			if ( 'publish' !== ( $handler['type'] ?? 'publish' ) ) {
				continue;
			}

			$platform = (string) ( $handler['auth_provider_key'] ?? $slug );
			$contract = PublishComposerContract::for_handler( $handler );
			$this->assertIsArray( $contract, "{$platform} must declare composer metadata" );
			$this->assertNotEmpty( $contract['mediaKinds'], "{$platform} must declare supported media kinds" );
			$this->assertContains( $contract['target']['transport'], array( 'rest', 'ability' ) );
			$this->assertNotEmpty( $contract['target']['name'] );
			$this->assertNotEmpty( $contract['inputSchema'], "{$platform} must expose composition inputs" );
			$platforms[ $platform ] = $contract;
		}

		$registered = array_keys( $platforms );
		sort( $registered );
		$this->assertSame( array( 'bluesky', 'facebook', 'instagram', 'linkedin', 'mastodon', 'pinterest', 'threads', 'tiktok', 'tumblr', 'twitter', 'youtube' ), $registered );
		$this->assertTrue( $platforms['instagram']['crossPostCompatible'] );
		$this->assertFalse( $platforms['tiktok']['crossPostCompatible'] );
		$this->assertSame( 'datamachine/tiktok-publish', $platforms['tiktok']['target']['name'] );
		$this->assertContains( 'privacy_level', array_keys( $platforms['tiktok']['inputSchema']['properties'] ) );
		$this->assertSame( 'datamachine/youtube-upload', $platforms['youtube']['target']['name'] );
		$this->assertContains( 'video_url', $platforms['youtube']['inputSchema']['oneOf'][1]['required'] );
	}

	public function test_cross_post_contract_rejects_specialized_and_unsupported_media_targets(): void {
		$specialized = PublishComposerContract::validate_cross_post( array( 'tiktok' ), 'video' );
		$unsupported = PublishComposerContract::validate_cross_post( array( 'facebook' ), 'carousel' );

		$this->assertWPError( $specialized );
		$this->assertSame( 'social_cross_post_unsupported_channel', $specialized->get_error_code() );
		$this->assertWPError( $unsupported );
		$this->assertSame( 'social_cross_post_unsupported_channel_media', $unsupported->get_error_code() );
		$this->assertTrue( PublishComposerContract::validate_cross_post( array( 'instagram', 'twitter' ), 'carousel' ) );
	}

	public function test_rest_cross_post_rejects_an_unsupported_combination_before_scheduling(): void {
		$request = new WP_REST_Request( 'POST', '/datamachine/v1/socials/post' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'platforms' => array( 'youtube' ),
					'caption'   => 'A specialized upload.',
					'media_kind' => 'image',
					'images'    => array( array( 'url' => 'https://example.test/image.jpg' ) ),
				)
			)
		);

		$response = RestApi::cross_post( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'social_cross_post_unsupported_channel', $response->get_data()['code'] );
	}
}
