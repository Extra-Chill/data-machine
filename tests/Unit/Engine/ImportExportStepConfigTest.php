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

use DataMachine\Abilities\PipelineAbilities;
use DataMachine\Abilities\PipelineStepAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\Actions\ImportExport;
use WP_UnitTestCase;

class ImportExportStepConfigTest extends WP_UnitTestCase {

	private ImportExport $import_export;
	private PipelineAbilities $pipeline_abilities;
	private PipelineStepAbilities $step_abilities;
	private Pipelines $db_pipelines;
	private Flows $db_flows;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->import_export      = new ImportExport();
		$this->pipeline_abilities = new PipelineAbilities();
		$this->step_abilities     = new PipelineStepAbilities();
		$this->db_pipelines       = new Pipelines();
		$this->db_flows           = new Flows();
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_import_restores_step_config_from_export(): void {
		// Build a source pipeline with a configured AI step.
		$created            = $this->pipeline_abilities->executeCreatePipeline(
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

		$csv  = "pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,handler,settings\n";
		$csv .= '999,"Flow Row Guard Test",0,fetch,' . $this->csv_field( $step_config_json ) . ',,,,' . "\n";
		$csv .= '999,"Flow Row Guard Test",0,fetch,' . $this->csv_field( $step_config_json ) . ',42,"Default Flow",rss,' . $this->csv_field( $settings_json ) . "\n";

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
		$created            = $this->pipeline_abilities->executeCreatePipeline(
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
				'feed_url' => 'https://example.com/feed',
				'max_items' => 25,
			),
		);
		$flow_config[ $source_flow_step ]['enabled'] = true;
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
			$imported_step['handler_configs']['rss']
		);
		$this->assertTrue( $imported_step['enabled'] );
	}

	public function test_import_without_flow_rows_creates_default_flow_fallback(): void {
		// Pipeline with only an AI step and no handler-bearing flow — export emits no flow
		// rows, so import should still leave the pipeline with at least one flow.
		$created            = $this->pipeline_abilities->executeCreatePipeline(
			array( 'pipeline_name' => 'Empty Flow Source' )
		);
		$source_pipeline_id = $created['pipeline_id'];
		$this->step_abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $source_pipeline_id,
				'step_type'   => 'ai',
			)
		);

		// Delete any auto-created flows on the source so the export has no flow rows.
		foreach ( $this->db_flows->get_flows_for_pipeline( $source_pipeline_id ) as $flow ) {
			$this->db_flows->delete_flow( (int) $flow['flow_id'] );
		}

		$csv         = $this->import_export->handle_export( 'pipelines', array( $source_pipeline_id ) );
		$csv_renamed = str_replace( 'Empty Flow Source', 'Empty Flow Target', $csv );

		$result = $this->import_export->handle_import( 'pipelines', $csv_renamed );
		$this->assertCount( 1, $result['imported'] );

		$imported_flows = $this->db_flows->get_flows_for_pipeline( (int) $result['imported'][0] );
		$this->assertCount( 1, $imported_flows, 'Exports with no flow rows should still produce one default flow on import.' );
		$this->assertSame( 'Default Flow', $imported_flows[0]['flow_name'] );
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
