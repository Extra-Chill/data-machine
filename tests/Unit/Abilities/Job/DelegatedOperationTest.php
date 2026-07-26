<?php
/**
 * Delegated operation contract coverage.
 *
 * @package DataMachine\Tests\Unit\Abilities\Job
 */

namespace DataMachine\Tests\Unit\Abilities\Job;

use DataMachine\Abilities\Job\DelegatedOperationAbilities;
use DataMachine\Abilities\Job\ExecuteWorkflowAbility;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DelegatedOperations\DelegatedOperationRegistry;
use DataMachine\Core\DelegatedOperations\DelegatedOperationService;
use WP_UnitTestCase;

final class DelegatedOperationTest extends WP_UnitTestCase {

	private int $first_actor;
	private int $second_actor;
	private int $execution_owner;
	private int $manager;
	private array $authorized = array();
	private array $projection = array();
	private bool $retry_safe = true;

	public function set_up(): void {
		parent::set_up();
		datamachine_register_capabilities();
		$this->first_actor     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->second_actor    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->execution_owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->manager         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->authorized      = array( $this->first_actor, $this->second_actor );
		$this->projection      = array(
			'effect_count' => 1,
			'record_ref'   => 'rec_public',
		);
		add_filter( DelegatedOperationRegistry::FILTER, array( $this, 'registerAction' ) );
	}

