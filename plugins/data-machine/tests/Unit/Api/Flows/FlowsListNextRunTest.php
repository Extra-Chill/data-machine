<?php
/**
 * Flows list next_run resolution tests.
 *
 * The CLI/REST list path batch-resolves next_run through
 * FlowFormatter::batch_get_next_run_times() → FlowRoutines::next_runs().
 * Recurring flows are Agents API routines: pending wakes live under the
 * `wp_agent_routine_run_scheduled` hook with `routine_id` args in the
 * `agents-api` group.
 *
 * @package DataMachine\Tests\Unit\Api\Flows
 */

namespace DataMachine\Tests\Unit\Api\Flows;

use DataMachine\Core\Admin\FlowFormatter;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\Scheduling\FlowRoutines;
use WP_UnitTestCase;

class FlowsListNextRunTest extends WP_UnitTestCase {

	private int $pipeline_id;
	/** @var int[] */
	private array $flow_ids = array();

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$pipeline          = wp_get_ability( 'datamachine/create-pipeline' )->execute( array( 'pipeline_name' => 'Flows list next run' ) );
		$this->pipeline_id = (int) $pipeline['pipeline_id'];
	}

	public function tear_down(): void {
		$flows = new Flows();
		foreach ( $this->flow_ids as $flow_id ) {
			FlowRoutines::unschedule( $flow_id );
			$flows->delete_flow( $flow_id );
		}
		( new Pipelines() )->delete_pipeline( $this->pipeline_id );

		parent::tear_down();
	}

	public function test_list_resolves_next_run_from_pending_routine_action(): void {
		$flow_id = $this->create_flow( 'Scheduled flow' );
		$this->installRoutineSchedule( $flow_id );

		$result = wp_get_ability( 'datamachine/get-flows' )->execute(
			array(
				'per_page'    => 0,
				'output_mode' => 'list',
			)
		);

		$this->assertTrue( $result['success'] );
		$flow = $this->findFlow( $result['flows'], $flow_id );
		$this->assertNotNull( $flow, 'Scheduled flow should appear in list output.' );
		$this->assertNotNull( $flow['next_run'], 'next_run must resolve from the pending routine action.' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $flow['next_run'] );
		$this->assertNotSame( 'Never', $flow['next_run_display'] );
	}

	public function test_batch_next_run_times_match_routine_identity(): void {
		$flow_id = $this->create_flow( 'Batch scheduled flow' );
		$this->installRoutineSchedule( $flow_id );

		$next_runs = FlowFormatter::batch_get_next_run_times( array( $flow_id ) );

		$this->assertArrayHasKey( $flow_id, $next_runs );
		$this->assertNotNull( $next_runs[ $flow_id ], 'Batch resolver must match the routine id args.' );
	}

	public function test_list_reports_null_next_run_without_pending_action(): void {
		$flow_id = $this->create_flow( 'Manual flow' );

		$result = wp_get_ability( 'datamachine/get-flows' )->execute(
			array(
				'per_page'    => 0,
				'output_mode' => 'list',
			)
		);

		$this->assertTrue( $result['success'] );
		$flow = $this->findFlow( $result['flows'], $flow_id );
		$this->assertNotNull( $flow, 'Manual flow should appear in list output.' );
		$this->assertNull( $flow['next_run'] );
	}

	private function create_flow( string $name ): int {
		$result  = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id'       => $this->pipeline_id,
				'flow_name'         => $name,
				'scheduling_config' => array( 'interval' => 'manual' ),
			)
		);
		$flow_id = (int) $result['flow_id'];

		$this->deleteRoutineActions( $flow_id );
		$this->flow_ids[] = $flow_id;

		return $flow_id;
	}

	private function installRoutineSchedule( int $flow_id ): void {
		$this->assertTrue( FlowRoutines::sync( $flow_id, array( 'interval' => 'hourly' ), true ) );

		$action_id = $this->findPendingRoutineActionId( $flow_id );
		$this->assertGreaterThan( 0, $action_id, 'Scheduling must leave a pending routine action behind.' );

		// Guard the regression precondition: the stored args carry the
		// logical routine id, not a positional flow id.
		$action = \ActionScheduler_Store::instance()->fetch_action( $action_id );
		$args   = $action->get_args();
		$this->assertSame(
			array( 'routine_id' => FlowRoutines::routine_id( $flow_id ) ),
			$args,
			'Pending routine action must use the routine_id args shape.'
		);
	}

	/**
	 * Pending routine action whose routine_id matches this flow.
	 */
	private function findPendingRoutineActionId( int $flow_id ): int {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => FlowRoutines::ROUTINE_HOOK,
				'group'    => FlowRoutines::ROUTINE_GROUP,
				'status'   => 'pending',
				'per_page' => 100,
			),
			'ids'
		);

		foreach ( is_array( $ids ) ? $ids : array() as $action_id ) {
			try {
				$action = \ActionScheduler_Store::instance()->fetch_action( (int) $action_id );
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
				continue;
			}

			$args = $action->get_args();
			if ( ( $args['routine_id'] ?? '' ) === FlowRoutines::routine_id( $flow_id ) ) {
				return (int) $action_id;
			}
		}

		return 0;
	}

	private function deleteRoutineActions( int $flow_id ): void {
		$store     = \ActionScheduler_Store::instance();
		$action_id = $this->findPendingRoutineActionId( $flow_id );
		while ( $action_id > 0 ) {
			$store->delete_action( $action_id );
			$action_id = $this->findPendingRoutineActionId( $flow_id );
		}
	}

	/**
	 * @param array<int, array> $flows
	 */
	private function findFlow( array $flows, int $flow_id ): ?array {
		foreach ( $flows as $flow ) {
			if ( (int) ( $flow['flow_id'] ?? 0 ) === $flow_id ) {
				return $flow;
			}
		}

		return null;
	}
}
