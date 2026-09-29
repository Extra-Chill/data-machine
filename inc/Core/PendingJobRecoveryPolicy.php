<?php
/**
 * Pending-job orphan recovery policy.
 *
 * Pure verdict logic for `pending` job rows that no live Action Scheduler
 * action can advance. `jobs.status` is lifecycle, not liveness: a pending row
 * whose scheduler action was never created (enqueue_failed), was pruned
 * (enqueued), or was interrupted mid-enqueue (preparing/enqueuing) is a
 * phantom that still counts against the flow-start admission ceiling.
 *
 * @package DataMachine\Core
 * @since 0.180.0
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

class PendingJobRecoveryPolicy {

	/** Default age before a pending row is considered for recovery. */
	public const DEFAULT_GRACE_SECONDS = HOUR_IN_SECONDS;

	/** Margin over the enqueue claim lease before a preparing/enqueuing claim is treated as abandoned. */
	public const ENQUEUE_LEASE_SECONDS = 300;

	public const OPERATION_STATE_PREPARING     = 'preparing';
	public const OPERATION_STATE_ENQUEUING     = 'enqueuing';
	public const OPERATION_STATE_ENQUEUED      = 'enqueued';
	public const OPERATION_STATE_ENQUEUE_FAILED = 'enqueue_failed';

	/** Verdicts double as the terminal failure reason recorded on the job. */
	public const VERDICT_ENQUEUE_FAILED      = 'enqueue_failed';
	public const VERDICT_EVIDENCE_PRUNED     = 'evidence_pruned';
	public const VERDICT_ENQUEUE_INTERRUPTED = 'enqueue_interrupted';
	public const VERDICT_ORPHANED_PENDING    = 'orphaned_pending';

	public const SKIP_NOT_PENDING        = 'not_pending';
	public const SKIP_WITHIN_GRACE       = 'within_grace';
	public const SKIP_BATCH_PARENT       = 'batch_parent';
	public const SKIP_RETRY_SCHEDULED    = 'retry_scheduled';
	public const SKIP_AI_THROTTLE_ACTIVE = 'ai_throttle_scheduled';
	public const SKIP_LEASE_ACTIVE       = 'enqueue_lease_active';
	public const SKIP_LIVE_ACTION        = 'live_scheduler_action';
	public const SKIP_UNKNOWN_STATE      = 'unrecognized_operation_state';

	/**
	 * All verdicts, in reporting order.
	 *
	 * @return array<int,string>
	 */
	public static function verdicts(): array {
		return array(
			self::VERDICT_ENQUEUE_FAILED,
			self::VERDICT_EVIDENCE_PRUNED,
			self::VERDICT_ENQUEUE_INTERRUPTED,
			self::VERDICT_ORPHANED_PENDING,
		);
	}

	/**
	 * Diagnose one pending job.
	 *
	 * @param array<string,mixed> $job              Job row (status, created_at, operation_state, operation_claimed_at).
	 * @param array<string,mixed> $engine_data      Decoded engine_data.
	 * @param array<int,int>      $live_action_ids  Live (pending / fresh in-progress) scheduler action IDs for this job.
	 * @param int                 $now              Current unix time.
	 * @param int                 $grace_seconds    Minimum row age.
	 * @return array{verdict:string,skip:string,operation_state:string}
	 */
	public static function diagnose( array $job, array $engine_data, array $live_action_ids, int $now, int $grace_seconds = self::DEFAULT_GRACE_SECONDS ): array {
		$operation_state = (string) ( $job['operation_state'] ?? '' );
		$result          = static fn( string $verdict, string $skip ): array => array(
			'verdict'         => $verdict,
			'skip'            => $skip,
			'operation_state' => $operation_state,
		);

		if ( JobStatus::PENDING !== (string) ( $job['status'] ?? '' ) ) {
			return $result( '', self::SKIP_NOT_PENDING );
		}

		$created = strtotime( (string) ( $job['created_at'] ?? '' ) . ' UTC' );
		if ( false === $created || ( $now - $created ) < max( 0, $grace_seconds ) ) {
			return $result( '', self::SKIP_WITHIN_GRACE );
		}

		if ( ! empty( $engine_data['batch'] ) ) {
			return $result( '', self::SKIP_BATCH_PARENT );
		}

		$retry = is_array( $engine_data['retry'] ?? null ) ? $engine_data['retry'] : array();
		if ( self::isFuture( $retry['next_retry_at'] ?? null, $now ) ) {
			return $result( '', self::SKIP_RETRY_SCHEDULED );
		}

		$throttle = is_array( $engine_data['ai_concurrency_throttle'] ?? null ) ? $engine_data['ai_concurrency_throttle'] : array();
		if ( self::isFuture( $throttle['next_retry_at'] ?? null, $now ) ) {
			return $result( '', self::SKIP_AI_THROTTLE_ACTIVE );
		}

		if ( in_array( $operation_state, array( self::OPERATION_STATE_PREPARING, self::OPERATION_STATE_ENQUEUING ), true ) ) {
			$claimed_raw = (string) ( $job['operation_claimed_at'] ?? '' );
			$claimed_at  = '' === $claimed_raw ? false : strtotime( $claimed_raw . ' UTC' );
			if ( false !== $claimed_at && ( $now - $claimed_at ) < self::ENQUEUE_LEASE_SECONDS ) {
				return $result( '', self::SKIP_LEASE_ACTIVE );
			}
		}

		if ( ! empty( $live_action_ids ) ) {
			return $result( '', self::SKIP_LIVE_ACTION );
		}

		switch ( $operation_state ) {
			case self::OPERATION_STATE_ENQUEUE_FAILED:
				return $result( self::VERDICT_ENQUEUE_FAILED, '' );
			case self::OPERATION_STATE_ENQUEUED:
				return $result( self::VERDICT_EVIDENCE_PRUNED, '' );
			case self::OPERATION_STATE_PREPARING:
			case self::OPERATION_STATE_ENQUEUING:
				return $result( self::VERDICT_ENQUEUE_INTERRUPTED, '' );
			case '':
				return $result( self::VERDICT_ORPHANED_PENDING, '' );
		}

		return $result( '', self::SKIP_UNKNOWN_STATE );
	}

	/**
	 * Terminal job status string for a verdict.
	 *
	 * @param string $verdict Verdict constant.
	 * @return string Canonical `failed - <reason>` status.
	 */
	public static function terminalStatus( string $verdict ): string {
		return JobStatus::failed( $verdict )->toString();
	}

	/** Whether a timestamp value is strictly after now. */
	private static function isFuture( mixed $value, int $now ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		$timestamp = strtotime( $value );
		return false !== $timestamp && $timestamp > $now;
	}
}