	public function tear_down(): void {
		remove_filter( DelegatedOperationRegistry::FILTER, array( $this, 'registerAction' ) );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function registerAction( array $actions ): array {
		$actions['owner/write-record'] = array(
			'version'         => '1',
			'normalize_input' => static fn( array $input ): array => array(
				'record_key' => sanitize_key( (string) ( $input['record_key'] ?? '' ) ),
				'value'      => sanitize_text_field( (string) ( $input['value'] ?? '' ) ),
			),
			'authorize'       => function ( array $context ) {
				return in_array( (int) ( $context['actor']['user_id'] ?? 0 ), $this->authorized, true )
					? true
					: new \WP_Error( 'owner_action_forbidden', 'Owner policy denied the actor.' );
			},
			'prepare'         => function ( array $input ): array {
				return array(
					'owner_user_id' => $this->execution_owner,
					'label'         => 'Write owner record',
					'workflow'      => $this->workflow( $input['value'] ),
					'initial_data'  => array( 'owner_record_key' => $input['record_key'] ),
				);
			},
			'project'         => fn(): array => $this->projection,
			'retry'           => fn(): bool => $this->retry_safe,
		);
		return $actions;
	}

	public function test_two_authorized_users_share_one_owner_neutral_operation(): void {
		$service = new DelegatedOperationService();
		wp_set_current_user( $this->first_actor );
		$first = $service->submit( $this->submission( 'shared-operation' ) );
		wp_set_current_user( $this->second_actor );
		$second = $service->submit( $this->submission( 'shared-operation' ) );
		$job    = $this->job( 'shared-operation' );

		$this->assertTrue( $first['success'] );
		$this->assertTrue( $second['success'] );
		$this->assertSame( $first['operation_ref'], $second['operation_ref'] );
		$this->assertFalse( $first['replayed'] );
		$this->assertTrue( $second['replayed'] );
		$this->assertSame( $this->execution_owner, (int) $job['user_id'] );
		$this->assertSame( $this->first_actor, (int) $job['engine_data']['delegated_operation']['initiator']['user_id'] );
		$this->assertSame( $this->execution_owner, (int) $job['engine_data']['delegated_operation']['execution_owner']['user_id'] );
	}

	public function test_receipt_recovery_and_fingerprint_conflict(): void {
		$service = new DelegatedOperationService();
		wp_set_current_user( $this->first_actor );
		$fail_schedule = static fn() => false;
		add_filter( 'pre_as_schedule_single_action', $fail_schedule );
		$interrupted = $service->submit( $this->submission( 'crash-recovery' ) );
		remove_filter( 'pre_as_schedule_single_action', $fail_schedule );

		$recovered = $service->submit( $this->submission( 'crash-recovery' ) );
		$conflict  = $service->submit( $this->submission( 'crash-recovery', 'changed' ) );

		$this->assertFalse( $interrupted['success'] );
		$this->assertTrue( $interrupted['retryable'] );
		$this->assertTrue( $recovered['success'] );
		$this->assertTrue( $recovered['replayed'] );
		$this->assertFalse( $conflict['success'] );
		$this->assertSame( 'delegated_operation_conflict', $conflict['error_code'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $this->job( 'crash-recovery' )['request_fingerprint'] );
	}

	public function test_action_authority_is_bounded_and_manager_has_no_owner_authority(): void {
		$service = new DelegatedOperationService();
		wp_set_current_user( $this->first_actor );
		$this->assertTrue( ( new DelegatedOperationAbilities( $service ) )->checkPermission() );
		$this->assertFalse( ( new ExecuteWorkflowAbility() )->checkPermission() );
		$this->assertTrue( $service->submit( $this->submission( 'bounded-authority' ) )['success'] );

		wp_set_current_user( $this->manager );
		$this->assertTrue( ( new ExecuteWorkflowAbility() )->checkPermission() );
		$denied = $service->submit( $this->submission( 'manager-denied' ) );
		$this->assertFalse( $denied['success'] );
		$this->assertSame( 'owner_action_forbidden', $denied['error_code'] );
		$this->assertSame( 'delegated_action_unavailable', $service->submit( array_replace( $this->submission( 'arbitrary-action' ), array( 'action' => 'owner/arbitrary' ) ) )['error_code'] );
	}

	public function test_registered_public_ability_executes_the_bounded_contract(): void {
		wp_set_current_user( $this->first_actor );
		$submit = wp_get_ability( 'datamachine/submit-delegated-operation' );
		$get    = wp_get_ability( 'datamachine/get-delegated-operation' );
		$retry  = wp_get_ability( 'datamachine/retry-delegated-operation' );
		$cancel = wp_get_ability( 'datamachine/cancel-delegated-operation' );
		$this->assertNotNull( $submit );
		$this->assertNotNull( $get );
		$this->assertNotNull( $retry );
		$this->assertNotNull( $cancel );

		$result = $submit->execute( $this->submission( 'public-ability' ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertMatchesRegularExpression( '/^dop_[a-f0-9]{64}$/', $result['operation_ref'] );
		$this->assertArrayNotHasKey( 'job_id', $result );
		$this->assertTrue( is_wp_error( $submit->execute( array_merge( $this->submission( 'undeclared-input' ), array( 'job_id' => 123 ) ) ) ) );
	}

	public function test_active_replay_returns_execution_state_without_reenqueue(): void {
		$service = new DelegatedOperationService();
		wp_set_current_user( $this->first_actor );
		$submitted = $service->submit( $this->submission( 'active-replay' ) );
		$job       = $this->job( 'active-replay' );
		$this->assertTrue( ( new Jobs() )->start_job( (int) $job['job_id'] ) );

		$replayed = $service->submit( $this->submission( 'active-replay' ) );
		$this->assertTrue( $replayed['success'] );
		$this->assertTrue( $replayed['replayed'] );
		$this->assertSame( $submitted['operation_ref'], $replayed['operation_ref'] );
		$this->assertSame( 'executing', $replayed['status'] );
	}

	public function test_canonical_no_op_and_owner_redaction_are_public_truth(): void {
		$service = new DelegatedOperationService();
		wp_set_current_user( $this->first_actor );
		$submitted = $service->submit( $this->submission( 'redacted-result' ) );
		$job       = $this->job( 'redacted-result' );
		$this->assertTrue( ( new Jobs() )->complete_job( (int) $job['job_id'], 'completed_no_items' ) );
		$job       = $this->job( 'redacted-result' );
		$engine    = $job['engine_data'];
		$engine['run_result'] = array(
			'schema_version' => 'datamachine.run_result.v1',
			'status'         => 'completed_no_items',
			'diagnostics'    => array( 'private_value' => 'must-not-cross-boundary' ),
		);
		$this->assertTrue( ( new Jobs() )->store_engine_data( (int) $job['job_id'], $engine ) );
		$this->projection = array( 'effect_count' => 1, 'record_ref' => 'rec_none' );

		$result = $service->reconcile( array( 'action' => 'owner/write-record', 'operation_ref' => $submitted['operation_ref'] ) );
		$this->assertSame( 'no-op', $result['status'] );
		$this->assertSame( array( 'effect_count' => 1, 'record_ref' => 'rec_none' ), $result['projection'] );
		$this->assertStringNotContainsString( 'must-not-cross-boundary', wp_json_encode( $result ) );
		$this->assertArrayNotHasKey( 'job_id', $result );
	}

	public function test_canonical_skipped_and_zero_effect_results_are_independent_no_ops(): void {
		$service = new DelegatedOperationService();
		wp_set_current_user( $this->first_actor );
		$submitted = $service->submit( $this->submission( 'canonical-zero' ) );
		$job       = $this->job( 'canonical-zero' );
		$this->assertTrue( ( new Jobs() )->complete_job( (int) $job['job_id'], 'completed' ) );
		$job       = $this->job( 'canonical-zero' );
		$engine    = $job['engine_data'];
		$engine['run_result'] = array(
			'schema_version' => 'datamachine.run_result.v1',
			'status'         => 'succeeded',
			'outputs'        => array( 'effect_count' => 0 ),
		);
		$this->assertTrue( ( new Jobs() )->store_engine_data( (int) $job['job_id'], $engine ) );
		$this->projection = array( 'record_ref' => 'rec_zero' );
		$zero = $service->reconcile( array( 'action' => 'owner/write-record', 'operation_ref' => $submitted['operation_ref'] ) );
		$this->assertSame( 'no-op', $zero['status'] );

		$submitted = $service->submit( $this->submission( 'canonical-skipped' ) );
		$job       = $this->job( 'canonical-skipped' );
		$this->assertTrue( ( new Jobs() )->complete_job( (int) $job['job_id'], 'completed' ) );
		$job       = $this->job( 'canonical-skipped' );
		$engine    = $job['engine_data'];
		$engine['run_result'] = array( 'schema_version' => 'datamachine.run_result.v1', 'status' => 'skipped' );
		$this->assertTrue( ( new Jobs() )->store_engine_data( (int) $job['job_id'], $engine ) );
		$skipped = $service->reconcile( array( 'action' => 'owner/write-record', 'operation_ref' => $submitted['operation_ref'] ) );
		$this->assertSame( 'no-op', $skipped['status'] );
	}

	public function test_delayed_cancel_fences_generation_and_executing_cancel_fails(): void {
		$service = new DelegatedOperationService();
		wp_set_current_user( $this->first_actor );
		$delayed = $this->submission( 'delayed-cancel' );
		$delayed['timestamp'] = time() + HOUR_IN_SECONDS;
		$submitted = $service->submit( $delayed );
		$before    = $this->job( 'delayed-cancel' );

		$cancelled = $service->cancel( array( 'action' => 'owner/write-record', 'operation_ref' => $submitted['operation_ref'] ) );
		$after     = $this->job( 'delayed-cancel' );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( 'cancelled', $after['operation_state'] );
		$this->assertGreaterThan( (int) $before['operation_generation'], (int) $after['operation_generation'] );
		$this->assertEmpty( $after['operation_claim_token'] );

		$running = $service->submit( $this->submission( 'executing-cancel' ) );
		$job     = $this->job( 'executing-cancel' );
		$this->assertTrue( ( new Jobs() )->start_job( (int) $job['job_id'] ) );
		$denied = $service->cancel( array( 'action' => 'owner/write-record', 'operation_ref' => $running['operation_ref'] ) );
		$this->assertSame( 'delegated_operation_not_cancellable', $denied['error_code'] );
		$this->assertSame( 'processing', $this->job( 'executing-cancel' )['status'] );
	}

	public function test_failed_retry_reuses_operation_and_retrying_is_distinct(): void {
		$service = new DelegatedOperationService();
		wp_set_current_user( $this->first_actor );
		$submitted = $service->submit( $this->submission( 'retry-operation' ) );
		$job       = $this->job( 'retry-operation' );
		$this->assertTrue( ( new Jobs() )->complete_job( (int) $job['job_id'], 'failed - test' ) );

		$retried = $service->retry( array( 'action' => 'owner/write-record', 'operation_ref' => $submitted['operation_ref'] ) );
		$this->assertTrue( $retried['success'] );
		$this->assertSame( 'submitted', $retried['status'] );
		$this->assertSame( (int) $job['job_id'], (int) $this->job( 'retry-operation' )['job_id'] );

		$retrying_job = $this->job( 'retry-operation' );
		$engine       = $retrying_job['engine_data'];
		$engine['retry'] = array( 'attempt' => 1, 'max_attempts' => 3, 'next_retry_at' => gmdate( 'c', time() + 60 ) );
		$this->assertTrue( ( new Jobs() )->store_engine_data( (int) $retrying_job['job_id'], $engine ) );
		$retrying = $service->reconcile( array( 'action' => 'owner/write-record', 'operation_ref' => $submitted['operation_ref'] ) );
		$this->assertSame( 'retrying', $retrying['status'] );
		$this->assertSame( 1, $retrying['retry']['attempt'] );

		$this->assertTrue( ( new Jobs() )->complete_job( (int) $retrying_job['job_id'], 'failed - retry-fence' ) );
		$this->retry_safe = false;
		$unsafe = $service->retry( array( 'action' => 'owner/write-record', 'operation_ref' => $submitted['operation_ref'] ) );
		$this->assertSame( 'delegated_operation_retry_unsafe', $unsafe['error_code'] );
	}

	private function submission( string $operation_id, string $value = 'stable' ): array {
		return array(
			'action'       => 'owner/write-record',
			'operation_id' => $operation_id,
			'input'        => array( 'record_key' => 'example', 'value' => $value ),
		);
	}

	private function job( string $operation_id ): array {
		$key = 'delegated:' . hash( 'sha256', 'owner/write-record' . "\0" . $operation_id );
		$job = ( new Jobs() )->get_job_by_idempotency_key( $key );
		$this->assertIsArray( $job );
		return $job;
	}

	private function workflow( string $value ): array {
		return array(
			'steps' => array(
				array(
					'step_type'     => 'ai',
					'system_prompt' => $value,
				),
			),
		);
	}
}
