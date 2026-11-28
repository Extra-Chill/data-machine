<?php
/**
 * Impact revenue refresh system task contract tests.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\System\Tasks
 */

namespace DataMachine\Tests\Unit\Engine\AI\System\Tasks;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Engine\Tasks\RecurringScheduleRegistry;
use DataMachineBusiness\Abilities\Analytics\ImpactReportsAbilities;
use DataMachineBusiness\Abilities\Revenue\RevenueLedger;
use DataMachineBusiness\Abilities\Revenue\RevenueLedgerAbilities;
use DataMachineBusiness\Engine\AI\System\Tasks\ImpactRevenueRefreshTask;
use WP_UnitTestCase;

class ImpactRevenueRefreshTaskTest extends WP_UnitTestCase {

	private const SID   = 'IRrefreshSID9';
	private const TOKEN = 'tok_REFRESH_SECRET';

	/** @var string[] */
	private array $requests = array();

	/** @var callable */
	private $responder;

	public function set_up(): void {
		parent::set_up();
		RevenueLedger::create_table();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', RevenueLedger::table_name() ) );
		update_option( ImpactReportsAbilities::CONFIG_OPTION, array(
			'account_sid' => self::SID,
			'auth_token'  => self::TOKEN,
		) );
		ImpactRevenueRefreshTask::register();
		RecurringScheduleRegistry::reset();
		$this->requests  = array();
		$this->responder = static fn(): array => array(
			'@nextpageuri' => '',
			'Actions'      => array(),
		);
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_option( ImpactReportsAbilities::CONFIG_OPTION );
		RecurringScheduleRegistry::reset();
		parent::tear_down();
	}

	public function intercept( $pre, $args, $url ) {
		$this->requests[] = $url;
		$body             = ( $this->responder )( $url );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $body['__status'] ?? 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	private function createJob(): int {
		$job_id = ( new Jobs() )->create_job( array(
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'system',
			'label'       => 'Impact Revenue Refresh',
		) );
		if ( ! $job_id ) {
			$this->markTestSkipped( 'Could not create test job.' );
		}
		return (int) $job_id;
	}

	public function test_window_is_45_inclusive_utc_days_ending_today(): void {
		$now    = strtotime( '2026-03-01 23:30:00 UTC' );
		$window = ImpactRevenueRefreshTask::refreshWindow( $now );

		$this->assertSame( '2026-03-01', $window['end_date'] );
		$this->assertSame( '2026-01-16', $window['start_date'] );
		$days = ( strtotime( $window['end_date'] . ' UTC' ) - strtotime( $window['start_date'] . ' UTC' ) ) / DAY_IN_SECONDS + 1;
		$this->assertSame( 45, (int) $days );
		$this->assertCount( 1, ImpactReportsAbilities::chunkWindows( $window['start_date'], $window['end_date'] ), 'one Impact chunk' );
	}

	public function test_task_is_registered_agent_less_and_runnable(): void {
		$tasks = apply_filters( 'datamachine_tasks', array() );
		$this->assertSame( ImpactRevenueRefreshTask::class, $tasks['impact_revenue_refresh'] ?? null );

		$task = new ImpactRevenueRefreshTask();
		$this->assertFalse( $task->requiresAgentContext() );
		$this->assertTrue( ImpactRevenueRefreshTask::getTaskMeta()['supports_run'] );
	}

	public function test_schedule_is_daily_and_enabled_only_with_credentials(): void {
		$schedule = RecurringScheduleRegistry::get( 'impact_revenue_refresh' );
		$this->assertIsArray( $schedule );
		$this->assertSame( 'impact_revenue_refresh', $schedule['task_type'] );
		$this->assertSame( 'daily', $schedule['interval'] );
		$this->assertTrue( $schedule['network_only'] );
		$this->assertTrue( RecurringScheduleRegistry::isEnabled( $schedule ) );

		update_option( ImpactReportsAbilities::CONFIG_OPTION, array( 'account_sid' => self::SID ) );
		RecurringScheduleRegistry::reset();
		$this->assertFalse( RecurringScheduleRegistry::isEnabled( (array) RecurringScheduleRegistry::get( 'impact_revenue_refresh' ) ) );

		delete_option( ImpactReportsAbilities::CONFIG_OPTION );
		RecurringScheduleRegistry::reset();
		$this->assertFalse( RecurringScheduleRegistry::isEnabled( (array) RecurringScheduleRegistry::get( 'impact_revenue_refresh' ) ) );
	}

	public function test_unconfigured_run_is_a_no_op(): void {
		delete_option( ImpactReportsAbilities::CONFIG_OPTION );
		$job_id = $this->createJob();

		( new ImpactRevenueRefreshTask() )->executeTask( $job_id, array() );

		$this->assertSame( array(), $this->requests );
		$jobs = new Jobs();
		$this->assertStringContainsString( 'completed', strtolower( (string) ( $jobs->get_job( $job_id )['status'] ?? '' ) ) );
		$this->assertTrue( $jobs->retrieve_engine_data( $job_id )['skipped'] ?? false );
	}

	public function test_run_stores_trailing_window_in_ledger(): void {
		$window          = ImpactRevenueRefreshTask::refreshWindow();
		$this->responder = static fn(): array => array(
			'@nextpageuri' => '',
			'Actions'      => array(
				array(
					'Id'           => 'r1',
					'CampaignId'   => '4272',
					'CampaignName' => 'Ticketmaster',
					'State'        => 'PENDING',
					'Payout'       => '2.25',
					'Amount'       => '40.00',
					'Currency'     => 'USD',
					'EventDate'    => $window['end_date'] . 'T12:00:00Z',
				),
			),
		);
		$job_id          = $this->createJob();

		( new ImpactRevenueRefreshTask() )->executeTask( $job_id, array() );

		$this->assertCount( 1, $this->requests, 'one Impact request per tick' );
		$this->assertStringContainsString( 'ActionDateStart=' . $window['start_date'] . 'T00%3A00%3A00Z', $this->requests[0] );
		$end_exclusive = gmdate( 'Y-m-d', strtotime( $window['end_date'] . ' UTC' ) + DAY_IN_SECONDS );
		$this->assertStringContainsString( 'ActionDateEnd=' . $end_exclusive . 'T00%3A00%3A00Z', $this->requests[0] );

		$jobs = new Jobs();
		$this->assertStringContainsString( 'completed', strtolower( (string) ( $jobs->get_job( $job_id )['status'] ?? '' ) ) );
		$this->assertSame( $window, $jobs->retrieve_engine_data( $job_id )['window'] ?? null );

		$query = RevenueLedgerAbilities::query( array(
			'source'     => 'impact',
			'start_date' => $window['start_date'],
			'end_date'   => $window['end_date'],
		) );
		$this->assertIsArray( $query );
		$this->assertSame( 2.25, (float) $query['totals'][0]['amount'] );
	}

	public function test_fetch_error_fails_job_without_leaking_token(): void {
		$this->responder = static fn(): array => array(
			'__status' => 401,
			'Message'  => 'invalid credentials ' . self::TOKEN,
		);
		$job_id          = $this->createJob();

		( new ImpactRevenueRefreshTask() )->executeTask( $job_id, array() );

		$jobs   = new Jobs();
		$job    = $jobs->get_job( $job_id );
		$status = (string) ( $job['status'] ?? '' );
		$this->assertStringStartsWith( 'failed', strtolower( $status ) );
		$this->assertStringContainsString( 'impact_authentication', $status . wp_json_encode( $jobs->retrieve_engine_data( $job_id ) ) );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $job ) );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $jobs->retrieve_engine_data( $job_id ) ) );
	}
}
