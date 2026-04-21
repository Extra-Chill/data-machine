<?php
/**
 * ImportExport — step_config round-trip tests.
 *
 * Verifies that `datamachine/import-pipelines` honors the step_config column
 * emitted by `datamachine/export-pipelines` (issue #1133 step 1). Flow and
 * handler_config restoration is explicitly out of scope.
 *
 * @package DataMachine\Tests\Unit\Engine
 */

namespace DataMachine\Tests\Unit\Engine;

use DataMachine\Abilities\PipelineAbilities;
use DataMachine\Abilities\PipelineStepAbilities;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\Actions\ImportExport;
use WP_UnitTestCase;

class ImportExportStepConfigTest extends WP_UnitTestCase {

	private ImportExport $import_export;
	private PipelineAbilities $pipeline_abilities;
	private PipelineStepAbilities $step_abilities;
	private Pipelines $db_pipelines;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->import_export      = new ImportExport();
		$this->pipeline_abilities = new PipelineAbilities();
		$this->step_abilities     = new PipelineStepAbilities();
		$this->db_pipelines       = new Pipelines();
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
		// and one flow that has a handler configured. Today the flow-row branch of the CSV is
		// deferred (step 2), but we still must not create duplicate steps from those rows.
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
