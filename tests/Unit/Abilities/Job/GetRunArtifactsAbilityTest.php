<?php
/**
 * Public run artifact read contract coverage.
 *
 * @package DataMachine\Tests\Unit\Abilities\Job
 */

namespace DataMachine\Tests\Unit\Abilities\Job;

use DataMachine\Abilities\Job\GetRunArtifactsAbility;
use DataMachine\Core\Database\Jobs\Jobs;
use WP_UnitTestCase;

class GetRunArtifactsAbilityTest extends WP_UnitTestCase {

	private int $owner_id;
	private int $other_user_id;
	private Jobs $jobs;

	public function set_up(): void {
		parent::set_up();

		datamachine_register_capabilities();
		$this->owner_id      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $this->owner_id )->add_cap( 'datamachine_manage_flows' );
		get_user_by( 'id', $this->other_user_id )->add_cap( 'datamachine_manage_flows' );
		$this->jobs = new Jobs();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_contract_is_discoverable_as_a_public_ability(): void {
		new GetRunArtifactsAbility();

		$this->assertTrue( wp_has_ability( 'datamachine/get-run-artifacts' ) );
	}

	public function test_missing_run_has_explicit_error(): void {
		wp_set_current_user( $this->owner_id );
		$result = ( new GetRunArtifactsAbility() )->execute( array( 'job_id' => PHP_INT_MAX ) );

		$this->assertWPError( $result );
		$this->assertSame( 'job_not_found', $result->get_error_code() );
		$this->assertSame( sprintf( 'Job %d was not found.', PHP_INT_MAX ), $result->get_error_message() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertFalse( $result->get_error_data()['retryable'] );
	}

	public function test_unauthorized_run_is_denied_before_artifact_projection(): void {
		$job_id = $this->createJob();
		wp_set_current_user( $this->other_user_id );

		$result = ( new GetRunArtifactsAbility() )->execute( array( 'job_id' => $job_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'job_access_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertFalse( $result->get_error_data()['retryable'] );
	}

	public function test_valid_empty_artifacts_and_absent_policy_are_distinct_from_failure(): void {
		$job_id = $this->createJob();
		wp_set_current_user( $this->owner_id );

		$result = ( new GetRunArtifactsAbility() )->execute( array( 'job_id' => $job_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['schema_version'] );
		$this->assertSame( $job_id, $result['job_id'] );
		$this->assertIsArray( $result['artifacts'] );
		$this->assertSame( array(), $result['artifacts']['daily_memory_artifacts'] );
		$this->assertSame( array(), $result['run_artifact_egress_policy'] );
		$this->assertSame(
			array(
				'source'     => 'none',
				'path'       => '',
				'normalized' => true,
			),
			$result['policy_provenance']
		);
	}

	public function test_populated_artifacts_and_job_policy_are_normalized(): void {
		$job_id = $this->createJob(
			array(
				'completion_assertions_required'  => array( 'tool_names' => array( 'build' ) ),
				'completion_assertions_satisfied' => array( 'tool_names' => array( 'build' ) ),
				'run_artifact_egress_policy'       => array(
					'daily_memory' => array(
						'egress'               => array( 'pr-body', 'invalid-target', 'bundle-file', 'pr-body' ),
						'bundle_relative_path' => '/memory/agent/daily/{yyyy}/{mm}/{dd}.md',
					),
				),
			)
		);
		wp_set_current_user( $this->owner_id );

		$result = ( new GetRunArtifactsAbility() )->execute( array( 'job_id' => $job_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 'build' ), $result['artifacts']['required_tool_names'] );
		$this->assertSame( array( 'build' ), $result['artifacts']['satisfied_tool_names'] );
		$this->assertSame(
			array(
				'daily_memory' => array(
					'egress'               => array( 'bundle-file', 'pr-body' ),
					'bundle_relative_path' => 'memory/agent/daily/{yyyy}/{mm}/{dd}.md',
				),
			),
			$result['run_artifact_egress_policy']
		);
		$this->assertSame( 'job_snapshot', $result['policy_provenance']['source'] );
		$this->assertSame( 'run_artifact_egress_policy', $result['policy_provenance']['path'] );
	}

	public function test_malformed_legacy_policy_normalizes_to_empty_with_provenance(): void {
		$job_id = $this->createJob(
			array(
				'flow' => array(
					'run_artifacts' => 'legacy-invalid-value',
				),
			)
		);
		wp_set_current_user( $this->owner_id );

		$result = ( new GetRunArtifactsAbility() )->execute( array( 'job_id' => $job_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['run_artifact_egress_policy'] );
		$this->assertSame(
			array(
				'source'     => 'flow_snapshot',
				'path'       => 'flow.run_artifacts',
				'normalized' => true,
			),
			$result['policy_provenance']
		);
	}

	public function test_legacy_flow_policy_is_returned_with_provenance(): void {
		$job_id = $this->createJob(
			array(
				'flow' => array(
					'run_artifacts' => array(
						'transcript_summary' => array( 'egress' => array( 'artifact' ) ),
					),
				),
			)
		);
		wp_set_current_user( $this->owner_id );

		$result = ( new GetRunArtifactsAbility() )->execute( array( 'job_id' => $job_id ) );

		$this->assertSame(
			array( 'transcript_summary' => array( 'egress' => array( 'artifact' ) ) ),
			$result['run_artifact_egress_policy']
		);
		$this->assertSame( 'flow_snapshot', $result['policy_provenance']['source'] );
	}

	/**
	 * @param array<string,mixed> $engine_data Engine data to store.
	 */
	private function createJob( array $engine_data = array() ): int {
		$job_id = $this->jobs->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'user_id'     => $this->owner_id,
				'source'      => 'direct',
			)
		);
		$this->assertIsInt( $job_id );
		if ( ! empty( $engine_data ) ) {
			$this->assertTrue( $this->jobs->store_engine_data( $job_id, $engine_data ) );
		}

		return $job_id;
	}
}
