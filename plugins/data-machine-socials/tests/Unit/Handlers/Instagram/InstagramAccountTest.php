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

	private function assertStoredItem( bool $download_succeeds ): void {
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
		add_filter( 'pre_http_request', static fn() => array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array(
				'business_discovery' => array(
					'name'  => 'Venue',
					'media' => array(
						'data' => array(
							array(
								'id'         => '123',
								'caption'    => 'Show tonight',
								'media_type' => 'IMAGE',
								'media_url'  => 'https://example.org/flyer.jpg',
								'permalink'  => 'https://instagram.com/p/123',
							),
						),
					),
				),
			) ),
		) );
		$context = $this->getMockBuilder( ExecutionContext::class )->disableOriginalConstructor()->onlyMethods( array( 'isItemProcessed', 'isItemClaimed', 'isDirect', 'isStandalone', 'downloadFile', 'log' ) )->getMock();
		$context->method( 'isItemProcessed' )->willReturn( false );
		$context->method( 'isItemClaimed' )->willReturn( false );
		$context->method( 'isDirect' )->willReturn( true );
		$context->method( 'isStandalone' )->willReturn( false );
		$context->expects( $this->once() )->method( 'downloadFile' )->with( 'https://example.org/flyer.jpg', 'instagram_image_123.jpg', array( 'timeout' => 30 ) )->willReturn( $download_succeeds ? array(
			'path'     => '/tmp/flyer.jpg',
			'filename' => 'instagram_image_123.jpg',
			'size'     => 100,
		) : null );
		$handler = new class() extends InstagramAccount {
			public function fetchForTest( array $config, ExecutionContext $context ): array {
				return $this->executeFetch( $config, $context );
			}
		};
		$result  = $handler->fetchForTest( array(
			'username'  => 'venue',
			'max_items' => 1,
		), $context );
		if ( ! $download_succeeds ) {
			$this->assertSame( array(), $result );
			return;
		}
		$item = $result['items'][0];
		$this->assertSame( '123', $item['metadata']['item_identifier'] );
		$this->assertSame( 'Show tonight', $item['content'] );
		$this->assertSame( '/tmp/flyer.jpg', $item['file_info']['file_path'] );
		$this->assertSame( 'image/jpeg', $item['file_info']['mime_type'] );
		$this->assertSame( array(
			'source_url'      => 'https://instagram.com/p/123',
			'image_file_path' => '/tmp/flyer.jpg',
		), $item['metadata']['_engine_data'] );
		$this->assertArrayNotHasKey( 'image_info', $item );
	}
}
