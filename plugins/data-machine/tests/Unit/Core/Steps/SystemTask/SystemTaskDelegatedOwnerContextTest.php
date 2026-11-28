<?php
/**
 * Agentless delegated SystemTask owner-context integration coverage.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\SystemTask
 */

namespace DataMachine\Tests\Unit\Core\Steps\SystemTask;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\SystemTask\SystemTaskStep;
use DataMachine\Core\Steps\WorkflowConfigFactory;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Tasks\TaskRegistry;
use WP_UnitTestCase;

final class DelegatedOwnerContextTask extends SystemTask {

	public static array $observations = array();
	public static bool $requires_agent_context = true;
	public static $during_execution = null;

	public function getTaskType(): string {
		return 'delegated_owner_context_fixture';
	}

	public function requiresAgentContext(): bool {
		return self::$requires_agent_context;
	}

	public function executeTask( int $jobId, array $params ): void {
		$label = (string) ( $params['label'] ?? 'task' );
		$this->observe( $label . ':before' );

		if ( is_callable( self::$during_execution ) ) {
			( self::$during_execution )();
			$this->observe( $label . ':after' );
		}

		if ( ! empty( $params['switch_blog_id'] ) ) {
			switch_to_blog( (int) $params['switch_blog_id'] );
			$this->observe( $label . ':switched' );
		}

		if ( ! empty( $params['wp_error'] ) ) {
			self::$observations[] = new \WP_Error( 'fixture_effect_error', 'Fixture effect failed.' );
		}

		if ( ! empty( $params['throw'] ) ) {
			throw new \RuntimeException( 'Fixture task exception.' );
		}

		$this->completeJob( $jobId, array( 'fixture_complete' => true ) );
	}

	private function observe( string $label ): void {
		self::$observations[] = array(
			'label'            => $label,
			'user_id'          => get_current_user_id(),
			'blog_id'          => get_current_blog_id(),
			'can_manage'       => current_user_can( 'manage_options' ),
			'in_agent_context' => PermissionHelper::in_agent_context(),
			'agent_id'         => PermissionHelper::get_acting_agent_id(),
		);
	}
}

final class SystemTaskDelegatedOwnerContextTest extends WP_UnitTestCase {
	private ?EngineData $last_engine = null;
	private int $last_parent_id = 0;
	private string $last_step_id = '';

