<?php
/**
 * Tests for BingWebmasterAbilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Analytics\BingWebmasterAbilities;
use DataMachine\Core\HttpClient;
use WP_UnitTestCase;

class BingWebmasterAbilitiesTest extends WP_UnitTestCase {

	private BingWebmasterAbilities $abilities;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->abilities = new BingWebmasterAbilities();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );
		parent::tear_down();
	}

	/**
	 * Test ability registration.
	 */
	public function test_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/bing-webmaster' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/bing-webmaster', $ability->get_name() );
	}

	/**
	 * Test fetchStats with invalid action.
	 */
	public function test_fetch_stats_invalid_action(): void {
		$result = BingWebmasterAbilities::fetchStats( [ 'action' => 'invalid_action' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid action', $result['error'] );
	}

	/**
	 * Test fetchStats with missing action.
	 */
	public function test_fetch_stats_missing_action(): void {
		$result = BingWebmasterAbilities::fetchStats( [] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid action', $result['error'] );
	}

	/**
	 * Test fetchStats with missing config.
	 */
	public function test_fetch_stats_missing_config(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );

		$result = BingWebmasterAbilities::fetchStats( [ 'action' => 'query_stats' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not configured', $result['error'] );
	}

	/**
	 * Test fetchStats with API error.
	 */
	public function test_fetch_stats_api_error(): void {
		update_site_option( 'datamachine_bing_webmaster_config', [
			'api_key' => 'test-key',
			'site_url' => 'https://example.com'
		] );

		// Mock HttpClient to return error
		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'ssl.bing.com' ) ) {
				return new \WP_Error( 'http_request_failed', 'Connection timeout' );
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = BingWebmasterAbilities::fetchStats( [
			'action' => 'query_stats',
			'limit' => 10
		] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Failed to connect to Bing Webmaster API', $result['error'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test fetchStats with invalid JSON response.
	 */
	public function test_fetch_stats_invalid_json(): void {
		update_site_option( 'datamachine_bing_webmaster_config', [
			'api_key' => 'test-key',
			'site_url' => 'https://example.com'
		] );

		// Mock HttpClient to return invalid JSON
		$filter = function( $result, $parsed_args, $url ) {
			if ( str_contains( $url, 'ssl.bing.com' ) ) {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => 'invalid json response',
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = BingWebmasterAbilities::fetchStats( [ 'action' => 'query_stats' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Failed to parse', $result['error'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test fetchStats success with default parameters.
	 */
	public function test_fetch_stats_success_defaults(): void {
		update_site_option( 'datamachine_bing_webmaster_config', [
			'api_key' => 'test-key',
			'site_url' => 'https://example.com'
		] );

		$mock_data = [
			'd' => [
				[ 'query' => 'test query 1', 'clicks' => 100 ],
				[ 'query' => 'test query 2', 'clicks' => 50 ]
			]
		];

		// Mock HttpClient to return success
		$filter = function( $result, $parsed_args, $url ) use ( $mock_data ) {
			if ( str_contains( $url, 'ssl.bing.com' ) ) {
				// Verify API key and site_url are in the URL
				$this->assertStringContainsString( 'apikey=test-key', $url );
				$this->assertStringContainsString( 'siteUrl=https://example.com', $url );
				$this->assertStringContainsString( 'GetQueryStats', $url );

				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( $mock_data ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = BingWebmasterAbilities::fetchStats( [ 'action' => 'query_stats' ] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'query_stats', $result['action'] );
		$this->assertSame( 2, $result['results_count'] );
		$this->assertSame( $mock_data['d'], $result['results'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test fetchStats success with custom parameters.
	 */
	public function test_fetch_stats_success_custom_params(): void {
		update_site_option( 'datamachine_bing_webmaster_config', [
			'api_key' => 'test-key'
		] );

		$mock_data = [
			'd' => array_fill( 0, 25, [ 'query' => 'test', 'clicks' => 10 ] )
		];

		// Mock HttpClient to return success
		$filter = function( $result, $parsed_args, $url ) use ( $mock_data ) {
			if ( str_contains( $url, 'ssl.bing.com' ) ) {
				// Verify custom site_url is used instead of config
				$this->assertStringContainsString( 'siteUrl=https://custom.com', $url );
				$this->assertStringContainsString( 'GetRankAndTrafficStats', $url );

				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( $mock_data ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $result;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = BingWebmasterAbilities::fetchStats( [
			'action' => 'traffic_stats',
			'site_url' => 'https://custom.com',
			'limit' => 10
		] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'traffic_stats', $result['action'] );
		$this->assertSame( 10, $result['results_count'] ); // Limited to 10
		$this->assertCount( 10, $result['results'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test all supported actions map to correct endpoints.
	 */
	public function test_all_action_endpoints(): void {
		update_site_option( 'datamachine_bing_webmaster_config', [
			'api_key' => 'test-key',
			'site_url' => 'https://example.com'
		] );

		$actions = [
			'query_stats' => 'GetQueryStats',
			'traffic_stats' => 'GetRankAndTrafficStats',
			'page_stats' => 'GetPageStats',
			'crawl_stats' => 'GetCrawlStats'
		];

		foreach ( $actions as $action => $endpoint ) {
			$filter = function( $result, $parsed_args, $url ) use ( $endpoint ) {
				if ( str_contains( $url, 'ssl.bing.com' ) ) {
					$this->assertStringContainsString( $endpoint, $url );
					return array(
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'body'     => wp_json_encode( array( 'd' => array() ) ),
						'headers'  => array(),
						'cookies'  => array(),
					);
				}
				return $result;
			};
			add_filter( 'pre_http_request', $filter, 10, 3 );

			$result = BingWebmasterAbilities::fetchStats( [ 'action' => $action ] );
			$this->assertTrue( $result['success'], "Action {$action} should succeed" );

			remove_filter( 'pre_http_request', $filter, 10 );
		}
	}

	/**
	 * Test is_configured returns false when no config.
	 */
	public function test_is_configured_false(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );
		$this->assertFalse( BingWebmasterAbilities::is_configured() );
	}

	/**
	 * Test is_configured returns true when api_key present.
	 */
	public function test_is_configured_true(): void {
		update_site_option( 'datamachine_bing_webmaster_config', [ 'api_key' => 'test-key' ] );
		$this->assertTrue( BingWebmasterAbilities::is_configured() );
	}

	/**
	 * Test get_config returns empty array by default.
	 */
	public function test_get_config_empty(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );
		$this->assertSame( [], BingWebmasterAbilities::get_config() );
	}

	/**
	 * Test get_config returns stored configuration.
	 */
	public function test_get_config_returns_stored(): void {
		$config = [ 'api_key' => 'test-key', 'site_url' => 'https://example.com' ];
		update_site_option( 'datamachine_bing_webmaster_config', $config );
		$this->assertSame( $config, BingWebmasterAbilities::get_config() );
	}

	/**
	 * Test permission callback denies access for non-admin users.
	 */
	public function test_permission_callback(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/bing-webmaster' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( [ 'action' => 'query_stats' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}
}