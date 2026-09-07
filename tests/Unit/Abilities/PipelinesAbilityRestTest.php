<?php
/**
 * Pipelines ability REST-runner tests.
 *
 * Covers the ability routes the Pipeline Builder admin consumes after the
 * datamachine/v1 wrapper routes were deleted for #3456: pipeline CRUD,
 * CSV export, and pipeline memory files.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Pipeline\CreatePipelineAbility;
use DataMachine\Abilities\Pipeline\ImportExportAbility;
use DataMachine\Abilities\PipelineStepAbilities;
use DataMachine\Core\Database\Pipelines\Pipelines as PipelineDatabase;
use WP_REST_Request;
use WP_UnitTestCase;

class PipelinesAbilityRestTest extends WP_UnitTestCase {

	private const CSV_HEADER = 'format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings';

	private function act_as_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	private function run_ability( string $slug, array $input ): array {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/' . $slug . '/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'Ability run failed: ' . wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	public function test_rest_visible_get_pipelines_lists_without_wrapper_route(): void {
		$this->act_as_admin();

		$this->run_ability( 'create-pipeline', array( 'pipeline_name' => 'Ability List Pipeline' ) );
		$result = $this->run_ability(
			'get-pipelines',
			array(
				'output_mode'   => 'list',
				'include_flows' => false,
				'per_page'      => 100,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertContains( 'Ability List Pipeline', array_column( $result['pipelines'], 'pipeline_name' ) );
	}

	public function test_rest_visible_create_and_update_pipeline_round_trip(): void {
		$this->act_as_admin();

		$created = $this->run_ability( 'create-pipeline', array( 'pipeline_name' => 'Ability Round Trip' ) );
		$this->assertNotEmpty( $created['pipeline_id'] );

		$updated = $this->run_ability(
			'update-pipeline',
			array(
				'pipeline_id'   => (int) $created['pipeline_id'],
				'pipeline_name' => 'Ability Round Trip Renamed',
			)
		);

		$this->assertTrue( $updated['success'] );
		$this->assertSame( 'Ability Round Trip Renamed', $updated['pipeline_name'] );
	}

	public function test_rest_visible_delete_pipeline_removes_pipeline(): void {
		$this->act_as_admin();

		$created = $this->run_ability( 'create-pipeline', array( 'pipeline_name' => 'Ability Delete Me' ) );
		$deleted = $this->run_ability( 'delete-pipeline', array( 'pipeline_id' => (int) $created['pipeline_id'] ) );

		$this->assertTrue( $deleted['success'] );
		$this->assertNull( ( new PipelineDatabase() )->get_pipeline( (int) $created['pipeline_id'] ) );
	}

	public function test_rest_visible_export_ability_returns_csv_content(): void {
		$this->act_as_admin();

		$created = $this->run_ability( 'create-pipeline', array( 'pipeline_name' => 'Ability Export Me' ) );
		$result  = $this->run_ability( 'export-pipelines', array( 'pipeline_ids' => array( (int) $created['pipeline_id'] ) ) );

		$this->assertTrue( $result['success'] );
		$this->assertIsString( $result['data'] );
		$this->assertStringContainsString( 'Ability Export Me', $result['data'] );
	}

	public function test_rest_visible_memory_files_abilities_round_trip(): void {
		$this->act_as_admin();

		$created = $this->run_ability( 'create-pipeline', array( 'pipeline_name' => 'Ability Memory Files' ) );
		$this->assertNotEmpty( $created['pipeline_id'] );
		$pipeline_id = (int) $created['pipeline_id'];

		$empty = $this->run_ability( 'get-pipeline-memory-files', array( 'pipeline_id' => $pipeline_id ) );
		$this->assertSame( array(), $empty['memory_files'] );

		$updated = $this->run_ability(
			'update-pipeline-memory-files',
			array(
				'pipeline_id'  => $pipeline_id,
				'memory_files' => array( 'agent-memory/notes.md', 'agent-memory/style.md' ),
			)
		);

		$this->assertTrue( $updated['success'] );
		$this->assertSame( array( 'agent-memory/notes.md', 'agent-memory/style.md' ), $updated['memory_files'] );
		$this->assertSame(
			array( 'agent-memory/notes.md', 'agent-memory/style.md' ),
			$this->run_ability( 'get-pipeline-memory-files', array( 'pipeline_id' => $pipeline_id ) )['memory_files']
		);
	}

	public function test_rest_visible_memory_files_update_sanitizes_filenames(): void {
		$this->act_as_admin();

		$created = $this->run_ability( 'create-pipeline', array( 'pipeline_name' => 'Ability Memory Sanitize' ) );
		$pipeline_id = (int) $created['pipeline_id'];

		$updated = $this->run_ability(
			'update-pipeline-memory-files',
			array(
				'pipeline_id'  => $pipeline_id,
				'memory_files' => array( '../evil.md', '', 'kept.md' ),
			)
		);

		$this->assertTrue( $updated['success'] );
		$this->assertSame( array( 'evil.md', 'kept.md' ), $updated['memory_files'] );
	}

	public function test_rest_visible_memory_files_update_rejects_missing_pipeline(): void {
		$this->act_as_admin();

		$request  = $this->memory_files_request( 'update-pipeline-memory-files', 999999, array( 'notes.md' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_rest_visible_import_ability_imports_canonical_csv(): void {
		$this->act_as_admin();
		$csv = self::CSV_HEADER . "\n1.0,pipeline,3255,REST Ability Import,,,,,,\n";

		$result = $this->run_ability(
			'import-pipelines',
			array(
				'format' => 'csv',
				'data'   => $csv,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertCount( 1, $result['imported'] );
		$this->assertSame( 'REST Ability Import', ( new PipelineDatabase() )->get_pipeline( $result['imported'][0] )['pipeline_name'] );
	}

	public function test_rest_visible_import_ability_rejects_malformed_csv_before_writes(): void {
		$this->act_as_admin();
		$csv = self::CSV_HEADER . "\n1.0,pipeline,3255,Must Not Persist,,,,,,\n1.0,pipeline,broken\n";

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/import-pipelines/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => array( 'data' => $csv, 'format' => 'csv' ) ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_pipeline_csv', $response->get_data()['code'] );
		$this->assertNotContains( 'Must Not Persist', array_column( ( new PipelineDatabase() )->get_all_pipelines(), 'pipeline_name' ) );
	}

	public function test_import_ability_keeps_operational_failures_as_server_errors(): void {
		wp_set_current_user( 0 );
		$csv    = self::CSV_HEADER . "\n1.0,pipeline,3255,Unauthorized Import,,,,,,\n";
		$result = ( new ImportExportAbility() )->executeImport( array( 'data' => $csv ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pipeline_import_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_export_ability_excludes_handler_credentials(): void {
		$this->act_as_admin();

		$created = ( new CreatePipelineAbility() )->execute(
			array(
				'pipeline_name' => 'Secure Public Export',
				'flow_config'   => array( 'flow_name' => 'Default Flow' ),
			)
		);
		$this->assertIsArray( $created );

		$pipeline_id = (int) $created['pipeline_id'];
		$flow_id     = (int) $created['flow_id'];
		$added       = ( new PipelineStepAbilities() )->executeAddPipelineStep(
			array(
				'pipeline_id' => $pipeline_id,
				'step_type'   => 'fetch',
			)
		);
		$this->assertIsArray( $added );

		$flow_step_id = apply_filters( 'datamachine_generate_flow_step_id', '', $added['pipeline_step_id'], $flow_id );
		$db_flows     = new \DataMachine\Core\Database\Flows\Flows();
		$flow         = $db_flows->get_flow( $flow_id );
		$flow_config  = $flow['flow_config'];
		$flow_config[ $flow_step_id ]['handler_slugs']   = array( 'custom_api' );
		$flow_config[ $flow_step_id ]['handler_configs'] = array(
			'custom_api' => array(
				'endpoint' => 'https://api.example.test',
				'api_key'  => 'public-boundary-api-key',
				'nested'   => array( 'access_token' => 'public-boundary-token' ),
			),
		);
		$db_flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) );

		$ability_result = ( new ImportExportAbility() )->executeExport( array( 'pipeline_ids' => array( $pipeline_id ) ) );
		$this->assertIsArray( $ability_result );
		$this->assertStringNotContainsString( 'public-boundary-api-key', $ability_result['data'] );
		$this->assertStringNotContainsString( 'public-boundary-token', $ability_result['data'] );
		$csv_rows = array_map( 'str_getcsv', str_getcsv( $ability_result['data'], "\n" ) );
		$flow_row = current(
			array_filter(
				$csv_rows,
				static fn( array $row ): bool => 'flow_step' === ( $row[1] ?? '' )
			)
		);
		$this->assertIsArray( $flow_row );
		$exported_settings = json_decode( $flow_row[9], true );
		$this->assertSame( 'https://api.example.test', $exported_settings['handler_configs']['custom_api']['endpoint'] );
	}

	private function memory_files_request( string $slug, int $pipeline_id, array $memory_files ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/' . $slug . '/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'input' => array(
						'pipeline_id'  => $pipeline_id,
						'memory_files' => $memory_files,
					),
				)
			)
		);

		return $request;
	}
}
