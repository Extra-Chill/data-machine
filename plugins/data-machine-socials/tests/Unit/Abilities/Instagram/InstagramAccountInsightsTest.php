<?php
/**
 * Instagram account insights ability tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Instagram
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Instagram;

use DataMachineSocials\Abilities\Instagram\InstagramAccountInsightsAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class InstagramAccountInsightsTest extends WP_UnitTestCase {
	private InstagramAuth $auth;
	private InstagramAccountInsightsAbility $ability;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new InstagramAuth();
		\DataMachine\Abilities\AuthAbilities::clearCache();
		add_filter( 'datamachine_auth_providers', function ( $providers ) {
			$providers['instagram'] = $this->auth;
			return $providers;
		} );
		$this->ability = new InstagramAccountInsightsAbility();
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

	private function fake_graph( array &$calls, $insights_body, int $insights_code = 200 ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$calls, $insights_body, $insights_code ) {
			$calls[] = $url;
			if ( str_contains( $url, '/insights?' ) ) {
				return array( 'response' => array( 'code' => $insights_code ), 'body' => wp_json_encode( $insights_body ) );
			}
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'username' => 'extrachill', 'followers_count' => 9295, 'follows_count' => 2206, 'media_count' => 2326 ) ) );
		}, 10, 3 );
	}

	public function test_returns_followers_and_account_totals(): void {
		$this->authenticate();
		$calls = array();
		$this->fake_graph(
			$calls,
			array(
				'data' => array(
					array( 'name' => 'reach', 'total_value' => array( 'value' => 9698 ) ),
					array( 'name' => 'views', 'total_value' => array( 'value' => 41392 ) ),
					array( 'name' => 'accounts_engaged', 'total_value' => array( 'value' => 826 ) ),
				),
			)
		);

		$result = $this->ability->execute( array( 'days' => 7 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 9295, $result['data']['followers_count'] );
		$this->assertSame( 9698, $result['data']['totals']['reach'] );
		$this->assertSame( 41392, $result['data']['totals']['views'] );
		$this->assertNull( $result['data']['totals']['profile_links_taps'], 'A metric Instagram omits is null, never zero.' );
		$this->assertSame( 7, $result['data']['window']['days'] );

		$insights = array_values( array_filter( $calls, static fn( string $url ): bool => str_contains( $url, '/12345/insights?' ) ) );
		$this->assertCount( 1, $insights );
		parse_str( (string) parse_url( $insights[0], PHP_URL_QUERY ), $query );
		$this->assertSame( 'total_value', $query['metric_type'] );
		$this->assertSame( 'reach,views,accounts_engaged,total_interactions,profile_links_taps', $query['metric'] );
		$this->assertSame( 7 * DAY_IN_SECONDS, (int) $query['until'] - (int) $query['since'] );
	}

	public function test_rejects_windows_longer_than_thirty_days(): void {
		$this->authenticate();
		$result = $this->ability->execute( array( 'days' => 31 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
	}

	public function test_missing_scope_has_reauth_guidance(): void {
		$this->authenticate();
		$calls = array();
		$this->fake_graph( $calls, array( 'error' => array( 'code' => 10, 'message' => 'Permission denied' ) ), 400 );
		$result = $this->ability->execute( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_scope', $result->get_error_code() );
	}

	public function test_unauthenticated_account_errors(): void {
		$result = $this->ability->execute( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
	}
}
