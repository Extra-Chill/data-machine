<?php
/**
 * Persisted step-result projection compatibility tests.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\RunResult;
use DataMachine\Core\StepResult;
use WP_UnitTestCase;

class RunResultStepProjectionCompatibilityTest extends WP_UnitTestCase {

	public function test_nested_step_envelope_is_canonical(): void {
		$nested = $this->envelope( 'nested', 'succeeded' );
		$result = $this->project(
			array(
				'step_results' => array(
					'fetch' => $this->step_row( 'fetch', $nested ),
				),
			)
		);

		$this->assertSame( $nested, $result['step_results'][0] );
	}

	public function test_top_level_step_envelope_remains_a_historical_fallback(): void {
		$fallback = $this->envelope( 'fallback', 'succeeded' );
		$result   = $this->project(
			array(
				'step_results' => array(
					'fetch' => $this->step_row( 'fetch' ),
				),
				'step_result'  => array(
					'fetch' => $fallback,
				),
			)
		);

		$this->assertSame( $fallback, $result['step_results'][0] );
	}

	public function test_identical_dual_projection_preserves_output(): void {
		$envelope = $this->envelope( 'identical', 'succeeded' );
		$nested   = $this->project(
			array(
				'step_results' => array(
					'fetch' => $this->step_row( 'fetch', $envelope ),
				),
			)
		);
		$dual     = $this->project(
			array(
				'step_results' => array(
					'fetch' => $this->step_row( 'fetch', $envelope ),
				),
				'step_result'  => array(
					'fetch' => $envelope,
				),
			)
		);

		$this->assertSame( $nested['step_results'], $dual['step_results'] );
		$this->assertSame( $nested['packet_refs'], $dual['packet_refs'] );
		$this->assertSame( $nested['replay'], $dual['replay'] );
	}

	public function test_nested_step_envelope_wins_when_dual_projections_diverge(): void {
		$nested = $this->envelope( 'nested-wins', 'succeeded' );
		$top    = $this->envelope( 'top-loses', 'failed' );
		$result = $this->project(
			array(
				'step_results' => array(
					'fetch' => $this->step_row( 'fetch', $nested ),
				),
				'step_result'  => array(
					'fetch' => $top,
				),
			)
		);

		$this->assertSame( $nested, $result['step_results'][0] );
		$this->assertSame( 'nested-wins', $result['step_results'][0]['diagnostics']['source'] );
	}

	public function test_legacy_metrics_row_without_envelope_is_synthesized(): void {
		$result = $this->project(
			array(
				'step_results' => array(
					'fetch' => array(
						'flow_step_id' => 'fetch',
						'step_type'    => 'fetch',
						'result'       => 'completed',
						'packet_count' => 2,
					),
				),
			)
		);

		$this->assertSame( StepResult::SCHEMA_VERSION, $result['step_results'][0]['schema_version'] );
		$this->assertSame( 2, $result['step_results'][0]['outputs']['packet_count'] );
	}

	private function project( array $engine ): array {
		$step_results = array_values( is_array( $engine['step_results'] ?? null ) ? $engine['step_results'] : array() );

		return RunResult::fromJobSummary(
			array(
				'job_id'      => 0,
				'status'      => 'completed',
				'engine_data' => $engine,
			),
			array(
				'job_id'       => 0,
				'status'       => 'completed',
				'step_results' => $step_results,
			)
		);
	}

	private function step_row( string $flow_step_id, ?array $envelope = null ): array {
		$row = array(
			'flow_step_id' => $flow_step_id,
			'step_type'    => 'fetch',
			'result'       => 'completed',
			'packet_count' => 1,
		);
		if ( null !== $envelope ) {
			$row['step_result'] = $envelope;
		}

		return $row;
	}

	private function envelope( string $source, string $status ): array {
		return array(
			'schema_version' => StepResult::SCHEMA_VERSION,
			'flow_step_id'   => 'fetch',
			'status'         => $status,
			'outputs'        => array( 'packet_count' => 1 ),
			'artifact_refs'  => array(),
			'packet_refs'    => array(
				array(
					'index'        => 0,
					'content_hash' => "sha256:{$source}",
				),
			),
			'diagnostics'    => array( 'source' => $source ),
			'replay'         => array( 'content_hashes' => array() ),
		);
	}
}
