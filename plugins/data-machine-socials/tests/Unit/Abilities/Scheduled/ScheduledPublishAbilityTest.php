<?php
/**
 * Scheduled social publish ability tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Scheduled
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Scheduled;

use DataMachineSocials\Abilities\ScheduledPublishAbility;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use WP_UnitTestCase;

class ScheduledPublishAbilityTest extends WP_UnitTestCase {

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
		$this->auth->save_account( array(
			'access_token'     => 'ig_test_tok',
			'user_id'          => '12345',
			'username'         => 'extrachill',
			'token_expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		as_unschedule_all_actions( ScheduledPublishAbility::WORKER_HOOK );
	}

	public function tear_down(): void {
		as_unschedule_all_actions( ScheduledPublishAbility::WORKER_HOOK );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_auth_providers' );
		\DataMachine\Abilities\AuthAbilities::clearCache();
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	private function payload(): array {
		return array(
			'content'     => 'Extra Chill Wednesdays',
			'image_urls'  => array( 'https://example.com/flyer.jpg' ),
			'location_id' => '42',
		);
	}

	private function schedule( array $overrides = array() ) {
		return wp_get_ability( 'datamachine/social-publish-scheduled' )->execute(
			array_merge(
				array(
					'platform'   => 'instagram',
					'payload'    => $this->payload(),
					'publish_at' => time() + HOUR_IN_SECONDS,
				),
				$overrides
			)
		);
	}

	/**
	 * Mock the Graph API.
	 *
	 * @param string $mode success | container_500 | publish_unconfirmed.
	 */
	private function mock_graph( string $mode, int &$publish_calls = 0 ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $mode, &$publish_calls ) {
			if ( str_ends_with( $url, '/12345/media' ) ) {
				if ( 'container_500' === $mode ) {
					return array( 'response' => array( 'code' => 500 ), 'body' => wp_json_encode( array( 'error' => array( 'message' => 'Temporary' ) ) ) );
				}
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'id' => 'c1' ) ) );
			}
			if ( str_contains( $url, 'status_code' ) ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status_code' => 'FINISHED' ) ) );
			}
			if ( str_contains( $url, 'media_publish' ) ) {
				$publish_calls++;
				$body = 'publish_unconfirmed' === $mode ? array() : array( 'id' => 'media_1' );
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
			}
			if ( str_contains( $url, 'permalink' ) ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'permalink' => 'https://www.instagram.com/p/X/' ) ) );
			}
			return $preempt;
		}, 10, 3 );
	}

	private function pending_jobs(): array {
		$jobs = array();
		foreach ( as_get_scheduled_actions( array( 'hook' => ScheduledPublishAbility::WORKER_HOOK, 'status' => 'pending' ) ) as $action ) {
			$jobs[] = $action->get_args()[0];
		}
		return $jobs;
	}

	public function test_schedule_queues_payload_without_publishing(): void {
		$calls = 0;
		$this->mock_graph( 'success', $calls );

		$result = $this->schedule();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $calls );
		$jobs = $this->pending_jobs();
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'instagram', $jobs[0]['platform'] );
		$this->assertSame( $this->payload(), $jobs[0]['payload'] );
		$this->assertSame( 1, $jobs[0]['attempt'] );
		$this->assertStringNotContainsString( 'ig_test_tok', wp_json_encode( $jobs ) );
	}

	public function test_rejects_past_publish_at(): void {
		$result = $this->schedule( array( 'publish_at' => time() - 60 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_publish_at', $result->get_error_code() );
	}

	public function test_rejects_unknown_platform(): void {
		$result = $this->schedule( array( 'platform' => 'myspace' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_platform', $result->get_error_code() );
	}

	public function test_requires_publish_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$result = $this->schedule();

		remove_filter( 'datamachine_cli_bypass_permissions', '__return_false' );
		$this->assertWPError( $result );
		$this->assertCount( 0, $this->pending_jobs() );
	}

	public function test_parse_publish_at_uses_site_timezone_when_none_given(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$year     = (int) gmdate( 'Y' ) + 1;
		$expected = ( new \DateTimeImmutable( "{$year}-10-21T20:00:00", new \DateTimeZone( 'America/New_York' ) ) )->getTimestamp();

		$this->assertSame( $expected, ScheduledPublishAbility::parse_publish_at( "{$year}-10-21 20:00" ) );
		$this->assertSame( $expected, ScheduledPublishAbility::parse_publish_at( "{$year}-10-21T20:00:00-04:00" ) );
	}

	public function test_list_and_cancel(): void {
		$scheduled = $this->schedule();

		$list = wp_get_ability( 'datamachine/social-publish-scheduled-list' )->execute( array() );
		$this->assertSame( $scheduled['schedule_id'], $list['items'][0]['schedule_id'] );
		$this->assertSame( 'pending', $list['items'][0]['status'] );

		$cancel = wp_get_ability( 'datamachine/social-publish-scheduled-cancel' )->execute( array( 'schedule_id' => $scheduled['schedule_id'] ) );
		$this->assertTrue( $cancel['success'] );
		$this->assertCount( 0, $this->pending_jobs() );

		$again = wp_get_ability( 'datamachine/social-publish-scheduled-cancel' )->execute( array( 'schedule_id' => $scheduled['schedule_id'] ) );
		$this->assertWPError( $again );
	}

	public function test_worker_publishes_stored_payload(): void {
		$calls = 0;
		$this->mock_graph( 'success', $calls );

		ScheduledPublishAbility::run_worker( array( 'platform' => 'instagram', 'payload' => $this->payload(), 'attempt' => 1 ) );

		$this->assertSame( 1, $calls );
		$this->assertCount( 0, $this->pending_jobs() );
	}

	public function test_worker_retries_transient_failure_then_gives_up(): void {
		$this->mock_graph( 'container_500' );

		ScheduledPublishAbility::run_worker( array( 'platform' => 'instagram', 'payload' => $this->payload(), 'attempt' => 1 ) );
		$jobs = $this->pending_jobs();
		$this->assertCount( 1, $jobs );
		$this->assertSame( 2, $jobs[0]['attempt'] );

		as_unschedule_all_actions( ScheduledPublishAbility::WORKER_HOOK );
		$this->expectException( \RuntimeException::class );
		ScheduledPublishAbility::run_worker( array( 'platform' => 'instagram', 'payload' => $this->payload(), 'attempt' => ScheduledPublishAbility::MAX_ATTEMPTS ) );
	}

	public function test_worker_never_retries_unconfirmed_publish(): void {
		$calls = 0;
		$this->mock_graph( 'publish_unconfirmed', $calls );

		try {
			ScheduledPublishAbility::run_worker( array( 'platform' => 'instagram', 'payload' => $this->payload(), 'attempt' => 1 ) );
			$this->fail( 'Expected RuntimeException' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'publish_unconfirmed', $e->getMessage() );
		}

		$this->assertSame( 1, $calls );
		$this->assertCount( 0, $this->pending_jobs() );
	}

	public function test_is_retryable(): void {
		$this->assertTrue( ScheduledPublishAbility::is_retryable( new \WP_Error( 'api_error', 'x', array( 'status' => 500 ) ) ) );
		$this->assertFalse( ScheduledPublishAbility::is_retryable( new \WP_Error( 'api_error', 'x', array( 'status' => 400 ) ) ) );
		$this->assertFalse( ScheduledPublishAbility::is_retryable( new \WP_Error( 'publish_unconfirmed', 'x', array( 'status' => 500 ) ) ) );
	}
}
