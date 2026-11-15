<?php
/**
 * Hetzner Cloud read-only integration tests (#3945).
 *
 * All HTTP is intercepted with the core `pre_http_request` filter — no live
 * token or network access is needed.
 *
 * @package DataMachine\Tests\Unit\Abilities\Hetzner
 */

namespace DataMachine\Tests\Unit\Abilities\Hetzner;

use DataMachineBusiness\Abilities\Hetzner\HetznerAbilities;
use DataMachineBusiness\Abilities\Hetzner\HetznerHealth;
use WP_UnitTestCase;

class HetznerAbilitiesTest extends WP_UnitTestCase {

	private const TOKEN = 'hz-secret-token-value';

	/**
	 * Recorded outbound requests: list of {url, args}.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $requests = array();

	/**
	 * Queued responses: each is {code, body, headers?} or a callable(url): array.
	 *
	 * @var array<int,mixed>
	 */
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		$this->requests  = array();
		$this->responses = array();
		HetznerHealth::reset();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		update_site_option( HetznerAbilities::CONFIG_OPTION, array( 'api_token' => self::TOKEN ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		remove_all_filters( 'datamachine_hetzner_backup_stale_hours' );
		delete_site_option( HetznerAbilities::CONFIG_OPTION );
		HetznerHealth::reset();
		parent::tear_down();
	}

	/**
	 * pre_http_request interceptor.
	 *
	 * @param mixed  $preempt Preempt value.
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 * @return array
	 */
	public function intercept( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		$next = array_shift( $this->responses );
		if ( is_callable( $next ) ) {
			$next = $next( $url );
		}
		if ( null === $next ) {
			$next = array(
				'code' => 500,
				'body' => array(
					'error' => array(
						'code'    => 'test_setup',
						'message' => 'no fixture queued',
					),
				),
			);
		}

		return array(
			'headers'  => $next['headers'] ?? array(),
			'body'     => wp_json_encode( $next['body'] ),
			'response' => array(
				'code'    => $next['code'],
				'message' => 'Test',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function queue( int $code, array $body, array $headers = array() ): void {
		$this->responses[] = array(
			'code'    => $code,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	private function server( int $id, ?string $backup_window, string $name = '' ): array {
		return array(
			'id'            => $id,
			'name'          => '' !== $name ? $name : 'srv-' . $id,
			'status'        => 'running',
			'backup_window' => $backup_window,
			'server_type'   => array(
				'name'   => 'cpx41',
				'cores'  => 8,
				'memory' => 16.0,
				'disk'   => 240,
			),
			'datacenter'    => array(
				'name'     => 'ash-dc1',
				'location' => array( 'name' => 'ash' ),
			),
			'public_net'    => array( 'ipv4' => array( 'ip' => '203.0.113.10' ) ),
			'protection'    => array(
				'delete'  => true,
				'rebuild' => false,
			),
			'volumes'       => array( 101 ),
			'created'       => '2025-01-01T00:00:00+00:00',
		);
	}

	private function backup_image( int $id, int $created_ts, string $status = 'available' ): array {
		return array(
			'id'          => $id,
			'type'        => 'backup',
			'status'      => $status,
			'description' => 'Backup ' . $id,
			'created'     => gmdate( 'c', $created_ts ),
			'image_size'  => 42.5,
			'disk_size'   => 240,
			'bound_to'    => 1,
		);
	}

	private function pagination( ?int $next ): array {
		return array(
			'pagination' => array(
				'page'      => 1,
				'per_page'  => 50,
				'next_page' => $next,
			),
		);
	}

	// ------------------------------------------------------------------
	// Pure backup derivation.
	// ------------------------------------------------------------------

	public function test_backup_age_and_freshness_derivation(): void {
		$now    = 1_800_000_000;
		$status = HetznerAbilities::derive_backup_status(
			$this->server( 1, '22-02' ),
			array(
				$this->backup_image( 10, $now - ( 30 * 3600 ) ),
				$this->backup_image( 11, $now - ( 5 * 3600 ) ),
				$this->backup_image( 12, $now - 600, 'creating' ),
			),
			$now,
			26.0
		);

		$this->assertSame( 1, $status['server_id'] );
		$this->assertSame( '22-02', $status['backup_window'] );
		$this->assertFalse( $status['backups_disabled'] );
		$this->assertSame( gmdate( 'c', $now - ( 5 * 3600 ) ), $status['last_backup_at'], 'newest *available* backup wins; in-progress images are ignored' );
		$this->assertSame( 5.0, $status['backup_age_hours'] );
		$this->assertFalse( $status['stale'] );
		$this->assertSame( 3, $status['count'] );
		$this->assertSame( 'ok', $status['state'] );
		$this->assertSame( 12, $status['backups'][0]['id'], 'backups are sorted newest first' );
	}

	public function test_backup_older_than_threshold_is_stale(): void {
		$now    = 1_800_000_000;
		$status = HetznerAbilities::derive_backup_status(
			$this->server( 1, '22-02' ),
			array( $this->backup_image( 10, $now - ( 31 * 3600 ) ) ),
			$now,
			26.0
		);

		$this->assertSame( 31.0, $status['backup_age_hours'] );
		$this->assertTrue( $status['stale'] );
		$this->assertSame( 'stale', $status['state'] );

		$summary = HetznerAbilities::summarize_backup_statuses( array( $status ), 26.0 );
		$this->assertFalse( $summary['healthy'] );
		$this->assertStringContainsString( '31h old', $summary['issues'][0] );
	}

	public function test_stale_threshold_is_filterable_and_overridable(): void {
		$this->assertSame( 26.0, HetznerAbilities::stale_threshold() );

		add_filter( 'datamachine_hetzner_backup_stale_hours', static fn() => 48 );
		$this->assertSame( 48.0, HetznerAbilities::stale_threshold() );
		$this->assertSame( 12.0, HetznerAbilities::stale_threshold( 12.0 ), 'explicit input beats the filter' );
	}

	public function test_null_backup_window_flags_backups_disabled(): void {
		$now    = 1_800_000_000;
		$status = HetznerAbilities::derive_backup_status( $this->server( 2, null ), array(), $now, 26.0 );

		$this->assertNull( $status['backup_window'] );
		$this->assertTrue( $status['backups_disabled'] );
		$this->assertNull( $status['last_backup_at'] );
		$this->assertNull( $status['backup_age_hours'] );
		$this->assertTrue( $status['stale'] );
		$this->assertSame( 0, $status['count'] );
		$this->assertSame( 'disabled', $status['state'] );

		$summary = HetznerAbilities::summarize_backup_statuses( array( $status ), 26.0 );
		$this->assertStringContainsString( 'disabled', $summary['issues'][0] );
	}

	// ------------------------------------------------------------------
	// Config.
	// ------------------------------------------------------------------

	public function test_missing_config_names_option_and_skips_http(): void {
		delete_site_option( HetznerAbilities::CONFIG_OPTION );

		foreach ( array(
			HetznerAbilities::list_servers(),
			HetznerAbilities::backup_status(),
			HetznerAbilities::list_volumes(),
			HetznerAbilities::server_metrics( 1 ),
		) as $result ) {
			$this->assertFalse( $result['success'] );
			$this->assertSame( 'not_configured', $result['error_code'] );
			$this->assertStringContainsString( 'datamachine_hetzner_config', $result['error'] );
		}

		$this->assertEmpty( $this->requests, 'missing config never touches HTTP' );
		$this->assertFalse( HetznerAbilities::is_configured() );
	}

	public function test_json_string_option_is_accepted(): void {
		update_site_option( HetznerAbilities::CONFIG_OPTION, wp_json_encode( array( 'api_token' => self::TOKEN ) ) );
		$this->assertTrue( HetznerAbilities::is_configured() );
	}

	// ------------------------------------------------------------------
	// HTTP: auth, pagination, endpoints.
	// ------------------------------------------------------------------

	public function test_list_servers_follows_pagination_across_two_pages(): void {
		$this->queue(
			200,
			array(
				'servers' => array( $this->server( 1, '22-02' ) ),
				'meta'    => $this->pagination( 2 ),
			)
		);
		$this->queue(
			200,
			array(
				'servers' => array( $this->server( 2, null ) ),
				'meta'    => $this->pagination( null ),
			)
		);

		$result = HetznerAbilities::list_servers();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( array( 1, 2 ), array_column( $result['servers'], 'id' ) );
		$this->assertCount( 2, $this->requests );
		$this->assertStringStartsWith( 'https://api.hetzner.cloud/v1/servers?', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'page=1', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'per_page=50', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'page=2', $this->requests[1]['url'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, $this->requests[0]['args']['headers']['Authorization'] );

		$first = $result['servers'][0];
		$this->assertSame( 'cpx41', $first['server_type'] );
		$this->assertSame( 'ash', $first['location'] );
		$this->assertTrue( $first['backups_enabled'] );
		$this->assertTrue( $first['delete_protection'] );
		$this->assertFalse( $result['servers'][1]['backups_enabled'] );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $result ), 'token is never echoed in output' );
	}

	public function test_backup_status_queries_backup_images_bound_to_each_server(): void {
		$recent = time() - 3600;
		$this->queue( 200, array( 'server' => $this->server( 7, '02-06', 'prod' ) ) );
		$this->queue(
			200,
			array(
				'images' => array( $this->backup_image( 70, $recent ) ),
				'meta'   => $this->pagination( null ),
			)
		);

		$result = HetznerAbilities::backup_status( 7 );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['healthy'] );
		$this->assertSame( 26.0, $result['stale_hours'] );
		$this->assertSame( 'https://api.hetzner.cloud/v1/servers/7', $this->requests[0]['url'] );
		$this->assertStringContainsString( '/images?', $this->requests[1]['url'] );
		$this->assertStringContainsString( 'type=backup', $this->requests[1]['url'] );
		$this->assertStringContainsString( 'bound_to=7', $this->requests[1]['url'] );
		$this->assertSame( 1, $result['servers'][0]['count'] );
		$this->assertFalse( $result['servers'][0]['stale'] );
	}

	public function test_list_volumes_normalizes_rows(): void {
		$this->queue(
			200,
			array(
				'volumes' => array(
					array(
						'id'           => 101,
						'name'         => 'workspace',
						'size'         => 196,
						'server'       => 7,
						'location'     => array( 'name' => 'ash' ),
						'status'       => 'available',
						'format'       => 'ext4',
						'linux_device' => '/dev/disk/by-id/scsi-0HC_Volume_101',
						'protection'   => array( 'delete' => false ),
					),
				),
				'meta'    => $this->pagination( null ),
			)
		);

		$result = HetznerAbilities::list_volumes();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 196.0, $result['volumes'][0]['size_gb'] );
		$this->assertSame( 7, $result['volumes'][0]['server_id'] );
		$this->assertSame( 196.0, $result['total_size_gb'] );
	}

	public function test_server_metrics_builds_window_and_summarizes_series(): void {
		$this->queue(
			200,
			array(
				'metrics' => array(
					'start'       => '2026-11-14T00:00:00Z',
					'end'         => '2026-11-14T01:00:00Z',
					'step'        => 60,
					'time_series' => array(
						'cpu' => array(
							'values' => array(
								array( 1_800_000_000, '10' ),
								array( 1_800_000_060, '30' ),
							),
						),
					),
				),
			)
		);

		$result = HetznerAbilities::server_metrics( 7, 'cpu', 1.0, '2026-11-14T00:00:00Z', '2026-11-14T01:00:00Z' );

		$this->assertTrue( $result['success'] );
		$url = $this->requests[0]['url'];
		$this->assertStringStartsWith( 'https://api.hetzner.cloud/v1/servers/7/metrics?', $url );
		$this->assertStringContainsString( 'type=cpu', $url );
		$this->assertStringContainsString( 'start=2026-11-14T00%3A00%3A00Z', $url );
		$this->assertSame( 2, $result['summary']['cpu']['points'] );
		$this->assertSame( 30.0, $result['summary']['cpu']['latest'] );
		$this->assertSame( 20.0, $result['summary']['cpu']['avg'] );
		$this->assertSame( 30.0, $result['summary']['cpu']['max'] );

		$all = HetznerAbilities::server_metrics( 7, 'cpu,memory' );
		$this->assertFalse( $all['success'] );
		$this->assertSame( 'invalid_input', $all['error_code'] );
		$this->assertCount( 1, $this->requests, 'invalid metric types are rejected before HTTP' );
	}

	// ------------------------------------------------------------------
	// Error mapping.
	// ------------------------------------------------------------------

	/**
	 * @dataProvider api_error_provider
	 */
	public function test_api_errors_are_mapped( int $status, array $body, string $expected_code, string $expected_fragment ): void {
		$this->queue( $status, $body, array( 'ratelimit-reset' => '1800000000' ) );

		$result = HetznerAbilities::list_volumes();

		$this->assertFalse( $result['success'] );
		$this->assertSame( $status, $result['status_code'] );
		$this->assertSame( $expected_code, $result['error_code'] );
		$this->assertStringContainsString( $expected_fragment, $result['error'] );
		$this->assertStringNotContainsString( self::TOKEN, $result['error'] );
	}

	public function api_error_provider(): array {
		return array(
			'401 unauthorized' => array(
				401,
				array(
					'error' => array(
						'code'    => 'unauthorized',
						'message' => 'unable to authenticate',
					),
				),
				'unauthorized',
				'401 unauthorized',
			),
			'403 forbidden'    => array(
				403,
				array(
					'error' => array(
						'code'    => 'forbidden',
						'message' => 'insufficient permissions',
					),
				),
				'forbidden',
				'insufficient permissions',
			),
			'429 rate limited' => array(
				429,
				array(
					'error' => array(
						'code'    => 'rate_limit_exceeded',
						'message' => 'limit reached',
					),
				),
				'rate_limit_exceeded',
				'resets at',
			),
			'500 no body'      => array( 500, array(), 'http_500', 'HTTP 500' ),
		);
	}

	public function test_error_message_echoing_token_is_redacted(): void {
		$this->queue(
			401,
			array(
				'error' => array(
					'code'    => 'unauthorized',
					'message' => 'bad token ' . self::TOKEN,
				),
			)
		);

		$result = HetznerAbilities::list_servers();

		$this->assertStringNotContainsString( self::TOKEN, $result['error'] );
		$this->assertStringContainsString( '[redacted]', $result['error'] );
	}

	public function test_error_on_second_page_fails_whole_collection(): void {
		$this->queue(
			200,
			array(
				'servers' => array( $this->server( 1, '22-02' ) ),
				'meta'    => $this->pagination( 2 ),
			)
		);
		$this->queue(
			429,
			array(
				'error' => array(
					'code'    => 'rate_limit_exceeded',
					'message' => 'slow down',
				),
			)
		);

		$result = HetznerAbilities::list_servers();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'rate_limit_exceeded', $result['error_code'] );
	}

	// ------------------------------------------------------------------
	// Health surfaces.
	// ------------------------------------------------------------------

	public function test_health_check_registered_and_default_only_when_configured(): void {
		$checks = HetznerHealth::filter_health_checks( array() );
		$this->assertArrayHasKey( 'hetzner_backups', $checks );
		$this->assertTrue( $checks['hetzner_backups']['default'] );

		delete_site_option( HetznerAbilities::CONFIG_OPTION );
		$checks = HetznerHealth::filter_health_checks( array() );
		$this->assertFalse( $checks['hetzner_backups']['default'] );
	}

	public function test_wake_briefing_emits_line_for_stale_backup_only(): void {
		$this->queue(
			200,
			array(
				'servers' => array( $this->server( 1, '22-02', 'prod' ) ),
				'meta'    => $this->pagination( null ),
			)
		);
		$this->queue(
			200,
			array(
				'images' => array( $this->backup_image( 10, time() - ( 31 * 3600 ) ) ),
				'meta'   => $this->pagination( null ),
			)
		);

		$signals = HetznerHealth::filter_wake_briefing_signals( array( 'existing' ) );

		$this->assertCount( 2, $signals );
		$this->assertSame( 'existing', $signals[0] );
		$this->assertStringContainsString( 'Hetzner backups', $signals[1] );
		$this->assertStringContainsString( 'prod', $signals[1] );
		$this->assertStringContainsString( '31h old', $signals[1] );

		// Memoized: the health check reuses the same status without new HTTP.
		$health = HetznerHealth::run_health_check( array() );
		$this->assertFalse( $health['healthy'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_wake_briefing_is_silent_when_unconfigured_or_healthy(): void {
		delete_site_option( HetznerAbilities::CONFIG_OPTION );
		$this->assertSame( array(), HetznerHealth::filter_wake_briefing_signals( array() ) );
		$this->assertEmpty( $this->requests );

		$this->assertSame(
			array(),
			HetznerHealth::briefing_lines(
				array(
					'success' => true,
					'issues'  => array(),
				)
			)
		);
	}
}
