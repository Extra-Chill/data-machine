<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integration coverage owns and cleans its claim rows.
/**
 * ExecuteStep regression coverage for mixed exhausted claim collections.
 *
 * @package DataMachine\Tests\Unit\Abilities\Engine
 */

namespace DataMachine\Tests\Unit\Abilities\Engine;

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\JobStatus;
use DataMachine\Core\Steps\Step;
use WP_UnitTestCase;

class ExecuteStepMixedClaimRoutingTest extends WP_UnitTestCase {

	private Jobs $jobs;
	private ProcessedItems $processed;
	private array $scheduled = array();
	private array $terminal_transitions = array();
	private $step_types_filter;
	private $schedule_capture;
	private $terminal_capture;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		$this->jobs      = new Jobs();
		$this->processed = new ProcessedItems();
		$this->processed->create_table();
		MixedClaimFetchStep::$packets = array();

		$this->step_types_filter = static function ( array $types ): array {
			$types['fetch'] = array(
				'class'                     => MixedClaimFetchStep::class,
				'uses_handler'              => false,
				'source_ingestion'          => true,
				'allows_empty_output'       => true,
				'supports_item_disposition' => true,
				'handler_category'          => 'source',
			);
			$types['ai'] = array(
				'class'        => MixedClaimAIStep::class,
				'uses_handler' => false,
			);
			$types['passthrough'] = array(
				'class'        => MixedClaimFetchStep::class,
				'uses_handler' => false,
			);
			return $types;
		};
		add_filter( 'datamachine_step_types', $this->step_types_filter, PHP_INT_MAX );
		StepTypeAbilities::clearCache();

		$this->schedule_capture = function ( int $job_id, string $flow_step_id, array $packets ): void {
			$this->scheduled[] = compact( 'job_id', 'flow_step_id', 'packets' );
		};
		add_action( 'datamachine_schedule_next_step', $this->schedule_capture, 1, 3 );

