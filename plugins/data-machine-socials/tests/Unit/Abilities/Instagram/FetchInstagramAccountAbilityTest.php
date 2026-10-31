<?php
namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;
use DataMachineSocials\Abilities\Instagram\FetchInstagramAccountAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use DataMachineSocials\Handlers\Instagram\InstagramAccountSettings;
use WP_UnitTestCase;

class FetchInstagramAccountAbilityTest extends WP_UnitTestCase {
	private InstagramAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new InstagramAuth();
		$this->auth->save_account( array(
			'access_token'     => 'test-token',
			'user_id'          => '12345',
			'token_expires_at' => time() + 30 * DAY_IN_SECONDS,
		) );
		AuthAbilities::clearCache();
		add_filter( 'datamachine_auth_providers', function ( $providers ) {
			$providers['instagram'] = $this->auth;
			return $providers;
		} );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	private function post( string $id, string $type = 'IMAGE' ): array {
		return array(
			'id'            => $id,
			'media_type'    => $type,
			'timestamp'     => gmdate( 'c' ),
			'caption'       => 'Live MUSIC tonight',
			'media_url'     => 'https://example.org/flyer.jpg',
			'thumbnail_url' => 'https://example.org/thumb.jpg',
			'permalink'     => 'https://instagram.com/p/' . $id,
		);
	}

