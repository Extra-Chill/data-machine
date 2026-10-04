<?php
/**
 * Instagram insights ability tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachineSocials\Abilities\Instagram\InstagramInsightsAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramInsightsTest extends WP_UnitTestCase {
	private InstagramAuth $auth;
	private InstagramInsightsAbility $ability;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new InstagramAuth();
		\DataMachine\Abilities\AuthAbilities::clearCache();
		add_filter( 'datamachine_auth_providers', function ( $providers ) {
			$providers['instagram'] = $this->auth;
			return $providers;
		} );
		$this->ability = new InstagramInsightsAbility();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		\DataMachine\Abilities\AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	private function authenticate(): void {
		$this->auth->save_account( array( 'access_token' => 'test-token', 'user_id' => '12345', 'token_expires_at' => time() + DAY_IN_SECONDS ) );
	}

	public function test_reel_requests_reel_metrics(): void {
		$this->authenticate();
		$calls = array();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$calls ) {
			$calls[] = $url;
			$body = str_contains( $url, '/insights?' ) ? array( 'data' => array() ) : array( 'media_type' => 'VIDEO', 'media_product_type' => 'REELS' );
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
		}, 10, 3 );
		$result = $this->ability->execute( array( 'media_id' => 'media-1' ) );
		parse_str( (string) parse_url( $calls[1], PHP_URL_QUERY ), $query );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'views,reach,saved,shares,total_interactions,ig_reels_avg_watch_time,ig_reels_video_view_total_time', $query['metric'] );
	}

	public function test_image_requests_feed_metrics(): void {
		$this->authenticate();
		$calls = array();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$calls ) {
			$calls[] = $url;
			$body = str_contains( $url, '/insights?' ) ? array( 'data' => array() ) : array( 'media_type' => 'IMAGE', 'media_product_type' => 'FEED' );
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
		}, 10, 3 );
		$this->ability->execute( array( 'media_id' => 'media-2' ) );
		parse_str( (string) parse_url( $calls[1], PHP_URL_QUERY ), $query );
		$this->assertSame( 'views,reach,saved,shares,total_interactions', $query['metric'] );
	}

	public function test_missing_insights_scope_has_reauth_guidance(): void {
		$this->authenticate();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			$body = str_contains( $url, '/insights?' )
				? array( 'error' => array( 'code' => 10, 'message' => 'Permission denied' ) )
				: array( 'media_type' => 'VIDEO', 'media_product_type' => 'REELS' );
			return array( 'response' => array( 'code' => str_contains( $url, '/insights?' ) ? 400 : 200 ), 'body' => wp_json_encode( $body ) );
		}, 10, 3 );
		$result = $this->ability->execute( array( 'media_id' => 'media-3' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_scope', $result->get_error_code() );
		$this->assertStringContainsString( 'instagram_manage_insights', $result->get_error_message() );
		$this->assertStringContainsString( 'Re-authenticate', $result->get_error_message() );
	}

	public function test_auth_requests_insights_scope(): void {
		$this->assertContains( 'instagram_manage_insights', explode( ',', InstagramAuth::SCOPES ) );
	}
}
