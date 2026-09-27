<?php
/**
 * Canonical run result envelope builder.
 *
 * Projects Data Machine job/run state into the substrate's canonical
 * `agents-api/run-result/v1` envelope ({@see WP_Agent_Run_Result_Envelope})
 * instead of a Data-Machine-private schema. Domain-specific state that has
 * no canonical home (packet refs, the raw job reference, diagnostics, child
 * job envelopes) is carried in `metadata.datamachine`.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

use AgentsAPI\AI\WP_Agent_Run_Result_Envelope;
use DataMachine\Core\Database\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the canonical WP_Agent_Run_Result_Envelope for a Data Machine job/run.
 */
class RunResultEnvelope {

	private const MAX_ENVELOPE_BYTES = 163840;

	/**
	 * Build a canonical nonterminal projection envelope.
	 *
	 * The run id is the job id (as a string) when available; no better
	 * stable run identifier exists for a Data Machine job at this layer.
	 */
	public static function active( string $status, int $job_id = 0 ): array {
		return WP_Agent_Run_Result_Envelope::from_array(
			array(
				'run_id'        => $job_id > 0 ? (string) $job_id : '',
				'status'        => WP_Agent_Run_Result_Envelope::STATUS_RUNNING,
				'status_detail' => $status,
				'provenance'    => array( 'source' => 'datamachine' ),
			)
		)->to_array();
	}

