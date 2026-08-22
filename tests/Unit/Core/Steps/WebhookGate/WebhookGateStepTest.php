<?php
/**
 * Tests for WebhookGateStep.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\WebhookGate
 */

namespace DataMachine\Tests\Unit\Core\Steps\WebhookGate;

use DataMachine\Abilities\Engine\ScheduleNextStepAbility;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DataPacketStore;
use DataMachine\Core\JobStatus;
use DataMachine\Core\Steps\WebhookGate\WebhookGateStep;
use DataMachine\Core\Steps\WebhookGate\WebhookGateSettings;
use ReflectionMethod;
use WP_UnitTestCase;

final class ScheduleNextStepAbilityStoreDouble extends ScheduleNextStepAbility {

	public function __construct(
		private string $result_type,
		private int $returned_id = 0
	) {
		parent::__construct( false );
	}

	protected function saveAtomicAction( \ActionScheduler_DBStore $store, \ActionScheduler_Action $action ): int {
		if ( 'foreign' === $this->result_type ) {
			$foreign_action = new \ActionScheduler_Action(
				$action->get_hook(),
				$action->get_args(),
				$action->get_schedule(),
				'foreign-group'
			);
			return (int) $store->save_action( $foreign_action );
		}
		if ( 'valid' === $this->result_type ) {
			return parent::saveAtomicAction( $store, $action );
		}
		return $this->returned_id;
	}
}

class WebhookGateStepTest extends WP_UnitTestCase {

