<?php
/** Instagram message fetch tests. @package DataMachineSocials */
namespace DataMachineSocials\Tests\Unit\Handlers\Instagram;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\ExecutionContext;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use DataMachineSocials\Handlers\Instagram\InstagramMessages;
use DataMachineSocials\Handlers\Instagram\InstagramMessagesSettings;
use WP_UnitTestCase;

class InstagramMessagesTest extends WP_UnitTestCase {
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_settings(): void {
		$defaults = InstagramMessagesSettings::sanitize( array() );
		$this->assertSame( 10, $defaults['max_items'] );
		$this->assertSame( 7, $defaults['days_back'] );
		$this->assertSame( 20, $defaults['conversation_limit'] );
		$this->assertTrue( $defaults['require_attachment'] );
		$settings = InstagramMessagesSettings::sanitize(
			array(
				'max_items'          => 999,
				'days_back'          => -4,
				'conversation_limit' => 0,
				'require_attachment' => 'false',
			)
		);
		$this->assertSame( 500, $settings['max_items'] );
		$this->assertSame( 0, $settings['days_back'] );
		$this->assertSame( 1, $settings['conversation_limit'] );
		$this->assertFalse( $settings['require_attachment'] );
	}

	public function test_maps_inbound_image_and_stable_id(): void {
		$items = $this->fetch();
		$this->assertCount( 1, $items );
		$item = $items[0];
		$this->assertSame( 'inbound', $item['metadata']['item_identifier'] );
		$this->assertSame( 'Show tonight', $item['content'] );
		$this->assertSame( 'fan', $item['metadata']['sender_username'] );
		$this->assertLessThanOrEqual( 5, abs( time() - strtotime( $item['metadata']['timestamp'] ) ) );
		$this->assertSame( 'thread', $item['metadata']['conversation_id'] );
		$this->assertSame( '/tmp/flyer.jpg', $item['file_info']['file_path'] );
		$this->assertSame( 'image/jpeg', $item['file_info']['mime_type'] );
		$this->assertSame( '/tmp/flyer.jpg', $item['metadata']['_engine_data']['image_file_path'] );
	}

	public function test_optional_attachment_allows_text_and_video_but_skips_outbound_and_old_messages(): void {
		$items = $this->fetch( false );
		$this->assertSame( array( 'inbound', 'text', 'video' ), array_column( array_column( $items, 'metadata' ), 'item_identifier' ) );
		$this->assertArrayNotHasKey( 'file_info', $items[1] );
	}

	public function test_processed_messages_are_not_downloaded(): void {
		$this->assertSame( array(), $this->fetch( true, true ) );
	}

	public function test_failed_download_does_not_emit_text_only_item(): void {
		$this->assertSame( array(), $this->fetch( true, false, false ) );
	}

	private function fetch( bool $require_attachment = true, bool $processed = false, bool $download = true ): array {
		$auth = new class() extends InstagramAuth {
			public function get_page_context(): array|\WP_Error {
				return array(
					'id'           => 'page',
					'access_token' => 'page-token',
				);
			}
		};
		$auth->save_account(
			array(
				'access_token'     => 'token',
				'user_id'          => 'self',
				'token_expires_at' => time() + DAY_IN_SECONDS,
			)
		);
		AuthAbilities::clearCache();
		add_filter(
			'datamachine_auth_providers',
			static function ( $providers ) use ( $auth ) {
				$providers['instagram'] = $auth;
				return $providers;
			}
		);
		$base     = array(
			'id'           => 'inbound',
			'message'      => 'Show tonight',
			'created_time' => gmdate( 'c' ),
			'from'         => array(
				'id'       => 'fan-id',
				'username' => 'fan',
			),
			'attachments'  => array( 'data' => array( array( 'image_data' => array( 'url' => 'https://example.org/flyer.jpg' ) ) ) ),
		);
		$messages = array(
			$base,
			array_replace(
				$base,
				array(
					'id'   => 'outbound',
					'from' => array( 'id' => 'self' ),
				)
			),
			array_replace(
				$base,
				array(
					'id'           => 'old',
					'created_time' => gmdate( 'c', time() - 10 * DAY_IN_SECONDS ),
				)
			),
			array_replace(
				$base,
				array(
					'id'          => 'text',
					'attachments' => array(),
				)
			),
			array_replace(
				$base,
				array(
					'id'          => 'video',
					'attachments' => array( 'data' => array( array( 'video_data' => array( 'url' => 'https://example.org/video.mp4' ) ) ) ),
				)
			),
			$base,
		);
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $messages ) {
				$this->assertSame( 'GET', $args['method'] );
				$body = str_contains( $url, '/conversations?' ) ? array( 'data' => array( array( 'id' => 'thread' ) ) ) : array( 'messages' => array( 'data' => $messages ) );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $body ),
				);
			},
			10,
			3
		);
		$context = $this->getMockBuilder( ExecutionContext::class )->disableOriginalConstructor()->onlyMethods( array( 'isItemProcessed', 'isItemClaimed', 'isDirect', 'downloadFile', 'log' ) )->getMock();
		$context->method( 'isItemProcessed' )->willReturn( $processed );
		$context->method( 'isItemClaimed' )->willReturn( false );
		$context->method( 'isDirect' )->willReturn( true );
		$context->expects( $processed ? $this->never() : $this->once() )->method( 'downloadFile' )->willReturn(
			$download ? array(
				'path'     => '/tmp/flyer.jpg',
				'filename' => 'flyer.jpg',
				'size'     => 100,
			) : null
		);
		$handler = new class() extends InstagramMessages {
			public function fetchForTest( array $config, ExecutionContext $context ): array {
				return $this->executeFetch( $config, $context );
			}
		};
		return $handler->fetchForTest( array( 'require_attachment' => $require_attachment ), $context )['items'] ?? array();
	}
}