	public function set_up(): void {
		parent::set_up();
		Jobs::create_table();
		DelegatedOwnerContextTask::$observations           = array();
		DelegatedOwnerContextTask::$requires_agent_context = true;
		DelegatedOwnerContextTask::$during_execution       = null;
		add_filter( 'datamachine_tasks', array( $this, 'registerFixtureTask' ) );
		TaskRegistry::reset();
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_tasks', array( $this, 'registerFixtureTask' ) );
		TaskRegistry::reset();
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function registerFixtureTask( array $tasks ): array {
		$tasks['delegated_owner_context_fixture'] = DelegatedOwnerContextTask::class;
		return $tasks;
	}

	public function test_valid_frozen_owner_executes_with_wordpress_capabilities_and_restores_ambient_user(): void {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$actor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$stale_agent = ( new Agents() )->create_if_missing( 'stale-delegated-owner-context-agent', 'Stale Delegated Owner Context Agent', $actor );
		wp_set_current_user( $actor );

		$this->runTask( $owner, array( 'label' => 'valid', 'owner_user_id' => $actor ), $actor, true, 0, null, $stale_agent );

		$this->assertSame( $owner, DelegatedOwnerContextTask::$observations[0]['user_id'] );
		$this->assertTrue( DelegatedOwnerContextTask::$observations[0]['can_manage'] );
		$this->assertFalse( DelegatedOwnerContextTask::$observations[0]['in_agent_context'] );
		$this->assertSame( $actor, get_current_user_id() );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	public function test_missing_and_deleted_frozen_owners_fail_closed_before_effects(): void {
		$deleted_owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_delete_user( $deleted_owner );
		$failures = array();
		$capture  = static function ( $job_id, $code ) use ( &$failures ): void {
			unset( $job_id );
			$failures[] = $code;
		};
		add_action( 'datamachine_fail_job', $capture, 1, 2 );

		$this->runTask( null, array( 'label' => 'missing' ) );
		$this->runTask( $deleted_owner, array( 'label' => 'deleted' ) );
		remove_action( 'datamachine_fail_job', $capture, 1 );

		$this->assertSame( array(), DelegatedOwnerContextTask::$observations );
		$this->assertSame(
			array( 'system_task_delegated_owner_invalid', 'system_task_delegated_owner_invalid' ),
			$failures
		);
	}

	public function test_nested_owner_context_restores_outer_owner_then_ambient_user(): void {
		$outer_owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$inner_owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		$ambient     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $ambient );

		DelegatedOwnerContextTask::$during_execution = function () use ( $inner_owner ): void {
			$callback = DelegatedOwnerContextTask::$during_execution;
			DelegatedOwnerContextTask::$during_execution = null;
			$this->runTask( $inner_owner, array( 'label' => 'inner' ) );
			DelegatedOwnerContextTask::$during_execution = $callback;
		};
		$this->runTask( $outer_owner, array( 'label' => 'outer' ) );

		$this->assertSame(
			array( $outer_owner, $inner_owner, $outer_owner ),
			array_column( DelegatedOwnerContextTask::$observations, 'user_id' )
		);
		$this->assertSame( array( 'outer:before', 'inner:before', 'outer:after' ), array_column( DelegatedOwnerContextTask::$observations, 'label' ) );
		$this->assertSame( $ambient, get_current_user_id() );
	}

	public function test_exception_and_wp_error_paths_restore_ambient_user(): void {
		$owner   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$ambient = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $ambient );

		$this->runTask( $owner, array( 'label' => 'wp-error', 'wp_error' => true ) );
		$this->assertInstanceOf( \WP_Error::class, DelegatedOwnerContextTask::$observations[1] );
		$this->assertSame( $ambient, get_current_user_id() );

		$this->runTask( $owner, array( 'label' => 'exception', 'throw' => true ) );
		$this->assertSame( $owner, DelegatedOwnerContextTask::$observations[2]['user_id'] );
		$this->assertSame( $ambient, get_current_user_id() );
	}

	public function test_retry_resume_reestablishes_frozen_owner_without_leaking_between_jobs(): void {
		$first_owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$second_owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		$first_actor  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$second_actor = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $first_actor );
		$this->runTask( $first_owner, array( 'label' => 'attempt-1' ) );
		$this->assertSame( $first_actor, get_current_user_id() );

		wp_set_current_user( $second_actor );
		$this->resumeLastTask();
		$this->runTask( $second_owner, array( 'label' => 'next-job' ) );

