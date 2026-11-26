<?php
namespace DataMachineSocials\Tests\Unit\Handlers\Instagram;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\ExecutionContext;
use DataMachineSocials\Handlers\Instagram\InstagramAccount;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramAccountTest extends WP_UnitTestCase {
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_pipeline_stores_image_and_wires_engine_data(): void {
		$this->assertStoredItem( true );
	}

	public function test_failed_download_does_not_emit_caption_only_item(): void {
		$this->assertStoredItem( false );
	}

	public function test_recent_context_is_appended_without_emitting_or_processing_context_posts(): void {
		$this->assertStoredItem( true, 3 );
	}

	public function test_explicit_zero_keeps_original_content(): void {
		$this->assertStoredItem( true, 0 );
	}

	public function test_url_claim_matrix(): void {
		$claims = array(
			'https://www.instagram.com/Venue/'             => 'venue',
			'https://instagram.com/venue'                  => 'venue',
			'http://instagram.com/venue/'                  => 'venue',
			'https://www.instagram.com/the.venue_1/?hl=en' => 'the.venue_1',
			'https://instagram.com/venue#posts'            => 'venue',
			'@Venue'                                       => 'venue',
		);
		foreach ( $claims as $input => $username ) {
			$this->assertSame( array( 'username' => $username ), InstagramAccount::claimUrl( $input ), $input );
		}
		foreach ( array(
			'https://www.instagram.com/p/ABC123/',
			'https://www.instagram.com/reel/ABC123/',
			'https://www.instagram.com/reels/',
			'https://www.instagram.com/explore/',
			'https://www.instagram.com/explore/tags/music/',
			'https://www.instagram.com/stories/venue/123/',
			'https://www.instagram.com/venue/p/ABC123/',
			'https://www.instagram.com/',
			'https://instagram.com/bad-name!/',
			'https://notinstagram.com/venue',
			'https://instagram.com.evil.test/venue',
			'https://facebook.com/venue',
			'ftp://instagram.com/venue',
			'venue',
			'@venue/extra',
			'',
		) as $input ) {
			$this->assertNull( InstagramAccount::claimUrl( $input ), $input );
		}
	}

	public function test_registration_declares_url_claim_and_ai_guidance(): void {
		// Earlier tests may have registered the handler under hooks that were since restored.
		$registered = new \ReflectionProperty( InstagramAccount::class, 'registered_handlers' );
		$registered->setValue( null, array() );
		new InstagramAccount();
		$handlers = apply_filters( 'datamachine_handlers', array(), 'fetch' );
		$meta     = $handlers['instagram_account']['meta'] ?? array();
		$this->assertSame( 10, $meta['url_claim_priority'] );
		$this->assertTrue( is_callable( $meta['url_claim'] ) );
		$this->assertSame( array( 'username' => 'venue' ), call_user_func( $meta['url_claim'], 'https://instagram.com/venue/' ) );
		$this->assertSame( InstagramAccount::AI_GUIDANCE, $meta['ai_guidance'] );
		foreach ( array( 'context only', 'never create an item from a context post', 'Read the image carefully', 'post timestamp', 'permalink' ) as $needle ) {
			$this->assertStringContainsString( $needle, $meta['ai_guidance'] );
		}
		// Domain rules belong to the consuming flow, not the handler.
		$this->assertStringNotContainsString( 'reject_source', $meta['ai_guidance'] );
		$this->assertStringNotContainsString( 'live music', $meta['ai_guidance'] );
	}

	private function assertStoredItem( bool $download_succeeds, ?int $recent_context = null ): void {
		$auth = new InstagramAuth();
		$auth->save_account( array(
			'access_token'     => 'test-token',
			'user_id'          => '12345',
			'token_expires_at' => time() + DAY_IN_SECONDS,
		) );
		AuthAbilities::clearCache();
		add_filter( 'datamachine_auth_providers', static function ( $providers ) use ( $auth ) {
			$providers['instagram'] = $auth;
			return $providers;
		} );
		$requests = 0;
		add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$requests ) {
			if ( str_contains( $url, 'fields=' ) ) {
				++$requests;
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'business_discovery' => array(
						'name'  => 'Venue',
						'media' => array(
							'data' => array(
								array(
									'id'         => '123',
									'caption'    => 'Show tonight',
									'timestamp'  => '2026-10-15T00:00:00+00:00',
									'media_type' => 'IMAGE',
									'media_url'  => 'https://example.org/flyer.jpg',
									'permalink'  => 'https://instagram.com/p/123',
								),
								array(
									'id'        => 'context-1',
									'timestamp' => '2026-10-14T00:00:00+00:00',
									'permalink' => 'https://instagram.com/p/context-1',
									'caption'   => 'Doors at 7pm',
								),
								array(
									'id'        => 'context-2',
									'timestamp' => '2026-10-13T00:00:00+00:00',
									'permalink' => 'https://instagram.com/p/context-2',
									'caption'   => 'At the Waverly',
								),
								array(
									'id'        => 'context-3',
									'timestamp' => '2026-10-12T00:00:00+00:00',
									'permalink' => 'https://instagram.com/p/context-3',
									'caption'   => 'Tickets available',
								),
							),
						),
					),
				) ),
			);
		}, 10, 3 );
		$context = $this->getMockBuilder( ExecutionContext::class )->disableOriginalConstructor()->onlyMethods( array( 'isItemProcessed', 'isItemClaimed', 'isDirect', 'isStandalone', 'downloadFile', 'log' ) )->getMock();
		$context->expects( $this->once() )->method( 'isItemProcessed' )->with( '123' )->willReturn( false );
		$context->method( 'isItemClaimed' )->willReturn( false );
		$context->method( 'isDirect' )->willReturn( true );
		$context->method( 'isStandalone' )->willReturn( false );
		$context->expects( $this->once() )->method( 'downloadFile' )->with( 'https://example.org/flyer.jpg', 'instagram_image_123.jpg', array( 'timeout' => 30 ) )->willReturn( $download_succeeds ? array(
			'path'     => '/tmp/flyer.jpg',
			'filename' => 'instagram_image_123.jpg',
			'size'     => 100,
		) : null );
		$handler = new class() extends InstagramAccount {
			public function fetchForTest( array $config, ExecutionContext $context ): array|\WP_Error {
				return $this->executeFetch( $config, $context );
			}
		};
		$config  = array(
			'username'  => 'venue',
			'max_items' => 1,
		);
		if ( null !== $recent_context ) {
			$config['recent_context'] = $recent_context;
		}
		$result = $handler->fetchForTest( $config, $context );
		$this->assertSame( 1, $requests );
		if ( ! $download_succeeds ) {
			$this->assertSame( array(), $result );
			return;
		}
		$this->assertCount( 1, $result['items'] );
		$item = $result['items'][0];
		$this->assertSame( '123', $item['metadata']['item_identifier'] );
		$expected_content = 'Show tonight';
		if ( $recent_context ) {
			$expected_content .= "\n\nOther recent posts from this account (context only — do not create items from these alone)\n\n"
				. "Timestamp: 2026-10-14T00:00:00+00:00\nPermalink: https://instagram.com/p/context-1\nCaption: Doors at 7pm\n\n"
				. "Timestamp: 2026-10-13T00:00:00+00:00\nPermalink: https://instagram.com/p/context-2\nCaption: At the Waverly\n\n"
				. "Timestamp: 2026-10-12T00:00:00+00:00\nPermalink: https://instagram.com/p/context-3\nCaption: Tickets available";
		}
		$this->assertSame( $expected_content, $item['content'] );
		$this->assertSame( '/tmp/flyer.jpg', $item['file_info']['file_path'] );
		$this->assertSame( 'image/jpeg', $item['file_info']['mime_type'] );
		$this->assertSame( array(
			'source_url'      => 'https://instagram.com/p/123',
			'image_file_path' => '/tmp/flyer.jpg',
		), $item['metadata']['_engine_data'] );
		$this->assertArrayNotHasKey( 'image_info', $item );
	}
}
