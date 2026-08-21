<?php
/**
 * Pipeline configuration owner-contract tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\FlowStep\UpdateFlowStepAbility;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\Pipeline\PipelineConfigurationAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Logs\LogRepository;
use DataMachine\Core\Database\Pipelines\Pipelines;
use WP_UnitTestCase;

class TicketmasterLikeConfigurationSettings {

	public static function get_fields(): array {
		return array_fill_keys(
			array( 'classification_type', 'location', 'radius', 'genre', 'venue_id', 'search', 'exclude_keywords', 'max_items', 'include_parking', 'params' ),
			array()
		);
	}

	public static function sanitize( array $raw ): array {
		return array(
			'classification_type' => (string) ( $raw['classification_type'] ?? '' ),
			'location'            => (string) ( $raw['location'] ?? '' ),
			'radius'              => (string) ( $raw['radius'] ?? '50' ),
			'genre'               => (string) ( $raw['genre'] ?? '' ),
			'venue_id'            => (string) ( $raw['venue_id'] ?? '' ),
			'search'              => (string) ( $raw['search'] ?? '' ),
			'exclude_keywords'    => (string) ( $raw['exclude_keywords'] ?? '' ),
			'max_items'           => (int) ( $raw['max_items'] ?? 100 ),
			'include_parking'     => (bool) ( $raw['include_parking'] ?? false ),
			'params'              => is_array( $raw['params'] ?? null ) ? $raw['params'] : array(),
		);
	}
}

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

	public function test_handler_patch_parity_uses_real_abilities_without_dry_run_persistence(): void {
		$handler_filter = static function ( array $handlers, ?string $step_type ): array {
			if ( null === $step_type || 'fetch' === $step_type ) {
				$handlers['ticketmaster_like'] = array(
					'type'  => 'fetch',
					'label' => 'Ticketmaster Like',
				);
			}
			return $handlers;
		};
		$settings_filter = static function ( array $settings, ?string $handler_slug ): array {
			if ( null === $handler_slug || 'ticketmaster_like' === $handler_slug ) {
				$settings['ticketmaster_like'] = new TicketmasterLikeConfigurationSettings();
			}
			return $settings;
		};

		add_filter( 'datamachine_handlers', $handler_filter, 10, 2 );
		add_filter( 'datamachine_handler_settings', $settings_filter, 10, 2 );
		HandlerAbilities::clearCache();

		try {
			$flows        = new Flows();
			$flow_id      = (int) $flows->create_flow(
				array(
					'pipeline_id'      => $this->pipeline_id,
					'flow_name'        => 'Handler Configuration Parity',
					'flow_config'      => array(),
					'scheduling_config' => array(),
				)
			);
			$flow_step_id = $this->pipeline_id . '_ticketmaster_' . $flow_id;
			$stored       = array(
				'classification_type' => 'music',
				'location'            => '51.5074,-0.1278',
				'radius'              => 15,
				'genre'               => 'rock',
				'venue_id'            => 'KovZpZA6tFlA',
				'search'              => 'live',
				'exclude_keywords'    => 'tribute',
				'max_items'           => 100,
				'include_parking'     => true,
				'params'              => array(
					'filters' => array(
						'city'    => 'London',
						'keyword' => 'original',
					),
					'mode'    => 'strict',
				),
			);
			$flow_config = array(
				$flow_step_id => array(
					'flow_step_id'     => $flow_step_id,
					'pipeline_step_id' => $this->pipeline_id . '_ticketmaster',
					'pipeline_id'      => $this->pipeline_id,
					'flow_id'          => $flow_id,
					'step_type'        => 'fetch',
					'handler_slugs'    => array( 'ticketmaster_like' ),
					'handler_configs'  => array( 'ticketmaster_like' => $stored ),
				),
			);
			$flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) );

			$patch = array(
				'max_items' => 1,
				'params'    => array( 'filters' => array( 'keyword' => 'updated' ) ),
			);
			$expected = $stored;
			$expected['max_items'] = 1;
			$expected['params']['filters']['keyword'] = 'updated';

			$update     = new UpdateFlowStepAbility();
			$logs       = new LogRepository();
			$logs_before = $logs->get_logs()['total'];
			$raw_before  = $flows->get_flow_config_json( $flow_id );
			$preview     = $update->execute(
				array(
					'flow_step_id'   => $flow_step_id,
					'handler_config' => $patch,
					'validate_only'  => true,
				)
			);

			$this->assertTrue( $preview['success'] );
			$this->assertSame( $expected, $preview['effective_handler_config'] );
			$this->assertSame( $raw_before, $flows->get_flow_config_json( $flow_id ) );
			$this->assertSame( $logs_before, $logs->get_logs()['total'], 'Dry-run must not persist database logs.' );

			$applied = $update->execute(
				array(
					'flow_step_id'   => $flow_step_id,
					'handler_config' => $patch,
				)
			);
			$this->assertTrue( $applied['success'] );
			$stored_after_apply = $this->storedHandlerConfig( $flows, $flow_id, $flow_step_id );
			$this->assertSame( $expected, $stored_after_apply );
			$this->assertSame( 15, $stored_after_apply['radius'], 'Omitted scalar type must remain unchanged.' );
			$this->assertTrue( $stored_after_apply['include_parking'], 'Omitted boolean sibling must remain unchanged.' );

			$this->assertOwnerHandlerUpdatePersistsExpectedConfig( $flows, $flow_id, $flow_step_id, $flow_config, $patch, $expected );

			$full = array(
				'classification_type' => 'sports',
				'location'            => '40.7128,-74.0060',
				'radius'              => 25,
				'genre'               => '',
				'venue_id'            => '',
				'search'              => 'finals',
				'exclude_keywords'    => '',
				'max_items'           => 50,
				'include_parking'     => false,
				'params'              => array(
					'filters' => array( 'city' => 'New York', 'keyword' => 'finals' ),
					'mode'    => 'broad',
				),
			);
			$sanitized_full = TicketmasterLikeConfigurationSettings::sanitize( $full );
			$flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) );
			$this->assertTrue(
				$update->execute( array( 'flow_step_id' => $flow_step_id, 'handler_config' => $full ) )['success']
			);
			$this->assertSame( $sanitized_full, $this->storedHandlerConfig( $flows, $flow_id, $flow_step_id ) );

			$this->assertOwnerHandlerUpdatePersistsExpectedConfig( $flows, $flow_id, $flow_step_id, $flow_config, $full, $sanitized_full );
		} finally {
			remove_filter( 'datamachine_handlers', $handler_filter, 10 );
			remove_filter( 'datamachine_handler_settings', $settings_filter, 10 );
			HandlerAbilities::clearCache();
		}
	}

	public function test_system_task_params_patch_preserves_task_and_nested_siblings(): void {
		$flows        = new Flows();
		$flow_id      = (int) $flows->create_flow(
			array(
				'pipeline_id'      => $this->pipeline_id,
				'flow_name'        => 'System Task Configuration Parity',
				'flow_config'      => array(),
				'scheduling_config' => array(),
			)
		);
		$flow_step_id = $this->pipeline_id . '_system_task_' . $flow_id;
		$stored       = array(
			'task_type' => 'dispatch_message',
			'params'    => array(
				'channel'   => 'extra-chill',
				'recipient' => 'chubes',
				'message'   => 'original',
			),
		);
		$flow_config  = array(
			$flow_step_id => array(
				'flow_step_id'      => $flow_step_id,
				'pipeline_step_id'  => $this->pipeline_id . '_system_task',
				'pipeline_id'       => $this->pipeline_id,
				'flow_id'           => $flow_id,
				'step_type'         => 'system_task',
				'flow_step_settings' => $stored,
			),
		);
		$flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) );

		$patch    = array( 'params' => array( 'message' => 'updated' ) );
		$expected = $stored;
		$expected['params']['message'] = 'updated';
		$update   = new UpdateFlowStepAbility();
		$preview  = $update->execute(
			array(
				'flow_step_id'       => $flow_step_id,
				'flow_step_settings' => $patch,
				'validate_only'      => true,
			)
		);

		$this->assertTrue( $preview['success'] );
		$this->assertSame( $expected, $preview['effective_handler_config'] );
		$this->assertSame( $stored, $flows->get_flow( $flow_id )['flow_config'][ $flow_step_id ]['flow_step_settings'] );

		$current       = $this->abilities->executeGet( array( 'pipeline_id' => $this->pipeline_id ) );
		$flow_snapshot = $this->configurationFlowSnapshot( $current['flows'], $flow_id );
		$result        = $this->abilities->executeUpdate(
			array(
				'target'            => 'flow',
				'flow_id'           => $flow_id,
				'step_id'           => $flow_step_id,
				'expected_revision' => $flow_snapshot['revision'],
				'configuration'     => array( 'flow_step_settings' => $patch ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( $expected, $flows->get_flow( $flow_id )['flow_config'][ $flow_step_id ]['flow_step_settings'] );
	}

	private function storedHandlerConfig( Flows $flows, int $flow_id, string $flow_step_id ): array {
		$flow = $flows->get_flow( $flow_id );
		return $flow['flow_config'][ $flow_step_id ]['handler_configs']['ticketmaster_like'];
	}

	private function assertOwnerHandlerUpdatePersistsExpectedConfig( Flows $flows, int $flow_id, string $flow_step_id, array $flow_config, array $patch, array $expected ): void {
		$flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) );
		$current       = $this->abilities->executeGet( array( 'pipeline_id' => $this->pipeline_id ) );
		$flow_snapshot = $this->configurationFlowSnapshot( $current['flows'], $flow_id );
		$result        = $this->abilities->executeUpdate(
			array(
				'target'            => 'flow',
				'flow_id'           => $flow_id,
				'step_id'           => $flow_step_id,
				'expected_revision' => $flow_snapshot['revision'],
				'configuration'     => array( 'handler_config' => $patch ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( $expected, $this->storedHandlerConfig( $flows, $flow_id, $flow_step_id ) );
	}

	private function configurationFlowSnapshot( array $flows, int $flow_id ): array {
		foreach ( $flows as $flow ) {
			if ( $flow_id === $flow['flow_id'] ) {
				return $flow;
			}
		}
		$this->fail( "Flow {$flow_id} is missing from the configuration snapshot." );
	}
}
