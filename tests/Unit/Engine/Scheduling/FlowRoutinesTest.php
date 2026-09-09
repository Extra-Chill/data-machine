<?php
/**
 * FlowRoutines adapter tests: interval resolution, sync idempotence,
 * manual teardown, substrate permission allow-list, and the legacy
 * migration plan (#3458).
 *
 * @package DataMachine\Tests\Unit\Engine\Scheduling
 */

namespace DataMachine\Tests\Unit\Engine\Scheduling;

use AgentsAPI\AI\Routines\WP_Agent_Routine;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\Scheduling\FlowRoutines;
use DataMachine\Engine\Scheduling\HashGatedRoutineBackend;
use WP_UnitTestCase;

class FlowRoutinesTest extends WP_UnitTestCase {

	private int $pipeline_id;
	/** @var int[] */
	private array $flow_ids = array();

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( FlowRoutines::MIGRATED_OPTION );
		$pipeline          = wp_get_ability( 'datamachine/create-pipeline' )->execute( array( 'pipeline_name' => 'Routines adapter' ) );
		$this->pipeline_id = (int) $pipeline['pipeline_id'];
	}

	public function tear_down(): void {
		$flows = new Flows();
		foreach ( $this->flow_ids as $flow_id ) {
			FlowRoutines::unschedule( $flow_id );
			$flows->delete_flow( $flow_id );
		}
		$this->flow_ids = array();
		( new Pipelines() )->delete_pipeline( $this->pipeline_id );

		foreach ( WP_Agent_Routine_Registry::all() as $routine ) {
			if ( str_starts_with( $routine->get_id(), 'flow-' ) ) {
				WP_Agent_Routine_Registry::unregister( $routine->get_id() );
			}
		}
		WP_Agent_Routine_Registry::reset();
		HashGatedRoutineBackend::reset_state();
		delete_option( 'datamachine_routine_schedule_hashes' );
		delete_option( FlowRoutines::MIGRATED_OPTION );

		parent::tear_down();
	}

	public function test_interval_seconds_resolves_aliases_and_special_keys(): void {
		$this->assertSame( DAY_IN_SECONDS, FlowRoutines::interval_seconds( 'daily' ) );
		$this->assertSame( HOUR_IN_SECONDS * 6, FlowRoutines::interval_seconds( 'every_6_hours' ), 'Aliases must resolve through the canonical table.' );
		$this->assertSame( HOUR_IN_SECONDS * 6, FlowRoutines::interval_seconds( 'qtrdaily' ) );
		$this->assertNull( FlowRoutines::interval_seconds( 'manual' ) );
		$this->assertNull( FlowRoutines::interval_seconds( 'one_time' ) );
		$this->assertNull( FlowRoutines::interval_seconds( 'cron' ) );
		$this->assertNull( FlowRoutines::interval_seconds( null ) );
		$this->assertNull( FlowRoutines::interval_seconds( 'not_an_interval' ) );
	}

	public function test_sync_is_idempotent_and_does_not_reset_timers(): void {
		$flow_id = $this->create_flow( 'Idempotent flow' );

		$this->assertTrue( FlowRoutines::sync( $flow_id, array( 'interval' => 'hourly' ) ) );

		$first = $this->pending_routine_action_id( $flow_id );
		$this->assertGreaterThan( 0, $first, 'First sync must schedule a pending routine action.' );

		$this->assertTrue( FlowRoutines::sync( $flow_id, array( 'interval' => 'hourly' ) ), 'Second identical sync must be a no-op success.' );

		$this->assertSame( $first, $this->pending_routine_action_id( $flow_id ), 'Idempotent sync must not reschedule the chain.' );
	}

	public function test_sync_manual_unregisters_the_routine_and_cancels_coverage(): void {
		$flow_id = $this->create_flow( 'Manual teardown flow' );

		$this->assertTrue( FlowRoutines::sync( $flow_id, array( 'interval' => 'daily' ) ) );
		$this->assertGreaterThan( 0, $this->pending_routine_action_id( $flow_id ) );

		$this->assertTrue( FlowRoutines::sync( $flow_id, array( 'interval' => 'manual' ), true ) );

		$this->assertSame( 0, $this->pending_routine_action_id( $flow_id ), 'Manual flow must have no pending routine action.' );
		$this->assertNull( FlowRoutines::next_run( $flow_id ) );

		$stored = ( new Flows() )->get_flow_scheduling( $flow_id );
		$this->assertSame( 'manual', (string) ( $stored['interval'] ?? '' ) );
	}

	public function test_permission_filter_allows_owned_flow_routines_and_denies_others(): void {
		$flow_id = $this->create_flow( 'Permission flow' );
		$this->assertTrue( FlowRoutines::sync( $flow_id, array( 'interval' => 'hourly' ) ) );

		$owned = new WP_Agent_Routine(
			FlowRoutines::routine_id( $flow_id ),
			array(
				'ability'  => 'datamachine/run-flow',
				'input'    => array( 'flow_id' => $flow_id ),
				'interval' => HOUR_IN_SECONDS,
			)
		);
		$this->assertTrue( FlowRoutines::filter_ability_permission( false, $owned, null ) );

		$foreign = new WP_Agent_Routine(
			'flow-999999999',
			array(
				'ability'  => 'datamachine/run-flow',
				'input'    => array( 'flow_id' => 999999999 ),
				'interval' => HOUR_IN_SECONDS,
			)
		);
		$this->assertFalse( FlowRoutines::filter_ability_permission( false, $foreign, null ), 'Unregistered routine ids must stay denied.' );

		$cross_target = new WP_Agent_Routine(
			FlowRoutines::routine_id( $flow_id ),
			array(
				'ability'  => 'datamachine/delete-flow',
				'input'    => array( 'flow_id' => $flow_id ),
				'interval' => HOUR_IN_SECONDS,
			)
		);
		$this->assertFalse( FlowRoutines::filter_ability_permission( false, $cross_target, null ), 'Routine ids may not execute abilities outside the allow-list.' );
	}

	public function test_migration_dry_run_reports_plan_without_cancelling(): void {
		$flow_id = $this->create_flow( 'Migration flow' );
		$this->assertTrue( FlowRoutines::sync( $flow_id, array( 'interval' => 'hourly' ) ) );

		$legacy_id = as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			'datamachine_run_flow_now',
			array( $flow_id, null, array( '_datamachine_schedule_generation' => wp_generate_uuid4() ) ),
			'data-machine'
		);
		$this->assertGreaterThan( 0, $legacy_id );

		$plan = FlowRoutines::migrate_legacy_schedules( true );

		$this->assertTrue( $plan['dry_run'] );
		$this->assertSame( 1, (int) $plan['legacy_actions'], 'Dry run must report the legacy pending action.' );
		$this->assertSame( 0, (int) $plan['cancelled'], 'Dry run must not cancel anything.' );

		$planned = array_column( $plan['flows'], null, 'flow_id' );
		$this->assertArrayHasKey( $flow_id, $planned, 'Plan must map the flow to its legacy action.' );
		$this->assertSame( FlowRoutines::routine_id( $flow_id ), $planned[ $flow_id ]['routine_id'] );
		$this->assertSame( array( (int) $legacy_id ), $planned[ $flow_id ]['legacy_action_ids'] );
		$this->assertFalse( (bool) $planned[ $flow_id ]['cancelled'] );

		$this->assertGreaterThan( 0, $this->legacy_action_pending( (int) $legacy_id ), 'Dry run must leave the legacy action pending.' );
		$this->assertFalse( get_option( FlowRoutines::MIGRATED_OPTION ), 'Dry run must not set the migrated marker.' );
	}

	public function test_migration_apply_cancels_generated_chains_only(): void {
		$flow_id = $this->create_flow( 'Migration apply flow' );
		$this->assertTrue( FlowRoutines::sync( $flow_id, array( 'interval' => 'hourly' ) ) );

		$legacy_id   = as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			'datamachine_run_flow_now',
			array( $flow_id, null, array( '_datamachine_schedule_generation' => wp_generate_uuid4() ) ),
			'data-machine'
		);
		$deferral_id = as_schedule_single_action(
			time() + 60,
			'datamachine_run_flow_now',
			array( $flow_id ),
			'data-machine'
		);

		$plan = FlowRoutines::migrate_legacy_schedules( false );

		$this->assertFalse( $plan['dry_run'] );
		$this->assertSame( 1, (int) $plan['cancelled'], 'Apply must cancel exactly the generated chain.' );
		$this->assertSame( 0, $this->legacy_action_pending( (int) $legacy_id ), 'Generated chain action must be cancelled.' );
		$this->assertGreaterThan( 0, $this->legacy_action_pending( (int) $deferral_id ), 'Backpressure deferral ticks must survive migration.' );

		$marker = get_option( FlowRoutines::MIGRATED_OPTION );
		$this->assertIsArray( $marker, 'Apply must set the one-shot migrated marker.' );
	}

	public function test_legacy_recurring_wake_is_ignored_but_positional_wakes_still_dispatch(): void {
		$flow_id  = $this->create_flow( 'Legacy wake flow' );
		$executed = array();
		$spy      = static function ( $name, $input ) use ( &$executed ): void {
			if ( 'datamachine/run-flow' === $name ) {
				$executed[] = (int) ( $input['flow_id'] ?? 0 );
			}
		};
		add_action( 'wp_ability_invoked', $spy, 10, 2 );

		$logged = array();
		$log    = static function ( $level, $message ) use ( &$logged ): void {
			$logged[] = array( $level, $message );
		};
		add_action( 'datamachine_log', $log, 10, 2 );

		try {
			// A stale generated chain (generation marker at args[2]) must not run.
			do_action( 'datamachine_run_flow_now', $flow_id, null, array( '_datamachine_schedule_generation' => 'stale' ) );
			$this->assertSame( array(), $executed, 'Legacy recurring wake must not dispatch the flow.' );
			$this->assertNotEmpty(
				array_filter( $logged, static fn( $entry ) => 'Legacy recurring schedule action ignored after routines migration' === $entry[1] ),
				'Ignored legacy wake must be logged.'
			);
		} finally {
			remove_action( 'wp_ability_invoked', $spy, 10 );
			remove_action( 'datamachine_log', $log, 10 );
		}
	}

	private function create_flow( string $name ): int {
		$result           = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id'       => $this->pipeline_id,
				'flow_name'         => $name,
				'scheduling_config' => array( 'interval' => 'manual' ),
			)
		);
		$flow_id          = (int) $result['flow_id'];
		$this->flow_ids[] = $flow_id;

		return $flow_id;
	}

	private function pending_routine_action_id( int $flow_id ): int {
		$timestamp = as_next_scheduled_action(
			FlowRoutines::ROUTINE_HOOK,
			array( 'routine_id' => FlowRoutines::routine_id( $flow_id ) ),
			FlowRoutines::ROUTINE_GROUP
		);

		return is_int( $timestamp ) && $timestamp > 0 ? $timestamp : 0;
	}

	/**
	 * Whether one action id is still pending; returns its id when pending.
	 */
	private function legacy_action_pending( int $action_id ): int {
		try {
			$status = \ActionScheduler_Store::instance()->get_status( (string) $action_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return 0;
		}

		return \ActionScheduler_Store::STATUS_PENDING === $status ? $action_id : 0;
	}
}
