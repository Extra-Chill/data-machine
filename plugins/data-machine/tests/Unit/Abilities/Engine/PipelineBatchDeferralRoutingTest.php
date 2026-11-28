<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integration coverage reads the plugin-owned batch worklist directly.
/**
 * Scheduled fan-out coverage for mixed durable-deferral routing.
 *
 * @package DataMachine\Tests\Unit\Abilities\Engine
 */

namespace DataMachine\Tests\Unit\Abilities\Engine;

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\EngineData;
use WP_UnitTestCase;

class PipelineBatchDeferralRoutingTest extends WP_UnitTestCase {

	private Jobs $jobs_db;
	private int $pipeline_id;
	private int $flow_id;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		add_filter( 'datamachine_step_types', array( $this, 'registerSourceStepCapabilities' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->jobs_db = new Jobs();

		$pipeline_ability  = wp_get_ability( 'datamachine/create-pipeline' );
		$pipeline          = $pipeline_ability->execute( array( 'pipeline_name' => 'Deferral Batch Test Pipeline' ) );
		$this->pipeline_id = $pipeline['pipeline_id'];
		$flow_ability      = wp_get_ability( 'datamachine/create-flow' );
		$flow              = $flow_ability->execute(
			array(
				'pipeline_id' => $this->pipeline_id,
				'flow_name'   => 'Deferral Batch Test Flow',
			)
		);
		$this->flow_id = $flow['flow_id'];
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_step_types', array( $this, 'registerSourceStepCapabilities' ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( PipelineBatchScheduler::BATCH_HOOK );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function registerSourceStepCapabilities( array $step_types ): array {
		$step_types['event_import'] = array(
			'source_ingestion'          => true,
			'allows_empty_output'       => true,
			'supports_item_disposition' => true,
			'handler_category'          => 'source',
		);

		return $step_types;
	}

	public function test_execute_step_fanout_excludes_exhausted_identity_and_routes_siblings(): void {
		$parent_id      = $this->create_parent_job();
		$engine         = $this->make_engine_snapshot( $parent_id );
		$source_step_id = 'source-step';
		$engine['flow_config'] = array(
			$source_step_id => array(
				'step_type'       => 'event_import',
				'execution_order' => 0,
				'pipeline_id'     => $this->pipeline_id,
			),
			'ai-step'       => array(
				'step_type'       => 'ai',
				'execution_order' => 1,
			),
		);
		datamachine_set_engine_data( $parent_id, $engine );

		$packets = array();
		foreach ( array( 'exhausted', 'sibling-a', 'sibling-b' ) as $item_identifier ) {
			$claim  = $this->claim_for_parent( $parent_id, $item_identifier );
			$packet = $this->make_data_packet( $item_identifier );
			$packet['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ] = $claim;
			$packets[] = $packet;
		}

		global $wpdb;
		$this->assertSame( 1, $wpdb->update(
			( new ProcessedItems() )->get_table_name(),
			array( 'deferral_count' => ProcessedItems::MAX_DEFERRAL_ATTEMPTS ),
			array(
				'flow_step_id'    => 'event_import:source_api',
				'source_type'     => 'source_api',
				'item_identifier' => 'exhausted',
			),
			array( '%d' ),
			array( '%s', '%s', '%s' )
		) );

		$route  = new \ReflectionMethod( ExecuteStepAbility::class, 'routeAfterExecution' );
		$result = $route->invoke(
			new ExecuteStepAbility(),
			$parent_id,
			$source_step_id,
			$this->flow_id,
			$engine['flow_config'][ $source_step_id ],
			'event_import',
			'',
			$packets,
			array(
				'job_id' => $parent_id,
				'engine' => new EngineData( $engine, $parent_id ),
			),
			true,
			null
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'batch_scheduled', $result['outcome'] );
		$worklist = $wpdb->get_col( $wpdb->prepare( 'SELECT payload FROM %i WHERE batch_job_id = %d ORDER BY item_index', $wpdb->prefix . 'datamachine_batch_items', $parent_id ) );
		$this->assertCount( 2, $worklist );
		$titles = array_map( static fn( string $payload ): string => (string) json_decode( $payload, true )['data']['title'], $worklist );
		$this->assertSame( array( 'sibling-a', 'sibling-b' ), $titles );
		$this->assertTrue( ( new ProcessedItems() )->has_item_been_processed( 'event_import:source_api', 'source_api', 'exhausted' ) );
	}

	private function make_engine_snapshot( int $job_id ): array {
		return array(
			'job'             => array(
				'job_id'      => $job_id,
				'flow_id'     => $this->flow_id,
				'pipeline_id' => $this->pipeline_id,
				'created_at'  => current_time( 'mysql', true ),
			),
			'flow'            => array(
				'name'        => 'Deferral Batch Test Flow',
				'description' => '',
				'scheduling'  => array(),
			),
			'pipeline'        => array(
				'name'        => 'Deferral Batch Test Pipeline',
				'description' => '',
			),
			'flow_config'     => array(),
			'pipeline_config' => array(),
		);
	}

	private function make_data_packet( string $title ): array {
		return array(
			'type'      => 'event_import',
			'timestamp' => time(),
			'data'      => array(
				'title' => $title,
				'body'  => wp_json_encode( array( 'event' => array( 'title' => $title ) ) ),
			),
			'metadata'  => array(
				'source_type'      => 'source_api',
				'event_identifier' => md5( $title ),
				'success'          => true,
			),
		);
	}

	private function create_parent_job(): int {
		$job_id = $this->jobs_db->create_job(
			array(
				'pipeline_id' => $this->pipeline_id,
				'flow_id'     => $this->flow_id,
				'source'      => 'pipeline',
				'label'       => 'Deferral Batch Test Flow',
			)
		);
		$this->assertNotFalse( $job_id );
		$this->jobs_db->start_job( (int) $job_id );
		datamachine_set_engine_data( (int) $job_id, $this->make_engine_snapshot( (int) $job_id ) );
		return (int) $job_id;
	}

	private function claim_for_parent( int $parent_id, string $item_identifier ): array {
		$identity_scope = 'event_import:source_api';
		$token          = ( new ProcessedItems() )->claim_item_owned( $identity_scope, 'source_api', $item_identifier, $parent_id );
		$this->assertIsString( $token );
		return array(
			'identity_scope'  => $identity_scope,
			'source_type'     => 'source_api',
			'item_identifier' => $item_identifier,
			'ownership_token' => $token,
			'disposition_id'  => ProcessedItems::disposition_identity( $identity_scope, 'source_api', $item_identifier ),
		);
	}
}
