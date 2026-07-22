<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * Generic run metrics helpers.
 *
 * Stores durable, generic progress counters in job engine_data so long flow,
 * pipeline, batch, and system-task runs can report progress without owning a
 * domain-specific metrics table.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( RunResult::class ) ) {
	require_once __DIR__ . '/RunResult.php';
}

class RunMetrics {

	private const KEY = 'run_metrics';

	private const COUNT_KEYS = array(
		'selected',
		'processed',
		'skipped',
		'failed',
		'fetch_packets',
		'no_content',
		'true_empty_query',
		'provider_error',
		'hydration_failed',
		'hydration_partial',
		'ai_empty_packet',
		'ai_required_handler_not_called',
		'ai_handler_tool_failed',
		'ai_empty_response',
		'ai_completion_assertions_missing',
		'missing_handler_packet',
		'source_rejected',
		'item_deferred',
		'retried',
		'scheduled',
		'staged_actions',
		'accepted_actions',
		'rejected_actions',
	);

	private const STEP_RESULTS_KEY = 'step_results';

	private const STEP_RESULT_ENVELOPES_KEY = 'step_result';

	private const RUN_RESULT_KEY = 'run_result';

	public static function recordStepResult( int $job_id, string $flow_step_id, array $result ): bool {
		if ( $job_id <= 0 || '' === $flow_step_id ) {
			return false;
		}

		$clean_result = self::sanitizeResult( $result );
		$result       = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $flow_step_id, $clean_result ): array {
				$step_results = is_array( $engine[ self::STEP_RESULTS_KEY ] ?? null ) ? $engine[ self::STEP_RESULTS_KEY ] : array();
				$existing     = is_array( $step_results[ $flow_step_id ] ?? null ) ? $step_results[ $flow_step_id ] : array();

				$step_results[ $flow_step_id ] = array_replace_recursive(
					$existing,
					array_merge(
						array(
							'flow_step_id' => $flow_step_id,
							'recorded_at'  => self::now(),
						),
						$clean_result
					)
				);

				$engine[ self::STEP_RESULTS_KEY ] = $step_results;
				if ( is_array( $clean_result['step_result'] ?? null ) ) {
					$step_result_envelopes                     = is_array( $engine[ self::STEP_RESULT_ENVELOPES_KEY ] ?? null ) ? $engine[ self::STEP_RESULT_ENVELOPES_KEY ] : array();
					$step_result_envelope                      = $clean_result['step_result'];
					$step_result_envelope['flow_step_id']    ??= $flow_step_id;
					$step_result_envelopes[ $flow_step_id ]    = $step_result_envelope;
					$engine[ self::STEP_RESULT_ENVELOPES_KEY ] = $step_result_envelopes;
				}

				$metrics = self::normalize( $engine[ self::KEY ] ?? array() );
				if ( isset( $clean_result['packet_count'] ) && 'fetch' === ( $clean_result['step_type'] ?? '' ) ) {
					$metrics['counts']['fetch_packets'] = max( (int) $metrics['counts']['fetch_packets'], (int) $clean_result['packet_count'] );
				}
				if ( in_array( $clean_result['result'] ?? '', array( 'no_content', 'completed_no_items' ), true ) ) {
					$metrics['counts']['no_content'] = max( 1, (int) $metrics['counts']['no_content'] );
				}
				if ( 'source_rejected' === ( $clean_result['result'] ?? '' ) || ! empty( $clean_result['source_rejection_reason'] ) ) {
					$metrics['counts']['source_rejected'] = max( 1, (int) $metrics['counts']['source_rejected'] );
				}
				foreach ( self::classesFromStepResult( $clean_result, (string) ( $clean_result['status'] ?? '' ) ) as $class ) {
					if ( ! isset( $metrics['counts'][ $class ] ) ) {
						$metrics['counts'][ $class ] = 0;
					}
					$metrics['counts'][ $class ] = max( 1, (int) $metrics['counts'][ $class ] );
				}
				$metrics['last_activity_at'] = self::now();
				$engine[ self::KEY ]         = $metrics;

				return $engine;
			},
			'record_step_result'
		);

		return ! empty( $result['success'] );
	}

	public static function start( int $job_id, array $context = array() ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		// Compare-and-swap mutate so a concurrent writer's keys (e.g. a
		// fan-out batch_state merge happening in the same tight window)
		// are never clobbered by a blind full-snapshot overwrite. See #2762.
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $context, $job_id ): array {
				$metrics = self::normalize( $engine[ self::KEY ] ?? array() );
				$now     = self::now();

				if ( empty( $metrics['started_at'] ) ) {
					$metrics['started_at'] = $now;
				}
				$metrics['last_activity_at'] = $now;

				if ( ! empty( $context ) ) {
					$metrics['context'] = array_replace_recursive(
						is_array( $metrics['context'] ?? null ) ? $metrics['context'] : array(),
						self::sanitizeContext( $context )
					);
				}

				$engine[ self::KEY ] = $metrics;
				return self::withRunResult( $job_id, $engine );
			},
			'run_metrics_start'
		);

		return ! empty( $result['success'] );
	}

	public static function increment( int $job_id, string $key, int $amount = 1 ): bool {
		if ( $job_id <= 0 || $amount <= 0 ) {
			return false;
		}

		// Compare-and-swap mutate so concurrent writers cannot lose the
		// incremented counter or clobber unrelated keys. See #2762.
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $key, $amount ): array {
				$metrics = self::normalize( $engine[ self::KEY ] ?? array() );

				if ( ! isset( $metrics['counts'][ $key ] ) ) {
					$metrics['counts'][ $key ] = 0;
				}

				$metrics['counts'][ $key ]   = max( 0, (int) $metrics['counts'][ $key ] + $amount );
				$metrics['last_activity_at'] = self::now();

				$engine[ self::KEY ] = $metrics;
				return $engine;
			},
			'run_metrics_increment'
		);

		return ! empty( $result['success'] );
	}

	public static function complete( int $job_id, string $status, ?string $completed_at = null, int $processed_claim_count = 0 ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		// Compare-and-swap mutate so completing a parent job cannot blindly
		// overwrite a concurrent fan-out batch_state write with a stale
		// snapshot — the lost-update race behind batch_state_missing. See #2762.
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $status, $job_id, $completed_at, $processed_claim_count ): array {
				$metrics = self::normalize( $engine[ self::KEY ] ?? array() );
				$applied = max( 0, (int) ( $metrics['terminal_processed_claims_applied'] ?? 0 ) );
				if ( $processed_claim_count > $applied ) {
					$metrics['counts']['processed']              += $processed_claim_count - $applied;
					$metrics['terminal_processed_claims_applied'] = $processed_claim_count;
				}
				if ( $status === $metrics['terminal_status'] && ! empty( $metrics['completed_at'] ) && is_array( $engine[ self::RUN_RESULT_KEY ] ?? null ) ) {
					$engine[ self::KEY ] = $metrics;
					return $engine;
				}
				$now = ! empty( $completed_at ) ? $completed_at : self::now();

				if ( empty( $metrics['started_at'] ) ) {
					$metrics['started_at'] = $engine['started_at'] ?? ( $engine['job']['created_at'] ?? $now );
				}

				$metrics['completed_at']     = $now;
				$metrics['last_activity_at'] = $now;
				$metrics['duration_seconds'] = self::durationSeconds( $metrics['started_at'], $now );
				$metrics['terminal_status']  = $status;

				if ( JobStatus::isStatusFailure( $status ) ) {
					$metrics['counts']['failed'] = max( 1, (int) ( $metrics['counts']['failed'] ?? 0 ) );
				} elseif ( str_starts_with( $status, JobStatus::AGENT_SKIPPED ) || str_starts_with( $status, JobStatus::COMPLETED_NO_ITEMS ) ) {
					$metrics['counts']['skipped'] = max( 1, (int) ( $metrics['counts']['skipped'] ?? 0 ) );
				} elseif ( JobStatus::COMPLETED === $status ) {
					$metrics['counts']['processed'] = max( 1, (int) ( $metrics['counts']['processed'] ?? 0 ) );
				}

				$engine[ self::KEY ] = $metrics;
				return self::withRunResult( $job_id, $engine, $status );
			},
			'run_metrics_complete'
		);

		return ! empty( $result['success'] );
	}

	public static function fromJob( array $job ): array {
		$engine  = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$metrics = self::normalize( $engine[ self::KEY ] ?? array() );
		$status  = (string) ( $job['status'] ?? '' );

		$started_at   = ! empty( $metrics['started_at'] ) ? $metrics['started_at'] : ( $engine['started_at'] ?? ( $job['created_at'] ?? null ) );
		$ended_at     = ! empty( $metrics['completed_at'] ) ? $metrics['completed_at'] : ( $job['completed_at'] ?? null );
		$last         = ! empty( $metrics['last_activity_at'] ) ? $metrics['last_activity_at'] : ( ! empty( $ended_at ) ? $ended_at : $started_at );
		$counts       = $metrics['counts'];
		$duration_end = ! empty( $ended_at ) ? $ended_at : self::now();

		foreach ( self::inferCountsFromEngine( $engine, $status ) as $key => $value ) {
			$counts[ $key ] = max( (int) ( $counts[ $key ] ?? 0 ), (int) $value );
		}

		$outcome_classes = self::outcomeClasses( $job, $engine, $counts );

		$summary = array(
			'job_id'           => (int) ( $job['job_id'] ?? 0 ),
			'source'           => $job['source'] ?? null,
			'label'            => $job['label'] ?? ( $job['display_label'] ?? null ),
			'flow_id'          => $job['flow_id'] ?? null,
			'pipeline_id'      => $job['pipeline_id'] ?? null,
			'parent_job_id'    => isset( $job['parent_job_id'] ) ? (int) $job['parent_job_id'] : 0,
			'status'           => $status,
			'counts'           => $counts,
			'outcome_classes'  => $outcome_classes,
			'child_jobs'       => self::childTotals( (int) ( $job['job_id'] ?? 0 ) ),
			'timestamps'       => array(
				'created_at'       => $job['created_at'] ?? null,
				'started_at'       => $started_at,
				'last_activity_at' => $last,
				'completed_at'     => $ended_at,
			),
			'duration_seconds' => self::durationSeconds( $started_at, $duration_end ),
			'outcome'          => self::outcomeDetails( $job, $engine, $counts, $outcome_classes ),
			'step_results'     => self::stepResults( $engine ),
			'run_result'       => self::runResult( $engine, $status ),
			'context'          => $metrics['context'],
			'token_usage'      => self::tokenUsage( $engine ),
			'cost'             => self::cost( $engine ),
		);

		$summary['run_result'] = is_array( $engine[ self::RUN_RESULT_KEY ] ?? null ) ? $engine[ self::RUN_RESULT_KEY ] : RunResult::fromJobSummary( $job, $summary );

		return $summary;
	}

	public static function normalize( $metrics ): array {
		$metrics = is_array( $metrics ) ? $metrics : array();
		$counts  = is_array( $metrics['counts'] ?? null ) ? $metrics['counts'] : array();

		foreach ( self::COUNT_KEYS as $key ) {
			$counts[ $key ] = max( 0, (int) ( $counts[ $key ] ?? 0 ) );
		}

		return array(
			'counts'                            => $counts,
			'started_at'                        => isset( $metrics['started_at'] ) ? (string) $metrics['started_at'] : null,
			'last_activity_at'                  => isset( $metrics['last_activity_at'] ) ? (string) $metrics['last_activity_at'] : null,
			'completed_at'                      => isset( $metrics['completed_at'] ) ? (string) $metrics['completed_at'] : null,
			'duration_seconds'                  => isset( $metrics['duration_seconds'] ) ? max( 0, (int) $metrics['duration_seconds'] ) : null,
			'terminal_status'                   => isset( $metrics['terminal_status'] ) ? (string) $metrics['terminal_status'] : null,
			'terminal_processed_claims_applied' => max( 0, (int) ( $metrics['terminal_processed_claims_applied'] ?? 0 ) ),
			'context'                           => is_array( $metrics['context'] ?? null ) ? $metrics['context'] : array(),
		);
	}

	private static function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}

	private static function durationSeconds( $start, $end ): ?int {
		if ( empty( $start ) || empty( $end ) ) {
			return null;
		}

		$start_ts = strtotime( (string) $start );
		$end_ts   = strtotime( (string) $end );
		if ( false === $start_ts || false === $end_ts ) {
			return null;
		}

		return max( 0, $end_ts - $start_ts );
	}

	private static function sanitizeContext( array $context ): array {
		$out = array();
		foreach ( $context as $key => $value ) {
			$key = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $key ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private static function inferCountsFromEngine( array $engine, string $status ): array {
		$counts = array();

		if ( isset( $engine['batch_results'] ) && is_array( $engine['batch_results'] ) ) {
			$counts['selected']  = (int) ( $engine['batch_results']['selected'] ?? 0 );
			$counts['processed'] = (int) ( $engine['batch_results']['processed'] ?? ( $engine['batch_results']['completed'] ?? 0 ) );
			$counts['failed']    = (int) ( $engine['batch_results']['failed'] ?? 0 );
			$counts['skipped']   = (int) ( $engine['batch_results']['skipped'] ?? 0 );
			$counts['retried']   = (int) ( $engine['batch_results']['retried'] ?? 0 );
		}

		foreach ( array( 'batch_scheduled', 'tasks_scheduled' ) as $key ) {
			if ( isset( $engine[ $key ] ) ) {
				$counts['scheduled'] = max( (int) ( $counts['scheduled'] ?? 0 ), (int) $engine[ $key ] );
			}
		}

		if ( ! empty( $engine['skipped'] ) || str_starts_with( $status, JobStatus::AGENT_SKIPPED ) || str_starts_with( $status, JobStatus::COMPLETED_NO_ITEMS ) ) {
			$counts['skipped'] = max( 1, (int) ( $counts['skipped'] ?? 0 ) );
		}

		if ( JobStatus::isStatusFailure( $status ) ) {
			$counts['failed'] = max( 1, (int) ( $counts['failed'] ?? 0 ) );
		}

		foreach ( self::stepResults( $engine ) as $step_result ) {
			if ( 'fetch' === ( $step_result['step_type'] ?? '' ) && isset( $step_result['packet_count'] ) ) {
				$counts['fetch_packets'] = max( (int) ( $counts['fetch_packets'] ?? 0 ), (int) $step_result['packet_count'] );
			}
			if ( in_array( $step_result['result'] ?? '', array( 'no_content', 'completed_no_items' ), true ) ) {
				$counts['no_content'] = max( 1, (int) ( $counts['no_content'] ?? 0 ) );
			}
			if ( 'source_rejected' === ( $step_result['result'] ?? '' ) || ! empty( $step_result['source_rejection_reason'] ) ) {
				$counts['source_rejected'] = max( 1, (int) ( $counts['source_rejected'] ?? 0 ) );
			}
			foreach ( self::classesFromStepResult( $step_result, $status ) as $class ) {
				$counts[ $class ] = max( 1, (int) ( $counts[ $class ] ?? 0 ) );
			}
		}

		foreach ( self::classesFromStatus( $status ) as $class ) {
			$counts[ $class ] = max( 1, (int) ( $counts[ $class ] ?? 0 ) );
		}

		return $counts;
	}

	private static function outcomeDetails( array $job, array $engine, array $counts, array $outcome_classes ): array {
		$status             = (string) ( $job['status'] ?? '' );
		$job_status         = JobStatus::fromString( $status );
		$source_rejection   = is_array( $engine['source_rejection'] ?? null ) ? $engine['source_rejection'] : array();
		$is_source_rejected = ! empty( $counts['source_rejected'] ) || ! empty( $source_rejection ) || 'source-rejected' === $job_status->getReason();
		$class_counts       = array();
		foreach ( $outcome_classes as $class ) {
			$class_counts[ $class ] = (int) ( $counts[ $class ] ?? 0 );
		}

		return array_filter(
			array(
				'status'                  => $status,
				'base_status'             => $job_status->getBaseStatus(),
				'status_reason'           => $job_status->getReason(),
				'primary_class'           => $outcome_classes[0] ?? null,
				'classes'                 => $outcome_classes,
				'class_counts'            => $class_counts,
				'fetch_packet_count'      => (int) ( $counts['fetch_packets'] ?? 0 ),
				'no_content'              => ! empty( $counts['no_content'] ) || JobStatus::COMPLETED_NO_ITEMS === $job_status->getBaseStatus(),
				'source_rejected'         => $is_source_rejected,
				'source_rejection_reason' => $source_rejection['reason'] ?? ( $is_source_rejected ? $job_status->getReason() : null ),
				'handler_slug'            => self::firstStepField( $engine, 'handler_slug' ),
				'provider_id'             => self::firstStepField( $engine, 'provider_id' ),
				'tool_ids'                => self::mergedStepListField( $engine, 'tool_ids' ),
				'ai_diagnostic_reason'    => self::firstStepField( $engine, 'diagnostic_reason' ),
			),
			static fn( $value ) => null !== $value
		);
	}

	private static function outcomeClasses( array $job, array $engine, array $counts ): array {
		$status  = (string) ( $job['status'] ?? '' );
		$classes = self::classesFromStatus( $status );

		foreach ( self::stepResults( $engine ) as $step_result ) {
			$classes = array_merge( $classes, self::classesFromStepResult( $step_result, $status ) );
		}

		foreach ( array( 'true_empty_query', 'provider_error', 'hydration_failed', 'hydration_partial', 'ai_empty_packet', 'ai_required_handler_not_called', 'ai_handler_tool_failed', 'ai_empty_response', 'ai_completion_assertions_missing', 'missing_handler_packet', 'source_rejected', 'item_deferred' ) as $class ) {
			if ( ! empty( $counts[ $class ] ) ) {
				$classes[] = $class;
			}
		}

		return array_values( array_unique( array_filter( $classes ) ) );
	}

	private static function classesFromStepResult( array $step_result, string $status = '' ): array {
		$result  = (string) ( $step_result['result'] ?? '' );
		$reason  = self::outcomeReasonFrom( $step_result, $status );
		$classes = self::classesFromReason( $reason );
		if ( isset( $step_result['diagnostic_reason'] ) && is_scalar( $step_result['diagnostic_reason'] ) ) {
			$classes = array_merge( $classes, self::classesFromReason( (string) $step_result['diagnostic_reason'] ) );
		}

		if ( in_array( $result, array( 'no_content', 'completed_no_items' ), true ) ) {
			$classes[] = 'true_empty_query';
		}
		if ( 'source_rejected' === $result || ! empty( $step_result['source_rejection_reason'] ) ) {
			$classes[] = 'source_rejected';
		}
		if ( 'fetch' === ( $step_result['step_type'] ?? '' ) && 'failed' === $result ) {
			$classes[] = 'provider_error';
		}

		return array_values( array_unique( array_filter( $classes ) ) );
	}

	private static function classesFromStatus( string $status ): array {
		$job_status = JobStatus::fromString( $status );
		$classes    = self::classesFromReason( (string) $job_status->getReason() );

		if ( JobStatus::COMPLETED_NO_ITEMS === $job_status->getBaseStatus() ) {
			$classes[] = 'true_empty_query';
		}

		return array_values( array_unique( array_filter( $classes ) ) );
	}

	private static function classesFromReason( string $reason ): array {
		$reason = strtolower( str_replace( '-', '_', trim( $reason ) ) );
		if ( '' === $reason ) {
			return array();
		}

		$classes = array();
		if ( in_array( $reason, array( 'mcp_fetch_failed', 'auth_ref_resolution_failed', 'ai_provider_missing' ), true ) || str_contains( $reason, 'provider' ) ) {
			$classes[] = 'provider_error';
		}
		if ( 'missing_source_content' === $reason || str_contains( $reason, 'hydration_failed' ) ) {
			$classes[] = 'hydration_failed';
		}
		if ( str_contains( $reason, 'hydration_partial' ) ) {
			$classes[] = 'hydration_partial';
		}
		if ( 'empty_data_packet_returned' === $reason ) {
			$classes[] = 'ai_empty_packet';
		}
		if ( in_array( $reason, array( 'ai_required_handler_not_called', 'required_handler_tool_not_called' ), true ) ) {
			$classes[] = 'ai_required_handler_not_called';
		}
		if ( 'ai_handler_tool_failed' === $reason ) {
			$classes[] = 'ai_handler_tool_failed';
		}
		if ( 'ai_empty_response' === $reason ) {
			$classes[] = 'ai_empty_response';
		}
		if ( in_array( $reason, array( 'ai_completion_assertions_missing', 'completion_assertions_missing' ), true ) ) {
			$classes[] = 'ai_completion_assertions_missing';
		}
		if ( 'handler_requiring_step_missing_handler_packets' === $reason ) {
			$classes[] = 'missing_handler_packet';
		}
		if ( 'source_rejected' === $reason ) {
			$classes[] = 'source_rejected';
		}
		if ( 'item_deferred' === $reason ) {
			$classes[] = 'item_deferred';
		}

		return $classes;
	}

	private static function outcomeReasonFrom( array $step_result, string $status ): string {
		foreach ( array( 'reason', 'error', 'status' ) as $key ) {
			$value = $step_result[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				if ( 'status' === $key ) {
					return (string) JobStatus::fromString( (string) $value )->getReason();
				}
				return (string) $value;
			}
		}

		return (string) JobStatus::fromString( $status )->getReason();
	}

	private static function stepResults( array $engine ): array {
		$step_results = is_array( $engine[ self::STEP_RESULTS_KEY ] ?? null ) ? $engine[ self::STEP_RESULTS_KEY ] : array();
		return array_values( array_filter( $step_results, 'is_array' ) );
	}

	private static function runResult( array $engine, string $status ): array {
		$step_results = array();
		foreach ( self::stepResults( $engine ) as $result ) {
			if ( is_array( $result['step_result'] ?? null ) ) {
				$step_results[] = $result['step_result'];
			}
		}

		return RunResult::fromStepResults(
			$step_results,
			array(
				'status' => $status,
			)
		);
	}

	private static function firstStepField( array $engine, string $field ) {
		foreach ( self::stepResults( $engine ) as $result ) {
			if ( isset( $result[ $field ] ) && '' !== $result[ $field ] ) {
				return $result[ $field ];
			}
		}
		return null;
	}

	private static function mergedStepListField( array $engine, string $field ): array {
		$values = array();
		foreach ( self::stepResults( $engine ) as $result ) {
			$list = is_array( $result[ $field ] ?? null ) ? $result[ $field ] : array();
			foreach ( $list as $value ) {
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$values[] = (string) $value;
				}
			}
		}
		return array_values( array_unique( $values ) );
	}

	private static function sanitizeResult( array $result ): array {
		$clean = array();
		foreach ( $result as $key => $value ) {
			$key = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $key ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitizeList( $value );
			}
		}
		return $clean;
	}

	private static function sanitizeList( array $values ): array {
		$clean = array();
		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ is_int( $key ) ? $key : (string) $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$clean[ is_int( $key ) ? $key : (string) $key ] = self::sanitizeList( $value );
			}
		}
		return $clean;
	}

	private static function withRunResult( int $job_id, array $engine, ?string $status_override = null ): array {
		$job = self::jobForEnvelope( $job_id, $engine, $status_override );
		if ( null === $job ) {
			return $engine;
		}

		unset( $engine[ self::RUN_RESULT_KEY ] );
		$job['engine_data']             = $engine;
		$summary                        = self::fromJob( $job );
		$engine[ self::RUN_RESULT_KEY ] = RunResult::fromJobSummary( $job, $summary );

		return $engine;
	}

	private static function jobForEnvelope( int $job_id, array $engine, ?string $status_override = null ): ?array {
		global $wpdb;
		if ( $job_id <= 0 || ! is_object( $wpdb ) ) {
			return null;
		}

		$table = $wpdb->prefix . 'datamachine_jobs';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL -- Data Machine owns the jobs table and needs fresh job state for durable result envelopes.
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE job_id = %d LIMIT 1",
				$job_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL

		if ( ! is_array( $job ) ) {
			$job = is_array( $engine['job'] ?? null ) ? $engine['job'] : array( 'job_id' => $job_id );
		}

		if ( null !== $status_override ) {
			$job['status'] = $status_override;
		}

		$job['engine_data'] = $engine;

		return $job;
	}

	private static function childTotals( int $job_id ): array {
		$empty = array(
			'total'      => 0,
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'skipped'    => 0,
			'failed'     => 0,
		);

		if ( $job_id <= 0 ) {
			return $empty;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return $empty;
		}

		$table = $wpdb->prefix . 'datamachine_jobs';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
					SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
					SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
					SUM(CASE WHEN status LIKE %s OR status LIKE %s THEN 1 ELSE 0 END) AS skipped,
					SUM(CASE WHEN status LIKE %s THEN 1 ELSE 0 END) AS failed
				FROM %i
				WHERE parent_job_id = %d",
				$wpdb->esc_like( JobStatus::AGENT_SKIPPED ) . '%',
				$wpdb->esc_like( JobStatus::COMPLETED_NO_ITEMS ) . '%',
				$wpdb->esc_like( JobStatus::FAILED ) . '%',
				$table,
				$job_id
			),
			ARRAY_A
		);

		return array(
			'total'      => (int) ( $row['total'] ?? 0 ),
			'pending'    => (int) ( $row['pending'] ?? 0 ),
			'processing' => (int) ( $row['processing'] ?? 0 ),
			'completed'  => (int) ( $row['completed'] ?? 0 ),
			'skipped'    => (int) ( $row['skipped'] ?? 0 ),
			'failed'     => (int) ( $row['failed'] ?? 0 ),
		);
	}

	private static function tokenUsage( array $engine ): ?array {
		$usage = $engine['token_usage'] ?? ( $engine['usage'] ?? null );
		return is_array( $usage ) ? $usage : null;
	}

	private static function cost( array $engine ) {
		if ( isset( $engine['cost'] ) && is_numeric( $engine['cost'] ) ) {
			return (float) $engine['cost'];
		}
		if ( isset( $engine['usage']['cost'] ) && is_numeric( $engine['usage']['cost'] ) ) {
			return (float) $engine['usage']['cost'];
		}
		return null;
	}
}
