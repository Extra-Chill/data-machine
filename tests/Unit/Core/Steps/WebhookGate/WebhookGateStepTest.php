<?php
/**
 * Tests for WebhookGateStep.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\WebhookGate
 */

namespace DataMachine\Tests\Unit\Core\Steps\WebhookGate;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Steps\WebhookGate\WebhookGateStep;
use DataMachine\Core\Steps\WebhookGate\WebhookGateSettings;
use DataMachine\Core\JobStatus;
use ReflectionMethod;
use WP_UnitTestCase;

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
			array( array( 'invalid_utf8' => "\xB1\x31" ) ),
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

	public function test_status_failure_rolls_back_scheduler_insert_and_payload(): void {
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
		$token  = str_repeat( 'f', 64 );
		$job_id = $this->createWaitingGate( $token );
		set_transient( 'datamachine_webhook_gate_' . $token, $job_id, HOUR_IN_SECONDS );
		$request = new \WP_REST_Request( 'POST', '/datamachine/v1/webhook/' . $token );
		$request->set_param( 'token', $token );
		$request->set_body_params( array( 'event' => 'accepted' ) );
		try {
			$winner = WebhookGateStep::handleInboundWebhook( $request );
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
		$this->assertSame( array( 'event' => 'accepted' ), $job['engine_data']['step_input_packets']['next-step'][0]['data']['body'] );
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
	}

	public function test_job_row_lock_serializes_competing_claim_connections(): void {
		global $wpdb;
		if ( ! class_exists( '\mysqli' ) || ! defined( 'MYSQLI_ASYNC' ) ) {
			$this->markTestSkipped( 'mysqli async support is required.' );
		}
		$first  = $this->openMysqlConnection();
		$second = $this->openMysqlConnection();
		if ( ! $first instanceof \mysqli || ! $second instanceof \mysqli ) {
			$this->markTestSkipped( 'Two MySQL connections are unavailable.' );
		}
		$job_id = $this->createWaitingGate( str_repeat( '4', 64 ) );
		$table  = str_replace( '`', '``', $this->jobs->get_table_name() );

		try {
			$this->assertTrue( $first->begin_transaction() );
			$this->assertInstanceOf( \mysqli_result::class, $first->query( "SELECT job_id FROM `{$table}` WHERE job_id = {$job_id} FOR UPDATE" ) );
			$this->assertTrue( $second->query( "SELECT job_id FROM `{$table}` WHERE job_id = {$job_id} FOR UPDATE", MYSQLI_ASYNC ) );
			$read = array( $second );
			$error = $reject = array();
			$this->assertSame( 0, \mysqli_poll( $read, $error, $reject, 0, 100000 ), 'The competing claim must wait for the jobs row lock.' );
			$this->assertTrue( $first->commit() );
			do {
				$read  = array( $second );
				$error = $reject = array();
				$ready = \mysqli_poll( $read, $error, $reject, 1 );
			} while ( 0 === $ready );
			$this->assertInstanceOf( \mysqli_result::class, $second->reap_async_query() );
		} finally {
			$first->rollback();
			$second->rollback();
			$first->close();
			$second->close();
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

	private function assertWaitingGate( int $job_id ): void {
		wp_cache_delete( $job_id, 'datamachine_engine_data' );
		$job = $this->jobs->get_job( $job_id );
		$this->assertSame( JobStatus::WAITING, $job['status'] );
		$this->assertSame( 'waiting', $job['engine_data']['webhook_gate']['status'] );
	}

	private function openMysqlConnection(): ?\mysqli {
		$host   = DB_HOST;
		$port   = null;
		$socket = null;
		if ( preg_match( '/^([^:]+):(\d+)$/', $host, $matches ) ) {
			$host = $matches[1];
			$port = (int) $matches[2];
		} elseif ( preg_match( '/^([^:]+):(.+)$/', $host, $matches ) ) {
			$host   = $matches[1];
			$socket = $matches[2];
		}

		$connection = \mysqli_init();
		if ( false === $connection || ! @$connection->real_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket ) ) {
			return null;
		}
		return $connection;
	}
}