	/**
	 * Validate the canonical envelope before it crosses a public boundary.
	 *
	 * A stored value that predates the canonical schema (or any other
	 * malformed value) fails validation; callers treat that as a missing
	 * envelope and rebuild it rather than migrating the row.
	 */
	public static function validate( $value ): bool {
		if ( ! is_array( $value ) || WP_Agent_Run_Result_Envelope::SCHEMA !== ( $value['schema'] ?? null ) || ! is_string( $value['status'] ?? null ) || '' === trim( $value['status'] ) ) {
			return false;
		}
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) && strlen( $encoded ) <= self::MAX_ENVELOPE_BYTES;
	}

	/**
	 * Build a run envelope from step result envelopes.
	 *
	 * @param array<int,array<string,mixed>> $step_results StepResult envelopes.
	 * @param array<string,mixed>            $context      Optional status/outputs/artifact refs/replay context.
	 * @return array<string,mixed>
	 */
	public static function fromSteps( array $step_results, array $context = array() ): array {
		$steps         = array_values( array_filter( $step_results, fn( $step_result ) => is_array( $step_result ) ) );
		$raw_status    = is_scalar( $context['status'] ?? null ) ? trim( (string) $context['status'] ) : '';
		$status        = self::deriveStatus( $steps, $raw_status );
		$outputs       = is_array( $context['outputs'] ?? null ) ? $context['outputs'] : array();
		$artifact_refs = self::mergeRefs( $steps, 'artifact_refs', $context['artifact_refs'] ?? ( $context['artifacts'] ?? array() ) );
		$packet_refs   = self::mergeRefs( $steps, 'packet_refs', $context['packet_refs'] ?? array() );
		$diagnostics   = is_array( $context['diagnostics'] ?? null ) ? $context['diagnostics'] : array();
		$run_id        = is_scalar( $context['run_id'] ?? null ) ? trim( (string) $context['run_id'] ) : '';

		return WP_Agent_Run_Result_Envelope::from_array(
			array(
				'run_id'        => $run_id,
				'status'        => $status,
				'status_detail' => $status,
				'outputs'       => $outputs,
				'artifact_refs' => $artifact_refs,
				'steps'         => $steps,
				'replay'        => self::buildReplayMetadata( $steps, $outputs, $artifact_refs, $packet_refs, is_array( $context['replay'] ?? null ) ? $context['replay'] : array() ),
				'metadata'      => array(
					'datamachine' => self::filterNull(
						array(
							'packet_refs' => $packet_refs,
							'diagnostics' => $diagnostics,
						)
					),
				),
				'provenance'    => array( 'source' => 'datamachine' ),
			)
		)->to_array();
	}

	/**
	 * Build the canonical run result envelope from a job row and its metrics summary.
	 *
	 * @param array<string,mixed> $job     Job row.
	 * @param array<string,mixed> $summary RunMetrics::fromJob() summary.
	 * @return array<string,mixed>
	 */
	public static function fromJobSummary( array $job, array $summary ): array {
		$engine        = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$step_results  = self::stepResultEnvelopes( $summary['step_results'] ?? array(), $engine );
		$job_id        = (int) ( $summary['job_id'] ?? ( $job['job_id'] ?? 0 ) );
		$parent_job_id = (int) ( $summary['parent_job_id'] ?? ( $job['parent_job_id'] ?? 0 ) );
		$raw_status    = (string) ( $summary['status'] ?? ( $job['status'] ?? '' ) );
		$outputs       = self::outputs( $summary );
		$artifact_refs = array_values( JobArtifactSurfaces::artifactRefs( $engine ) );
		$packet_refs   = self::packetRefs( $step_results );

		return WP_Agent_Run_Result_Envelope::from_array(
			array(
				'run_id'         => $job_id > 0 ? (string) $job_id : '',
				'status'         => $raw_status,
				'status_detail'  => $raw_status,
				'outputs'        => $outputs,
				'artifact_refs'  => $artifact_refs,
				'steps'          => $step_results,
				'parent_run_id'  => $parent_job_id > 0 ? (string) $parent_job_id : '',
				'child_run_refs' => self::childRunRefs( $job_id ),
				'replay'         => array(
					'content_hashes' => array(
						'outputs'       => self::contentHash( $outputs ),
						'artifact_refs' => self::contentHash( $artifact_refs ),
						'packet_refs'   => self::contentHash( $packet_refs ),
					),
				),
				'metadata'       => array(
					'datamachine' => self::filterNull(
						array(
							'job'                 => self::jobRef( $job, $summary ),
							'packet_refs'         => $packet_refs,
							'diagnostics'         => self::diagnostics( $summary ),
							'child_job_envelopes' => self::childJobEnvelopes( $job_id ),
						)
					),
				),
				'provenance'     => array( 'source' => 'datamachine' ),
			)
		)->to_array();
	}

	/**
	 * @param array<string,mixed> $job
	 * @param array<string,mixed> $summary
	 * @return array<string,mixed>
	 */
	private static function jobRef( array $job, array $summary ): array {
		return self::filterNull(
			array(
				'job_id'        => (int) ( $summary['job_id'] ?? ( $job['job_id'] ?? 0 ) ),
				'source'        => $summary['source'] ?? ( $job['source'] ?? null ),
				'label'         => $summary['label'] ?? ( $job['label'] ?? null ),
				'flow_id'       => $summary['flow_id'] ?? ( $job['flow_id'] ?? null ),
				'pipeline_id'   => $summary['pipeline_id'] ?? ( $job['pipeline_id'] ?? null ),
				'parent_job_id' => (int) ( $summary['parent_job_id'] ?? ( $job['parent_job_id'] ?? 0 ) ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $summary
	 * @return array<string,mixed>
	 */
	private static function outputs( array $summary ): array {
		return self::filterNull(
			array(
				'counts'           => is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array(),
				'outcome'          => is_array( $summary['outcome'] ?? null ) ? $summary['outcome'] : array(),
				'outcome_classes'  => is_array( $summary['outcome_classes'] ?? null ) ? array_values( $summary['outcome_classes'] ) : array(),
				'child_jobs'       => is_array( $summary['child_jobs'] ?? null ) ? $summary['child_jobs'] : array(),
				'token_usage'      => is_array( $summary['token_usage'] ?? null ) ? $summary['token_usage'] : array(),
				'cost'             => is_array( $summary['cost'] ?? null ) ? $summary['cost'] : array(),
				'duration_seconds' => $summary['duration_seconds'] ?? null,
				'timestamps'       => is_array( $summary['timestamps'] ?? null ) ? $summary['timestamps'] : array(),
			)
		);
	}

	/**
	 * @param mixed               $step_results Legacy step result rows.
	 * @param array<string,mixed> $engine       Engine data.
	 * @return array<int,array<string,mixed>>
	 */
	private static function stepResultEnvelopes( $step_results, array $engine ): array {
		$envelopes = array();
		$canonical = is_array( $engine['step_result'] ?? null ) ? $engine['step_result'] : array();

		foreach ( is_array( $step_results ) ? $step_results : array() as $step_result ) {
			if ( ! is_array( $step_result ) ) {
				continue;
			}

			$envelope = is_array( $step_result['step_result'] ?? null ) ? $step_result['step_result'] : array();
			$step_id  = is_scalar( $step_result['flow_step_id'] ?? null ) ? (string) $step_result['flow_step_id'] : '';
			if ( array() === $envelope && '' !== $step_id && is_array( $canonical[ $step_id ] ?? null ) ) {
				$envelope = $canonical[ $step_id ];
			}
			if ( array() === $envelope ) {
				$envelope = StepResult::fromExecutionResult(
					array(
						'status'      => is_scalar( $step_result['result'] ?? null ) ? (string) $step_result['result'] : (string) ( $step_result['status'] ?? 'failed' ),
						'packets'     => array(),
						'reason'      => is_scalar( $step_result['reason'] ?? null ) ? (string) $step_result['reason'] : '',
						'error'       => is_scalar( $step_result['error'] ?? null ) ? (string) $step_result['error'] : null,
						'diagnostics' => array(
							'flow_step_id' => $step_id,
							'step_type'    => is_scalar( $step_result['step_type'] ?? null ) ? (string) $step_result['step_type'] : '',
						),
					),
					array(
						'outputs' => array(
							'packet_count' => (int) ( $step_result['packet_count'] ?? 0 ),
						),
					)
				);
			}

			if ( array() !== $envelope ) {
				if ( '' !== $step_id && ! isset( $envelope['flow_step_id'] ) ) {
					$envelope['flow_step_id'] = $step_id;
				}
				$envelopes[] = $envelope;
			}
		}

		return $envelopes;
	}

	/**
	 * @param array<int,array<string,mixed>> $step_results Step result envelopes.
	 * @return array<int,mixed>
	 */
	private static function packetRefs( array $step_results ): array {
		$refs = array();
		foreach ( $step_results as $step_result ) {
			foreach ( is_array( $step_result['packet_refs'] ?? null ) ? $step_result['packet_refs'] : array() as $packet_ref ) {
				$refs[] = $packet_ref;
			}
		}

		return $refs;
	}

	/**
	 * @param int $job_id Parent job ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function childRunRefs( int $job_id ): array {
		$refs = array();
		foreach ( self::childJobs( $job_id ) as $child ) {
			$refs[] = self::filterNull(
				array(
					'id'     => (string) ( $child['job_id'] ?? 0 ),
					'type'   => 'datamachine_job',
					'label'  => $child['label'] ?? null,
					'status' => $child['status'] ?? null,
				)
			);
		}

		return $refs;
	}

	/**
	 * @param int $job_id Parent job ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function childJobEnvelopes( int $job_id ): array {
		$envelopes = array();
		foreach ( self::childJobs( $job_id ) as $child ) {
			$engine = is_array( $child['engine_data'] ?? null ) ? $child['engine_data'] : array();
			$stored = $engine['run_result'] ?? null;
			if ( self::validate( $stored ) ) {
				$envelopes[] = $stored;
			}
		}

		return $envelopes;
	}

	/**
	 * @param int $job_id Parent job ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function childJobs( int $job_id ): array {
		if ( $job_id <= 0 ) {
			return array();
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return array();
		}

		$table = $wpdb->prefix . Jobs::TABLE_NAME;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data Machine owns the jobs table and needs fresh child state for terminal envelopes.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT job_id FROM %i WHERE parent_job_id = %d ORDER BY job_id ASC',
				$table,
				$job_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$jobs = new Jobs();
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$child = $jobs->get_job( (int) ( $row['job_id'] ?? 0 ) );
			if ( is_array( $child ) ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $summary Run summary.
	 * @return array<string,mixed>
	 */
	private static function diagnostics( array $summary ): array {
		return self::filterNull(
			array(
				'context' => is_array( $summary['context'] ?? null ) ? $summary['context'] : array(),
			)
		);
	}

	/**
	 * Derive an aggregate run status from step envelopes.
	 *
	 * @param array<int,array<string,mixed>> $steps           Step envelopes.
	 * @param string                         $explicit_status Caller-provided raw status.
	 * @return string Raw run status (not yet canonicalized).
	 */
	private static function deriveStatus( array $steps, string $explicit_status ): string {
		if ( '' !== $explicit_status ) {
			return $explicit_status;
		}

		foreach ( $steps as $step ) {
			if ( 'failed' === ( $step['status'] ?? '' ) || 'blocked' === ( $step['status'] ?? '' ) ) {
				return (string) $step['status'];
			}
		}

		return array() === $steps ? JobStatus::COMPLETED_NO_ITEMS : 'succeeded';
	}

	/**
	 * Merge caller refs with refs from every step envelope.
	 *
	 * @param array<int,array<string,mixed>> $steps Step envelopes.
	 * @param string                         $key   Envelope ref key.
	 * @param mixed                          $refs  Caller refs.
	 * @return array<int,mixed>
	 */
	private static function mergeRefs( array $steps, string $key, $refs ): array {
		$merged = is_array( $refs ) ? array_values( $refs ) : array();

		foreach ( $steps as $step ) {
			if ( is_array( $step[ $key ] ?? null ) ) {
				$merged = array_merge( $merged, array_values( $step[ $key ] ) );
			}
		}

		return $merged;
	}

	/**
	 * Build replay metadata with deterministic content hashes.
	 *
	 * @param array<int,array<string,mixed>> $steps         Step envelopes.
	 * @param array<string,mixed>            $outputs       Run outputs.
	 * @param array<int,mixed>               $artifact_refs Artifact refs.
	 * @param array<int,mixed>               $packet_refs   Packet refs.
	 * @param array<string,mixed>            $replay        Replay metadata.
	 * @return array<string,mixed>
	 */
	private static function buildReplayMetadata( array $steps, array $outputs, array $artifact_refs, array $packet_refs, array $replay ): array {
		$content_hashes  = is_array( $replay['content_hashes'] ?? null ) ? $replay['content_hashes'] : array();
		$content_hashes += array(
			'steps'         => self::contentHash( $steps ),
			'outputs'       => self::contentHash( $outputs ),
			'artifact_refs' => self::contentHash( $artifact_refs ),
			'packet_refs'   => self::contentHash( $packet_refs ),
		);

		$replay['content_hashes'] = $content_hashes;

		return $replay;
	}

	/**
	 * @param mixed $value Value to hash.
	 * @return string
	 */
	private static function contentHash( $value ): string {
		$encoded = wp_json_encode( self::sortKeysRecursive( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return 'sha256:' . hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private static function sortKeysRecursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sortKeysRecursive( $item );
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $values Values to filter.
	 * @return array<string,mixed>
	 */
	private static function filterNull( array $values ): array {
		return array_filter(
			$values,
			static fn( $value ) => null !== $value
		);
	}
}
