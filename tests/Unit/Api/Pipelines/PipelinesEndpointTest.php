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
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

class PipelinesEndpointTest extends WP_UnitTestCase {

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
		$csv_rows          = str_getcsv( $ability_result['data'], "\n" );
		$flow_row          = str_getcsv( $csv_rows[2] );
		$exported_settings = json_decode( $flow_row[7], true );
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
