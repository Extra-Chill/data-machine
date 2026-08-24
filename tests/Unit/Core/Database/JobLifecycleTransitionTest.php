<?php
/**
 * Tests for job lifecycle status transitions.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Jobs\LegacyAIConcurrencyReconciler;
use DataMachine\Core\JobStatus;
use DataMachine\Core\RecoveryExecutionFence;
use DataMachine\Core\ChildJobRecoveryPolicy;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Engine\AI\AIConcurrencyBackpressure;
use DataMachine\Engine\Actions\Handlers\StepLifecycleHandler;
use DataMachine\Abilities\Job\RecoverStuckJobsAbility;
use DataMachine\Abilities\Engine\ExecuteStepAbility;
use WP_UnitTestCase;

class JobLifecycleTransitionTest extends WP_UnitTestCase {

	private Jobs $db_jobs;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		$this->db_jobs = new Jobs();
	}

	public function tear_down(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'datamachine_execute_step' );
			as_unschedule_all_actions( AIConcurrencyBackpressure::RESUME_HOOK );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_terminal_status_cannot_be_overwritten_by_start_job(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Terminal immutability' ) );
		$this->assertIsInt( $job_id );

		$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::FAILED ) );
		$this->assertFalse( $this->db_jobs->start_job( $job_id, JobStatus::PROCESSING ) );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::FAILED, $job['status'] );
	}

	public function test_metadata_read_does_not_materialize_large_json_envelopes(): void {
		$large_value        = str_repeat( 'packet-', 128 );
		$engine_data        = array( 'batch' => true, 'references' => array_fill( 0, 2000, $large_value ) );
		$operation_envelope = array( 'steps' => array_fill( 0, 2000, $large_value ) );
		$job_id             = $this->db_jobs->create_job(
			array(
				'label'              => 'Bounded metadata',
				'engine_data'        => $engine_data,
				'operation_envelope' => $operation_envelope,
			)
		);
		$this->assertIsInt( $job_id );
		unset( $large_value, $engine_data, $operation_envelope );
		gc_collect_cycles();

		$queries_before = get_num_queries();
		$memory_before  = memory_get_usage();
		$metadata       = $this->db_jobs->get_job_metadata( $job_id );
		$memory_delta = memory_get_usage() - $memory_before;
		$this->assertIsArray( $metadata );
		$this->assertSame( JobStatus::PENDING, $metadata['status'] );
		$this->assertArrayNotHasKey( 'engine_data', $metadata );
		$this->assertArrayNotHasKey( 'operation_envelope', $metadata );
		$this->assertSame( 1, get_num_queries() - $queries_before );
		$this->assertStringNotContainsString( 'engine_data', strtolower( (string) $GLOBALS['wpdb']->last_query ) );
		$this->assertStringNotContainsString( 'operation_envelope', strtolower( (string) $GLOBALS['wpdb']->last_query ) );
		$this->assertLessThan( 1024 * 1024, $memory_delta, 'Metadata projection should not materialize either multi-megabyte JSON envelope.' );

		$full = $this->db_jobs->get_job( $job_id );
		$this->assertIsArray( $full['engine_data'] );
		$this->assertCount( 2000, $full['engine_data']['references'] );
		$this->assertIsArray( $full['operation_envelope'] );
		$this->assertCount( 2000, $full['operation_envelope']['steps'] );
	}

	public function test_concurrent_pathless_child_recovery_has_one_owner(): void {
		$parent_id = $this->db_jobs->create_job( array( 'label' => 'Recovery parent' ) );
		$child_id  = $this->db_jobs->create_job( array( 'label' => 'Recovery child', 'parent_job_id' => $parent_id ) );
		$this->assertIsInt( $parent_id );
		$this->assertIsInt( $child_id );
		$this->assertTrue( $this->db_jobs->start_job( $child_id ) );

		$ability = new RecoverStuckJobsAbility();
		$method  = new \ReflectionMethod( $ability, 'claimPathlessChildRecovery' );
		$first   = $method->invoke( $ability, $child_id, 'test' );
		$second  = $method->invoke( $ability, $child_id, 'test' );

		$this->assertTrue( $first['owned'] );
		$this->assertFalse( $second['owned'] );
		$this->assertNotSame( $first['token'], $second['token'] );
	}

	public function test_stale_recovery_owner_cannot_finish_or_terminalize_after_reclaim(): void {
		$parent_id = $this->db_jobs->create_job( array( 'label' => 'Stale recovery parent' ) );
		$child_id  = $this->db_jobs->create_job( array( 'label' => 'Stale recovery child', 'parent_job_id' => $parent_id ) );
		$this->assertTrue( $this->db_jobs->start_job( $child_id ) );

		$ability = new RecoverStuckJobsAbility();
		$claim   = new \ReflectionMethod( $ability, 'claimPathlessChildRecovery' );
		$finish  = new \ReflectionMethod( $ability, 'finishPathlessChildRecovery' );
		$first   = $claim->invoke( $ability, $child_id, 'test' );
		$engine  = datamachine_get_engine_data( $child_id );
		$engine['scheduler_recovery']['expires_at'] = gmdate( 'c', time() - 1 );
		$this->assertTrue( datamachine_set_engine_data( $child_id, $engine ) );

		$second = $claim->invoke( $ability, $child_id, 'test' );
		$this->assertTrue( $second['owned'] );
		$this->assertGreaterThan( $first['generation'], $second['generation'] );
		$this->assertFalse( $finish->invoke( $ability, $child_id, $first['token'], $first['generation'], 'stale_finish', array() ) );

		$stale_terminal = $this->db_jobs->transition_recovery_owned_child( $child_id, 'failed - stale_owner', $first['token'], $first['generation'] );
		$this->assertFalse( $stale_terminal['success'] );
		$this->assertSame( JobStatus::PROCESSING, $this->db_jobs->get_job( $child_id )['status'] );

		$winner = $this->db_jobs->transition_recovery_owned_child( $child_id, 'failed - scheduler_path_lost', $second['token'], $second['generation'] );
		$this->assertTrue( $winner['success'] );
	}

	public function test_recovery_requeue_rolls_back_scheduler_exception_and_can_retry(): void {
		$parent_id = $this->db_jobs->create_job( array( 'label' => 'Receipt parent' ) );
		$child_id  = $this->db_jobs->create_job( array( 'label' => 'Receipt child', 'parent_job_id' => $parent_id ) );
		$this->assertTrue( $this->db_jobs->start_job( $child_id ) );

		$ability = new RecoverStuckJobsAbility();
		$claim   = ( new \ReflectionMethod( $ability, 'claimPathlessChildRecovery' ) )->invoke( $ability, $child_id, 'test' );
		$crashed = $this->db_jobs->commit_recovery_owned_requeue(
			$child_id,
			$claim['token'],
			$claim['generation'],
			static function () use ( $child_id ): int {
				as_schedule_single_action( time(), 'datamachine_execute_step', array( 'job_id' => $child_id, 'flow_step_id' => 'crashed' ), 'data-machine' );
				throw new \RuntimeException( 'simulated crash' );
			}
		);
		$this->assertFalse( $crashed['success'] );
		$this->assertSame( JobStatus::PROCESSING, $this->db_jobs->get_job( $child_id )['status'] );
		$this->assertArrayNotHasKey( 'receipt', datamachine_get_engine_data( $child_id )['scheduler_recovery'] );
		$this->assertFalse( as_has_scheduled_action( 'datamachine_execute_step', array( 'job_id' => $child_id, 'flow_step_id' => 'crashed' ), 'data-machine' ) );

		$retried = $this->db_jobs->commit_recovery_owned_requeue(
			$child_id,
			$claim['token'],
			$claim['generation'],
			static fn(): int => (int) as_schedule_single_action( time(), 'datamachine_execute_step', array( 'job_id' => $child_id, 'flow_step_id' => 'retried' ), 'data-machine', true )
		);
		$this->assertTrue( $retried['success'] );
		$this->assertGreaterThan( 0, $retried['action_id'] );
		$this->assertSame( JobStatus::PENDING, $this->db_jobs->get_job( $child_id )['status'] );
		$this->assertSame( $retried['action_id'], datamachine_get_engine_data( $child_id )['scheduler_recovery']['receipt']['action_id'] );
		$this->assertSame( JobStatus::PENDING, datamachine_get_engine_data( $child_id )['run_lifecycle']['status'] );
	}

	public function test_recovery_apply_limit_bounds_multiple_candidate_batches(): void {
		$job_ids = array();
		for ( $index = 0; $index < 55; ++$index ) {
			$job_id = $this->db_jobs->create_job( array( 'label' => 'Bounded recovery ' . $index ) );
			$this->assertTrue( $this->db_jobs->start_job( $job_id ) );
			datamachine_merge_engine_data( $job_id, array( 'job_status' => 'failed - bounded_recovery' ) );
			$job_ids[] = $job_id;
		}

		$result = ( new RecoverStuckJobsAbility() )->execute( array( 'dry_run' => false, 'limit' => 52 ) );
		$this->assertSame( 52, $result['mutations'] );
		$this->assertSame( 52, $result['attempted'] );
		$this->assertSame( 52, $result['touched'] );
		$this->assertSame( 52, $result['mutated'] );
		$this->assertSame( 'broad_touch_cap', $result['limit_mode'] );
		$this->assertSame( 52, $result['limit_value'] );
		$this->assertSame( 'logical_touch', $result['limit_unit'] );
		$this->assertSame( 52, $result['input_limit'] );
		$this->assertSame( 52, $result['requested_limit'] );
		$this->assertSame( 52, $result['logical_touch_limit'] );
		$this->assertSame( 52, $result['target_attempts'] );
		$this->assertSame( 52, $result['logical_touches'] );
		$this->assertSame( 52, $result['logical_mutations'] );
		$this->assertSame( 52, $result['recovered'] );
		$this->assertTrue( $result['limit_reached'] );

		$processing = array_filter( $job_ids, fn( int $job_id ): bool => JobStatus::PROCESSING === $this->db_jobs->get_job( $job_id )['status'] );
		$this->assertCount( 3, $processing );
	}

	public function test_active_pending_ai_deferral_is_guarded(): void {
		$job_id    = $this->db_jobs->create_job( array( 'label' => 'Active pending AI deferral' ) );
		$generation = 1;
		$action_id = as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			AIConcurrencyBackpressure::RESUME_HOOK,
			array( 'job_id' => $job_id, 'flow_step_id' => 'ai-step', 'ai_resume_generation' => $generation ),
			'data-machine-ai-resume-test',
			true
		);
		$this->storePendingAIDeferral( $job_id, (int) $action_id, $generation );

		$result = ( new RecoverStuckJobsAbility() )->execute( array( 'dry_run' => true, 'job_id' => $job_id, 'limit' => 1 ) );
		$this->assertSame( 0, $result['pending_ai_terminalized'] );
		$this->assertSame( 1, $result['pending_ai_guarded'] );
		$this->assertSame( 'recorded_scheduler_action_exists', $result['jobs'][0]['reason'] );
		$this->assertSame( JobStatus::PENDING, $this->db_jobs->get_job( $job_id )['status'] );
	}

	public function test_expired_pending_ai_deferral_dry_run_and_apply_are_coherent(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Expired pending AI deferral' ) );
		$this->storePendingAIDeferral( $job_id, 900000001, 4 );

		$dry_run = ( new RecoverStuckJobsAbility() )->execute( array( 'dry_run' => true, 'job_id' => $job_id, 'limit' => 1 ) );
		$this->assertSame( 1, $dry_run['pending_ai_terminalized'] );
		$this->assertSame( 0, $dry_run['mutations'] );
		$this->assertSame( 0, $dry_run['touched'] );
		$this->assertSame( 'would_terminalize_expired_ai_deferral', $dry_run['jobs'][0]['status'] );

		$applied = ( new RecoverStuckJobsAbility() )->execute( array( 'job_id' => $job_id, 'limit' => 1 ) );
		$this->assertSame( $dry_run['pending_ai_terminalized'], $applied['pending_ai_terminalized'] );
		$this->assertSame( 1, $applied['mutations'] );
		$this->assertSame( 1, $applied['touched'] );
		$this->assertSame( 'terminalized_expired_ai_deferral', $applied['jobs'][0]['status'] );
		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::CANCELLED, $job['status'] );
		$this->assertArrayNotHasKey( 'ai_concurrency_throttle', $job['engine_data'] );
		$this->assertSame( 'scheduler_action_missing', $job['engine_data']['ai_concurrency_history'][0]['reason'] );
	}

	public function test_legacy_expired_pending_ai_deferral_without_generation_is_recoverable(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Legacy expired pending AI deferral' ) );
		$this->storePendingAIDeferral( $job_id, 900000002, 0, true );

		$dry_run = ( new RecoverStuckJobsAbility() )->execute( array( 'dry_run' => true, 'job_id' => $job_id, 'limit' => 1 ) );
		$this->assertSame( 1, $dry_run['pending_ai_terminalized'] );
		$this->assertSame( 0, $dry_run['jobs'][0]['generation'] );

		$applied = ( new RecoverStuckJobsAbility() )->execute( array( 'job_id' => $job_id, 'limit' => 1 ) );
		$this->assertSame( 1, $applied['pending_ai_terminalized'] );
		$this->assertSame( JobStatus::CANCELLED, $this->db_jobs->get_job( $job_id )['status'] );
	}

	public function test_pending_ai_recovery_honors_exact_scope_and_limit(): void {
		$job_ids = array();
		foreach ( array( 900000011, 900000012, 900000013 ) as $index => $action_id ) {
			$job_id = $this->db_jobs->create_job( array( 'label' => 'Bounded pending AI deferral ' . $index ) );
			$this->storePendingAIDeferral( $job_id, $action_id, 2 );
			$job_ids[] = $job_id;
		}

		$exact = ( new RecoverStuckJobsAbility() )->execute( array( 'dry_run' => true, 'job_id' => $job_ids[1], 'limit' => 3 ) );
		$this->assertSame( 1, $exact['pending_ai_terminalized'] );
		$this->assertSame( $job_ids[1], $exact['jobs'][0]['job_id'] );
		$this->assertSame( 3, $exact['input_limit'] );
		$this->assertSame( 1, $exact['requested_limit'] );
		$this->assertSame( 'target', $exact['limit_unit'] );
		$this->assertSame( 100, $exact['logical_touch_limit'] );

		$dry_run = ( new RecoverStuckJobsAbility() )->execute( array( 'dry_run' => true, 'limit' => 2 ) );
		$applied = ( new RecoverStuckJobsAbility() )->execute( array( 'limit' => 2 ) );
		$this->assertSame( 2, $dry_run['pending_ai_terminalized'] );
		$this->assertSame( $dry_run['pending_ai_terminalized'], $applied['pending_ai_terminalized'] );
		$this->assertSame( 2, $applied['touched'] );
		$this->assertCount( 1, array_filter( $job_ids, fn( int $id ): bool => JobStatus::PENDING === $this->db_jobs->get_job( $id )['status'] ) );
	}

	private function storePendingAIDeferral( int $job_id, int $action_id, int $generation, bool $legacy = false ): void {
		$engine = datamachine_get_engine_data( $job_id );
		$engine['ai_concurrency_throttle'] = array(
			'flow_step_id'      => 'ai-step',
			'next_retry_at'     => gmdate( 'c', time() - HOUR_IN_SECONDS ),
			'action_id'         => $action_id,
		);
		if ( ! $legacy ) {
			$engine['ai_concurrency_throttle']['state']             = 'deferred';
			$engine['ai_concurrency_throttle']['resume_generation'] = $generation;
			$engine['ai_concurrency_resume_ownership'] = array(
				'status'       => 'scheduled',
				'flow_step_id' => 'ai-step',
				'action_id'    => $action_id,
				'generation'   => $generation,
			);
		}
		$this->assertTrue( datamachine_set_engine_data( $job_id, $engine ) );
	}

	public function test_action_history_overfetches_past_numeric_prefix_collisions(): void {
		$exact_id = as_schedule_single_action( time(), 'datamachine_execute_step', array( 'job_id' => 42, 'flow_step_id' => 'exact' ), 'data-machine' );
		for ( $index = 0; $index < 75; ++$index ) {
			as_schedule_single_action( time(), 'datamachine_execute_step', array( 'job_id' => 420, 'flow_step_id' => 'collision-' . $index ), 'data-machine' );
		}

		$ability = new RecoverStuckJobsAbility();
		$history = ( new \ReflectionMethod( $ability, 'getStepActionHistory' ) )->invoke( $ability, 42 );
		$this->assertCount( 1, $history );
		$this->assertSame( $exact_id, (int) $history[0]['action_id'] );
		$this->assertSame( 42, (int) $history[0]['decoded_args']['job_id'] );
	}

	public function test_recovery_rejects_invalid_exact_job_scope(): void {
		$result = ( new RecoverStuckJobsAbility() )->execute( array( 'job_id' => '1.5' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'get_jobs_failed', $result->get_error_code() );
		$this->assertSame( 'job_id must be a positive integer.', $result->get_error_message() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_exact_pathless_recovery_with_limit_one_attempts_only_target_and_is_idempotent(): void {
		global $wpdb;
		$unrelated_parent = $this->db_jobs->create_job( array( 'label' => 'Unrelated recovery parent' ) );
		$unrelated_child  = $this->db_jobs->create_job( array( 'label' => 'Unrelated recovery child', 'parent_job_id' => $unrelated_parent ) );
		$parent_id        = $this->db_jobs->create_job( array( 'label' => 'Exact recovery parent' ) );
		$child_id         = $this->db_jobs->create_job( array( 'label' => 'Exact recovery child', 'parent_job_id' => $parent_id ) );
		$this->assertTrue( $this->db_jobs->start_job( $unrelated_child ) );
		$this->assertTrue( $this->db_jobs->start_job( $child_id ) );
		$wpdb->update(
			$wpdb->prefix . 'datamachine_jobs',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ),
			array( 'job_id' => $unrelated_child ),
			array( '%s' ),
			array( '%d' )
		);
		$wpdb->update(
			$wpdb->prefix . 'datamachine_jobs',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ),
			array( 'job_id' => $child_id ),
			array( '%s' ),
			array( '%d' )
		);
		$unrelated_parent_before = $this->db_jobs->get_job( $unrelated_parent );
		$unrelated_child_before  = $this->db_jobs->get_job( $unrelated_child );

		$input = array( 'job_id' => $child_id, 'limit' => 1, 'timeout_hours' => 1, 'recover_pathless_children' => true );
		$dry_run = ( new RecoverStuckJobsAbility() )->execute( array( 'dry_run' => true ) + $input );
		$this->assertSame( $child_id, $dry_run['jobs'][0]['job_id'] );
		$this->assertSame( 'would_transition_pathless_child', $dry_run['jobs'][0]['status'] );

		$applied = ( new RecoverStuckJobsAbility() )->execute( $input );
		$this->assertSame( 1, $applied['pathless_terminal'] );
		$this->assertSame( 2, $applied['attempted'] );
		$this->assertSame( 2, $applied['touched'] );
		$this->assertSame( 2, $applied['mutated'] );
		$this->assertSame( 1, $applied['input_limit'] );
		$this->assertSame( 1, $applied['requested_limit'] );
		$this->assertSame( 100, $applied['apply_limit'] );
		$this->assertSame( 'exact_target', $applied['limit_mode'] );
		$this->assertSame( 1, $applied['limit_value'] );
		$this->assertSame( 'target', $applied['limit_unit'] );
		$this->assertSame( 1, $applied['target_attempts'] );
		$this->assertSame( 2, $applied['logical_touches'] );
		$this->assertSame( 2, $applied['logical_mutations'] );
		$this->assertSame( 100, $applied['logical_touch_limit'] );
		$this->assertSame( $applied['attempted'], $applied['touched'] );
		$this->assertFalse( $applied['limit_reached'] );
		$this->assertSame( $child_id, $applied['jobs'][0]['job_id'] );
		$this->assertSame( 'transitioned_pathless_child', $applied['jobs'][0]['status'] );
		$this->assertSame( $unrelated_parent_before, $this->db_jobs->get_job( $unrelated_parent ) );
		$this->assertSame( $unrelated_child_before, $this->db_jobs->get_job( $unrelated_child ) );
		$this->assertSame( JobStatus::FAILED, $this->db_jobs->get_job( $child_id )['status'] );

		$second_apply = ( new RecoverStuckJobsAbility() )->execute( $input );
		$this->assertSame( 0, $second_apply['pathless_terminal'] );
		$this->assertSame( 0, $second_apply['attempted'] );
		$this->assertSame( 0, $second_apply['touched'] );
		$this->assertSame( 0, $second_apply['mutated'] );
		$this->assertSame( 0, $second_apply['target_attempts'] );
		$this->assertSame( 0, $second_apply['logical_touches'] );
		$this->assertSame( 0, $second_apply['logical_mutations'] );
		$this->assertSame( 'exact_target', $second_apply['limit_mode'] );
		$this->assertFalse( $second_apply['limit_reached'] );
		$this->assertSame( JobStatus::FAILED, $this->db_jobs->get_job( $child_id )['status'] );
		$this->assertSame( $unrelated_parent_before, $this->db_jobs->get_job( $unrelated_parent ) );
		$this->assertSame( $unrelated_child_before, $this->db_jobs->get_job( $unrelated_child ) );
	}

	public function test_touch_limit_stops_before_partial_pathless_claim(): void {
		global $wpdb;
		$parent_id = $this->db_jobs->create_job( array( 'label' => 'Touch-bound parent' ) );
		$child_id  = $this->db_jobs->create_job( array( 'label' => 'Touch-bound child', 'parent_job_id' => $parent_id ) );
		$this->assertTrue( $this->db_jobs->start_job( $child_id ) );
		$wpdb->update(
			$wpdb->prefix . 'datamachine_jobs',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ),
			array( 'job_id' => $child_id ),
			array( '%s' ),
			array( '%d' )
		);

		$result = ( new RecoverStuckJobsAbility() )->execute(
			array(
				'limit'                     => 2,
				'timeout_hours'             => 1,
				'recover_pathless_children' => true,
			)
		);
		$this->assertTrue( $result['limit_reached'] );
		$this->assertSame( 0, $result['attempted'] );
		$this->assertSame( 0, $result['touched'] );
		$this->assertSame( 0, $result['mutated'] );
		$this->assertArrayNotHasKey( 'scheduler_recovery', datamachine_get_engine_data( $child_id ) );
		$this->assertSame( JobStatus::PROCESSING, $this->db_jobs->get_job( $child_id )['status'] );

		$result = ( new RecoverStuckJobsAbility() )->execute(
			array(
				'limit'                     => 3,
				'timeout_hours'             => 1,
				'recover_pathless_children' => true,
			)
		);
		$this->assertFalse( $result['limit_reached'] );
		$this->assertSame( 1, $result['mutations'] );
		$this->assertSame( 2, $result['attempted'] );
		$this->assertSame( 2, $result['touched'] );
		$this->assertSame( 2, $result['mutated'] );
		$this->assertSame( 1, $result['target_attempts'] );
		$this->assertSame( 2, $result['logical_touches'] );
		$this->assertSame( 2, $result['logical_mutations'] );
		$this->assertSame( 'broad_touch_cap', $result['limit_mode'] );
		$this->assertSame( 1, $result['pathless_terminal'] );
		$job = $this->db_jobs->get_job( $child_id );
		$this->assertSame( JobStatus::FAILED, $job['status'] );
		$this->assertSame( 'scheduler_path_lost', $job['engine_data']['job_status_reason'] );
	}

	public function test_long_running_recovery_takeover_blocks_terminal_callbacks(): void {
		$parent_id = $this->db_jobs->create_job( array( 'label' => 'Generation parent' ) );
		$child_id  = $this->db_jobs->create_job( array( 'label' => 'Generation child', 'parent_job_id' => $parent_id ) );
		$this->assertTrue( $this->db_jobs->start_job( $child_id ) );
		$engine = datamachine_get_engine_data( $child_id );
		$engine['scheduler_recovery'] = array(
			'schema'     => 'datamachine.scheduler-recovery.v1',
			'state'      => 'requeued',
			'token'      => 'generation-one',
			'generation' => 1,
			'receipt'    => array( 'generation' => 1, 'action_id' => 101 ),
		);
		$this->assertTrue( datamachine_set_engine_data( $child_id, $engine ) );

		$terminal_callbacks = 0;
		$callback = static function ( string $status ) use ( &$terminal_callbacks ): string {
			++$terminal_callbacks;
			return $status;
		};
		add_filter( 'datamachine_job_terminal_status', $callback, 1, 1 );
		$fence = new RecoveryExecutionFence( $child_id, 'generation-one', 1 );
		$nested_fence = new RecoveryExecutionFence( $child_id, 'generation-one', 1 );
		unset( $nested_fence );

		$takeover = datamachine_get_engine_data( $child_id );
		$takeover['scheduler_recovery'] = array(
			'schema'     => 'datamachine.scheduler-recovery.v1',
			'state'      => 'claimed',
			'token'      => 'generation-two',
			'generation' => 2,
			'claimed_at' => gmdate( 'c' ),
			'expires_at' => gmdate( 'c', time() + 300 ),
		);
		$this->assertTrue( datamachine_set_engine_data( $child_id, $takeover ) );

		$this->assertFalse( $this->db_jobs->transition_job_status( $child_id, JobStatus::COMPLETED, true ) );
		$this->assertSame( 0, $terminal_callbacks );
		$this->assertSame( JobStatus::PROCESSING, $this->db_jobs->get_job( $child_id )['status'] );
		$executor = new ExecuteStepAbility();
		$still_owned = ( new \ReflectionMethod( $executor, 'recoveryGenerationStillOwned' ) )->invoke( $executor, $child_id, 1, 'generation-one' );
		$noop = ( new \ReflectionMethod( $executor, 'staleRejectedTerminalTransition' ) )->invoke(
			$executor,
			$child_id,
			1,
			'generation-one',
			array( 'success' => false )
		);
		$this->assertFalse( $still_owned );
		$this->assertTrue( $noop['success'] );
		$this->assertSame( 'stale_recovery_noop', $noop['outcome'] );
		unset( $fence );
		remove_filter( 'datamachine_job_terminal_status', $callback, 1 );
	}

	public function test_recovery_evidence_validates_active_claim_ownership(): void {
		$job_id    = $this->db_jobs->create_job( array( 'label' => 'Claim evidence' ) );
		$processed = new ProcessedItems();
		$token     = $processed->claim_item_owned( 'scope', 'source', 'item', $job_id, 600 );
		$this->assertIsString( $token );
		$claim = array(
			'identity_scope'  => 'scope',
			'source_type'     => 'source',
			'item_identifier' => 'item',
			'ownership_token' => $token,
		);
		$ability  = new RecoverStuckJobsAbility();
		$evidence = ( new \ReflectionMethod( $ability, 'activeClaimEvidence' ) )->invoke( $ability, array( '_datamachine_item_claim' => $claim ), $job_id );
		$this->assertSame( 'active_owned', $evidence['state'] );
		$this->assertSame( 1, $evidence['active'] );

		$this->assertSame( 1, $processed->release_owned_claim( 'scope', 'source', 'item', $token ) );
		$stale = ( new \ReflectionMethod( $ability, 'activeClaimEvidence' ) )->invoke( $ability, array( '_datamachine_item_claim' => $claim ), $job_id );
		$this->assertSame( 'stale_or_unowned', $stale['state'] );
		$this->assertSame( 0, $stale['active'] );
	}

	public function test_recovery_evidence_does_not_label_absent_generation_valid(): void {
		$parent_id = $this->db_jobs->create_job( array( 'label' => 'Evidence parent' ) );
		$child_id  = $this->db_jobs->create_job( array( 'label' => 'Evidence child', 'parent_job_id' => $parent_id ) );
		$engine    = datamachine_get_engine_data( $child_id );
		$engine['flow_config'] = array( 'step' => array( 'step_type' => 'fetch' ) );
		$this->assertTrue( datamachine_set_engine_data( $child_id, $engine ) );
		$job = $this->db_jobs->get_job( $child_id );
		$diagnosis = ChildJobRecoveryPolicy::diagnose(
			$job,
			$engine,
			array(
				array(
					'action_id'   => 77,
					'hook'        => 'datamachine_execute_step',
					'status'      => 'failed',
					'decoded_args' => array( 'job_id' => $child_id, 'flow_step_id' => 'step' ),
				),
			),
			HOUR_IN_SECONDS,
			time()
		);
		$ability  = new RecoverStuckJobsAbility();
		$evidence = ( new \ReflectionMethod( $ability, 'childRecoveryEvidence' ) )->invoke( $ability, $job, $diagnosis, 'would_requeue_pathless_child', 'test' );
		$this->assertSame( 0, $evidence['action_generation'] );
		$this->assertSame( 'legacy_unfenced', $evidence['action_generation_state'] );
	}

	public function test_terminal_transition_hook_only_fires_when_status_changes(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Terminal hook idempotency' ) );
		$this->assertIsInt( $job_id );

		$completed = array();
		$listener  = static function ( int $completed_job_id, string $status ) use ( &$completed ): void {
			$completed[] = array( $completed_job_id, $status );
		};

		add_action( 'datamachine_job_complete', $listener, 10, 2 );
		try {
			$first = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );
			$again = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED, true );

			$this->assertTrue( $first['success'] );
			$this->assertTrue( $first['changed'] );
			$this->assertTrue( $again['success'] );
			$this->assertFalse( $again['changed'] );
			$this->assertSame( array( array( $job_id, JobStatus::COMPLETED ) ), $completed );
		} finally {
			remove_action( 'datamachine_job_complete', $listener, 10 );
		}
	}

	/**
	 * @dataProvider terminal_accounting_interruption_boundaries
	 */
	public function test_terminal_accounting_replays_from_every_post_commit_boundary( string $boundary ): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Terminal accounting ' . $boundary ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $this->db_jobs->start_job( $job_id ) );

		$committed_hooks = 0;
		$complete_hooks  = 0;
		$committed       = static function ( int $hook_job_id ) use ( $job_id, &$committed_hooks ): void {
			if ( $job_id === $hook_job_id ) {
				++$committed_hooks;
			}
		};
		$complete        = static function ( int $hook_job_id ) use ( $job_id, &$complete_hooks ): void {
			if ( $job_id === $hook_job_id ) {
				++$complete_hooks;
			}
		};
		$interrupted     = false;
		$interrupt       = static function ( bool $should_interrupt, string $current_boundary, int $filtered_job_id ) use ( $boundary, $job_id, &$interrupted ): bool {
			if ( ! $interrupted && $job_id === $filtered_job_id && $boundary === $current_boundary ) {
				$interrupted = true;
				return true;
			}
			return $should_interrupt;
		};

		add_action( 'datamachine_job_terminal_committed', $committed, 10, 1 );
		add_action( 'datamachine_job_complete', $complete, 10, 1 );
		add_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10, 3 );
		try {
			try {
				$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED );
				$this->fail( 'Expected terminal accounting interruption.' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'Terminal accounting interrupted at ' . $boundary, $exception->getMessage() );
			}
		} finally {
			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10 );
		}

		try {
			$job = $this->db_jobs->get_job( $job_id );
			$this->assertSame( JobStatus::COMPLETED, $job['status'] );
			if ( Jobs::TERMINAL_ACCOUNTING_COMPLETE !== (int) $job['terminal_accounting_state'] ) {
				$this->expireTerminalAccountingLease( $job_id );
			}
			StepLifecycleHandler::handleTerminalRollback( $job_id );

			$replayed = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$repeated = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$this->assertTrue( $replayed['complete'] );
			$this->assertTrue( $repeated['complete'] );
			$this->assertSame( 1, $committed_hooks );
			$this->assertSame( 1, $complete_hooks );

			$completed_job = $this->db_jobs->get_job( $job_id );
			$this->assertSame( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $completed_job['terminal_accounting_state'] );
			$this->assertSame( $completed_job['completed_at'], $completed_job['engine_data']['run_metrics']['completed_at'] );
			$this->assertSame( $completed_job['completed_at'], $completed_job['engine_data']['run_lifecycle']['completed_at'] );
		} finally {
			remove_action( 'datamachine_job_terminal_committed', $committed, 10 );
			remove_action( 'datamachine_job_complete', $complete, 10 );
		}
	}

	/** @return array<string,array{string}> */
	public static function terminal_accounting_interruption_boundaries(): array {
		return array(
			'before metrics'             => array( 'before:run_metrics' ),
			'after metrics operation'    => array( 'after_operation:run_metrics' ),
			'after metrics commit'       => array( 'after_commit:run_metrics' ),
			'before core callbacks'      => array( 'before:core_callbacks' ),
			'after core callbacks'       => array( 'after_operation:core_callbacks' ),
			'after core commit'          => array( 'after_commit:core_callbacks' ),
			'before lifecycle'           => array( 'before:run_lifecycle' ),
			'after lifecycle operation'  => array( 'after_operation:run_lifecycle' ),
			'after lifecycle commit'     => array( 'after_commit:run_lifecycle' ),
			'before notifications'       => array( 'before:extension_notifications' ),
			'after notifications'        => array( 'after_notification:extension_notifications' ),
		);
	}

	public function test_throwing_core_callback_does_not_skip_later_callback_and_remains_replayable(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Replayable core callbacks' ) );
		$this->assertIsInt( $job_id );

		$throw_calls = 0;
		$later_calls = 0;
		$registry    = static function ( array $callbacks ) use ( &$throw_calls, &$later_calls ): array {
			$callbacks['throwing_test'] = static function () use ( &$throw_calls ): void {
				++$throw_calls;
				if ( $throw_calls <= 2 ) {
					throw new \RuntimeException( 'core callback interrupted' );
				}
			};
			$callbacks['later_test'] = static function () use ( &$later_calls ): void {
				++$later_calls;
			};
			return $callbacks;
		};
		add_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		try {
			$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED ) );
			$incomplete = $this->db_jobs->get_job( $job_id );
			$this->assertSame( 1, (int) $incomplete['terminal_accounting_state'] );
			$this->assertSame( 1, $later_calls, 'later core callback runs despite an earlier exception' );

			$failed = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$this->assertFalse( $failed['success'] );
			$this->assertSame( 'core_callbacks', $failed['stage'] );
			$this->assertSame( 'core_callback_exception', $failed['errors'][0]['code'] );
			$this->assertSame( 'throwing_test', $failed['errors'][0]['callback'] );
			$this->assertSame( 2, $later_calls );

			$replayed = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$this->assertTrue( $replayed['complete'] );
			$this->assertSame( 3, $throw_calls );
			$this->assertSame( 3, $later_calls );
		} finally {
			remove_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		}
	}

	public function test_concurrent_reconciler_cannot_overtake_owned_stage(): void {
		$job_id          = $this->db_jobs->create_job( array( 'label' => 'Ordered concurrent accounting' ) );
		$contender       = null;
		$core_calls      = 0;
		$core_at_contend = null;
		$registry        = static function ( array $callbacks ) use ( &$core_calls ): array {
			$callbacks['ordering_test'] = static function () use ( &$core_calls ): void {
				++$core_calls;
			};
			return $callbacks;
		};
		$interleave      = static function ( bool $interrupt, string $boundary, int $filtered_job_id ) use ( $job_id, &$contender, &$core_at_contend, &$core_calls ): bool {
			if ( $job_id === $filtered_job_id && 'before:run_metrics' === $boundary && null === $contender ) {
				$contender       = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
				$core_at_contend = $core_calls;
			}
			return $interrupt;
		};

		add_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		add_filter( 'datamachine_job_terminal_accounting_interrupt', $interleave, 10, 3 );
		try {
			$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED ) );
		} finally {
			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interleave, 10 );
			remove_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		}

		$this->assertIsArray( $contender );
		$this->assertTrue( $contender['in_progress'] );
		$this->assertSame( 'run_metrics', $contender['stage'] );
		$this->assertSame( 0, $core_at_contend );
		$this->assertSame( 1, $core_calls );
		$this->assertSame( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $this->db_jobs->get_job( $job_id )['terminal_accounting_state'] );
	}

	public function test_extension_listener_exception_is_best_effort_after_core_completion(): void {
		$job_id      = $this->db_jobs->create_job( array( 'label' => 'Best effort extension notification' ) );
		$core_calls  = 0;
		$hook_calls  = 0;
		$registry    = static function ( array $callbacks ) use ( &$core_calls ): array {
			$callbacks['notification_order_test'] = static function () use ( &$core_calls ): void {
				++$core_calls;
			};
			return $callbacks;
		};
		$listener    = static function () use ( &$hook_calls ): void {
			++$hook_calls;
			throw new \RuntimeException( 'extension notification failed' );
		};
		$interrupted = false;
		$interrupt   = static function ( bool $should_interrupt, string $boundary, int $filtered_job_id ) use ( $job_id, &$interrupted ): bool {
			if ( ! $interrupted && $job_id === $filtered_job_id && 'before:extension_notifications' === $boundary ) {
				$interrupted = true;
				return true;
			}
			return $should_interrupt;
		};

		add_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
		add_action( 'datamachine_job_complete', $listener, 1 );
		add_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10, 3 );
		try {
			try {
				$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED );
				$this->fail( 'Expected interruption before extension notification.' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'Terminal accounting interrupted at before:extension_notifications', $exception->getMessage() );
			}
			$this->assertSame( 1, $core_calls );
			$this->assertSame( 0, $hook_calls );

			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10 );
			$this->expireTerminalAccountingLease( $job_id );
			$result = ( new Jobs() )->reconcile_terminal_accounting( $job_id );
			$this->assertTrue( $result['complete'] );
			$this->assertSame( 'extension_notification_failed', $result['errors'][0]['code'] );
			$this->assertSame( 1, $hook_calls );
			$this->assertTrue( ( new Jobs() )->reconcile_terminal_accounting( $job_id )['complete'] );
			$this->assertSame( 1, $hook_calls );
		} finally {
			remove_filter( 'datamachine_job_terminal_accounting_interrupt', $interrupt, 10 );
			remove_filter( 'datamachine_job_terminal_core_callbacks', $registry, 100 );
			remove_action( 'datamachine_job_complete', $listener, 1 );
		}
	}

	public function test_reopening_failed_job_starts_a_fresh_accounting_receipt(): void {
		$job_id = $this->db_jobs->create_job(
			array(
				'label'       => 'Fresh retry accounting',
				'engine_data' => array(
					'job_status_reason' => 'delegated_operation_failed',
					'retry_context'     => 'preserved',
				),
			)
		);
		$this->assertIsInt( $job_id );
		$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::FAILED ) );
		$this->assertSame( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $this->db_jobs->get_job( $job_id )['terminal_accounting_state'] );

		$this->assertTrue( $this->db_jobs->reopen_failed_job( $job_id ) );
		$reopened = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::PENDING, $reopened['status'] );
		$this->assertNull( $reopened['terminal_accounting_state'] );
		$this->assertArrayNotHasKey( 'job_status_reason', $reopened['engine_data'] );
		$this->assertSame( 'preserved', $reopened['engine_data']['retry_context'] );

		$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED ) );
		$this->assertSame( Jobs::TERMINAL_ACCOUNTING_COMPLETE, (int) $this->db_jobs->get_job( $job_id )['terminal_accounting_state'] );
	}

	public function test_terminal_status_can_move_from_active_once(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Active to terminal' ) );
		$this->assertIsInt( $job_id );

		$this->assertTrue( $this->db_jobs->start_job( $job_id, JobStatus::PROCESSING ) );

		$completed = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::COMPLETED_NO_ITEMS, true );
		$failed    = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::FAILED, true );

		$this->assertTrue( $completed['success'] );
		$this->assertTrue( $completed['changed'] );
		$this->assertFalse( $failed['success'] );
		$this->assertFalse( $failed['changed'] );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $job['status'] );
	}

	public function test_cancelled_is_generic_terminal_status(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Cancelled terminal lifecycle' ) );
		$this->assertIsInt( $job_id );

		$this->assertTrue( $this->db_jobs->start_job( $job_id, JobStatus::PROCESSING ) );

		$cancelled = $this->db_jobs->transition_job_status_result( $job_id, JobStatus::CANCELLED, true );
		$restart   = $this->db_jobs->start_job( $job_id, JobStatus::PROCESSING );

		$this->assertTrue( $cancelled['success'] );
		$this->assertTrue( $cancelled['changed'] );
		$this->assertFalse( $restart );
		$this->assertTrue( JobStatus::isStatusFinal( JobStatus::CANCELLED ) );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::CANCELLED, $job['status'] );
	}

	public function test_exact_legacy_ai_contention_failure_can_be_audited_and_reclassified(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Legacy AI contention' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue(
			datamachine_merge_engine_data(
				$job_id,
				array(
					'run_metrics' => array(
						'terminal_status' => LegacyAIConcurrencyReconciler::SOURCE_STATUS,
						'counts'          => array( 'failed' => 1 ),
					),
				)
			)
		);
		global $wpdb;
		$this->assertSame(
			1,
			$wpdb->update(
				$wpdb->prefix . 'datamachine_jobs',
				array( 'status' => LegacyAIConcurrencyReconciler::SOURCE_STATUS ),
				array( 'job_id' => $job_id ),
				array( '%s' ),
				array( '%d' )
			)
		);

		$generic_rewrite = $this->db_jobs->transition_job_status_result( $job_id, LegacyAIConcurrencyReconciler::TARGET_STATUS, true );
		$this->assertFalse( $generic_rewrite['success'] );

		$audits          = array();
		$terminal_hooks  = array();
		$audit_listener  = static function ( int $reconciled_job_id, array $audit ) use ( &$audits ): void {
			$audits[] = array( $reconciled_job_id, $audit );
		};
		$terminal_listener = static function ( int $completed_job_id, string $status ) use ( &$terminal_hooks ): void {
			$terminal_hooks[] = array( $completed_job_id, $status );
		};
		add_action( 'datamachine_job_status_reconciled', $audit_listener, 10, 2 );
		add_action( 'datamachine_job_complete', $terminal_listener, 10, 2 );
		try {
			$reconciled = ( new LegacyAIConcurrencyReconciler() )->reconcile( $job_id );
		} finally {
			remove_action( 'datamachine_job_status_reconciled', $audit_listener, 10 );
			remove_action( 'datamachine_job_complete', $terminal_listener, 10 );
		}
		$this->assertTrue( $reconciled['success'] );
		$this->assertTrue( $reconciled['changed'] );
		$this->assertSame( LegacyAIConcurrencyReconciler::SOURCE_STATUS, $reconciled['current_status'] );
		$this->assertSame( LegacyAIConcurrencyReconciler::TARGET_STATUS, $reconciled['status'] );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( LegacyAIConcurrencyReconciler::TARGET_STATUS, $job['status'] );
		$this->assertSame( 'datamachine.status_reconciliation.v1', $job['engine_data']['status_reconciliation']['schema'] );
		$this->assertSame( LegacyAIConcurrencyReconciler::SOURCE_STATUS, $job['engine_data']['status_reconciliation']['source_status'] );
		$this->assertSame( LegacyAIConcurrencyReconciler::TARGET_STATUS, $job['engine_data']['status_reconciliation']['target_status'] );
		$this->assertSame( 0, $job['engine_data']['run_metrics']['counts']['failed'] );
		$this->assertCount( 1, $audits );
		$this->assertSame( $job_id, $audits[0][0] );
		$this->assertSame( array(), $terminal_hooks );

		$idempotent = ( new LegacyAIConcurrencyReconciler() )->reconcile( $job_id );
		$this->assertTrue( $idempotent['success'] );
		$this->assertFalse( $idempotent['changed'] );
	}

	public function test_legacy_ai_contention_reconciliation_rejects_every_other_terminal_status(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'Unrelated terminal status' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $this->db_jobs->complete_job( $job_id, JobStatus::failed( 'handler_failure' )->toString() ) );

		$reconciled = ( new LegacyAIConcurrencyReconciler() )->reconcile( $job_id );
		$this->assertFalse( $reconciled['success'] );
		$this->assertFalse( $reconciled['changed'] );

		$job = $this->db_jobs->get_job( $job_id );
		$this->assertSame( JobStatus::FAILED, $job['status'] );
		$this->assertSame( 'handler_failure', $job['engine_data']['job_status_reason'] );
		$this->assertArrayNotHasKey( 'status_reconciliation', $job['engine_data'] );
	}

	public function test_ai_resume_generation_ownership_advances_with_cas(): void {
		$job_id = $this->db_jobs->create_job( array( 'label' => 'AI resume generation ownership' ) );
		$this->assertIsInt( $job_id );

		$generation_one = AIConcurrencyBackpressure::claimNextGeneration( $job_id, 'ai-1', 0, time() );
		$duplicate_one  = AIConcurrencyBackpressure::claimNextGeneration( $job_id, 'ai-1', 0, time() );
		$this->assertTrue( $generation_one['success'] );
		$this->assertTrue( $generation_one['owned'] );
		$this->assertSame( 1, $generation_one['generation'] );
		$this->assertTrue( $duplicate_one['success'] );
		$this->assertFalse( $duplicate_one['owned'] );
		$this->assertSame( 1, $duplicate_one['generation'] );

		$this->assertTrue( AIConcurrencyBackpressure::recordScheduledAction( $job_id, 'ai-1', 1, $generation_one['token'], 101 ) );
		$this->assertTrue( AIConcurrencyBackpressure::beginGeneration( $job_id, 'ai-1', 1, time() ) );
		$this->assertFalse( AIConcurrencyBackpressure::beginGeneration( $job_id, 'ai-1', 1, time() ) );

		$generation_two = AIConcurrencyBackpressure::claimNextGeneration( $job_id, 'ai-1', 1, time() );
		$duplicate_two  = AIConcurrencyBackpressure::claimNextGeneration( $job_id, 'ai-1', 1, time() );
		$this->assertTrue( $generation_two['success'] );
		$this->assertTrue( $generation_two['owned'] );
		$this->assertSame( 2, $generation_two['generation'] );
		$this->assertTrue( $duplicate_two['success'] );
		$this->assertFalse( $duplicate_two['owned'] );
		$this->assertFalse( AIConcurrencyBackpressure::beginGeneration( $job_id, 'ai-1', 1, time() ) );
	}

	public function test_create_or_get_job_returns_existing_job_for_same_idempotency_key(): void {
		$idempotency_key = 'unit-idempotent-job-' . wp_generate_uuid4();

		$first = $this->db_jobs->create_or_get_job(
			array(
				'label'           => 'First idempotent job',
				'idempotency_key' => $idempotency_key,
			)
		);

		$this->assertIsArray( $first );
		$this->assertTrue( $first['created'] );
		$this->assertFalse( $first['already_exists'] );
		$this->assertIsInt( $first['job_id'] );

		$second = $this->db_jobs->create_or_get_job(
			array(
				'label'           => 'Second idempotent job',
				'idempotency_key' => $idempotency_key,
			)
		);

		$this->assertIsArray( $second );
		$this->assertFalse( $second['created'] );
		$this->assertTrue( $second['already_exists'] );
		$this->assertSame( $first['job_id'], $second['job_id'] );
		$this->assertSame( 'First idempotent job', $second['job']['label'] );
		$this->assertSame( $idempotency_key, $second['job']['idempotency_key'] );
	}

	public function test_create_or_get_job_requires_idempotency_key(): void {
		$this->assertFalse( $this->db_jobs->create_or_get_job( array( 'label' => 'Missing idempotency key' ) ) );
	}

	private function expireTerminalAccountingLease( int $job_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->db_jobs->get_table_name(),
			array( 'terminal_accounting_claimed_at' => '2000-01-01 00:00:00' ),
			array( 'job_id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