	private Jobs $jobs;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		$this->jobs = new Jobs();
	}

	/**
	 * Test that WebhookGateStep class exists and extends Step.
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( WebhookGateStep::class ) );
	}

	/**
	 * Test that WebhookGateStep extends the base Step class.
	 */
	public function test_extends_step(): void {
		$reflection = new \ReflectionClass( WebhookGateStep::class );
		$this->assertTrue( $reflection->isSubclassOf( \DataMachine\Core\Steps\Step::class ) );
	}

	/**
	 * Test that WebhookGateStep uses StepTypeRegistrationTrait.
	 */
	public function test_uses_registration_trait(): void {
		$traits = class_uses( WebhookGateStep::class );
		$this->assertArrayHasKey(
			\DataMachine\Core\Steps\StepTypeRegistrationTrait::class,
			$traits
		);
	}

	/**
	 * Test that validateStepConfiguration exists and returns bool.
	 */
	public function test_has_validate_step_configuration(): void {
		$method = new ReflectionMethod( WebhookGateStep::class, 'validateStepConfiguration' );
		$this->assertTrue( $method->isProtected() );
		$return_type = $method->getReturnType();
		$this->assertNotNull( $return_type );
		$this->assertSame( 'bool', $return_type->getName() );
	}

	/**
	 * Test that executeStep exists and returns array.
	 */
	public function test_has_execute_step(): void {
		$method = new ReflectionMethod( WebhookGateStep::class, 'executeStep' );
		$this->assertTrue( $method->isProtected() );
		$return_type = $method->getReturnType();
		$this->assertNotNull( $return_type );
		$this->assertSame( 'array', $return_type->getName() );
	}

	/**
	 * Test that handleInboundWebhook is a public static method.
	 */
	public function test_has_handle_inbound_webhook(): void {
		$method = new ReflectionMethod( WebhookGateStep::class, 'handleInboundWebhook' );
		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	/**
	 * Test WebhookGateSettings fields.
	 */
	public function test_settings_fields(): void {
		$fields = WebhookGateSettings::get_fields();

		$this->assertIsArray( $fields );
		$this->assertArrayHasKey( 'timeout_hours', $fields );
		$this->assertArrayHasKey( 'description', $fields );
		$this->assertSame( 'number', $fields['timeout_hours']['type'] );
		$this->assertSame( 0, $fields['timeout_hours']['default'] );
		$this->assertSame( 'text', $fields['description']['type'] );
	}

	/**
	 * Test WebhookGateSettings extends SettingsHandler.
	 */
	public function test_settings_extends_settings_handler(): void {
		$reflection = new \ReflectionClass( WebhookGateSettings::class );
		$this->assertTrue( $reflection->isSubclassOf( \DataMachine\Core\Steps\Settings\SettingsHandler::class ) );
	}

	public function test_same_token_deliveries_have_one_durable_winner(): void {
		$token  = str_repeat( 'a', 64 );
		$job_id = $this->createWaitingGate( $token );

		$scheduled = 0;
		$schedule  = static function () use ( &$scheduled ): int {
			return ++$scheduled;
		};
		$winner = $this->jobs->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:00Z', $this->packet(), $schedule );
		$loser  = ( new Jobs() )->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:01Z', $this->packet(), $schedule );

		$this->assertTrue( $winner['owned'] );
		$this->assertFalse( $loser['owned'] );
		$this->assertTrue( $loser['already_resumed'] );
		$job = $this->jobs->get_job( $job_id );
		$this->assertSame( JobStatus::PROCESSING, $job['status'] );
		$this->assertSame( 'received', $job['engine_data']['webhook_gate']['status'] );
		$this->assertSame( '2026-08-21T12:00:00Z', $job['engine_data']['webhook_gate']['received_at'] );
		$this->assertSame( 1, $job['engine_data']['webhook_gate']['action_id'] );
		$this->assertSame( array( 'event' => 'accepted' ), $job['engine_data']['step_input_packets']['next-step'][0]['data']['body'] );
		$this->assertSame( 'gate-step', $job['engine_data']['step_input_packets']['next-step'][0]['metadata']['flow_step_id'] );
		$this->assertSame( 1, $scheduled );
		$this->assertArrayNotHasKey( 'job_status', $job['engine_data'] );
	}

	public function test_received_token_is_a_replay_after_downstream_status_changes(): void {
		$token     = str_repeat( '1', 64 );
		$job_id    = $this->createWaitingGate( $token );
		$scheduled = 0;
		$schedule  = static function () use ( &$scheduled ): int {
			return ++$scheduled;
		};

		$this->assertTrue( $this->jobs->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:00Z', $this->packet(), $schedule )['owned'] );
		$this->assertTrue( $this->jobs->update_job_status( $job_id, JobStatus::PENDING ) );
		$pending_replay = ( new Jobs() )->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:01Z', $this->packet(), $schedule );
		$this->assertTrue( $this->jobs->update_job_status( $job_id, JobStatus::COMPLETED ) );
		$terminal_replay = ( new Jobs() )->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:02Z', $this->packet(), $schedule );

		$this->assertTrue( $pending_replay['already_resumed'] );
		$this->assertTrue( $terminal_replay['already_resumed'] );
		$this->assertSame( 1, $scheduled );
	}

	public function test_timeout_wins_atomically_before_webhook_resume(): void {
		$token  = str_repeat( '4', 64 );
		$job_id = $this->createWaitingGate( $token );

		$timeout = $this->jobs->timeout_webhook_gate( $job_id, $token );
		$claim   = ( new Jobs() )->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:00Z', $this->packet(), static fn(): int => 1 );
		$job     = $this->jobs->get_job( $job_id );

		$this->assertTrue( $timeout['success'] );
		$this->assertTrue( $timeout['changed'] );
		$this->assertSame( JobStatus::FAILED, $job['status'] );
		$this->assertSame( 'timed_out', $job['engine_data']['webhook_gate']['status'] );
		$this->assertFalse( $claim['success'] );
		$this->assertFalse( $claim['owned'] );
	}

	public function test_webhook_resume_wins_atomically_before_timeout(): void {
		$token  = str_repeat( '5', 64 );
		$job_id = $this->createWaitingGate( $token );

		$claim   = $this->jobs->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:00Z', $this->packet(), static fn(): int => 1 );
		$timeout = ( new Jobs() )->timeout_webhook_gate( $job_id, $token );
		$job     = $this->jobs->get_job( $job_id );

		$this->assertTrue( $claim['owned'] );
		$this->assertFalse( $timeout['success'] );
		$this->assertFalse( $timeout['changed'] );
		$this->assertSame( JobStatus::PROCESSING, $job['status'] );
		$this->assertSame( 'received', $job['engine_data']['webhook_gate']['status'] );
	}

	public function test_wrong_token_fails_closed_without_mutation(): void {
		$token  = str_repeat( 'b', 64 );
		$job_id = $this->createWaitingGate( $token );

		$result = $this->jobs->claim_webhook_gate_resume( $job_id, str_repeat( 'c', 64 ), '2026-08-21T12:00:00Z', $this->packet(), static fn(): int => 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'token_mismatch', $result['reason'] );
		$this->assertSame( JobStatus::WAITING, $this->jobs->get_job( $job_id )['status'] );
		$this->assertSame( 'waiting', datamachine_get_engine_data( $job_id )['webhook_gate']['status'] );
	}

	public function test_payload_failure_rolls_back_without_scheduling(): void {
		$token  = str_repeat( 'd', 64 );
		$job_id = $this->createWaitingGate( $token );
		$scheduled = 0;
		$result    = $this->jobs->claim_webhook_gate_resume(
			$job_id,
			$token,
			'2026-08-21T12:00:00Z',
			array( array( 'invalid_number' => INF ) ),
			static function () use ( &$scheduled ): int {
				return ++$scheduled;
			}
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'payload_persistence_failed', $result['reason'] );
		$this->assertSame( 0, $scheduled );
		$this->assertWaitingGate( $job_id );
	}

	public function test_schedule_failure_rolls_back_payload_and_receipt(): void {
		$token  = str_repeat( '2', 64 );
		$job_id = $this->createWaitingGate( $token );

		$result = $this->jobs->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:00Z', $this->packet(), static fn(): int => 0 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'schedule_failed', $result['reason'] );
		$this->assertWaitingGate( $job_id );
		$this->assertArrayNotHasKey( 'step_input_packets', $this->jobs->get_job( $job_id )['engine_data'] );
	}

	public function test_empty_payload_cannot_claim_gate(): void {
		$token  = str_repeat( '6', 64 );
		$job_id = $this->createWaitingGate( $token );

		$result = $this->jobs->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:00Z', array(), static fn(): int => 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'payload_missing', $result['reason'] );
		$this->assertWaitingGate( $job_id );
	}

	public function test_webhook_payload_store_failure_does_not_claim_or_schedule(): void {
		$this->requireActionSchedulerDbStore();
		$token  = str_repeat( '5', 64 );
		$job_id = $this->createWaitingGate( $token );
		set_transient( 'datamachine_webhook_gate_' . $token, $job_id, HOUR_IN_SECONDS );
		$request = new \WP_REST_Request( 'POST', '/datamachine/v1/webhook/' . $token );
		$request->set_param( 'token', $token );
		$request->set_body_params( array( 'invalid_number' => INF ) );

		try {
			$result = WebhookGateStep::handleInboundWebhook( $request );
		} finally {
			delete_transient( 'datamachine_webhook_gate_' . $token );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'webhook_payload_persistence_failed', $result->get_error_code() );
		$this->assertWaitingGate( $job_id );
		$this->assertFalse( as_has_scheduled_action( 'datamachine_execute_step', array( 'job_id' => $job_id, 'flow_step_id' => 'next-step' ), 'data-machine' ) );
	}

	public function test_endpoint_pre_schedule_filter_cannot_substitute_action(): void {
		$this->requireActionSchedulerDbStore();
		$token  = str_repeat( '7', 64 );
		$job_id = $this->createWaitingGate( $token );
		set_transient( 'datamachine_webhook_gate_' . $token, $job_id, HOUR_IN_SECONDS );
		$request = new \WP_REST_Request( 'POST', '/datamachine/v1/webhook/' . $token );
		$request->set_param( 'token', $token );
		$request->set_body_params( array( 'event' => 'accepted' ) );
		$filter_called = false;
		$filter = static function () use ( &$filter_called ): int {
			$filter_called = true;
			return 0;
		};
		add_filter( 'pre_as_schedule_single_action', $filter );

		try {
			$result = WebhookGateStep::handleInboundWebhook( $request );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $filter );
			delete_transient( 'datamachine_webhook_gate_' . $token );
		}

		$this->assertSame( 200, $result->get_status() );
		$this->assertFalse( $filter_called );
		$this->assertNotFalse( as_has_scheduled_action( 'datamachine_execute_step', array( 'job_id' => $job_id, 'flow_step_id' => 'next-step' ), 'data-machine' ) );
	}

	public function test_atomic_schedule_rejects_stale_matching_id(): void {
		$this->requireActionSchedulerDbStore();
		$job_id = $this->createWaitingGate( str_repeat( '8', 64 ) );
		$args   = array( 'job_id' => $job_id, 'flow_step_id' => 'next-step' );
		$stale  = as_schedule_single_action( time(), 'datamachine_execute_step', $args, 'data-machine' );

		$this->expectException( \RuntimeException::class );
		( new ScheduleNextStepAbilityStoreDouble( 'returned', $stale ) )->scheduleActionAtomically( $job_id, 'next-step' );
	}

	public function test_atomic_schedule_rejects_new_foreign_group_id(): void {
		$this->requireActionSchedulerDbStore();
		$job_id = $this->createWaitingGate( str_repeat( '9', 64 ) );

		$this->expectException( \RuntimeException::class );
		( new ScheduleNextStepAbilityStoreDouble( 'foreign' ) )->scheduleActionAtomically( $job_id, 'next-step' );
	}

	public function test_atomic_schedule_rejects_fake_id(): void {
		$this->requireActionSchedulerDbStore();
		$job_id = $this->createWaitingGate( str_repeat( '0', 64 ) );

		$this->expectException( \RuntimeException::class );
		( new ScheduleNextStepAbilityStoreDouble( 'returned', PHP_INT_MAX ) )->scheduleActionAtomically( $job_id, 'next-step' );
	}

	public function test_atomic_schedule_rejects_alternate_store(): void {
		$store_property = new \ReflectionProperty( \ActionScheduler_Store::class, 'store' );
		$store_property->setAccessible( true );
		$original_store = $store_property->getValue();
		$store_property->setValue( null, new \ActionScheduler_wpPostStore() );

		try {
			$this->expectException( \RuntimeException::class );
			( new ScheduleNextStepAbility( false ) )->scheduleActionAtomically( 1, 'next-step' );
		} finally {
			$store_property->setValue( null, $original_store );
		}
	}

	public function test_atomic_schedule_inserts_exact_new_pending_row(): void {
		$this->requireActionSchedulerDbStore();
		$job_id = $this->createWaitingGate( str_repeat( 'a', 64 ) );
		$args   = array( 'job_id' => $job_id, 'flow_step_id' => 'next-step' );
		$id     = ( new ScheduleNextStepAbilityStoreDouble( 'valid' ) )->scheduleActionAtomically( $job_id, 'next-step' );
		$action = \ActionScheduler::store()->fetch_action( $id );

		$this->assertSame( 'datamachine_execute_step', $action->get_hook() );
		$this->assertSame( $args, $action->get_args() );
		$this->assertSame( 'data-machine', $action->get_group() );
		$this->assertSame( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler::store()->get_status( $id ) );
	}

	public function test_status_failure_rolls_back_scheduler_insert_and_payload(): void {
		$this->requireActionSchedulerDbStore();
		global $wpdb;
		$token  = str_repeat( '3', 64 );
		$job_id = $this->createWaitingGate( $token );
		$break_status_update = static function ( string $query ) use ( $wpdb ): string {
			if ( str_contains( $query, 'UPDATE' ) && str_contains( $query, $wpdb->prefix . 'datamachine_jobs' ) && str_contains( $query, "'processing'" ) ) {
				return str_replace( $wpdb->prefix . 'datamachine_jobs', $wpdb->prefix . 'datamachine_jobs_missing', $query );
			}
			return $query;
		};
		add_filter( 'query', $break_status_update );
		try {
			$result = $this->jobs->claim_webhook_gate_resume(
				$job_id,
				$token,
				'2026-08-21T12:00:00Z',
				$this->packet(),
				static fn( int $scheduled_job_id, string $step_id ): int => (int) as_schedule_single_action(
					time(),
					'datamachine_execute_step',
					array(
						'job_id'       => $scheduled_job_id,
						'flow_step_id' => $step_id,
					),
					'data-machine'
				)
			);
		} finally {
			remove_filter( 'query', $break_status_update );
		}

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'status_persistence_failed', $result['reason'] );
		$this->assertWaitingGate( $job_id );
		$this->assertFalse( as_has_scheduled_action( 'datamachine_execute_step', array( 'job_id' => $job_id, 'flow_step_id' => 'next-step' ), 'data-machine' ) );
	}

	public function test_lifecycle_projection_failure_rolls_back_complete_handoff(): void {
		global $wpdb;
		$token        = str_repeat( 'c', 64 );
		$job_id       = $this->createWaitingGate( $token );
		$break_lifecycle_write = static function ( string $query ) use ( $wpdb ): string {
			if ( str_contains( $query, 'UPDATE' ) && str_contains( $query, $wpdb->prefix . 'datamachine_jobs' ) && str_contains( $query, 'engine_data' ) ) {
				return str_replace( $wpdb->prefix . 'datamachine_jobs', $wpdb->prefix . 'datamachine_jobs_missing', $query );
			}
			return $query;
		};
		add_filter( 'query', $break_lifecycle_write );
		try {
			$result = $this->jobs->claim_webhook_gate_resume(
				$job_id,
				$token,
				'2026-08-21T12:00:00Z',
				$this->packet(),
				static fn(): int => 1
			);
		} finally {
			remove_filter( 'query', $break_lifecycle_write );
		}

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'lifecycle_persistence_failed', $result['reason'] );
		$this->assertWaitingGate( $job_id );
		$this->assertArrayNotHasKey( 'step_input_packets', $this->jobs->get_job( $job_id )['engine_data'] );
	}

	public function test_engine_cache_is_invalidated_before_and_after_commit(): void {
		$token  = str_repeat( 'd', 64 );
		$job_id = $this->createWaitingGate( $token );
		wp_cache_set( $job_id, array( 'webhook_gate' => array( 'status' => 'stale-waiting' ) ), 'datamachine_engine_data' );
		$result = $this->jobs->claim_webhook_gate_resume(
			$job_id,
			$token,
			'2026-08-21T12:00:00Z',
			$this->packet(),
			static fn(): int => 1
		);

		$this->assertTrue( $result['success'] );
		$this->assertFalse( wp_cache_get( $job_id, 'datamachine_engine_data' ) );
		$this->assertSame( 'received', datamachine_get_engine_data( $job_id )['webhook_gate']['status'] );
	}

	public function test_malformed_engine_data_fails_closed(): void {
		global $wpdb;
		$token  = str_repeat( 'e', 64 );
		$job_id = $this->createWaitingGate( $token );
		$wpdb->update( $this->jobs->get_table_name(), array( 'engine_data' => '{invalid' ), array( 'job_id' => $job_id ), array( '%s' ), array( '%d' ) );
		wp_cache_delete( $job_id, 'datamachine_engine_data' );

		$result = $this->jobs->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:00Z', $this->packet(), static fn(): int => 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'malformed_engine_data', $result['reason'] );
		$this->assertSame( JobStatus::WAITING, $this->jobs->get_job( $job_id )['status'] );
	}

	public function test_duplicate_webhook_delivery_persists_and_schedules_payload_once(): void {
		$this->requireActionSchedulerDbStore();
		$token  = str_repeat( 'f', 64 );
		$job_id = $this->createWaitingGate( $token );
		set_transient( 'datamachine_webhook_gate_' . $token, $job_id, HOUR_IN_SECONDS );
		$request = new \WP_REST_Request( 'POST', '/datamachine/v1/webhook/' . $token );
		$request->set_param( 'token', $token );
		$request->set_body_params( array( 'event' => 'accepted' ) );
		try {
			$winner = WebhookGateStep::handleInboundWebhook( $request );
			$request->set_body_params( array( 'invalid_utf8' => "\xB1\x31" ) );
			$replay = WebhookGateStep::handleInboundWebhook( $request );
		} finally {
			delete_transient( 'datamachine_webhook_gate_' . $token );
		}

		$this->assertSame( 200, $winner->get_status() );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertTrue( $replay->get_data()['already_resumed'] );
		$job = $this->jobs->get_job( $job_id );
		$this->assertGreaterThan( 0, $job['engine_data']['webhook_gate']['action_id'] );
		$this->assertCount( 1, $job['engine_data']['step_input_packets']['next-step'] );
		$this->assertFalse( DataPacketStore::is_ref( $job['engine_data']['step_input_packets']['next-step'][0] ) );
		$packets = $job['engine_data']['step_input_packets']['next-step'];
		$this->assertCount( 1, $packets );
		$this->assertSame( array( 'event' => 'accepted' ), $packets[0]['data']['body'] );
		$this->assertSame( 'gate-step', $packets[0]['metadata']['flow_step_id'] );
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => 'datamachine_execute_step',
					'args'   => array(
						'job_id'       => $job_id,
						'flow_step_id' => 'next-step',
					),
					'group'  => 'data-machine',
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				)
			)
		);
		$this->assertSame( JobStatus::PROCESSING, $job['engine_data']['run_lifecycle']['status'] );
	}

	public function test_scheduled_execution_reads_committed_packets(): void {
		$this->requireActionSchedulerDbStore();
		$token  = str_repeat( 'b', 64 );
		$job_id = $this->createWaitingGate( $token );
		$result = $this->jobs->claim_webhook_gate_resume(
			$job_id,
			$token,
			'2026-08-21T12:00:00Z',
			$this->packet(),
			static fn( int $scheduled_job_id, string $step_id ): int => ( new ScheduleNextStepAbility( false ) )->scheduleActionAtomically( $scheduled_job_id, $step_id )
		);

		$this->assertTrue( $result['success'] );
		$action = \ActionScheduler::store()->fetch_action( $result['action_id'] );
		wp_cache_delete( $job_id, 'datamachine_engine_data' );
		$execution_snapshot = datamachine_get_engine_data( (int) $action->get_args()['job_id'] );
		$this->assertSame( array( 'event' => 'accepted' ), $execution_snapshot['step_input_packets']['next-step'][0]['data']['body'] );
		$this->assertSame( 'received', $execution_snapshot['webhook_gate']['status'] );
	}

	public function test_production_claim_serializes_competing_mysql_callers(): void {
		$this->requireActionSchedulerDbStore();
		global $wpdb;
		if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'pcntl_waitpid' ) ) {
			$this->markTestSkipped( 'Process control support is required.' );
		}
		if ( ! isset( $wpdb->actionscheduler_actions, $wpdb->actionscheduler_groups ) ) {
			$this->markTestSkipped( 'Action Scheduler database tables are unavailable.' );
		}

		$token        = str_repeat( '4', 64 );
		$job_id       = $this->createWaitingGate( $token );
		$barrier      = tempnam( sys_get_temp_dir(), 'dm-webhook-barrier-' );
		$result_files = array(
			tempnam( sys_get_temp_dir(), 'dm-webhook-result-' ),
			tempnam( sys_get_temp_dir(), 'dm-webhook-result-' ),
		);
		if ( false === $barrier || in_array( false, $result_files, true ) ) {
			$this->markTestSkipped( 'Temporary coordination files are unavailable.' );
		}
		unlink( $barrier );
		$pids = array();
		try {
			foreach ( $result_files as $index => $result_file ) {
				$pid = pcntl_fork();
				if ( -1 === $pid ) {
					$this->fail( 'Unable to fork competing webhook claimant.' );
				}
				if ( 0 === $pid ) {
					try {
						$parent_wpdb = $GLOBALS['wpdb'];
						$child_wpdb  = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
						$child_wpdb->set_prefix( $parent_wpdb->prefix );
						$child_wpdb->actionscheduler_actions = $parent_wpdb->actionscheduler_actions;
						$child_wpdb->actionscheduler_groups  = $parent_wpdb->actionscheduler_groups;
						$GLOBALS['wpdb'] = $child_wpdb;
						while ( ! file_exists( $barrier ) ) {
							usleep( 1000 );
						}
						$event = 0 === $index ? 'first' : 'second';
						$claim = ( new Jobs() )->claim_webhook_gate_resume(
							$job_id,
							$token,
							'2026-08-21T12:00:0' . $index . 'Z',
							array( array( 'type' => 'webhook_payload', 'data' => array( 'body' => array( 'event' => $event ) ) ) ),
							static fn( int $scheduled_job_id, string $step_id ): int => ( new ScheduleNextStepAbility( false ) )->scheduleActionAtomically( $scheduled_job_id, $step_id )
						);
						file_put_contents( $result_file, wp_json_encode( array( 'event' => $event, 'claim' => $claim ) ) );
					} catch ( \Throwable $throwable ) {
						file_put_contents( $result_file, wp_json_encode( array( 'error' => $throwable->getMessage() ) ) );
					}
					exit( 0 );
				}
				$pids[] = $pid;
			}
			touch( $barrier );
			foreach ( $pids as $pid ) {
				pcntl_waitpid( $pid, $status );
				$this->assertTrue( pcntl_wifexited( $status ) );
			}

			$claims = array_map( static fn( string $file ): array => json_decode( (string) file_get_contents( $file ), true ), $result_files );
			$this->assertArrayNotHasKey( 'error', $claims[0] );
			$this->assertArrayNotHasKey( 'error', $claims[1] );
			$owned = array_values( array_filter( $claims, static fn( array $entry ): bool => ! empty( $entry['claim']['owned'] ) ) );
			$this->assertCount( 1, $owned );
			$this->assertCount( 1, array_filter( $claims, static fn( array $entry ): bool => ! empty( $entry['claim']['already_resumed'] ) ) );
			wp_cache_delete( $job_id, 'datamachine_engine_data' );
			$job = $this->jobs->get_job( $job_id );
			$this->assertSame( $owned[0]['event'], $job['engine_data']['step_input_packets']['next-step'][0]['data']['body']['event'] );
			$this->assertCount( 1, $job['engine_data']['step_input_packets']['next-step'] );
			$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => 'datamachine_execute_step', 'args' => array( 'job_id' => $job_id, 'flow_step_id' => 'next-step' ), 'group' => 'data-machine' ) ) );
			$replay_schedule_calls = 0;
			$replay = $this->jobs->claim_webhook_gate_resume( $job_id, $token, '2026-08-21T12:00:03Z', $this->packet(), static function () use ( &$replay_schedule_calls ): int {
				++$replay_schedule_calls;
				return 0;
			} );
			$this->assertTrue( $replay['already_resumed'] );
			$this->assertSame( $owned[0]['claim']['action_id'], $replay['action_id'] );
			$this->assertSame( 0, $replay_schedule_calls );
			$replayed_job = $this->jobs->get_job( $job_id );
			$this->assertSame( $owned[0]['event'], $replayed_job['engine_data']['step_input_packets']['next-step'][0]['data']['body']['event'] );
			$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => 'datamachine_execute_step', 'args' => array( 'job_id' => $job_id, 'flow_step_id' => 'next-step' ), 'group' => 'data-machine' ) ) );
		} finally {
			if ( file_exists( $barrier ) ) {
				unlink( $barrier );
			}
			foreach ( $result_files as $result_file ) {
				if ( file_exists( $result_file ) ) {
					unlink( $result_file );
				}
			}
		}
	}

	private function createWaitingGate( string $token ): int {
		$job_id = $this->jobs->create_job( array( 'label' => 'Webhook gate race' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue(
			$this->jobs->store_engine_data(
				$job_id,
				array(
					'job_status'  => JobStatus::WAITING,
					'webhook_gate' => array(
						'token'             => $token,
						'status'            => 'waiting',
						'flow_step_id'      => 'gate-step',
						'next_flow_step_id' => 'next-step',
					),
				)
			)
		);
		$this->assertTrue( $this->jobs->update_job_status( $job_id, JobStatus::WAITING ) );
		return $job_id;
	}

	private function packet(): array {
		return array(
			array(
				'type' => 'webhook_payload',
				'data' => array( 'body' => array( 'event' => 'accepted' ) ),
			),
		);
	}

	private function requireActionSchedulerDbStore(): void {
		if ( \ActionScheduler_DBStore::class !== get_class( \ActionScheduler::store() ) ) {
			$this->markTestSkipped( 'Atomic Webhook Gate handoff requires the Action Scheduler DB store.' );
		}
	}

	private function assertWaitingGate( int $job_id ): void {
		wp_cache_delete( $job_id, 'datamachine_engine_data' );
		$job = $this->jobs->get_job( $job_id );
		$this->assertSame( JobStatus::WAITING, $job['status'] );
		$this->assertSame( 'waiting', $job['engine_data']['webhook_gate']['status'] );
	}

}
