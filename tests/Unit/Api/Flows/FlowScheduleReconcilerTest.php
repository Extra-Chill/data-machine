<?php
/**
 * Flow schedule reconciliation tests.
 *
 * @package DataMachine\Tests\Unit\Api\Flows
 */

namespace DataMachine\Tests\Unit\Api\Flows;

use DataMachine\Api\Flows\FlowScheduleReconciler;
use DataMachine\Api\Flows\FlowScheduleReconciliationLock;
use DataMachine\Api\Flows\FlowScheduling;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Engine\Tasks\RecurringScheduler;
use WP_UnitTestCase;

class FlowScheduleReconcilerTest extends WP_UnitTestCase {

	private int $pipeline_id;
	private Flows $flows;
	private array $flow_ids = array();

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$pipeline         = wp_get_ability( 'datamachine/create-pipeline' )->execute( array( 'pipeline_name' => 'Schedule reconciliation' ) );
		$this->pipeline_id = (int) $pipeline['pipeline_id'];
		$this->flows       = new Flows();
	}

	public function tear_down(): void {
		foreach ( $this->flow_ids as $flow_id ) {
			RecurringScheduler::unschedule( FlowScheduling::FLOW_HOOK, array( $flow_id ) );
		}
		delete_option( 'datamachine_flow_schedule_reconciliation_lock' );

		parent::tear_down();
	}

	public function test_dry_run_excludes_non_recurring_and_paused_flows(): void {
		$hourly = $this->create_flow( 'Hourly', array( 'interval' => 'hourly' ) );
		$cron   = $this->create_flow(
			'Cron',
			array(
				'interval'        => 'cron',
				'cron_expression' => '0 * * * *',
			)
		);
		$this->create_flow( 'Manual', array( 'interval' => 'manual' ) );
		$this->create_flow( 'Missing', array() );
		$this->create_flow( 'One time', array( 'interval' => 'one_time', 'timestamp' => time() + HOUR_IN_SECONDS ) );
		$this->create_flow( 'Paused', array( 'interval' => 'daily', 'enabled' => false ) );

		$result = ( new FlowScheduleReconciler( $this->flows ) )->reconcile();

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['applied'] );
		$this->assertSame( 2, $result['eligible'] );
		$this->assertSame( 2, $result['missing'] );
		$this->assertSame( 4, $result['excluded'] );
		$this->assertFalse( RecurringScheduler::hasCoverage( FlowScheduling::FLOW_HOOK, array( $hourly ) ) );
		$this->assertFalse( RecurringScheduler::hasCoverage( FlowScheduling::FLOW_HOOK, array( $cron ) ) );
	}

	public function test_apply_repairs_missing_schedules_idempotently(): void {
		$hourly = $this->create_flow( 'Hourly', array( 'interval' => 'hourly' ) );
		$cron   = $this->create_flow(
			'Cron',
			array(
				'interval'        => 'cron',
				'cron_expression' => '15 * * * *',
			)
		);

		$first = ( new FlowScheduleReconciler( $this->flows ) )->reconcile( true, 12 );

		$this->assertTrue( $first['success'] );
		$this->assertSame( 2, $first['repaired'] );
		$this->assertSame( 0, $first['remaining_missing'] );
		$this->assertSame( 12, $first['distribution_window_hours'] );
		$this->assertTrue( RecurringScheduler::hasCoverage( FlowScheduling::FLOW_HOOK, array( $hourly ) ) );
		$this->assertTrue( RecurringScheduler::hasCoverage( FlowScheduling::FLOW_HOOK, array( $cron ) ) );

		$second = ( new FlowScheduleReconciler( $this->flows ) )->reconcile( true );
		$this->assertTrue( $second['success'] );
		$this->assertSame( 0, $second['missing'] );
		$this->assertSame( 0, $second['repaired'] );
		$this->assertSame( 2, $second['covered'] );
	}

	public function test_in_progress_action_counts_as_coverage(): void {
		global $wpdb;

		$flow_id   = $this->create_flow( 'Running', array( 'interval' => 'hourly' ) );
		$action_id = as_schedule_recurring_action(
			time() + 60,
			HOUR_IN_SECONDS,
			FlowScheduling::FLOW_HOOK,
			array( $flow_id ),
			RecurringScheduler::GROUP,
			false
		);

		$wpdb->update(
			$wpdb->actionscheduler_actions,
			array(
				'status'           => 'in-progress',
				'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'action_id' => $action_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		wp_cache_flush();

		$result = ( new FlowScheduleReconciler( $this->flows ) )->reconcile();
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['covered'] );
		$this->assertSame( 0, $result['missing'] );
	}

	public function test_stale_in_progress_action_does_not_cover_schedule(): void {
		global $wpdb;

		$flow_id   = $this->create_flow( 'Stale running', array( 'interval' => 'hourly' ) );
		$action_id = as_schedule_recurring_action(
			time() + 60,
			HOUR_IN_SECONDS,
			FlowScheduling::FLOW_HOOK,
			array( $flow_id ),
			RecurringScheduler::GROUP,
			false
		);

		$wpdb->update(
			$wpdb->actionscheduler_actions,
			array(
				'status'           => 'in-progress',
				'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ),
			),
			array( 'action_id' => $action_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		wp_cache_flush();

		$result = ( new FlowScheduleReconciler( $this->flows ) )->reconcile();
		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['covered'] );
		$this->assertSame( 1, $result['blocked'] );

		$applied = ( new FlowScheduleReconciler( $this->flows ) )->reconcile( true );
		$this->assertFalse( $applied['success'] );
		$this->assertTrue( $applied['transient'] );
		$this->assertSame( 1, $applied['blocked'] );
		$this->assertSame( 0, $applied['repaired'] );
		$this->assertFalse( RecurringScheduler::isScheduled( FlowScheduling::FLOW_HOOK, array( $flow_id ) ) );

		$second = ( new FlowScheduleReconciler( $this->flows ) )->reconcile();
		$this->assertSame( 1, $second['blocked'] );
	}

	public function test_fresh_mismatched_in_progress_action_blocks_apply_without_successor(): void {
		global $wpdb;

		$flow_id   = $this->create_flow( 'Fresh mismatched owner', array( 'interval' => 'daily' ) );
		$action_id = as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, FlowScheduling::FLOW_HOOK, array( $flow_id ), RecurringScheduler::GROUP, false );
		$wpdb->update(
			$wpdb->actionscheduler_actions,
			array(
				'status'           => 'in-progress',
				'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'action_id' => $action_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		wp_cache_flush();

		$result = ( new FlowScheduleReconciler( $this->flows ) )->reconcile( true );
		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['transient'] );
		$this->assertSame( 1, $result['blocked'] );
		$this->assertSame( 0, $result['repaired'] );
		$this->assertFalse( RecurringScheduler::isScheduled( FlowScheduling::FLOW_HOOK, array( $flow_id ) ) );
	}

	public function test_fresh_in_progress_plus_pending_action_blocks_apply_without_changes(): void {
		global $wpdb;

		$flow_id = $this->create_flow( 'Fresh mixed owner', array( 'interval' => 'hourly' ) );
		$running = as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, FlowScheduling::FLOW_HOOK, array( $flow_id ), RecurringScheduler::GROUP, false );
		as_schedule_recurring_action( time() + 120, HOUR_IN_SECONDS, FlowScheduling::FLOW_HOOK, array( $flow_id ), RecurringScheduler::GROUP, false );
		$wpdb->update(
			$wpdb->actionscheduler_actions,
			array(
				'status'           => 'in-progress',
				'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'action_id' => $running ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$before = as_get_scheduled_actions(
			array(
				'hook'     => FlowScheduling::FLOW_HOOK,
				'args'     => array( $flow_id ),
				'group'    => RecurringScheduler::GROUP,
				'status'   => array( 'pending', 'in-progress' ),
				'per_page' => 10,
			),
			'ids'
		);
		$result = ( new FlowScheduleReconciler( $this->flows ) )->reconcile( true );
		$after  = as_get_scheduled_actions(
			array(
				'hook'     => FlowScheduling::FLOW_HOOK,
				'args'     => array( $flow_id ),
				'group'    => RecurringScheduler::GROUP,
				'status'   => array( 'pending', 'in-progress' ),
				'per_page' => 10,
			),
			'ids'
		);

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['transient'] );
		$this->assertSame( 1, $result['blocked'] );
		$this->assertSame( 0, $result['repaired'] );
		$this->assertSame( $before, $after );
	}

	public function test_pending_action_with_wrong_interval_is_missing_and_repaired(): void {
		$flow_id = $this->create_flow( 'Changed interval', array( 'interval' => 'daily' ) );
		as_schedule_recurring_action(
			time() + 60,
			HOUR_IN_SECONDS,
			FlowScheduling::FLOW_HOOK,
			array( $flow_id ),
			RecurringScheduler::GROUP,
			false
		);

		$dry_run = ( new FlowScheduleReconciler( $this->flows ) )->reconcile();
		$this->assertSame( 1, $dry_run['missing'] );

		$applied = ( new FlowScheduleReconciler( $this->flows ) )->reconcile( true );
		$this->assertTrue( $applied['success'] );
		$this->assertSame( 1, $applied['repaired'] );

		$verified = ( new FlowScheduleReconciler( $this->flows ) )->reconcile();
		$this->assertSame( 1, $verified['covered'] );
		$this->assertSame( 0, $verified['missing'] );
	}

	public function test_pending_cron_action_with_wrong_expression_is_missing(): void {
		$flow_id = $this->create_flow(
			'Changed cron',
			array(
				'interval'        => 'cron',
				'cron_expression' => '15 * * * *',
			)
		);
		as_schedule_cron_action(
			time(),
			'0 * * * *',
			FlowScheduling::FLOW_HOOK,
			array( $flow_id ),
			RecurringScheduler::GROUP,
			false
		);

		$result = ( new FlowScheduleReconciler( $this->flows ) )->reconcile();
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['missing'] );
		$this->assertSame( 0, $result['covered'] );
	}

	public function test_duplicate_pending_actions_are_missing_and_apply_collapses_them(): void {
		$flow_id = $this->create_flow( 'Duplicate actions', array( 'interval' => 'hourly' ) );
		as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, FlowScheduling::FLOW_HOOK, array( $flow_id ), RecurringScheduler::GROUP, false );
		as_schedule_recurring_action( time() + 120, HOUR_IN_SECONDS, FlowScheduling::FLOW_HOOK, array( $flow_id ), RecurringScheduler::GROUP, false );

		$dry_run = ( new FlowScheduleReconciler( $this->flows ) )->reconcile();
		$this->assertSame( 1, $dry_run['missing'] );

		$applied = ( new FlowScheduleReconciler( $this->flows ) )->reconcile( true );
		$this->assertTrue( $applied['success'] );
		$this->assertSame( 1, $applied['repaired'] );
		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => FlowScheduling::FLOW_HOOK,
				'args'     => array( $flow_id ),
				'group'    => RecurringScheduler::GROUP,
				'status'   => 'pending',
				'per_page' => 10,
			),
			'ids'
		);
		$this->assertCount( 1, $action_ids );
	}

	public function test_invalid_definitions_are_reported_without_failing_apply(): void {
		$this->create_flow( 'Broken cron', array( 'interval' => 'cron' ) );
		$this->create_flow( 'Unknown interval', array( 'interval' => 'sometimes' ) );

		$result = ( new FlowScheduleReconciler( $this->flows ) )->reconcile( true );
		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['transient'] );
		$this->assertSame( 2, $result['invalid'] );
		$this->assertSame( 0, $result['eligible'] );
		$this->assertSame( 0, $result['repaired'] );
	}

	public function test_apply_returns_transient_failure_while_lock_is_held(): void {
		$this->create_flow( 'Locked', array( 'interval' => 'hourly' ) );
		$token = FlowScheduleReconciliationLock::acquire();
		$this->assertIsString( $token );

		$result = ( new FlowScheduleReconciler( $this->flows ) )->reconcile( true );
		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['transient'] );
		$this->assertSame( 'flow_schedule_reconciliation_locked', $result['code'] );

		$this->assertTrue( FlowScheduleReconciliationLock::release( $token ) );
	}

	private function create_flow( string $name, array $scheduling ): int {
		$result = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id'      => $this->pipeline_id,
				'flow_name'        => $name,
				'scheduling_config' => array( 'interval' => 'manual' ),
			)
		);

		$flow_id = (int) $result['flow_id'];
		$this->flows->update_flow_scheduling( $flow_id, $scheduling );
		$this->flow_ids[] = $flow_id;

		return $flow_id;
	}
}
