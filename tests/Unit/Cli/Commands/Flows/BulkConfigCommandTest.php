<?php
/**
 * Bulk Config Command Tests
 *
 * Tests the bulk config operations underlying the CLI command,
 * using the ConfigureFlowStepsAbility directly. WP-CLI runtime
 * utilities are not available in PHPUnit.
 *
 * @package DataMachine\Tests\Unit\Cli\Commands\Flows
 */

namespace DataMachine\Tests\Unit\Cli\Commands\Flows;

use DataMachine\Abilities\FlowStep\ConfigureFlowStepsAbility;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use WP_UnitTestCase;

class BulkConfigCommandTest extends WP_UnitTestCase {

	private Pipelines $pipelines;
	private Flows $flows;
	private int $pipeline_id;
	private int $flow_id_1;
	private int $flow_id_2;
	private string $flow_step_id_1;
	private string $flow_step_id_2;

	public function set_up(): void {
		parent::set_up();

		$this->pipelines = new Pipelines();
		$this->flows     = new Flows();

		// Create a pipeline with a fetch step.
		$this->pipeline_id = $this->pipelines->create_pipeline( array(
			'pipeline_name' => 'Bulk Config Test Pipeline',
			'pipeline_config' => array(),
		) );

		$pipeline_step_id = $this->pipeline_id . '_fetch-step';
		$this->pipelines->update_pipeline( $this->pipeline_id, array(
			'pipeline_config' => array(
				$pipeline_step_id => array(
					'step_type' => 'fetch',
				),
			),
		) );

		// Create two flows with a handler configured.
		$this->flow_id_1      = $this->flows->create_flow( array(
			'pipeline_id'      => $this->pipeline_id,
			'flow_name'        => 'Bulk Test Flow 1',
			'flow_config'      => array(),
			'scheduling_config' => array(),
		) );
		$this->flow_step_id_1 = $pipeline_step_id . '_' . $this->flow_id_1;

		$this->flows->update_flow( $this->flow_id_1, array(
			'flow_config' => array(
				$this->flow_step_id_1 => array(
					'flow_step_id'    => $this->flow_step_id_1,
					'step_type'       => 'fetch',
					'pipeline_step_id' => $pipeline_step_id,
					'pipeline_id'     => $this->pipeline_id,
					'flow_id'         => $this->flow_id_1,
					'execution_order' => 0,
					'handler_slugs'   => array( 'rss' ),
					'handler_configs' => array(
						'rss' => array(
							'feed_url'  => 'https://example.com/feed',
							'max_items' => 5,
						),
					),
				),
			),
		) );

		$this->flow_id_2      = $this->flows->create_flow( array(
			'pipeline_id'      => $this->pipeline_id,
			'flow_name'        => 'Bulk Test Flow 2',
			'flow_config'      => array(),
			'scheduling_config' => array(),
		) );
		$this->flow_step_id_2 = $pipeline_step_id . '_' . $this->flow_id_2;

		$this->flows->update_flow( $this->flow_id_2, array(
			'flow_config' => array(
				$this->flow_step_id_2 => array(
					'flow_step_id'    => $this->flow_step_id_2,
					'step_type'       => 'fetch',
					'pipeline_step_id' => $pipeline_step_id,
					'pipeline_id'     => $this->pipeline_id,
					'flow_id'         => $this->flow_id_2,
					'execution_order' => 0,
					'handler_slugs'   => array( 'rss' ),
					'handler_configs' => array(
						'rss' => array(
							'feed_url'  => 'https://example.com/other-feed',
							'max_items' => 3,
						),
					),
				),
			),
		) );
	}

	public function test_bulk_config_command_class_exists(): void {
		$this->assertTrue(
			class_exists( \DataMachine\Cli\Commands\Flows\BulkConfigCommand::class ),
			'BulkConfigCommand class should be autoloadable'
		);
	}

