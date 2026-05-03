<?php
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

class RunMetrics {

	private const KEY = 'run_metrics';

	private const COUNT_KEYS = array(
		'processed',
		'skipped',
		'failed',
		'retried',
		'scheduled',
		'staged_actions',
		'accepted_actions',
		'rejected_actions',
	);

	public static function start( int $job_id, array $context = array() ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		$engine  = EngineData::retrieve( $job_id );
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
		return EngineData::persist( $job_id, $engine );
	}

	public static function increment( int $job_id, string $key, int $amount = 1 ): bool {
		if ( $job_id <= 0 || $amount <= 0 ) {
			return false;
		}

		$engine  = EngineData::retrieve( $job_id );
		$metrics = self::normalize( $engine[ self::KEY ] ?? array() );

		if ( ! isset( $metrics['counts'][ $key ] ) ) {
			$metrics['counts'][ $key ] = 0;
		}

		$metrics['counts'][ $key ] = max( 0, (int) $metrics['counts'][ $key ] + $amount );
		$metrics['last_activity_at'] = self::now();

		$engine[ self::KEY ] = $metrics;
		return EngineData::persist( $job_id, $engine );
	}

	public static function complete( int $job_id, string $status ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		$engine  = EngineData::retrieve( $job_id );
		$metrics = self::normalize( $engine[ self::KEY ] ?? array() );
		$now     = self::now();

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
		return EngineData::persist( $job_id, $engine );
	}

	public static function fromJob( array $job ): array {
		$engine  = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$metrics = self::normalize( $engine[ self::KEY ] ?? array() );
		$status  = (string) ( $job['status'] ?? '' );

		$started_at = $metrics['started_at'] ?: ( $engine['started_at'] ?? ( $job['created_at'] ?? null ) );
		$ended_at   = $metrics['completed_at'] ?: ( $job['completed_at'] ?? null );
		$last       = $metrics['last_activity_at'] ?: ( $ended_at ?: $started_at );
		$counts     = $metrics['counts'];

		foreach ( self::inferCountsFromEngine( $engine, $status ) as $key => $value ) {
			$counts[ $key ] = max( (int) ( $counts[ $key ] ?? 0 ), (int) $value );
		}

		return array(
			'job_id'           => (int) ( $job['job_id'] ?? 0 ),
			'source'           => $job['source'] ?? null,
			'label'            => $job['label'] ?? ( $job['display_label'] ?? null ),
			'flow_id'          => $job['flow_id'] ?? null,
			'pipeline_id'      => $job['pipeline_id'] ?? null,
			'parent_job_id'    => isset( $job['parent_job_id'] ) ? (int) $job['parent_job_id'] : 0,
			'status'           => $status,
			'counts'           => $counts,
			'child_jobs'       => self::childTotals( (int) ( $job['job_id'] ?? 0 ) ),
			'timestamps'       => array(
				'created_at'       => $job['created_at'] ?? null,
				'started_at'       => $started_at,
				'last_activity_at' => $last,
				'completed_at'     => $ended_at,
			),
			'duration_seconds' => self::durationSeconds( $started_at, $ended_at ?: self::now() ),
			'context'          => $metrics['context'],
			'token_usage'      => self::tokenUsage( $engine ),
			'cost'             => self::cost( $engine ),
		);
	}

	public static function normalize( $metrics ): array {
		$metrics = is_array( $metrics ) ? $metrics : array();
		$counts  = is_array( $metrics['counts'] ?? null ) ? $metrics['counts'] : array();

		foreach ( self::COUNT_KEYS as $key ) {
			$counts[ $key ] = max( 0, (int) ( $counts[ $key ] ?? 0 ) );
		}

		return array(
			'counts'            => $counts,
			'started_at'        => isset( $metrics['started_at'] ) ? (string) $metrics['started_at'] : null,
			'last_activity_at'  => isset( $metrics['last_activity_at'] ) ? (string) $metrics['last_activity_at'] : null,
			'completed_at'      => isset( $metrics['completed_at'] ) ? (string) $metrics['completed_at'] : null,
			'duration_seconds'  => isset( $metrics['duration_seconds'] ) ? max( 0, (int) $metrics['duration_seconds'] ) : null,
			'terminal_status'   => isset( $metrics['terminal_status'] ) ? (string) $metrics['terminal_status'] : null,
			'context'           => is_array( $metrics['context'] ?? null ) ? $metrics['context'] : array(),
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
			$counts['processed'] = (int) ( $engine['batch_results']['completed'] ?? 0 );
			$counts['failed']    = (int) ( $engine['batch_results']['failed'] ?? 0 );
			$counts['skipped']   = (int) ( $engine['batch_results']['skipped'] ?? 0 );
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

		return $counts;
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
					SUM(CASE WHEN status LIKE 'agent_skipped%%' OR status LIKE 'completed_no_items%%' THEN 1 ELSE 0 END) AS skipped,
					SUM(CASE WHEN status LIKE 'failed%%' THEN 1 ELSE 0 END) AS failed
				FROM {$table}
				WHERE parent_job_id = %d",
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
