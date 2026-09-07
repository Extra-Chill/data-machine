<?php
/**
 * ImportExport — pipeline round-trip tests.
 *
 * Covers the two lossy-import fixes from issue #1133:
 *   - Step 1: step_config restoration (system_prompt, provider, model, label, extensions).
 *   - Step 2: flow + handler_slugs / handler_configs restoration.
 *
 * @package DataMachine\Tests\Unit\Engine
 */

namespace DataMachine\Tests\Unit\Engine;

use DataMachine\Abilities\Pipeline\CreatePipelineAbility;
use DataMachine\Abilities\PipelineStepAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Engine\Actions\ImportExport;
use WP_UnitTestCase;

class ImportExportStepConfigTest extends WP_UnitTestCase {

	private ImportExport $import_export;
	private CreatePipelineAbility $create_pipeline_ability;
	private PipelineStepAbilities $step_abilities;
	private Pipelines $db_pipelines;
	private Flows $db_flows;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->import_export      = new ImportExport();
		$this->create_pipeline_ability = new CreatePipelineAbility();
		$this->step_abilities     = new PipelineStepAbilities();
		$this->db_pipelines       = new Pipelines();
		$this->db_flows           = new Flows();
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_import_restores_step_config_from_export(): void {
		// Build a source pipeline with a configured AI step.
		$created            = $this->create_pipeline_ability->execute(
			array( 'pipeline_name' => 'Round Trip Source' )
		);
		$source_pipeline_id = $created['pipeline_id'];

		$add_result = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $source_pipeline_id,
				'step_type'   => 'ai',
			)
		);
		$source_step_id = $add_result['pipeline_step_id'];

		// Overlay system_prompt + arbitrary custom field directly onto pipeline_config so the
		// exporter serializes them. provider/model already live on the step from add-step.
		$pipeline = $this->db_pipelines->get_pipeline( $source_pipeline_id );
		$pipeline_config = $pipeline['pipeline_config'] ?? array();
		$pipeline_config[ $source_step_id ]['system_prompt'] = 'You are a careful summarizer.';
		$pipeline_config[ $source_step_id ]['label']         = 'My AI';
		$pipeline_config[ $source_step_id ]['provider']      = 'openai';
		$pipeline_config[ $source_step_id ]['model']         = 'gpt-5.4';
		$pipeline_config[ $source_step_id ]['custom_field']  = 'passthrough';
		$this->db_pipelines->update_pipeline(
			$source_pipeline_id,
			array( 'pipeline_config' => $pipeline_config )
		);

		// Export.
		$csv = $this->import_export->handle_export( 'pipelines', array( $source_pipeline_id ) );
		$this->assertIsString( $csv );
		$this->assertNotEmpty( $csv );

		// Rename the pipeline in the CSV so re-import creates a distinct pipeline instead of
		// appending to the source (find_pipeline_by_name matches by name).
		$csv_renamed = str_replace( 'Round Trip Source', 'Round Trip Target', $csv );

		// Import.
		$result = $this->import_export->handle_import( 'pipelines', $csv_renamed );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'imported', $result );
		$this->assertCount( 1, $result['imported'] );

		$imported_pipeline_id = $result['imported'][0];
		$this->assertNotSame( $source_pipeline_id, $imported_pipeline_id );

		// The imported pipeline should have exactly one step, with all step_config fields
		// preserved and a freshly-generated pipeline_step_id.
		$steps_result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_id' => $imported_pipeline_id )
		);

		$this->assertTrue( $steps_result['success'] );
		$this->assertCount( 1, $steps_result['steps'] );

		$imported_step = $steps_result['steps'][0];
		$this->assertSame( 'ai', $imported_step['step_type'] );
		$this->assertSame( 'You are a careful summarizer.', $imported_step['system_prompt'] );
		$this->assertSame( 'My AI', $imported_step['label'] );
		$this->assertSame( 'openai', $imported_step['provider'] );
		$this->assertSame( 'gpt-5.4', $imported_step['model'] );
		$this->assertSame( 'passthrough', $imported_step['custom_field'] );

		// Fresh pipeline_step_id scoped to the new pipeline — NOT the source's id.
		$this->assertNotSame( $source_step_id, $imported_step['pipeline_step_id'] );
		$this->assertStringStartsWith( $imported_pipeline_id . '_', $imported_step['pipeline_step_id'] );
	}

	public function test_import_does_not_duplicate_steps_when_flow_rows_present(): void {
		// Hand-craft a CSV that mirrors what the exporter emits for a pipeline with one step
		// and one flow that has a handler configured. Flow rows share step_type/step_config
		// with their parent pipeline row and must NOT trigger duplicate add-step calls.
		$step_config_json = wp_json_encode(
			array(
				'step_type'        => 'fetch',
				'execution_order'  => 0,
				'pipeline_step_id' => '999_legacy-uuid',
				'label'            => 'Fetch',
			)
		);
		$settings_json    = wp_json_encode(
			array(
				'handler_slugs'   => array( 'rss' ),
				'handler_configs' => array( 'rss' => array( 'feed_url' => 'https://example.com/feed' ) ),
			)
		);

		$csv  = "format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings\n";
		$csv .= '1.0,pipeline_step,999,"Flow Row Guard Test",0,fetch,' . $this->csv_field( $step_config_json ) . ',,,' . "\n";
		$csv .= '1.0,flow,999,"Flow Row Guard Test",,,,42,"Default Flow",' . $this->csv_field( wp_json_encode( array( 'scheduling_config' => array( 'interval' => 'manual' ), 'portable_slug' => 'default-flow' ) ) ) . "\n";
		$csv .= '1.0,flow_step,999,"Flow Row Guard Test",0,fetch,' . $this->csv_field( $step_config_json ) . ',42,"Default Flow",' . $this->csv_field( $settings_json ) . "\n";

		$result = $this->import_export->handle_import( 'pipelines', $csv );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['imported'] );

		$steps_result = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_id' => $result['imported'][0] )
		);

		$this->assertTrue( $steps_result['success'] );
		$this->assertCount( 1, $steps_result['steps'], 'Flow rows must not trigger duplicate add-step calls.' );
		$this->assertSame( 'Fetch', $steps_result['steps'][0]['label'] );
	}

	public function test_import_restores_flow_and_handler_config_from_export(): void {
		// Build a source pipeline with a fetch step and a flow configured with an RSS handler.
		$created            = $this->create_pipeline_ability->execute(
			array(
				'pipeline_name' => 'Flow Round Trip Source',
				'flow_config'   => array( 'flow_name' => 'Morning Flow' ),
			)
		);
		$source_pipeline_id = $created['pipeline_id'];
		$source_flow_id     = $created['flow_id'] ?? null;
		$this->assertNotNull( $source_flow_id );

		$add_result     = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $source_pipeline_id,
				'step_type'   => 'fetch',
			)
		);
		$source_step_id = $add_result['pipeline_step_id'];

		// Directly seed the flow_config with handler_slugs + handler_configs for this step.
		$source_flow      = $this->db_flows->get_flow( (int) $source_flow_id );
		$flow_config      = $source_flow['flow_config'] ?? array();
		$source_flow_step = apply_filters( 'datamachine_generate_flow_step_id', '', $source_step_id, (int) $source_flow_id );
		$flow_config[ $source_flow_step ]['handler_slugs']   = array( 'rss' );
		$flow_config[ $source_flow_step ]['handler_configs'] = array(
			'rss' => array(
				'feed_url'  => 'https://example.com/feed',
				'max_items' => 25,
			),
		);
		$flow_config[ $source_flow_step ]['completion_assertions'] = array( 'required_tool_names' => array( 'publish_result' ) );
		$flow_config[ $source_flow_step ]['tool_runtime_rules']    = array( array( 'id' => 'publish-result', 'max_calls' => 1 ) );
		$flow_config[ $source_flow_step ]['enabled']               = false;
		$this->db_flows->update_flow( (int) $source_flow_id, array( 'flow_config' => $flow_config ) );

		// Export.
		$csv = $this->import_export->handle_export( 'pipelines', array( $source_pipeline_id ) );
		$this->assertIsString( $csv );
		$this->assertStringContainsString( 'Morning Flow', $csv );
		$this->assertStringContainsString( 'rss', $csv );

		// Rename to force a distinct target pipeline.
		$csv_renamed = str_replace( 'Flow Round Trip Source', 'Flow Round Trip Target', $csv );

		$result = $this->import_export->handle_import( 'pipelines', $csv_renamed );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['imported'] );

		$imported_pipeline_id = (int) $result['imported'][0];
		$this->assertNotSame( $source_pipeline_id, $imported_pipeline_id );

		// The imported pipeline should have exactly one flow, and it should be named after
		// the exported flow (not the legacy "Default Flow" fallback).
		$imported_flows = $this->db_flows->get_flows_for_pipeline( $imported_pipeline_id );
		$this->assertCount( 1, $imported_flows, 'Import should not leave an orphan Default Flow.' );
		$imported_flow = $imported_flows[0];
		$this->assertSame( 'Morning Flow', $imported_flow['flow_name'] );

		// Imported step id.
		$steps_result     = $this->step_abilities->executeGetPipelineSteps(
			array( 'pipeline_id' => $imported_pipeline_id )
		);
		$imported_step_id = $steps_result['steps'][0]['pipeline_step_id'];

		// Compute the target flow_step_id and verify handler_slugs + handler_configs round-trip.
		$imported_flow_step_id = apply_filters(
			'datamachine_generate_flow_step_id',
			'',
			$imported_step_id,
			(int) $imported_flow['flow_id']
		);
		$this->assertNotEmpty( $imported_flow_step_id );
		$this->assertNotSame( $source_flow_step, $imported_flow_step_id );

		$imported_flow_config = $imported_flow['flow_config'] ?? array();
		$this->assertArrayHasKey( $imported_flow_step_id, $imported_flow_config );
		$imported_step = $imported_flow_config[ $imported_flow_step_id ];

		$this->assertSame( array( 'rss' ), $imported_step['handler_slugs'] );
		$this->assertSame(
			array(
				'feed_url'  => 'https://example.com/feed',
				'max_items' => 25,
			),
			FlowStepConfig::getPrimaryHandlerConfig( $imported_step )
		);
		$this->assertFalse( $imported_step['enabled'] );
		$this->assertSame( array( 'required_tool_names' => array( 'publish_result' ) ), $imported_step['completion_assertions'] );
		$this->assertSame( array( array( 'id' => 'publish-result', 'max_calls' => 1 ) ), $imported_step['tool_runtime_rules'] );
	}

	public function test_round_trip_preserves_handler_free_flows_schedules_and_is_idempotent(): void {
		$created            = $this->create_pipeline_ability->execute(
			array( 'pipeline_name' => 'Lossless Flow Source' )
		);
		$source_pipeline_id = $created['pipeline_id'];
		$add_result         = $this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $source_pipeline_id,
				'step_type'   => 'ai',
			)
		);
		$source_step_id = $add_result['pipeline_step_id'];

		$create_flow = wp_get_ability( 'datamachine/create-flow' );
		$this->assertNotNull( $create_flow );

		$paused_schedule = array(
			'interval'           => 'manual',
			'enabled'            => false,
			'webhook_enabled'    => true,
			'webhook_auth_mode'  => 'hmac',
			'webhook_rate_limit' => array( 'requests' => 12, 'window' => 60 ),
			'run_artifacts'      => array( 'completion_assertions' => array( 'egress' => array( 'artifact' ) ) ),
		);
		$recurring_schedule = array(
			'interval'      => 'qtrdaily',
			'enabled'       => true,
			'run_artifacts' => array( 'completion_assertions' => array( 'egress' => array( 'artifact', 'bundle-file' ) ) ),
		);

		$paused_result = $create_flow->execute(
			array(
				'pipeline_id'       => $source_pipeline_id,
				'flow_name'         => 'Paused Webhook Flow',
				'scheduling_config' => $paused_schedule,
			)
		);
		$recurring_result = $create_flow->execute(
			array(
				'pipeline_id'       => $source_pipeline_id,
				'flow_name'         => 'Recurring Artifact Flow',
				'scheduling_config' => $recurring_schedule,
			)
		);
		$this->assertNotWPError( $paused_result );
		$this->assertNotWPError( $recurring_result );

		$paused_flow_id = (int) $paused_result['flow_id'];
		$this->db_flows->update_flow( $paused_flow_id, array( 'portable_slug' => 'paused-webhook' ) );
		$paused_flow         = $this->db_flows->get_flow( $paused_flow_id );
		$paused_flow_step_id = apply_filters( 'datamachine_generate_flow_step_id', '', $source_step_id, $paused_flow_id );
		$paused_flow['flow_config'][ $paused_flow_step_id ]['completion_assertions'] = array( 'required_tool_names' => array( 'publish_result' ) );
		$paused_flow['flow_config'][ $paused_flow_step_id ]['tool_runtime_rules']    = array( array( 'id' => 'publish-result', 'max_calls' => 1 ) );
		$paused_flow['flow_config'][ $paused_flow_step_id ]['enabled']               = false;
		$this->db_flows->update_flow( $paused_flow_id, array( 'flow_config' => $paused_flow['flow_config'] ) );

		$csv         = $this->import_export->handle_export( 'pipelines', array( $source_pipeline_id ) );
		$csv_renamed = str_replace( 'Lossless Flow Source', 'Lossless Flow Target', $csv );
		$this->assertSame( 2, substr_count( $csv_renamed, ',flow,' ), 'Every flow must have exactly one durable metadata row.' );

		$result = $this->import_export->handle_import( 'pipelines', $csv_renamed );
		$this->assertCount( 1, $result['imported'] );
		$imported_pipeline_id = (int) $result['imported'][0];

		$imported_flows = $this->db_flows->get_flows_for_pipeline( $imported_pipeline_id );
		$this->assertCount( 2, $imported_flows );
		$this->assertSame( array( 'Paused Webhook Flow', 'Recurring Artifact Flow' ), array_column( $imported_flows, 'flow_name' ) );

		$imported_paused = $imported_flows[0];
		$this->assertSame( 'paused-webhook', $imported_paused['portable_slug'] );
		foreach ( $paused_schedule as $key => $value ) {
			$this->assertSame( $value, $imported_paused['scheduling_config'][ $key ] ?? null, "Paused schedule field {$key} must round-trip." );
		}
		foreach ( $recurring_schedule as $key => $value ) {
			$this->assertSame( $value, $imported_flows[1]['scheduling_config'][ $key ] ?? null, "Recurring schedule field {$key} must round-trip." );
		}

		$steps_result     = $this->step_abilities->executeGetPipelineSteps( array( 'pipeline_id' => $imported_pipeline_id ) );
		$imported_step_id = $steps_result['steps'][0]['pipeline_step_id'];
		$imported_flow_step_id = apply_filters( 'datamachine_generate_flow_step_id', '', $imported_step_id, (int) $imported_paused['flow_id'] );
		$imported_step = $imported_paused['flow_config'][ $imported_flow_step_id ];
		$this->assertFalse( $imported_step['enabled'] );
		$this->assertSame( array( 'required_tool_names' => array( 'publish_result' ) ), $imported_step['completion_assertions'] );
		$this->assertSame( array( array( 'id' => 'publish-result', 'max_calls' => 1 ) ), $imported_step['tool_runtime_rules'] );

		$flow_ids_before = array_map( 'intval', array_column( $imported_flows, 'flow_id' ) );
		$second_result   = $this->import_export->handle_import( 'pipelines', $csv_renamed );
		$this->assertSame( array( $imported_pipeline_id ), array_values( $second_result['imported'] ) );
		$flows_after = $this->db_flows->get_flows_for_pipeline( $imported_pipeline_id );
		$this->assertSame( $flow_ids_before, array_map( 'intval', array_column( $flows_after, 'flow_id' ) ) );
		$steps_after = $this->step_abilities->executeGetPipelineSteps( array( 'pipeline_id' => $imported_pipeline_id ) );
		$this->assertCount( 1, $steps_after['steps'], 'Repeated import must not duplicate pipeline steps.' );
	}

	public function test_round_trip_keeps_distinct_zero_step_flows_with_the_same_name(): void {
		$created            = $this->create_pipeline_ability->execute( array( 'pipeline_name' => 'Duplicate Flow Source' ) );
		$source_pipeline_id = (int) $created['pipeline_id'];
		$create_flow        = wp_get_ability( 'datamachine/create-flow' );

		foreach ( array( false, true ) as $enabled ) {
			$result = $create_flow->execute(
				array(
					'pipeline_id'       => $source_pipeline_id,
					'flow_name'         => 'Shared Name',
					'scheduling_config' => array(
						'interval' => 'manual',
						'enabled'  => $enabled,
					),
				)
			);
			$this->assertNotWPError( $result );
		}

		$csv = $this->import_export->handle_export( 'pipelines', array( $source_pipeline_id ) );
		$csv = str_replace( 'Duplicate Flow Source', 'Duplicate Flow Target', $csv );

		$first_import = $this->import_export->handle_import( 'pipelines', $csv );
		$pipeline_id  = (int) $first_import['imported'][0];
		$flows        = $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		$this->assertCount( 2, $flows );
		$this->assertSame( array( 'Shared Name', 'Shared Name' ), array_column( $flows, 'flow_name' ) );
		$this->assertCount( 2, array_unique( array_column( $flows, 'portable_slug' ) ) );
		$this->assertSame( array( false, true ), array_column( array_column( $flows, 'scheduling_config' ), 'enabled' ) );

		$this->import_export->handle_import( 'pipelines', $csv );
		$this->assertCount( 2, $this->db_flows->get_flows_for_pipeline( $pipeline_id ), 'Repeated import must not collapse or duplicate same-name flows.' );
	}

	public function test_import_rejects_malformed_flow_metadata_before_writes(): void {
		$csv  = "format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings\n";
		$csv .= '1.0,flow,77,"Malformed Metadata",,,,42,"Named Flow",{}' . "\n";

		$result = $this->import_export->handle_import( 'pipelines', $csv );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_pipeline_csv', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNull( $this->find_pipeline_id( 'Malformed Metadata' ) );
	}

	private function find_pipeline_id( string $name ): ?int {
		foreach ( $this->db_pipelines->get_all_pipelines() as $pipeline ) {
			if ( $name === $pipeline['pipeline_name'] ) {
				return (int) $pipeline['pipeline_id'];
			}
		}
		return null;
	}

	/**
	 * Mirror the ImportExport::array_to_csv quoting rules for a single field.
	 */
	private function csv_field( string $value ): string {
		if ( false !== strpos( $value, ',' ) || false !== strpos( $value, '"' ) || false !== strpos( $value, "\n" ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}
}
