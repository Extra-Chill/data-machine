<?php
/**
 * Flow creation validation and atomicity tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\AgentAbilities;
use DataMachine\Abilities\Flow\QueueAbility;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Api\Flows\FlowScheduling;
use DataMachine\Cli\Commands\Flows\FlowsCommand;
use DataMachine\Cli\Commands\PipelinesCommand;
use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Steps\Settings\SettingsHandler;
use DataMachine\Engine\Tasks\RecurringScheduler;
use ReflectionMethod;
use WP_UnitTestCase;

class AtomicEventImportSettings extends SettingsHandler {
	public static function get_fields(): array {
		return array(
			'source' => array(
				'type'     => 'text',
				'required' => true,
			),
		);
	}
}

class AtomicUpsertSettings extends SettingsHandler {
	public static function get_fields(): array {
		return array(
			'post_type' => array(
				'type' => 'text',
			),
		);
	}
}

class AtomicFetchSettings extends SettingsHandler {
	public static function get_fields(): array {
		return array(
			'url' => array(
				'type' => 'url',
			),
		);
	}
}

class FlowCreationAtomicityTest extends WP_UnitTestCase {

	private int $pipeline_id;
	private \Closure $handlers_filter;
	private \Closure $settings_filter;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->handlers_filter = static function ( array $handlers, ?string $step_type ): array {
			if ( null === $step_type || 'event_import' === $step_type ) {
				$handlers['ticketmaster'] = array(
					'type'  => 'event_import',
					'label' => 'Ticketmaster',
				);
			}
			if ( null === $step_type || 'upsert' === $step_type ) {
				$handlers['upsert_event'] = array(
					'type'  => 'upsert',
					'label' => 'Upsert Event',
				);
			}
			if ( null === $step_type || 'fetch' === $step_type ) {
				$handlers['atomic_fetch'] = array(
					'type'  => 'fetch',
					'label' => 'Atomic Fetch',
				);
			}

			return $handlers;
		};
		add_filter(
			'datamachine_handlers',
			$this->handlers_filter,
			10,
			2
		);
		$this->settings_filter = static function ( array $settings, ?string $handler_slug ): array {
			if ( null === $handler_slug || 'ticketmaster' === $handler_slug ) {
				$settings['ticketmaster'] = new AtomicEventImportSettings();
			}
			if ( null === $handler_slug || 'upsert_event' === $handler_slug ) {
				$settings['upsert_event'] = new AtomicUpsertSettings();
			}
			if ( null === $handler_slug || 'atomic_fetch' === $handler_slug ) {
				$settings['atomic_fetch'] = new AtomicFetchSettings();
			}

			return $settings;
		};
		add_filter(
			'datamachine_handler_settings',
			$this->settings_filter,
			10,
			2
		);
		HandlerAbilities::clearCache();

		$pipelines         = new Pipelines();
		$this->pipeline_id = (int) $pipelines->create_pipeline(
			array(
				'pipeline_name'   => 'Atomic Flow Creation',
				'pipeline_config' => array(),
			)
		);

		$pipeline_config = array();
		foreach ( array( 'event_import', 'ai', 'upsert', 'fetch' ) as $order => $step_type ) {
			$pipeline_step_id                     = $this->pipeline_id . '_' . $step_type;
			$pipeline_config[ $pipeline_step_id ] = array(
				'pipeline_step_id' => $pipeline_step_id,
				'step_type'        => $step_type,
				'execution_order'  => $order,
				'label'            => $step_type,
			);
		}
		$pipelines->update_pipeline( $this->pipeline_id, array( 'pipeline_config' => $pipeline_config ) );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_handlers', $this->handlers_filter, 10 );
		remove_filter( 'datamachine_handler_settings', $this->settings_filter, 10 );
		HandlerAbilities::clearCache();
		delete_option( 'datamachine_handler_defaults' );

		parent::tear_down();
	}

	public function test_valid_handler_ai_and_upsert_config_is_applied(): void {
		$result = wp_get_ability( 'datamachine/create-flow' )->execute( $this->validInput( 'Configured Flow' ) );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 3, $result['configured_steps'] );

		$flow       = ( new Flows() )->get_flow( (int) $result['flow_id'] );
		$step_types = array_column( $flow['flow_config'], null, 'step_type' );
		$this->assertSame( array( 'ticketmaster' ), $step_types['event_import']['handler_slugs'] );
		$this->assertSame( 'ticketmaster', $step_types['event_import']['handler_configs']['ticketmaster']['source'] );
		$this->assertSame( array( 'Import upcoming events.' ), $step_types['ai'][ QueueAbility::SLOT_PROMPT_QUEUE ] );
		$this->assertSame( array( 'upsert_event' ), $step_types['upsert']['handler_slugs'] );
		$this->assertSame( 'event', $step_types['upsert']['handler_configs']['upsert_event']['post_type'] );
	}

	public function test_dry_run_executes_deep_validation_without_persisting(): void {
		$before = $this->flowCount();
		$input  = $this->validInput( 'Dry Run Flow' );
		$input['validate_only'] = true;

		$result = wp_get_ability( 'datamachine/create-flow' )->execute( $input );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'validate_only', $result['mode'] );
		$this->assertSame( $before, $this->flowCount() );
	}

	/**
	 * @dataProvider malformedAliasProvider
	 */
	public function test_malformed_aliases_fail_in_dry_run_and_write_mode( string $alias ): void {
		foreach ( array( false, true ) as $validate_only ) {
			$input = $this->validInput( 'Malformed Alias Flow' );
			$input['step_configs']['event_import'] = array(
				$alias => array( 'ticketmaster' ),
			);
			$input['validate_only'] = $validate_only;
			$before = $this->flowCount();

			$result = wp_get_ability( 'datamachine/create-flow' )->execute( $input );

			$this->assertFalse( $result['success'] );
			$this->assertStringContainsString( $alias, $result['error'] );
			$this->assertSame( $before, $this->flowCount() );
		}
	}

	public static function malformedAliasProvider(): array {
		return array(
			'plural handler slugs'   => array( 'handler_slugs' ),
			'plural handler configs' => array( 'handler_configs' ),
			'stored prompt queue'     => array( QueueAbility::SLOT_PROMPT_QUEUE ),
		);
	}

	public function test_invalid_late_step_rolls_back_the_entire_flow(): void {
		$input = $this->validInput( 'Rollback Flow' );
		$input['step_configs']['upsert']['handler_config'] = array( 'unknown_field' => 'event' );
		$before = $this->flowCount();

		$result = wp_get_ability( 'datamachine/create-flow' )->execute( $input );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unknown handler_config fields', $result['error'] );
		$this->assertSame( $before, $this->flowCount() );
	}

	public function test_empty_user_message_clears_prompt_queue(): void {
		$input = $this->validInput( 'Empty Message Flow' );
		$input['step_configs']['ai']['user_message'] = '';

		$result = wp_get_ability( 'datamachine/create-flow' )->execute( $input );

		$this->assertTrue( $result['success'] );
		$flow       = ( new Flows() )->get_flow( (int) $result['flow_id'] );
		$step_types = array_column( $flow['flow_config'], null, 'step_type' );
		$this->assertSame( array(), $step_types['ai'][ QueueAbility::SLOT_PROMPT_QUEUE ] );
	}

	public function test_nested_creation_preserves_caller_transaction(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'SAVEPOINT datamachine_test_caller' );

		$result = wp_get_ability( 'datamachine/create-flow' )->execute( $this->validInput( 'Nested Transaction Flow' ) );

		$this->assertTrue( $result['success'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotFalse( $wpdb->query( 'ROLLBACK TO SAVEPOINT datamachine_test_caller' ) );
		$this->assertNull( ( new Flows() )->get_flow( (int) $result['flow_id'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'RELEASE SAVEPOINT datamachine_test_caller' );
	}

	public function test_scheduled_dry_run_rolls_back_action_side_effects(): void {
		$input = $this->validInput( 'Scheduled Dry Run Flow' );
		$input['scheduling_config'] = array( 'interval' => 'hourly' );
		$input['validate_only'] = true;
		$before = $this->scheduledFlowActionCount();

		$result = wp_get_ability( 'datamachine/create-flow' )->execute( $input );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $before, $this->scheduledFlowActionCount() );
	}

	public function test_invalid_config_rolls_back_scheduled_action(): void {
		$input = $this->validInput( 'Scheduled Rollback Flow' );
		$input['scheduling_config'] = array( 'interval' => 'hourly' );
		$input['step_configs']['upsert']['handler_config'] = array( 'unknown_field' => 'event' );
		$before = $this->scheduledFlowActionCount();

		$result = wp_get_ability( 'datamachine/create-flow' )->execute( $input );

		$this->assertFalse( $result['success'] );
		$this->assertSame( $before, $this->scheduledFlowActionCount() );
	}

	public function test_scheduling_failure_rolls_back_flow_and_actions(): void {
		$input = $this->validInput( 'Scheduling Failure Flow' );
		$input['scheduling_config'] = array( 'interval' => 'one_time' );
		$flow_count   = $this->flowCount();
		$action_count = $this->scheduledFlowActionCount();

		$result = wp_get_ability( 'datamachine/create-flow' )->execute( $input );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Timestamp required', $result['error'] );
		$this->assertSame( $flow_count, $this->flowCount() );
		$this->assertSame( $action_count, $this->scheduledFlowActionCount() );
	}

	public function test_site_default_failure_rolls_back_flow(): void {
		update_option(
			'datamachine_handler_defaults',
			array( 'atomic_fetch' => array( 'url' => 'https://example.com/events' ) )
		);
		HandlerAbilities::clearSiteDefaultsCache();
		$reject_default = static function ( $flow_config ) {
			foreach ( $flow_config as $step_config ) {
				if ( in_array( 'atomic_fetch', $step_config['handler_slugs'] ?? array(), true ) ) {
					return new \WP_Error( 'default_write_failed', 'Forced site-default write failure' );
				}
			}
			return $flow_config;
		};
		add_filter( 'datamachine_flow_config_pre_save', $reject_default );
		$before = $this->flowCount();

		$result = wp_get_ability( 'datamachine/create-flow' )->execute( $this->validInput( 'Default Failure Flow' ) );

		remove_filter( 'datamachine_flow_config_pre_save', $reject_default );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Failed to update handler configuration', $result['error'] );
		$this->assertSame( $before, $this->flowCount() );
	}

	public function test_bulk_validation_rejects_invalid_config_before_creating_any_flow(): void {
		$valid                                             = $this->validInput( 'Valid Bulk Flow' );
		$invalid                                           = $this->validInput( 'Invalid Bulk Flow' );
		$invalid['step_configs']['upsert']['handler_config'] = array( 'unknown_field' => 'event' );
		$before                                            = $this->flowCount();

		$result = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'flows' => array( $valid, $invalid ),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( $before, $this->flowCount() );
	}

	public function test_owned_parent_and_child_are_visible_in_agent_export(): void {
		$agent = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'atomic-export-agent',
				'agent_name' => 'Atomic Export Agent',
				'owner_id'   => get_current_user_id(),
			)
		);
		$agent_id = (int) $agent['agent_id'];

		$pipeline = wp_get_ability( 'datamachine/create-pipeline' )->execute(
			array(
				'pipeline_name' => 'Owned Export Pipeline',
				'agent_id'      => $agent_id,
				'steps'         => array( array( 'step_type' => 'ai' ) ),
			)
		);
		$flow = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id' => $pipeline['pipeline_id'],
				'flow_name'   => 'Owned Export Flow',
				'agent_id'    => $agent_id,
			)
		);

		$this->assertTrue( $pipeline['success'] );
		$this->assertTrue( $flow['success'] );

		$export = ( new AgentBundler() )->export( 'atomic-export-agent' );
		$this->assertTrue( $export['success'] );
		$this->assertContains( 'Owned Export Pipeline', array_column( $export['bundle']['pipelines'], 'pipeline_name' ) );
		$this->assertContains( 'Owned Export Flow', array_column( $export['bundle']['flows'], 'flow_name' ) );
	}

	public function test_cli_create_commands_persist_explicit_agent_ownership(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$agent    = AgentAbilities::createAgent(
			array(
				'agent_slug' => 'cli-create-owner',
				'agent_name' => 'CLI Create Owner',
				'owner_id'   => $owner_id,
			)
		);
		$agent_id = (int) $agent['agent_id'];

		$pipeline_method = new ReflectionMethod( PipelinesCommand::class, 'createPipeline' );
		$pipeline_method->setAccessible( true );
		$pipeline_method->invoke(
			new PipelinesCommand(),
			array(
				'name'  => 'CLI Owned Pipeline',
				'agent' => 'cli-create-owner',
			)
		);

		$owned_pipelines = ( new Pipelines() )->get_all_pipelines( null, $agent_id );
		$this->assertContains( 'CLI Owned Pipeline', array_column( $owned_pipelines, 'pipeline_name' ) );
		$pipeline_ids = array_column( $owned_pipelines, 'pipeline_id', 'pipeline_name' );
		$pipeline_id  = (int) $pipeline_ids['CLI Owned Pipeline'];

		$flow_method = new ReflectionMethod( FlowsCommand::class, 'createFlow' );
		$flow_method->setAccessible( true );
		$flow_method->invoke(
			new FlowsCommand(),
			array(
				'pipeline_id' => $pipeline_id,
				'name'        => 'CLI Owned Flow',
				'agent'       => 'cli-create-owner',
			)
		);

		$owned_flows = ( new Flows() )->get_all_flows( null, $agent_id );
		$this->assertContains( 'CLI Owned Flow', array_column( $owned_flows, 'flow_name' ) );
	}

	private function validInput( string $flow_name ): array {
		return array(
			'pipeline_id'  => $this->pipeline_id,
			'flow_name'    => $flow_name,
			'step_configs' => array(
				'event_import' => array(
					'handler_slug'   => 'ticketmaster',
					'handler_config' => array( 'source' => 'ticketmaster' ),
				),
				'ai'           => array(
					'user_message' => 'Import upcoming events.',
				),
				'upsert'       => array(
					'handler_slug'   => 'upsert_event',
					'handler_config' => array( 'post_type' => 'event' ),
				),
			),
		);
	}

	private function flowCount(): int {
		return count( ( new Flows() )->get_flows_for_pipeline( $this->pipeline_id ) );
	}

	private function scheduledFlowActionCount(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		return count(
			as_get_scheduled_actions(
				array(
					'hook'   => FlowScheduling::FLOW_HOOK,
					'group'  => RecurringScheduler::GROUP,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
	}
}
