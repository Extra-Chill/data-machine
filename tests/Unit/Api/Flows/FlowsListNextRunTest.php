<?php
/**
 * Flows list next_run resolution tests (#3462).
 *
 * The CLI/REST list path batch-resolves next_run through
 * FlowFormatter::batch_get_next_run_times(). Pending actions are stored with
 * the generated identity appended by ScheduleActionIdentity::withGeneration(),
 * so the resolver must match through the logical identity instead of exact
 * stored args.
 *
 * @package DataMachine\Tests\Unit\Api\Flows
 */

namespace DataMachine\Tests\Unit\Api\Flows;

use DataMachine\Api\Flows\FlowScheduling;
use DataMachine\Core\Admin\FlowFormatter;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\Tasks\RecurringScheduler;
use DataMachine\Engine\Tasks\ScheduleActionIdentity;
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
			RecurringScheduler::unschedule(
				FlowScheduling::FLOW_HOOK,
				array( $flow_id ),
				RecurringScheduler::GROUP,
				array( 'generation_argument_index' => FlowScheduling::GENERATION_ARGUMENT_INDEX )
			);
			$flows->delete_flow( $flow_id );
		}
		( new Pipelines() )->delete_pipeline( $this->pipeline_id );

		parent::tear_down();
	}

	public function test_list_resolves_next_run_from_generated_action_identity(): void {
		$flow_id = $this->create_flow( 'Scheduled flow' );
		$this->installGeneratedSchedule( $flow_id );

		$result = wp_get_ability( 'datamachine/get-flows' )->execute(
			array(
				'per_page'    => 0,
				'output_mode' => 'list',
			)
		);

		$this->assertTrue( $result['success'] );
		$flow = $this->findFlow( $result['flows'], $flow_id );
		$this->assertNotNull( $flow, 'Scheduled flow should appear in list output.' );
		$this->assertNotNull( $flow['next_run'], 'next_run must resolve from the pending generated action.' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $flow['next_run'] );
		$this->assertNotSame( 'Never', $flow['next_run_display'] );
	}

	public function test_batch_next_run_times_match_generated_identity(): void {
		$flow_id = $this->create_flow( 'Batch scheduled flow' );
		$this->installGeneratedSchedule( $flow_id );

		$next_runs = FlowFormatter::batch_get_next_run_times( array( $flow_id ) );

		$this->assertArrayHasKey( $flow_id, $next_runs );
		$this->assertNotNull( $next_runs[ $flow_id ], 'Batch resolver must match the generated identity args.' );
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

		$this->deleteLogicalActions( $flow_id );
		$this->flow_ids[] = $flow_id;

		return $flow_id;
	}

	private function installGeneratedSchedule( int $flow_id ): void {
		$this->assertTrue( FlowScheduling::handle_scheduling_update( $flow_id, array( 'interval' => 'hourly' ), true ) );

		$action = $this->findPendingAction( $flow_id );
		$this->assertNotNull( $action, 'Scheduling must leave a pending action behind.' );

		// Guard the regression precondition: the stored args carry the
		// generated identity, not the bare logical signature.
		$this->assertNotNull(
			ScheduleActionIdentity::generationFromArgs( $action->get_args() ),
			'Pending action must use the generated identity shape.'
		);
		$this->assertSame( array( $flow_id ), ScheduleActionIdentity::logicalArgs( $action->get_args() ) );
	}

	/**
	 * Pending actions whose first arg is this flow ID, keyed by action ID.
	 *
	 * @return array<int, object>
	 */
	private function pendingActionsForFlow( int $flow_id ): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => FlowScheduling::FLOW_HOOK,
				'group'    => RecurringScheduler::GROUP,
				'status'   => 'pending',
				'per_page' => 100,
			),
			'OBJECT'
		);

		return array_filter(
			is_array( $actions ) ? $actions : array(),
			static fn( $action ): bool => is_object( $action )
				&& method_exists( $action, 'get_args' )
				&& (int) ( $action->get_args()[0] ?? 0 ) === $flow_id
		);
	}

	private function findPendingAction( int $flow_id ): ?object {
		$actions = $this->pendingActionsForFlow( $flow_id );
		$action  = reset( $actions );
		return false === $action ? null : $action;
	}

	private function deleteLogicalActions( int $flow_id ): void {
		$store = \ActionScheduler_Store::instance();
		foreach ( array_keys( $this->pendingActionsForFlow( $flow_id ) ) as $action_id ) {
			$store->delete_action( (int) $action_id );
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