		$this->terminal_capture = function ( string $status, int $job_id ): string {
			$this->terminal_transitions[] = compact( 'status', 'job_id' );
			return $status;
		};
		add_filter( 'datamachine_job_terminal_status', $this->terminal_capture, PHP_INT_MAX, 2 );
	}

	public function tear_down(): void {
		global $wpdb;
		remove_filter( 'datamachine_step_types', $this->step_types_filter, PHP_INT_MAX );
		remove_action( 'datamachine_schedule_next_step', $this->schedule_capture, 1 );
		remove_filter( 'datamachine_job_terminal_status', $this->terminal_capture, PHP_INT_MAX );
		StepTypeAbilities::clearCache();
		MixedClaimFetchStep::$packets = array();
		MixedClaimAIStep::$packets = array();
		$wpdb->delete( $this->processed->get_table_name(), array( 'flow_step_id' => 'mixed-scope' ), array( '%s' ) );
		parent::tear_down();
	}

	public function test_execute_step_routes_live_sibling_without_exhausted_identity(): void {
		$job_id    = $this->create_job();
		$exhausted = $this->claim( $job_id, 'mixed-exhausted', true );
		$live      = $this->claim( $job_id, 'mixed-live', false );

		MixedClaimFetchStep::$packets[ $job_id ] = array(
			$this->packet( array( $exhausted, $live ), $exhausted['disposition_id'] ),
		);

		$result = ( new ExecuteStepAbility() )->execute(
			array(
				'job_id'       => $job_id,
				'flow_step_id' => 'source',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'inline_continuation', $result['outcome'] );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'sink', $this->scheduled[0]['flow_step_id'] );
		$this->assertCount( 1, $this->scheduled[0]['packets'] );

		$routed   = $this->scheduled[0]['packets'][0];
		$metadata = $routed['metadata'];
		$claims   = ProcessedItems::disposition_claims( $metadata );
		$this->assertSame( array( $live['disposition_id'] ), array_keys( $claims ) );
		$this->assertSame( $live['disposition_id'], $metadata[ ProcessedItems::DISPOSITION_ID_METADATA_KEY ] );
		$this->assertSame( 'mixed-live', $metadata['item_identifier'] );
		$this->assertNotContains( $exhausted['disposition_id'], array_keys( $claims ) );
		$this->assertTrue( $this->processed->has_item_been_processed( 'mixed-scope', 'mixed-source', 'mixed-exhausted' ) );
		$this->assertTrue( $this->processed->owns_active_claim( $live, $job_id ) );
		$this->assertSame( array(), $this->terminal_transitions, 'A live sibling must prevent the terminal hook from running.' );
		$this->assertSame( JobStatus::PROCESSING, $this->jobs->get_job( $job_id )['status'] );

		$engine = datamachine_get_engine_data( $job_id );
		$this->assertSame( 'mixed-live', $engine['item_identifier'] );
		$this->assertSame( 'mixed-source', $engine['source_type'] );
		$this->assertSame( $live['disposition_id'], $engine[ ProcessedItems::DISPOSITION_ID_METADATA_KEY ] );
	}

	public function test_execute_step_routes_claimless_packet_after_every_claim_exhausts(): void {
		$job_id    = $this->create_job();
		$exhausted = $this->claim( $job_id, 'claimless-exhausted', true );

		MixedClaimFetchStep::$packets[ $job_id ] = array(
			$this->packet( array( $exhausted ) ),
			$this->claimless_packet(),
		);
		$result = ( new ExecuteStepAbility() )->execute(
			array(
				'job_id'       => $job_id,
				'flow_step_id' => 'source',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'inline_continuation', $result['outcome'] );
		$this->assertCount( 1, $this->scheduled );
		$this->assertCount( 1, $this->scheduled[0]['packets'] );
		$this->assertSame( 'Claimless packet', $this->scheduled[0]['packets'][0]['data']['title'] );
		$this->assertSame( array(), ProcessedItems::disposition_claims( $this->scheduled[0]['packets'][0]['metadata'] ) );
		$this->assertSame( array(), $this->terminal_transitions );
		$this->assertSame( JobStatus::PROCESSING, $this->jobs->get_job( $job_id )['status'] );
		$this->assertTrue( $this->processed->has_item_been_processed( 'mixed-scope', 'mixed-source', 'claimless-exhausted' ) );
	}

	public function test_execute_step_clears_scalar_identity_for_cross_source_live_collection(): void {
		$job_id    = $this->create_job();
		$exhausted = $this->claim( $job_id, 'cross-exhausted', true, 'source-a' );
		$first     = $this->claim( $job_id, 'cross-live-a', false, 'source-a' );
		$second    = $this->claim( $job_id, 'cross-live-b', false, 'source-b' );

		$packet = $this->packet( array( $exhausted, $first, $second ) );
		$packet['metadata']['item_identifier'] = 'cross-exhausted';
		$packet['metadata']['source_type'] = 'source-a';
		$packet['metadata']['_engine_data'] = array(
			'item_identifier' => 'cross-exhausted',
			'source_type'     => 'source-a',
		);
		MixedClaimFetchStep::$packets[ $job_id ] = array( $packet );

		$result = ( new ExecuteStepAbility() )->execute(
			array(
				'job_id'       => $job_id,
				'flow_step_id' => 'source',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'inline_continuation', $result['outcome'] );
		$routed_metadata = $this->scheduled[0]['packets'][0]['metadata'];
		$this->assertEqualsCanonicalizing( array( $first['disposition_id'], $second['disposition_id'] ), array_keys( ProcessedItems::disposition_claims( $routed_metadata ) ) );
		$this->assertArrayNotHasKey( 'item_identifier', $routed_metadata );
		$this->assertArrayNotHasKey( 'source_type', $routed_metadata );
		$this->assertArrayNotHasKey( 'item_identifier', $routed_metadata['_engine_data'] );
		$this->assertArrayNotHasKey( 'source_type', $routed_metadata['_engine_data'] );

		$engine = datamachine_get_engine_data( $job_id );
		$this->assertEqualsCanonicalizing( array( $first['disposition_id'], $second['disposition_id'] ), array_keys( ProcessedItems::disposition_claims( $engine ) ) );
		$this->assertArrayNotHasKey( 'item_identifier', $engine );
		$this->assertArrayNotHasKey( 'source_type', $engine );
		$this->assertSame( array(), $this->terminal_transitions );
	}

	public function test_execute_step_terminalizes_packet_when_every_claim_is_exhausted(): void {
		$job_id = $this->create_job();
		$first  = $this->claim( $job_id, 'all-exhausted-first', true );
		$second = $this->claim( $job_id, 'all-exhausted-second', true );

		MixedClaimFetchStep::$packets[ $job_id ] = array( $this->packet( array( $first, $second ) ) );
		$result = ( new ExecuteStepAbility() )->execute(
			array(
				'job_id'       => $job_id,
				'flow_step_id' => 'source',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'packets_dispositioned', $result['outcome'] );
		$this->assertSame( JobStatus::AGENT_SKIPPED, $this->jobs->get_job( $job_id )['status'] );
		$this->assertSame( 'defer-exhausted', datamachine_get_engine_data( $job_id )['job_status_reason'] );
		$this->assertSame( array(), $this->scheduled );
		$this->assertCount( 1, $this->terminal_transitions );
		$this->assertSame( JobStatus::agentSkipped( 'defer-exhausted' )->toString(), $this->terminal_transitions[0]['status'] );
		$this->assertTrue( $this->processed->has_item_been_processed( 'mixed-scope', 'mixed-source', 'all-exhausted-first' ) );
		$this->assertTrue( $this->processed->has_item_been_processed( 'mixed-scope', 'mixed-source', 'all-exhausted-second' ) );
		$this->assertCount( 2, datamachine_get_engine_data( $job_id )['packet_disposition_evidence'][0]['completed_ids'] );
	}

	public function test_execute_step_terminalizes_reject_result_without_routing_it(): void {
		$job_id = $this->create_job( 'ai' );
		$claim  = $this->claim( $job_id, 'ai-reject', false );
		$this->set_engine_claims( $job_id, array( $claim ) );
		MixedClaimAIStep::$packets[ $job_id ] = array( $this->disposition_result_packet( $claim, 'reject_source' ) );

		$result = $this->execute( $job_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'packets_dispositioned', $result['outcome'] );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $result['status'] );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $this->jobs->get_job( $job_id )['status'] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_execute_step_terminalizes_normal_defer_result_without_routing_it(): void {
		$job_id = $this->create_job( 'ai' );
		$claim  = $this->claim( $job_id, 'ai-defer', false );
		$this->assertSame( 1, $this->processed->record_owned_deferral_attempt( $claim, $job_id )['attempts'] );
		$this->set_engine_claims( $job_id, array( $claim ) );
		MixedClaimAIStep::$packets[ $job_id ] = array( $this->disposition_result_packet( $claim, 'defer_item' ) );

		$result = $this->execute( $job_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'packets_dispositioned', $result['outcome'] );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $result['status'] );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $this->jobs->get_job( $job_id )['status'] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_execute_step_terminalizes_exhausted_defer_with_skipped_status(): void {
		$job_id = $this->create_job( 'ai' );
		$claim  = $this->claim( $job_id, 'ai-defer-exhausted', true );
		$this->set_engine_claims( $job_id, array( $claim ) );
		MixedClaimAIStep::$packets[ $job_id ] = array( $this->disposition_result_packet( $claim, 'defer_exhausted' ) );

		$result = $this->execute( $job_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'packets_dispositioned', $result['outcome'] );
		$this->assertSame( JobStatus::AGENT_SKIPPED, $result['status'] );
		$this->assertSame( JobStatus::AGENT_SKIPPED, $this->jobs->get_job( $job_id )['status'] );
		$this->assertSame( 'defer-exhausted', datamachine_get_engine_data( $job_id )['job_status_reason'] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_defer_attempt_returning_existing_reject_preserves_reject_terminal_result(): void {
		$job_id = $this->create_job( 'ai' );
		$claim  = $this->claim( $job_id, 'ai-defer-after-reject', false );
		$this->set_engine_claims( $job_id, array( $claim ) );
		MixedClaimAIStep::$packets[ $job_id ] = array( $this->disposition_result_packet( $claim, 'reject_source', 'defer_item' ) );

		$result = $this->execute( $job_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'packets_dispositioned', $result['outcome'] );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $result['status'] );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $this->jobs->get_job( $job_id )['status'] );
		$this->assertSame( array(), $this->scheduled );
		$evidence = datamachine_get_engine_data( $job_id )['packet_disposition_evidence'][0];
		$this->assertSame( array( $claim['disposition_id'] ), $evidence['completed_ids'] );
		$this->assertSame( array(), $evidence['released_ids'] );
		$this->assertTrue( $this->processed->has_item_been_processed( 'mixed-scope', 'mixed-source', 'ai-defer-after-reject' ) );
	}

	public function test_reject_attempt_returning_existing_defer_preserves_defer_terminal_result(): void {
		$job_id = $this->create_job( 'ai' );
		$claim  = $this->claim( $job_id, 'ai-reject-after-defer', false );
		$this->assertSame( 1, $this->processed->record_owned_deferral_attempt( $claim, $job_id )['attempts'] );
		$this->set_engine_claims( $job_id, array( $claim ) );
		MixedClaimAIStep::$packets[ $job_id ] = array( $this->disposition_result_packet( $claim, 'defer_item', 'reject_source' ) );

		$result = $this->execute( $job_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'packets_dispositioned', $result['outcome'] );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $result['status'] );
		$this->assertSame( JobStatus::COMPLETED_NO_ITEMS, $this->jobs->get_job( $job_id )['status'] );
		$this->assertSame( array(), $this->scheduled );
		$evidence = datamachine_get_engine_data( $job_id )['packet_disposition_evidence'][0];
		$this->assertSame( array(), $evidence['completed_ids'] );
		$this->assertSame( array( $claim['disposition_id'] ), $evidence['released_ids'] );
		$this->assertFalse( $this->processed->has_item_been_processed( 'mixed-scope', 'mixed-source', 'ai-reject-after-defer' ) );
	}

	public function test_execute_step_routes_live_sibling_but_not_disposition_result(): void {
		$job_id  = $this->create_job( 'ai' );
		$rejected = $this->claim( $job_id, 'ai-mixed-reject', false );
		$live     = $this->claim( $job_id, 'ai-mixed-live', false );
		$this->set_engine_claims( $job_id, array( $rejected, $live ) );
		MixedClaimAIStep::$packets[ $job_id ] = array(
			$this->disposition_result_packet( $rejected, 'reject_source' ),
			$this->packet( array( $live ) ),
		);

		$result = $this->execute( $job_id );

		$this->assertSame( 'inline_continuation', $result['outcome'] );
		$this->assertCount( 1, $this->scheduled[0]['packets'] );
		$this->assertSame( array( $live['disposition_id'] ), array_keys( ProcessedItems::disposition_claims( $this->scheduled[0]['packets'][0]['metadata'] ) ) );
	}

	public function test_execute_step_routes_claimless_source_packet_from_ai(): void {
		$job_id = $this->create_job( 'ai' );
		MixedClaimAIStep::$packets[ $job_id ] = array( $this->claimless_packet() );

		$result = $this->execute( $job_id );

		$this->assertSame( 'inline_continuation', $result['outcome'] );
		$this->assertCount( 1, $this->scheduled[0]['packets'] );
		$this->assertSame( 'Claimless packet', $this->scheduled[0]['packets'][0]['data']['title'] );
	}

	public function test_execute_step_does_not_classify_source_packet_disposition_field_as_tool_result(): void {
		$job_id = $this->create_job( 'ai' );
		$claim  = $this->claim( $job_id, 'source-packet-disposition-field', false );
		$this->set_engine_claims( $job_id, array( $claim ) );
		$packet = $this->packet( array( $claim ) );
		$packet['type'] = 'source_api';
		$packet['metadata']['packet_disposition'] = 'reject_source';
		MixedClaimAIStep::$packets[ $job_id ] = array( $packet );

		$result = $this->execute( $job_id );

		$this->assertSame( 'inline_continuation', $result['outcome'] );
		$this->assertCount( 1, $this->scheduled[0]['packets'] );
		$this->assertSame( 'source_api', $this->scheduled[0]['packets'][0]['type'] );
		$this->assertSame( 'reject_source', $this->scheduled[0]['packets'][0]['metadata']['packet_disposition'] );
	}

	private function create_job( string $step_type = 'fetch' ): int {
		$job_id = $this->jobs->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'label'       => 'Mixed claim routing',
			)
		);
		$this->assertIsInt( $job_id );
		$this->assertTrue(
			datamachine_set_engine_data(
				$job_id,
				array(
					'job'         => array(
						'job_id'      => $job_id,
						'pipeline_id' => 'direct',
						'flow_id'     => 'direct',
					),
					'flow_config' => array(
						'source' => array(
							'flow_step_id'    => 'source',
							'step_type'       => $step_type,
							'execution_order' => 0,
							'pipeline_id'     => 'direct',
							'flow_id'         => 'direct',
						),
						'sink'   => array(
							'flow_step_id'    => 'sink',
							'step_type'       => 'passthrough',
							'execution_order' => 1,
							'pipeline_id'     => 'direct',
							'flow_id'         => 'direct',
						),
					),
				)
			)
		);
		return $job_id;
	}

	private function execute( int $job_id ): array {
		return ( new ExecuteStepAbility() )->execute(
			array(
				'job_id'       => $job_id,
				'flow_step_id' => 'source',
			)
		);
	}

	private function set_engine_claims( int $job_id, array $claims ): void {
		$engine = datamachine_get_engine_data( $job_id );
		$engine = ProcessedItems::replace_disposition_claims( $engine, $claims );
		$this->assertTrue( datamachine_set_engine_data( $job_id, $engine ) );
	}

	private function claim( int $job_id, string $item_identifier, bool $exhausted, string $source_type = 'mixed-source' ): array {
		global $wpdb;
		$token = $this->processed->claim_item_owned( 'mixed-scope', $source_type, $item_identifier, $job_id );
		$this->assertIsString( $token );
		if ( $exhausted ) {
			$this->assertSame( 1, $wpdb->update(
				$this->processed->get_table_name(),
				array( 'deferral_count' => ProcessedItems::MAX_DEFERRAL_ATTEMPTS ),
				array(
					'flow_step_id'    => 'mixed-scope',
					'source_type'     => $source_type,
					'item_identifier' => $item_identifier,
				)
			) );
		}

		return array(
			'identity_scope'  => 'mixed-scope',
			'source_type'     => $source_type,
			'item_identifier' => $item_identifier,
			'ownership_token' => $token,
			'disposition_id'  => ProcessedItems::disposition_identity( 'mixed-scope', $source_type, $item_identifier ),
		);
	}

	private function packet( array $claims, string $disposition_id = '' ): array {
		$metadata = array(
			ProcessedItems::CLAIMS_METADATA_KEY => $claims,
			'source_type'                         => 'mixed-source',
		);
		if ( '' !== $disposition_id ) {
			$metadata[ ProcessedItems::DISPOSITION_ID_METADATA_KEY ] = $disposition_id;
			$metadata['item_identifier'] = 'mixed-exhausted';
		}
		return array(
			'type'      => 'fetch',
			'timestamp' => time(),
			'data'      => array( 'title' => 'Mixed packet', 'body' => 'One packet owns a collection of claims.' ),
			'metadata'  => $metadata,
		);
	}

	private function claimless_packet(): array {
		return array(
			'type'      => 'fetch',
			'timestamp' => time(),
			'data'      => array( 'title' => 'Claimless packet', 'body' => 'This packet remains routable.' ),
			'metadata'  => array( 'origin' => 'claimless' ),
		);
	}

	private function disposition_result_packet( array $claim, string $disposition, string $tool_name = '' ): array {
		$tool_name = '' !== $tool_name ? $tool_name : ( 'reject_source' === $disposition ? 'reject_source' : 'defer_item' );
		return array(
			'type'      => 'ai_handler_complete',
			'timestamp' => time(),
			'data'      => array( 'title' => 'Disposition result' ),
			'metadata'  => array(
				'tool_name'                                    => $tool_name,
				'handler_tool'                                 => $tool_name,
				'packet_disposition'                           => $disposition,
				'tool_result_envelope'                         => array(
					'success'             => true,
					'disposition'         => $disposition,
					'disposition_id'      => $claim['disposition_id'],
					'already_dispositioned' => $tool_name !== $disposition,
				),
				'step_execution_success'                       => true,
				ProcessedItems::CLAIM_METADATA_KEY             => $claim,
				ProcessedItems::DISPOSITION_ID_METADATA_KEY    => $claim['disposition_id'],
				'disposition_id'                               => $claim['disposition_id'],
			),
		);
	}
}

class MixedClaimFetchStep extends Step {

	public static array $packets = array();

	public function __construct() {
		parent::__construct( 'fetch' );
	}

	protected function validateStepConfiguration(): bool {
		return true;
	}

	protected function executeStep(): array {
		return self::$packets[ $this->job_id ] ?? array();
	}
}

class MixedClaimAIStep extends Step {

	public static array $packets = array();

	public function __construct() {
		parent::__construct( 'ai' );
	}

	protected function validateStepConfiguration(): bool {
		return true;
	}

	protected function executeStep(): array {
		return self::$packets[ $this->job_id ] ?? array();
	}
}
