<?php
/**
 * Curated chat scheduling delegation tests.
 *
 * @package DataMachine\Tests\Unit\Api\Chat\Tools
 */

namespace DataMachine\Tests\Unit\Api\Chat\Tools;

use DataMachine\Api\Chat\Tools\CreateFlow;
use DataMachine\Api\Chat\Tools\CreatePipeline;
use DataMachine\Api\Chat\Tools\UpdateFlow;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use WP_UnitTestCase;

class SchedulingDelegationTest extends WP_UnitTestCase {

	private int $pipeline_id;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->pipeline_id = (int) ( new Pipelines() )->create_pipeline(
			array(
				'pipeline_name'   => 'Chat scheduling delegation',
				'pipeline_config' => array(),
			)
		);
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_create_flow_resolves_every_six_hours_through_ability(): void {
		$result = ( new CreateFlow() )->handle_tool_call(
			array(
				'pipeline_id'       => $this->pipeline_id,
				'flow_name'         => 'Six hour flow',
				'scheduling_config' => array( 'interval' => 'every_6_hours' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'qtrdaily', $result['data']['scheduling'] );
		$flow = ( new Flows() )->get_flow( (int) $result['data']['flow_id'] );
		$this->assertSame( 'qtrdaily', $flow['scheduling_config']['interval'] );
	}

	public function test_update_flow_resolves_every_twelve_hours_through_ability(): void {
		$created = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id' => $this->pipeline_id,
				'flow_name'   => 'Twelve hour flow',
			)
		);

		$result = ( new UpdateFlow() )->handle_tool_call(
			array(
				'flow_id'           => $created['flow_id'],
				'scheduling_config' => array( 'interval' => 'every_12_hours' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'twicedaily', $result['data']['scheduling'] );
		$this->assertSame( 'twicedaily', $result['data']['scheduling_config']['interval'] );
	}

	/**
	 * @dataProvider canonicalScheduleProvider
	 */
	public function test_create_flow_accepts_canonical_schedule_forms( array $scheduling_config, string $expected ): void {
		$result = ( new CreateFlow() )->handle_tool_call(
			array(
				'pipeline_id'       => $this->pipeline_id,
				'flow_name'         => 'Canonical schedule ' . $expected,
				'scheduling_config' => $scheduling_config,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( $expected, $result['data']['scheduling'] );
	}

	public static function canonicalScheduleProvider(): array {
		return array(
			'explicit cron'   => array(
				array(
					'interval'        => 'cron',
					'cron_expression' => '0 9 * * 1-5',
				),
				'cron',
			),
			'cron expression' => array( array( 'interval' => '0 9 * * 1-5' ), 'cron' ),
		);
	}

	public function test_invalid_and_incomplete_schedules_remain_tool_failures(): void {
		$invalid = ( new CreateFlow() )->handle_tool_call(
			array(
				'pipeline_id'       => $this->pipeline_id,
				'flow_name'         => 'Invalid interval flow',
				'scheduling_config' => array( 'interval' => 'sometimes' ),
			)
		);
		$one_time = ( new CreateFlow() )->handle_tool_call(
			array(
				'pipeline_id'       => $this->pipeline_id,
				'flow_name'         => 'Incomplete one-time flow',
				'scheduling_config' => array( 'interval' => 'one_time' ),
			)
		);

		$this->assertFalse( $invalid['success'] );
		$this->assertStringContainsString( 'Invalid interval', $invalid['error'] );
		$this->assertSame( 'create_flow', $invalid['tool_name'] );
		$this->assertFalse( $one_time['success'] );
		$this->assertSame( 'Timestamp required for one-time scheduling', $one_time['error'] );
		$this->assertSame( 'create_flow', $one_time['tool_name'] );
	}

	public function test_bulk_flow_validate_only_uses_canonical_resolution_without_writes(): void {
		$before = ( new Flows() )->count_flows_for_pipeline( $this->pipeline_id );
		$result = ( new CreateFlow() )->handle_tool_call(
			array(
				'flows'         => array(
					array(
						'pipeline_id'       => $this->pipeline_id,
						'flow_name'         => 'Bulk validation flow',
						'scheduling_config' => array( 'interval' => 'every_6_hours' ),
					),
				),
				'validate_only' => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'validate_only', $result['data']['mode'] );
		$this->assertSame( 'qtrdaily', $result['data']['would_create'][0]['scheduling'] );
		$this->assertSame( $before, ( new Flows() )->count_flows_for_pipeline( $this->pipeline_id ) );
	}

	public function test_create_pipeline_preserves_shorthand_and_resolves_seeded_flow_schedule(): void {
		$result = ( new CreatePipeline() )->handle_tool_call(
			array(
				'pipeline_name'     => 'Scheduled shorthand pipeline',
				'steps'             => array( 'ai' ),
				'scheduling_config' => array( 'interval' => 'every_6_hours' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['data']['steps_created'] );
		$this->assertSame( 'qtrdaily', $result['data']['scheduling'] );
		$flow = ( new Flows() )->get_flow( (int) $result['data']['flow_id'] );
		$this->assertSame( 'qtrdaily', $flow['scheduling_config']['interval'] );
	}

	public function test_pipeline_validate_only_and_invalid_preflight_use_canonical_contract(): void {
		$pipelines = new Pipelines();
		$before    = $pipelines->get_pipelines_count();
		$preview   = ( new CreatePipeline() )->handle_tool_call(
			array(
				'pipelines'     => array(
					array(
						'name'              => 'Preview pipeline',
						'scheduling_config' => array( 'interval' => 'every_12_hours' ),
					),
				),
				'validate_only' => true,
			)
		);
		$invalid   = ( new CreatePipeline() )->handle_tool_call(
			array(
				'pipeline_name'     => 'Invalid seeded pipeline',
				'scheduling_config' => array( 'interval' => 'one_time' ),
			)
		);

		$this->assertTrue( $preview['success'] );
		$this->assertSame( 'twicedaily', $preview['data']['would_create'][0]['scheduling'] );
		$this->assertFalse( $invalid['success'] );
		$this->assertSame( 'Timestamp required for one-time scheduling', $invalid['error'] );
		$this->assertSame( $before, $pipelines->get_pipelines_count() );
	}

	public function test_curated_tools_have_no_copied_interval_registry(): void {
		foreach ( array( CreateFlow::class, UpdateFlow::class, CreatePipeline::class ) as $class ) {
			$source = file_get_contents( ( new \ReflectionClass( $class ) )->getFileName() );
			$this->assertStringNotContainsString( 'datamachine_scheduler_intervals', $source );
			$this->assertStringNotContainsString( 'valid_intervals', $source );
			$this->assertStringNotContainsString( 'validateSchedulingConfig', $source );
		}
	}
}