		$this->assertSame(
			array( $first_owner, $first_owner, $second_owner ),
			array_column( DelegatedOwnerContextTask::$observations, 'user_id' )
		);
		$this->assertSame( $second_actor, get_current_user_id() );
	}

	public function test_cross_site_exception_restores_blog_user_and_capability_state(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite test suite is not active.' );
		}

		$main_blog_id = get_current_blog_id();
		$other_blog   = self::factory()->blog->create();
		$owner        = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ambient      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( $other_blog, $owner, 'administrator' );
		switch_to_blog( $other_blog );
		try {
			Jobs::create_table();
			wp_set_current_user( $ambient );
			$this->runTask(
				$owner,
				array(
					'label'          => 'cross-site',
					'switch_blog_id' => $main_blog_id,
					'throw'          => true,
				)
			);

			$this->assertSame( $other_blog, DelegatedOwnerContextTask::$observations[0]['blog_id'] );
			$this->assertTrue( DelegatedOwnerContextTask::$observations[0]['can_manage'] );
			$this->assertSame( $main_blog_id, DelegatedOwnerContextTask::$observations[1]['blog_id'] );
			$this->assertFalse( DelegatedOwnerContextTask::$observations[1]['can_manage'] );
			$this->assertSame( $other_blog, get_current_blog_id() );
			$this->assertSame( $ambient, get_current_user_id() );
			$this->assertFalse( current_user_can( 'manage_options' ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_ordinary_and_agent_backed_tasks_keep_existing_context_rules(): void {
		$ambient = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$spoofed = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $ambient );
		DelegatedOwnerContextTask::$requires_agent_context = false;
		$this->runTask( null, array( 'label' => 'ordinary' ), null, false, 0, $spoofed );
		$this->assertSame( $ambient, DelegatedOwnerContextTask::$observations[0]['user_id'] );
		$this->assertFalse( DelegatedOwnerContextTask::$observations[0]['in_agent_context'] );

		$owner    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$agent_id = ( new Agents() )->create_if_missing( 'delegated-owner-context-agent', 'Delegated Owner Context Agent', $owner );
		DelegatedOwnerContextTask::$requires_agent_context = true;
		$this->runTask( $owner, array( 'label' => 'agent' ), null, true, $agent_id );

		$this->assertSame( $owner, DelegatedOwnerContextTask::$observations[1]['user_id'] );
		$this->assertTrue( DelegatedOwnerContextTask::$observations[1]['in_agent_context'] );
		$this->assertSame( $agent_id, DelegatedOwnerContextTask::$observations[1]['agent_id'] );
		$this->assertSame( $ambient, get_current_user_id() );
		$this->assertFalse( PermissionHelper::in_agent_context() );
	}

	private function runTask( ?int $owner_id, array $params, ?int $job_user_id = null, bool $delegated = true, int $agent_id = 0, ?int $engine_owner_override = null, int $engine_agent_override = 0 ): array {
		$workflow  = ( new DelegatedOwnerContextTask() )->getWorkflow( $params );
		$configs   = WorkflowConfigFactory::buildEphemeralConfigs( $workflow );
		$step_id   = (string) array_key_first( $configs['flow_config'] );
		$jobs      = new Jobs();
		$operation = array(
			'action'          => 'fixture/effect',
			'initiator'       => array( 'user_id' => 999999 ),
			'execution_owner' => array(
				'user_id'  => $owner_id,
				'agent_id' => $agent_id,
			),
		);
		$create_args = array(
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => $delegated ? 'delegated' : 'test',
			'label'       => 'Delegated owner context fixture',
			'user_id'     => $job_user_id ?? ( $owner_id ?? 0 ),
		);
		if ( $delegated ) {
			$create_args['operation_envelope'] = array( 'delegated_operation' => $operation );
		}
		$parent_id = (int) $jobs->create_job( $create_args );

		$job = array(
			'job_id'      => $parent_id,
			'user_id'     => $job_user_id ?? ( $owner_id ?? 0 ),
			'flow_id'     => 'direct',
			'pipeline_id' => 'direct',
		);
		$engine_agent_id = $engine_agent_override > 0 ? $engine_agent_override : $agent_id;
		if ( $engine_agent_id > 0 ) {
			$job['agent_id'] = $engine_agent_id;
		}

		$engine_data = array(
			'flow_config'     => $configs['flow_config'],
			'pipeline_config' => $configs['pipeline_config'],
			'job'             => $job,
		);
		if ( $delegated ) {
			$engine_data['delegated_operation'] = $operation;
		} elseif ( null !== $engine_owner_override ) {
			$engine_data['delegated_operation'] = array(
				'action'          => 'fixture/spoofed-effect',
				'initiator'       => array( 'user_id' => $engine_owner_override ),
				'execution_owner' => array( 'user_id' => $engine_owner_override ),
			);
		}
		$this->last_parent_id = $parent_id;
		$this->last_step_id   = $step_id;
		$this->last_engine    = new EngineData( $engine_data, $parent_id );

		return ( new SystemTaskStep() )->execute(
			array(
				'job_id'       => $parent_id,
				'flow_step_id' => $step_id,
				'data'         => array(),
				'engine'       => $this->last_engine,
			)
		);
	}

	private function resumeLastTask(): array {
		$this->assertNotNull( $this->last_engine );

		return ( new SystemTaskStep() )->execute(
			array(
				'job_id'       => $this->last_parent_id,
				'flow_step_id' => $this->last_step_id,
				'data'         => array(),
				'engine'       => $this->last_engine,
			)
		);
	}
}
