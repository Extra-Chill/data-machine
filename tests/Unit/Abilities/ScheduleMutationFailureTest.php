<?php
/**
 * Schedule mutation failure integration tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Flow\CreateFlowAbility;
use DataMachine\Api\Flows\FlowScheduling;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\Tasks\GenerationFencedAction;
use DataMachine\Engine\Tasks\RecurringScheduler;
use ReflectionMethod;
use WP_UnitTestCase;

class GenerationTransitionActionFactory extends \ActionScheduler_ActionFactory {
	public \Closure $before_store;

	protected function store( \ActionScheduler_Action $action ) {
		( $this->before_store )();
		return parent::store( $action );
	}
}

class ScheduleMutationFailureTest extends WP_UnitTestCase {

	private const FLOW_HOOK = 'datamachine_run_flow_now';

	private Flows $flows;
	private Pipelines $pipelines;
	private int $pipeline_id;
	private int $flow_id;
	private array $managed_flow_ids = array();

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->flows       = new Flows();
		$this->pipelines   = new Pipelines();
		$this->pipeline_id = (int) $this->pipelines->create_pipeline(
			array(
				'pipeline_name'   => 'Schedule mutation failure fixture',
				'pipeline_config' => array(),
			)
		);
		$this->flow_id     = (int) $this->flows->create_flow(
			array(
				'pipeline_id'       => $this->pipeline_id,
				'flow_name'         => 'Schedule mutation failure flow',
				'flow_config'       => array(),
				'scheduling_config' => array(
					'enabled'  => true,
					'interval' => 'hourly',
				),
			)
		);
		$this->managed_flow_ids[] = $this->flow_id;

		$this->assertNotInstanceOf(
			\WP_Error::class,
			RecurringScheduler::ensureSchedule( self::FLOW_HOOK, array( $this->flow_id ), 'hourly' )
		);
	}

	public function tear_down(): void {
		foreach ( $this->managed_flow_ids as $flow_id ) {
			$this->releaseScheduleLock( $flow_id );
			RecurringScheduler::ensureSchedule( self::FLOW_HOOK, array( $flow_id ), 'manual' );
		}

		parent::tear_down();
	}

	public function test_delete_flow_keeps_record_when_recurrence_cannot_be_fenced(): void {
		$this->holdScheduleLock( $this->flow_id );

		$result = wp_get_ability( 'datamachine/delete-flow' )->execute( array( 'flow_id' => $this->flow_id ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'schedule_lock_timeout', $result['error_code'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertNotNull( $this->flows->get_flow( $this->flow_id ) );
		$this->assertNotFalse( as_next_scheduled_action( self::FLOW_HOOK, array( $this->flow_id ), RecurringScheduler::GROUP ) );
	}

	public function test_stale_successor_is_canceled_when_generation_changes_between_repeat_read_and_store(): void {
		$generation_option = 'datamachine_test_generation_' . wp_generate_uuid4();
		$generation        = wp_generate_uuid4();
		add_option( $generation_option, $generation, '', false );
		$schedule = new \ActionScheduler_IntervalSchedule( new \DateTime( '+1 hour' ), HOUR_IN_SECONDS );
		$action   = new GenerationFencedAction(
			'datamachine_test_repeat_race',
			array( $this->flow_id ),
			$schedule,
			RecurringScheduler::GROUP,
			$generation_option,
			$generation
		);
		RecurringScheduler::captureExecutedRecurrenceGeneration( 123, $action );

		$factory               = new GenerationTransitionActionFactory();
		$factory->before_store = static function () use ( $generation_option ): void {
			update_option( $generation_option, wp_generate_uuid4(), false );
		};
		$successor_id          = (int) $factory->repeat( $action );

		$this->assertGreaterThan( 0, $successor_id );
		$this->assertSame( \ActionScheduler_Store::STATUS_CANCELED, \ActionScheduler_Store::instance()->get_status( $successor_id ) );

		$current_generation    = (string) get_option( $generation_option );
		$current_action        = new GenerationFencedAction(
			'datamachine_test_repeat_race',
			array( $this->flow_id ),
			$schedule,
			RecurringScheduler::GROUP,
			$generation_option,
			$current_generation
		);
		RecurringScheduler::captureExecutedRecurrenceGeneration( 124, $current_action );
		$factory->before_store = static function (): void {};
		$current_successor_id  = (int) $factory->repeat( $current_action );
		$this->assertSame( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::instance()->get_status( $current_successor_id ) );
		\ActionScheduler_Store::instance()->cancel_action( $current_successor_id );

		delete_option( $generation_option );
	}

	public function test_pause_flow_keeps_enabled_state_when_recurrence_cannot_be_fenced(): void {
		$this->holdScheduleLock( $this->flow_id );

		$result = wp_get_ability( 'datamachine/pause-flow' )->execute( array( 'flow_id' => $this->flow_id ) );
		$flow   = $this->flows->get_flow( $this->flow_id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['errors'] );
		$this->assertSame( 'pause_error', $result['flows'][0]['status'] );
		$this->assertTrue( $flow['scheduling_config']['enabled'] );
		$this->assertNotFalse( as_next_scheduled_action( self::FLOW_HOOK, array( $this->flow_id ), RecurringScheduler::GROUP ) );
	}

	public function test_desired_state_records_reconciliation_drift_after_scheduler_failure(): void {
		$result = FlowScheduling::handle_scheduling_update(
			$this->flow_id,
			array( 'interval' => 'one_time' ),
			true
		);
		$flow = $this->flows->get_flow( $this->flow_id );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_timestamp', $result->get_error_code() );
		$this->assertSame( 'one_time', $flow['scheduling_config']['interval'] );
		$this->assertSame( 'drift', $flow['scheduling_config']['schedule_reconciliation']['status'] );
		$this->assertSame( 'missing_timestamp', $flow['scheduling_config']['schedule_reconciliation']['error_code'] );
	}

	public function test_delete_pipeline_keeps_database_records_when_any_recurrence_cannot_be_fenced(): void {
		$blocked_flow_id          = (int) $this->flows->create_flow(
			array(
				'pipeline_id'       => $this->pipeline_id,
				'flow_name'         => 'Blocked pipeline deletion flow',
				'flow_config'       => array(),
				'scheduling_config' => array(
					'enabled'  => true,
					'interval' => 'hourly',
				),
			)
		);
		$this->managed_flow_ids[] = $blocked_flow_id;
		RecurringScheduler::ensureSchedule( self::FLOW_HOOK, array( $blocked_flow_id ), 'hourly' );
		$this->holdScheduleLock( $blocked_flow_id );

		$result = wp_get_ability( 'datamachine/delete-pipeline' )->execute( array( 'pipeline_id' => $this->pipeline_id ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'pipeline_flow_deletion_incomplete', $result['error_code'] );
		$this->assertSame( 1, $result['deleted_flows'] );
		$this->assertSame( 'schedule_lock_timeout', $result['schedule_failures'][ $blocked_flow_id ]['error_code'] );
		$this->assertNotNull( $this->pipelines->get_pipeline( $this->pipeline_id ) );
		$this->assertNull( $this->flows->get_flow( $this->flow_id ) );
		$this->assertNotNull( $this->flows->get_flow( $blocked_flow_id ) );
		$this->assertFalse( as_next_scheduled_action( self::FLOW_HOOK, array( $this->flow_id ), RecurringScheduler::GROUP ) );
		$this->assertNotFalse( as_next_scheduled_action( self::FLOW_HOOK, array( $blocked_flow_id ), RecurringScheduler::GROUP ) );
	}

	public function test_creation_rollback_records_failed_schedule_compensation(): void {
		$ability = new CreateFlowAbility();
		$scope   = ( new ReflectionMethod( $ability, 'beginCreationTransactionScope' ) )->invoke( $ability );
		$flow_id = (int) $this->flows->create_flow(
			array(
				'pipeline_id'       => $this->pipeline_id,
				'flow_name'         => 'Failed rollback fixture',
				'flow_config'       => array(),
				'scheduling_config' => array(
					'enabled'  => true,
					'interval' => 'hourly',
				),
			)
		);
		$this->managed_flow_ids[] = $flow_id;
		RecurringScheduler::ensureSchedule( self::FLOW_HOOK, array( $flow_id ), 'hourly' );
		$this->holdScheduleLock( $flow_id );

		$result = ( new ReflectionMethod( $ability, 'rollbackCreation' ) )->invoke( $ability, $scope, $flow_id, 'Forced rollback' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'schedule_lock_timeout', $result['schedule_cleanup']['error_code'] );
		$this->assertTrue( $result['schedule_cleanup']['retryable'] );
		$this->assertNull( $this->flows->get_flow( $flow_id ) );
	}

	private function holdScheduleLock( int $flow_id ): void {
		$lock_option = $this->scheduleOptionNames( $flow_id )['lock'];
		delete_option( $lock_option );
		add_option(
			$lock_option,
			array(
				'token'      => 'competing-test-owner',
				'started_at' => time(),
				'expires_at' => time() + 300,
			),
			'',
			false
		);
	}

	private function releaseScheduleLock( int $flow_id ): void {
		delete_option( $this->scheduleOptionNames( $flow_id )['lock'] );
	}

	/**
	 * @return array{lock:string,generation:string}
	 */
	private function scheduleOptionNames( int $flow_id ): array {
		$signature = wp_json_encode( array( self::FLOW_HOOK, array( $flow_id ), RecurringScheduler::GROUP ) );
		$hash      = md5( (string) $signature );

		return array(
			'lock'       => 'datamachine_schedule_lock_' . $hash,
			'generation' => 'datamachine_schedule_generation_' . $hash,
		);
	}
}
