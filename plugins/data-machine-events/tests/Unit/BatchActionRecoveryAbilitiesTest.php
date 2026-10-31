<?php
/**
 * Batch action recovery selection tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\BatchActionRecoveryAbilities;
use WP_UnitTestCase;

class BatchActionRecoveryAbilitiesTest extends WP_UnitTestCase {

	/** @dataProvider classificationProvider */
	public function test_only_proven_obsolete_paths_are_eligible( ?array $parent, ?int $outstanding, int $offset, bool $eligible, string $reason ): void {
		$method = new \ReflectionMethod( BatchActionRecoveryAbilities::class, 'classifyPendingAction' );
		$method->setAccessible( true );
		$result = $method->invoke( null, array( 'parent_job_id' => 9, 'offset' => $offset ), $parent, $outstanding );

		$this->assertSame( $eligible, $result['eligible'] );
		$this->assertSame( $reason, $result['reason'] );
	}

	public function classificationProvider(): array {
		return array(
			'missing parent'       => array( null, null, 0, true, 'parent_missing' ),
			'terminal parent'      => array( array( 'status' => 'completed', 'engine_data' => array() ), null, 0, true, 'parent_terminal' ),
			'completed worklist'   => array( array( 'status' => 'processing', 'engine_data' => array( 'batch_state' => array( 'worklist_complete' => true ) ) ), null, 0, true, 'worklist_complete' ),
			'superseded offset'    => array( array( 'status' => 'processing', 'engine_data' => array() ), 20, 10, true, 'offset_superseded' ),
			'partially outstanding chunk' => array( array( 'status' => 'processing', 'engine_data' => array() ), 15, 10, false, 'active_or_future_path' ),
			'current active path'  => array( array( 'status' => 'processing', 'engine_data' => array() ), 20, 20, false, 'active_or_future_path' ),
			'future active path'   => array( array( 'status' => 'processing', 'engine_data' => array() ), 20, 30, false, 'active_or_future_path' ),
			'ambiguous worklist'   => array( array( 'status' => 'processing', 'engine_data' => array() ), null, 10, false, 'worklist_state_ambiguous' ),
			'non-processing parent' => array( array( 'status' => 'pending', 'engine_data' => array() ), null, 0, false, 'parent_not_processing' ),
		);
	}

	/** @dataProvider argumentProvider */
	public function test_action_arguments_require_exact_parent_and_offset( string $raw, ?array $expected ): void {
		$method = new \ReflectionMethod( BatchActionRecoveryAbilities::class, 'decodeActionArgs' );
		$method->setAccessible( true );
		$this->assertSame( $expected, $method->invoke( null, $raw ) );
	}

	public function argumentProvider(): array {
		return array(
			'json'       => array( '{"parent_job_id":12,"offset":30}', array( 'parent_job_id' => 12, 'offset' => 30 ) ),
			'nested json' => array( '[[{"parent_job_id":12,"offset":30}]]', null ),
			'missing offset' => array( '{"parent_job_id":12}', null ),
			'invalid parent' => array( '{"parent_job_id":0,"offset":30}', null ),
			'invalid offset' => array( '{"parent_job_id":12,"offset":-1}', null ),
			'invalid'    => array( 'not-an-action-payload', null ),
		);
	}
}
