<?php
/**
 * Pipeline configuration owner-contract tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Pipeline\PipelineConfigurationAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use WP_UnitTestCase;

class PipelineConfigurationAbilitiesTest extends WP_UnitTestCase {

	private PipelineConfigurationAbilities $abilities;
	private int $pipeline_id;
	private int $flow_id;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->abilities = new PipelineConfigurationAbilities();

		$pipeline = wp_get_ability( 'datamachine/create-pipeline' )->execute(
			array(
				'pipeline_name' => 'Configuration Contract Pipeline',
				'steps'         => array(
					array(
						'step_type' => 'ai',
						'label'     => 'AI',
					),
				),
			)
		);
		$this->pipeline_id = (int) $pipeline['pipeline_id'];

		$flow          = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id' => $this->pipeline_id,
				'flow_name'   => 'Configuration Contract Flow',
			)
		);
		$this->flow_id = (int) $flow['flow_id'];
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_contract_abilities_are_registered_with_strict_schemas(): void {
		$get    = wp_get_ability( 'datamachine/get-pipeline-configuration' );
		$update = wp_get_ability( 'datamachine/update-step-configuration' );

		$this->assertNotNull( $get );
		$this->assertNotNull( $update );
		$this->assertFalse( $get->get_input_schema()['additionalProperties'] );
		$this->assertFalse( $update->get_input_schema()['additionalProperties'] );
	}

	public function test_lookup_by_id_and_stable_name_returns_normalized_configuration(): void {
		$by_id = $this->abilities->executeGet( array( 'pipeline_id' => $this->pipeline_id ) );
		$by_name = $this->abilities->executeGet( array( 'pipeline_name' => 'Configuration Contract Pipeline' ) );

		$this->assertTrue( $by_id['success'] );
		$this->assertSame( 'datamachine.pipeline_configuration.v1', $by_id['schema_version'] );
		$this->assertSame( $this->pipeline_id, $by_id['pipeline']['pipeline_id'] );
		$this->assertMatchesRegularExpression( '/^sha256:[a-f0-9]{64}$/', $by_id['pipeline']['revision'] );
		$this->assertCount( 1, $by_id['pipeline']['steps'] );
		$this->assertArrayHasKey( 'pipeline_step_id', $by_id['pipeline']['steps'][0] );
		$this->assertCount( 1, $by_id['flows'] );
		$this->assertArrayHasKey( 'flow_step_id', $by_id['flows'][0]['steps'][0] );
		$this->assertSame( $by_id['pipeline']['pipeline_id'], $by_name['pipeline']['pipeline_id'] );
	}

	public function test_valid_pipeline_and_flow_updates_return_new_revisions(): void {
		$current = $this->abilities->executeGet( array( 'pipeline_id' => $this->pipeline_id ) );

		$pipeline_update = $this->abilities->executeUpdate(
			array(
				'target'            => 'pipeline',
				'pipeline_id'       => $this->pipeline_id,
				'step_type'         => 'ai',
				'expected_revision' => $current['pipeline']['revision'],
				'configuration'     => array( 'system_prompt' => 'Use the owner-safe contract.' ),
			)
		);

		$this->assertTrue( $pipeline_update['success'] );
		$this->assertNotSame( $current['pipeline']['revision'], $pipeline_update['revision'] );

		$flow_update = $this->abilities->executeUpdate(
			array(
				'target'            => 'flow',
				'flow_id'           => $this->flow_id,
				'step_type'         => 'ai',
				'expected_revision' => $current['flows'][0]['revision'],
				'configuration'     => array(
					'user_message'  => 'Process this item.',
					'enabled_tools' => array(),
				),
			)
		);

		$this->assertTrue( $flow_update['success'] );
		$flow = ( new Flows() )->get_flow( $this->flow_id );
		$step = reset( $flow['flow_config'] );
		$this->assertSame( 'Process this item.', $step['prompt_queue'][0]['prompt'] );
		$this->assertSame( array(), $step['enabled_tools'] );
	}

	public function test_unknown_configuration_field_is_rejected_without_writing(): void {
		$current = $this->abilities->executeGet( array( 'pipeline_id' => $this->pipeline_id ) );
		$result  = $this->abilities->executeUpdate(
			array(
				'target'            => 'pipeline',
				'pipeline_id'       => $this->pipeline_id,
				'step_type'         => 'ai',
				'expected_revision' => $current['pipeline']['revision'],
				'configuration'     => array( 'private_field' => true ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'unknown_field', $result->get_error_code() );
		$after = $this->abilities->executeGet( array( 'pipeline_id' => $this->pipeline_id ) );
		$this->assertSame( $current['pipeline']['revision'], $after['pipeline']['revision'] );
	}

	public function test_stale_update_returns_conflict_and_preserves_concurrent_write(): void {
		$current    = $this->abilities->executeGet( array( 'pipeline_id' => $this->pipeline_id ) );
		$repository = new Pipelines();
		$config     = $repository->get_pipeline_config( $this->pipeline_id );
		$step_id    = array_key_first( $config );
		$config[ $step_id ]['system_prompt'] = 'Concurrent value';
		$repository->update_pipeline( $this->pipeline_id, array( 'pipeline_config' => $config ) );

		$result = $this->abilities->executeUpdate(
			array(
				'target'            => 'pipeline',
				'pipeline_id'       => $this->pipeline_id,
				'step_type'         => 'ai',
				'expected_revision' => $current['pipeline']['revision'],
				'configuration'     => array( 'system_prompt' => 'Stale value' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'configuration_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'Concurrent value', $repository->get_pipeline_config( $this->pipeline_id )[ $step_id ]['system_prompt'] );
	}

	public function test_missing_pipeline_returns_explicit_not_found_error(): void {
		$result = $this->abilities->executeGet( array( 'pipeline_id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pipeline_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_ability_enforces_manage_flows_authorization(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = wp_get_ability( 'datamachine/get-pipeline-configuration' )->execute(
			array( 'pipeline_id' => $this->pipeline_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