	private function pages( array $pages, ?int &$requests = null ): void {
		$requests = 0;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $pages, &$requests ) {
			parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$this->assertStringContainsString( 'business_discovery.username(venue)', $query['fields'] );
			$this->assertSame( 'Bearer test-token', $args['headers']['Authorization'] );
			if ( $requests > 0 ) {
				$this->assertStringContainsString( '.after(next)', $query['fields'] );
			}
			$this->assertArrayHasKey( $requests, $pages, 'Unexpected extra HTTP request' );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'business_discovery' => array(
						'username' => 'venue',
						'name'     => 'Venue Name',
						'media'    => $pages[ $requests++ ],
					),
				) ),
			);
		}, 10, 3 );
	}

	public function test_mapping_image_carousel_video_and_fallback(): void {
		$carousel                     = $this->post( '2', 'CAROUSEL_ALBUM' );
		$carousel['children']['data'] = array(
			array(
				'media_type' => 'VIDEO',
				'media_url'  => 'video.mp4',
			),
			array(
				'media_type' => 'IMAGE',
				'media_url'  => 'https://example.org/child.jpg',
			),
		);
		$this->pages( array( array( 'data' => array( $this->post( '1' ), $carousel, $this->post( '3', 'VIDEO' ), $this->post( '4', 'CAROUSEL_ALBUM' ) ) ) ) );
		$result = ( new FetchInstagramAccountAbility() )->execute( array( 'username' => '@venue' ) );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 4, $result['items'] );
		foreach ( array( 'flyer.jpg', 'child.jpg', 'thumb.jpg', 'flyer.jpg' ) as $index => $image ) {
			$item = $result['items'][ $index ];
			$this->assertSame( 'https://example.org/' . $image, $item['data']['image_info']['url'] );
			$this->assertSame( 'Live MUSIC tonight', $item['data']['content'] );
			$this->assertSame( (string) ( $index + 1 ), $item['data']['metadata']['media_id'] );
			$this->assertSame( $item['source_url'], $item['data']['metadata']['source_url'] );
			$this->assertSame( 'Venue Name', $item['data']['metadata']['account_name'] );
		}
	}

	public function test_ability_is_registered_with_fetch_settings(): void {
		new FetchInstagramAccountAbility();
		$ability = wp_get_ability( 'datamachine-socials/fetch-instagram-account' );
		$this->assertNotNull( $ability );
		$this->assertContains( 'username', $ability->get_input_schema()['required'] );
		$this->assertArrayHasKey( 'items', $ability->get_output_schema()['properties'] );
	}

	public function test_recent_context_settings_bounds(): void {
		foreach ( array( array( null, 0 ), array( -1, 0 ), array( '3', 3 ), array( 99, 12 ) ) as [ $input, $expected ] ) {
			$this->assertSame( $expected, InstagramAccountSettings::sanitize( array(
				'username'       => 'venue',
				'recent_context' => $input,
			) )['recent_context'] );
		}
		$field = InstagramAccountSettings::get_fields()['recent_context'];
		$this->assertSame( 0, $field['default'] );
		$this->assertSame( 12, $field['max'] );
	}

	public function test_recent_context_uses_newest_unique_posts_and_excludes_each_item(): void {
		$posts = array();
		foreach ( array( 1, 4, 2, 3, 5 ) as $day ) {
			$post              = $this->post( (string) $day );
			$post['timestamp'] = sprintf( '2026-10-%02dT00:00:00+00:00', $day );
			$post['caption']   = 'Caption ' . $day;
			$posts[]           = $post;
		}
		$posts[] = $posts[1];
		$this->pages( array( array( 'data' => $posts ) ), $requests );
		$ability = new FetchInstagramAccountAbility();
		$result  = $ability->execute( array(
			'username'       => 'venue',
			'recent_context' => 3,
		) );
		$this->assertCount( 5, $result['items'] );
		foreach ( $result['items'] as $item ) {
			$ids     = array_slice( array_values( array_diff( array( '5', '4', '3', '2', '1' ), array( $item['item_id'] ) ) ), 0, 3 );
			$content = $item['data']['content'];
			$this->assertStringStartsWith( 'Caption ' . $item['item_id'] . "\n\nOther recent posts", $content );
			preg_match_all( '/Caption: Caption (\d)/', $content, $matches );
			$this->assertSame( $ids, $matches[1] );
			foreach ( $ids as $id ) {
				$this->assertStringContainsString( 'Permalink: https://instagram.com/p/' . $id, $content );
			}
		}
		$this->assertSame( 1, $requests );
	}

	public function test_zero_context_is_byte_identical_to_default(): void {
		$page = array( 'data' => array( $this->post( '1' ), $this->post( '2' ) ) );
		$this->pages( array( $page, $page ) );
		$ability = new FetchInstagramAccountAbility();
		$default = $ability->execute( array( 'username' => 'venue' ) );
		// The fixture's second request normally expects pagination; reset for a new fetch.
		remove_all_filters( 'pre_http_request' );
		$this->pages( array( $page ) );
		$zero = $ability->execute( array(
			'username'       => 'venue',
			'recent_context' => 0,
		) );
		$this->assertSame( $default, $zero );
		$this->assertSame( 'Live MUSIC tonight', $zero['items'][0]['data']['content'] );
	}

	public function test_username_normalization(): void {
		foreach ( array( '@Venue', 'https://www.instagram.com/Venue/?hl=en', 'instagram.com/venue/', ' venue ' ) as $name ) {
			$this->assertSame( 'venue', InstagramAccountSettings::normalizeUsername( $name ) );
		}
		$this->assertSame( '', InstagramAccountSettings::normalizeUsername( 'venue){id}' ) );
	}

	public function test_filters_stop_at_days_back_and_match_any_keyword(): void {
		$old                 = $this->post( 'old' );
		$old['timestamp']    = gmdate( 'c', time() - 10 * DAY_IN_SECONDS );
		$no_match            = $this->post( 'skip' );
		$no_match['caption'] = 'Lunch';
		$this->pages( array(
			array(
				'data'   => array( $this->post( 'fresh' ), $no_match, $old ),
				'paging' => array( 'cursors' => array( 'after' => 'next' ) ),
			),
		), $requests );
		$result = ( new FetchInstagramAccountAbility() )->execute( array(
			'username'  => 'venue',
			'days_back' => 2,
			'search'    => 'flyer, music',
		) );
		$this->assertSame( array( 'fresh' ), array_column( $result['items'], 'item_id' ) );
		$this->assertSame( 1, $requests );
	}

	public function test_collector_dedup_paginates_past_processed_items(): void {
		$this->pages( array(
			array(
				'data'   => array( $this->post( 'processed' ) ),
				'paging' => array( 'cursors' => array( 'after' => 'next' ) ),
			),
			array( 'data' => array( $this->post( 'processed' ), $this->post( 'fresh' ), $this->post( 'fresh' ) ) ),
		), $requests );
		$context = $this->getMockBuilder( ExecutionContext::class )->disableOriginalConstructor()->onlyMethods( array( 'isItemProcessed', 'isItemClaimed', 'isDirect', 'isStandalone' ) )->getMock();
		$context->method( 'isItemProcessed' )->willReturnCallback( static fn( $id ) => 'processed' === $id );
		$context->method( 'isItemClaimed' )->willReturn( false );
		$context->method( 'isDirect' )->willReturn( true );
		$context->method( 'isStandalone' )->willReturn( false );
		$result = ( new FetchInstagramAccountAbility() )->executeWithCollector( array(
			'username'       => 'venue',
			'recent_context' => 3,
		), new FreshCandidateCollector( $context, 2 ) );
		$this->assertSame( array( 'fresh' ), array_column( $result['items'], 'item_id' ) );
		$this->assertStringContainsString( 'Permalink: https://instagram.com/p/processed', $result['items'][0]['data']['content'] );
		$this->assertSame( 2, $requests );
	}

	public function test_direct_cap_and_duplicate_ids(): void {
		$this->pages( array(
			array(
				'data'   => array( $this->post( '1' ), $this->post( '1' ), $this->post( '2' ), $this->post( '3' ) ),
				'paging' => array( 'cursors' => array( 'after' => 'next' ) ),
			),
		), $requests );
		$result = ( new FetchInstagramAccountAbility() )->execute( array(
			'username'  => 'venue',
			'max_items' => 2,
		) );
		$this->assertSame( array( '1', '2' ), array_column( $result['items'], 'item_id' ) );
		$this->assertSame( 1, $requests );
	}

	public function test_graph_errors(): void {
		foreach ( array(
			4   => 'instagram_rate_limit',
			17  => 'instagram_rate_limit',
			32  => 'instagram_rate_limit',
			613 => 'instagram_rate_limit',
			190 => 'instagram_auth',
			102 => 'instagram_auth',
			100 => 'instagram_account_unavailable',
			2   => 'instagram_api',
		) as $code => $expected ) {
			remove_all_filters( 'pre_http_request' );
			add_filter( 'pre_http_request', static fn() => array(
				'response' => array( 'code' => 400 ),
				'body'     => wp_json_encode( array(
					'error' => array(
						'code'    => $code,
						'message' => 100 === $code ? 'Invalid user id' : 'Error',
					),
				) ),
			) );
			$result = ( new FetchInstagramAccountAbility() )->execute( array( 'username' => 'venue' ) );
			$this->assertWPError( $result );
			$this->assertSame( $expected, $result->get_error_code() );
		}
	}

	public function test_missing_auth_never_calls_http(): void {
		delete_site_option( 'datamachine_auth_data' );
		add_filter( 'pre_http_request', function () { $this->fail( 'HTTP must not run without auth' );
		} );
		$result = ( new FetchInstagramAccountAbility() )->execute( array( 'username' => 'venue' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'instagram_auth', $result->get_error_code() );
	}
}
