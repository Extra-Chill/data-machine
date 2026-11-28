<?php
/**
 * Parent run_result boundedness tests for fan-out jobs.
 *
 * A parent envelope must reference children by ref plus a small bounded
 * summary instead of inlining full child run_result envelopes, so parent
 * engine_data stays under the write guard regardless of child count.
 * See data-machine#3637.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use AgentsAPI\AI\WP_Agent_Run_Result_Envelope;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use DataMachine\Core\RunResultEnvelope;
use WP_UnitTestCase;

class RunResultEnvelopeChildSummaryTest extends WP_UnitTestCase {

	private const CHILD_COUNT            = 40;
	private const CHILD_RUN_RESULT_BYTES = 61440;
	private const PARENT_ENVELOPE_BOUND  = 163840;

	private Jobs $db_jobs;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();
		$this->db_jobs = new Jobs();
	}

	public function test_parent_run_result_stays_bounded_with_many_large_children(): void {
		$parent_job_id = $this->db_jobs->create_job(
			array(
				'source' => 'pipeline',
				'label'  => 'Fan-out parent boundedness test',
			)
		);
		$this->assertIsInt( $parent_job_id );

		$child_job_ids = array();
		for ( $i = 0; $i < self::CHILD_COUNT; $i++ ) {
			$child_job_id = $this->db_jobs->create_job(
				array(
					'source'        => 'pipeline',
					'label'         => "Fan-out child {$i}",
					'parent_job_id' => $parent_job_id,
				)
			);
			$this->assertIsInt( $child_job_id );
			$child_job_ids[] = $child_job_id;

			$this->assertTrue(
				datamachine_set_engine_data(
					$child_job_id,
					array(
						'job'        => array( 'job_id' => $child_job_id ),
						'run_result' => $this->large_child_run_result( $child_job_id ),
					)
				)
			);
		}

		$inlined_child_bytes = self::CHILD_COUNT * self::CHILD_RUN_RESULT_BYTES;
		$this->assertGreaterThan(
			self::PARENT_ENVELOPE_BOUND,
			$inlined_child_bytes,
			'Fixture must be large enough that inlining child envelopes would blow the bound.'
		);

		$parent = $this->db_jobs->get_job( $parent_job_id );
		$this->assertIsArray( $parent );

		$envelope = RunResultEnvelope::fromJobSummary(
			$parent,
			array(
				'job_id' => $parent_job_id,
				'status' => JobStatus::COMPLETED,
			)
		);

		$encoded = wp_json_encode( $envelope );
		$this->assertIsString( $encoded );
		$this->assertLessThanOrEqual(
			self::PARENT_ENVELOPE_BOUND,
			strlen( $encoded ),
			'Parent run_result must stay bounded regardless of child count.'
		);
		$this->assertTrue( RunResultEnvelope::validate( $envelope ) );

		$datamachine_metadata = $envelope['metadata']['datamachine'] ?? array();
		$this->assertArrayNotHasKey( 'child_job_envelopes', $datamachine_metadata );

		$summaries = $datamachine_metadata['child_job_summaries'] ?? array();
		$this->assertCount( self::CHILD_COUNT, $summaries );
		$this->assertSame( $child_job_ids[0], $summaries[0]['job_id'] ?? 0 );
		$this->assertSame( array( 'items_processed' => 5 ), $summaries[0]['counts'] ?? array() );
		$this->assertSame( array( 'created' => 5 ), $summaries[0]['outcome_class_counts'] ?? array() );
		$this->assertArrayNotHasKey( 'steps', $summaries[0] );
		$this->assertArrayNotHasKey( 'metadata', $summaries[0] );

		$child_run_refs = $envelope['child_run_refs'] ?? array();
		$this->assertCount( self::CHILD_COUNT, $child_run_refs );
		$this->assertSame( (string) $child_job_ids[0], $child_run_refs[0]['id'] ?? '' );
		$this->assertSame( 'datamachine_job', $child_run_refs[0]['type'] ?? '' );
	}

	/**
	 * Build a valid stored child run_result padded to roughly CHILD_RUN_RESULT_BYTES.
	 *
	 * @return array<string,mixed>
	 */
	private function large_child_run_result( int $child_job_id ): array {
		$packet_refs = array();
		$ref_count   = (int) ceil( self::CHILD_RUN_RESULT_BYTES / 1024 );
		for ( $i = 0; $i < $ref_count; $i++ ) {
			$packet_refs[] = array(
				'index'        => $i,
				'content_hash' => 'sha256:' . str_repeat( 'a', 960 ),
			);
		}

		return array(
			'schema'        => WP_Agent_Run_Result_Envelope::SCHEMA,
			'run_id'        => (string) $child_job_id,
			'status'        => JobStatus::COMPLETED,
			'status_detail' => JobStatus::COMPLETED,
			'outputs'       => array(
				'counts'  => array( 'items_processed' => 5 ),
				'outcome' => array( 'class_counts' => array( 'created' => 5 ) ),
			),
			'metadata'      => array(
				'datamachine' => array(
					'job'         => array( 'job_id' => $child_job_id ),
					'packet_refs' => $packet_refs,
				),
			),
		);
	}
}
