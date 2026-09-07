<?php
/**
 * Pipelines REST API tests.
 *
 * @package DataMachine\Tests\Unit\Api\Pipelines
 */

namespace DataMachine\Tests\Unit\Api\Pipelines;

use DataMachine\Abilities\Pipeline\CreatePipelineAbility;
use DataMachine\Abilities\Pipeline\ImportExportAbility;
use DataMachine\Abilities\PipelineStepAbilities;
use DataMachine\Api\Pipelines\Pipelines;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines as PipelineDatabase;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

class PipelinesEndpointTest extends WP_UnitTestCase {

	private const CSV_HEADER = 'format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings';

	public function test_post_pipeline_route_retains_only_ordinary_creation_contract(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$routes    = rest_get_server()->get_routes();
		$endpoints = $routes['/datamachine/v1/pipelines'];
		$post      = current(
			array_filter(
				$endpoints,
				static fn( array $endpoint ): bool => ! empty( $endpoint['methods']['POST'] )
			)
		);

		$this->assertIsArray( $post );
		$this->assertSame( array( 'pipeline_name', 'steps', 'flow_config' ), array_keys( $post['args'] ) );

		$request = new WP_REST_Request( 'POST', '/datamachine/v1/pipelines' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'pipeline_name' => 'Ordinary REST Pipeline' ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertNotEmpty( $response->get_data()['data']['pipeline_id'] );
	}

	public function test_rest_visible_import_ability_imports_canonical_csv(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$csv = self::CSV_HEADER . "\n1.0,pipeline,3255,REST Ability Import,,,,,,\n";

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/import-pipelines/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'input' => array(
						'format' => 'csv',
						'data'   => $csv,
					),
				)
			)
		);
		$response = rest_do_request( $request );
		$result   = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertCount( 1, $result['imported'] );
		$this->assertSame( 'REST Ability Import', ( new PipelineDatabase() )->get_pipeline( $result['imported'][0] )['pipeline_name'] );
	}

	public function test_rest_visible_import_ability_rejects_malformed_csv_before_writes(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
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

	public function test_missing_pipeline_preserves_not_found_response(): void {
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/pipelines/999999' );
		$request->set_param( 'pipeline_id', 999999 );

		$response = Pipelines::handle_get_pipelines( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'pipeline_not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_csv_export_failure_passes_native_error_through(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/datamachine/v1/pipelines' );
		$request->set_param( 'format', 'csv' );
		$request->set_param( 'ids', '999999' );

		$response = Pipelines::handle_get_pipelines( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'export_failed', $response->get_error_code() );
		$this->assertSame( 'Export failed', $response->get_error_message() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
	}

	public function test_csv_ability_and_rest_exports_exclude_handler_credentials(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

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
		$db_flows     = new Flows();
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

		$request = new WP_REST_Request( 'GET', '/datamachine/v1/pipelines' );
		$request->set_param( 'format', 'csv' );
		$request->set_param( 'ids', (string) $pipeline_id );
		$response = Pipelines::handle_get_pipelines( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $ability_result['data'], $response->get_data() );
		$this->assertSame( 'text/csv; charset=utf-8', $response->get_headers()['Content-Type'] );
	}
}