	public function test_pipeline_scope_updates_matching_flows(): void {
		$ability = new ConfigureFlowStepsAbility();
		$result  = $ability->execute( array(
			'pipeline_id'    => $this->pipeline_id,
			'handler_slug'   => 'rss',
			'handler_config' => array( 'max_items' => 10 ),
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['flows_updated'] );
		$this->assertSame( 2, $result['steps_modified'] );

		// Verify both flows got the new max_items.
		$flow_1 = $this->flows->get_flow( $this->flow_id_1 );
		$config_1 = $flow_1['flow_config'][ $this->flow_step_id_1 ]['handler_configs']['rss'] ?? array();
		$this->assertSame( 10, $config_1['max_items'] );

		$flow_2 = $this->flows->get_flow( $this->flow_id_2 );
		$config_2 = $flow_2['flow_config'][ $this->flow_step_id_2 ]['handler_configs']['rss'] ?? array();
		$this->assertSame( 10, $config_2['max_items'] );
	}

	public function test_pipeline_scope_preserves_existing_config(): void {
		$ability = new ConfigureFlowStepsAbility();
		$ability->execute( array(
			'pipeline_id'    => $this->pipeline_id,
			'handler_slug'   => 'rss',
			'handler_config' => array( 'max_items' => 20 ),
		) );

		// feed_url should be preserved (merge, not replace).
		$flow_1  = $this->flows->get_flow( $this->flow_id_1 );
		$config  = $flow_1['flow_config'][ $this->flow_step_id_1 ]['handler_configs']['rss'] ?? array();
		$this->assertSame( 'https://example.com/feed', $config['feed_url'] );
		$this->assertSame( 20, $config['max_items'] );
	}

	public function test_pipeline_scope_skips_non_matching_handlers(): void {
		$ability = new ConfigureFlowStepsAbility();
		$result  = $ability->execute( array(
			'pipeline_id'    => $this->pipeline_id,
			'handler_slug'   => 'ticketmaster',
			'handler_config' => array( 'max_items' => 10 ),
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No matching steps', $result['error'] );
	}

	public function test_global_scope_dry_run(): void {
		$ability = new ConfigureFlowStepsAbility();
		$result  = $ability->execute( array(
			'handler_slug'   => 'rss',
			'global_scope'   => true,
			'handler_config' => array( 'max_items' => 99 ),
			'validate_only'  => true,
		) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'validate_only', $result['mode'] );
		$this->assertCount( 2, $result['would_update'] );

		// Verify nothing was actually changed.
		$flow_1  = $this->flows->get_flow( $this->flow_id_1 );
		$config  = $flow_1['flow_config'][ $this->flow_step_id_1 ]['handler_configs']['rss'] ?? array();
		$this->assertSame( 5, $config['max_items'] );
	}

	public function test_global_scope_executes(): void {
		$ability = new ConfigureFlowStepsAbility();
		$result  = $ability->execute( array(
			'handler_slug'   => 'rss',
			'global_scope'   => true,
			'handler_config' => array( 'max_items' => 7 ),
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['flows_updated'] );

		// Verify both flows updated.
		$flow_1  = $this->flows->get_flow( $this->flow_id_1 );
		$config_1 = $flow_1['flow_config'][ $this->flow_step_id_1 ]['handler_configs']['rss'] ?? array();
		$this->assertSame( 7, $config_1['max_items'] );
	}

	public function test_global_scope_unknown_handler(): void {
		$ability = new ConfigureFlowStepsAbility();
		$result  = $ability->execute( array(
			'handler_slug'   => 'completely_nonexistent_handler',
			'global_scope'   => true,
			'handler_config' => array( 'max_items' => 10 ),
		) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_per_flow_override_in_pipeline_scope(): void {
		$ability = new ConfigureFlowStepsAbility();
		$result  = $ability->execute( array(
			'pipeline_id'    => $this->pipeline_id,
			'handler_slug'   => 'rss',
			'handler_config' => array( 'max_items' => 10 ),
			'flow_configs'   => array(
				array(
					'flow_id'        => $this->flow_id_2,
					'handler_config' => array( 'max_items' => 25 ),
				),
			),
		) );

		$this->assertTrue( $result['success'] );

		// Flow 1 gets the shared config.
		$flow_1  = $this->flows->get_flow( $this->flow_id_1 );
		$config_1 = $flow_1['flow_config'][ $this->flow_step_id_1 ]['handler_configs']['rss'] ?? array();
		$this->assertSame( 10, $config_1['max_items'] );

		// Flow 2 gets the per-flow override (wins over shared).
		$flow_2  = $this->flows->get_flow( $this->flow_id_2 );
		$config_2 = $flow_2['flow_config'][ $this->flow_step_id_2 ]['handler_configs']['rss'] ?? array();
		$this->assertSame( 25, $config_2['max_items'] );
	}

	public function test_step_type_filter(): void {
		$ability = new ConfigureFlowStepsAbility();
		$result  = $ability->execute( array(
			'pipeline_id'    => $this->pipeline_id,
			'handler_slug'   => 'rss',
			'step_type'      => 'ai',
			'handler_config' => array( 'max_items' => 10 ),
		) );

		// No AI steps with rss handler — should find no matches.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No matching steps', $result['error'] );
	}
}
