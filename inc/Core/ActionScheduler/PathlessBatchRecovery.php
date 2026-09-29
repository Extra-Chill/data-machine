<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery requires fresh scheduler and child-job evidence.
/**
 * Pathless pipeline batch recovery.
 *
 * @package DataMachine\Core\ActionScheduler
 */

namespace DataMachine\Core\ActionScheduler;

use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Core\Database\BatchItems\BatchItems;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\EngineData;
use DataMachine\Core\Jobs\SchedulerEvidence;

defined( 'ABSPATH' ) || exit;

class PathlessBatchRecovery {
	private const CLAIM_TTL         = 300;
	private const CHILD_QUERY_LIMIT = 100;

	/** Whether a v2 batch still has work that can be requeued. */
	public static function isRecoverable( array $engine_data ): bool {
		return BatchScheduler::STORAGE_VERSION === (int) ( $engine_data['batch_storage_version'] ?? 0 )
			&& empty( $engine_data['batch_state']['worklist_complete'] );
	}

	/** Check whether a batch parent still has scheduled chunk or child work. */
	public static function hasActiveWork( int $parent_job_id, array $engine_data, int $timeout_hours, ?SchedulerEvidence $evidence = null ): bool {
		return ! empty( self::diagnoseActiveWork( $parent_job_id, $engine_data, $timeout_hours, $evidence )['owned'] );
	}

	/** Describe the scheduler action or fresh child rows that currently own a batch. */
	public static function diagnoseActiveWork( int $parent_job_id, array $engine_data, int $timeout_hours, ?SchedulerEvidence $evidence = null ): array {
		$diagnosis = array(
			'owned'                => false,
			'chunk_action'         => false,
			'active_child_job_ids' => array(),
			'stale_child_job_ids'  => array(),
			'child_action_ids'     => array(),
			'evidence_complete'    => true,
		);
		if ( $parent_job_id <= 0 || empty( $engine_data['batch'] ) ) {
			return $diagnosis;
		}

		$evidence ??= SchedulerEvidence::load();
		if ( ! $evidence->isComplete() ) {
			// Absence of a live chunk or child action cannot be proven; refuse to infer it.
			$diagnosis['owned']             = true;
			$diagnosis['evidence_complete'] = false;
			return $diagnosis;
		}

		$now             = time();
		$timeout_seconds = max( 1, $timeout_hours ) * HOUR_IN_SECONDS;
		if ( ! empty( $evidence->liveActionIds( $parent_job_id, $now, $timeout_seconds, array( PipelineBatchScheduler::BATCH_HOOK ) ) ) ) {
			$diagnosis['owned']        = true;
			$diagnosis['chunk_action'] = true;
			return $diagnosis;
		}

		$diagnosis = self::diagnoseChildWork( $parent_job_id, $timeout_seconds, $now, $evidence );

		return array(
			'owned'                => ! $diagnosis['evidence_complete'] || ! empty( $diagnosis['active_job_ids'] ),
			'chunk_action'         => false,
			'active_child_job_ids' => $diagnosis['active_job_ids'],
			'stale_child_job_ids'  => $diagnosis['stale_job_ids'],
			'child_action_ids'     => $diagnosis['active_action_ids'],
			'evidence_complete'    => $diagnosis['evidence_complete'],
		);
	}

	/** Query child rows and read their active scheduler actions from the batch evidence snapshot. */
	public static function diagnoseChildWork( int $parent_job_id, int $timeout_seconds, int $now, ?SchedulerEvidence $evidence = null ): array {
		global $wpdb;
		$jobs_table = $wpdb->prefix . Jobs::TABLE_NAME;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WordPress prefix.
		$total_children = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE parent_job_id = %d", $parent_job_id )
		);
		$count_complete = null !== $total_children && '' === (string) $wpdb->last_error;
		$children       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, status, created_at
				 FROM {$jobs_table}
				 WHERE parent_job_id = %d AND status IN ( %s, %s )
				 ORDER BY job_id ASC
				 LIMIT %d",
				$parent_job_id,
				'pending',
				'processing',
				self::CHILD_QUERY_LIMIT + 1
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$children_complete = is_array( $children )
			&& '' === (string) $wpdb->last_error
			&& count( $children ) <= self::CHILD_QUERY_LIMIT;
		$children          = is_array( $children ) ? array_slice( $children, 0, self::CHILD_QUERY_LIMIT ) : array();
		if ( ! $count_complete || ! $children_complete ) {
			$diagnosis                   = self::diagnoseChildRows( $children, $timeout_seconds, $now, array(), false );
			$diagnosis['total_children'] = $count_complete ? (int) $total_children : count( $children );
			return $diagnosis;
		}

		$initial                   = self::diagnoseChildRows( $children, $timeout_seconds, $now );
		$initial['total_children'] = (int) $total_children;
		$stale_job_ids             = $initial['stale_job_ids'];
		if ( empty( $stale_job_ids ) ) {
			return $initial;
		}

		$evidence ??= SchedulerEvidence::load();
		$actions    = array();
		if ( $evidence->isComplete() ) {
			foreach ( $stale_job_ids as $stale_job_id ) {
				$actions = array_merge( $actions, $evidence->actionsFor( $stale_job_id, SchedulerEvidence::stepHooks() ) );
			}
		}

		$diagnosis                   = self::diagnoseChildRows( $children, $timeout_seconds, $now, $actions, $evidence->isComplete() );
		$diagnosis['total_children'] = (int) $total_children;
		return $diagnosis;
	}

	/**
	 * Apply the shared child-row ownership grace used by recovery and liveness.
	 *
	 * A fresh pending/processing row protects the create-and-schedule race. Once
	 * the timeout passes, only actual scheduler action evidence may own work.
	 */
	public static function diagnoseChildRows( array $children, int $timeout_seconds, int $now, array $actions = array(), bool $evidence_complete = true ): array {
		$active_job_ids = array();
		$stale_job_ids  = array();
		foreach ( $children as $child ) {
			$job_id     = (int) ( $child['job_id'] ?? 0 );
			$created_at = strtotime( (string) ( $child['created_at'] ?? '' ) . ' UTC' );
			if ( $job_id <= 0 || ! in_array( (string) ( $child['status'] ?? '' ), array( 'pending', 'processing' ), true ) ) {
				continue;
			}
			if ( false === $created_at || ( $now - $created_at ) < max( 1, $timeout_seconds ) ) {
				$active_job_ids[] = $job_id;
			} else {
				$stale_job_ids[] = $job_id;
			}
		}
		$active_action_ids = array();
		$action_job_ids    = array();
		foreach ( $actions as $action ) {
			$action = is_object( $action ) ? get_object_vars( $action ) : $action;
			$args   = json_decode( (string) ( $action['args'] ?? '' ), true );
			$job_id = is_array( $args ) && isset( $args['job_id'] ) && is_numeric( $args['job_id'] ) ? (int) $args['job_id'] : 0;
			if ( $job_id <= 0 || ! in_array( $job_id, $stale_job_ids, true ) ) {
				$evidence_complete = false;
				continue;
			}
			$status = (string) ( $action['status'] ?? '' );
			if ( 'pending' !== $status && 'in-progress' !== $status ) {
				continue;
			}
			$last_attempt = (string) ( $action['last_attempt_gmt'] ?? '' );
			$scheduled    = (string) ( $action['scheduled_date_gmt'] ?? '' );
			$reference    = '' !== $last_attempt && '0000-00-00 00:00:00' !== $last_attempt ? $last_attempt : $scheduled;
			$started_at   = strtotime( $reference . ' UTC' );
			if ( 'pending' === $status || false === $started_at || ( $now - $started_at ) < max( 1, $timeout_seconds ) ) {
				$active_action_ids[] = (int) ( $action['action_id'] ?? 0 );
				$action_job_ids[]    = $job_id;
			}
		}

		if ( ! $evidence_complete ) {
			$action_job_ids = $stale_job_ids;
		}
		$active_job_ids = array_values( array_unique( array_merge( $active_job_ids, $action_job_ids ) ) );
		$stale_job_ids  = array_values( array_diff( $stale_job_ids, $action_job_ids ) );

		return array(
			'total_children'    => count( $children ),
			'active_job_ids'    => $active_job_ids,
			'stale_job_ids'     => $stale_job_ids,
			'active_action_ids' => array_values( array_filter( array_unique( $active_action_ids ) ) ),
			'evidence_complete' => $evidence_complete,
		);
	}

	/** Re-establish one scheduler path for a durable pathless v2 batch. */
	public static function recover( int $parent_job_id ): bool {
		$engine = EngineData::retrieve( $parent_job_id );
		$state  = is_array( $engine['batch_state'] ?? null ) ? $engine['batch_state'] : array();
		$hook   = (string) ( $state['hook'] ?? $engine['batch_hook'] ?? '' );
		if ( ! self::isRecoverable( $engine ) || '' === $hook ) {
			return false;
		}

		$offset = ( new BatchItems() )->first_outstanding_index( $parent_job_id );
		if ( null === $offset ) {
			return false;
		}

		$token = bin2hex( random_bytes( 16 ) );
		$claim = EngineData::mutate(
			$parent_job_id,
			static function ( array $current ) use ( $token ): ?array {
				$owner            = is_array( $current['batch_recovery_owner'] ?? null ) ? $current['batch_recovery_owner'] : array();
				$claimed_at_value = (string) ( $owner['claimed_at'] ?? '' );
				$claimed_at       = '' !== $claimed_at_value ? strtotime( $claimed_at_value . ' UTC' ) : false;
				if ( false !== $claimed_at && ( time() - $claimed_at ) < self::CLAIM_TTL ) {
					return null;
				}
				$current['batch_recovery_owner'] = array(
					'token'      => $token,
					'claimed_at' => current_time( 'mysql', true ),
				);
				return $current;
			},
			'batch_recovery_claim'
		);
		if ( empty( $claim['success'] ) || ! self::schedule( $hook, $parent_job_id, $offset ) ) {
			return false;
		}

		EngineData::mutate(
			$parent_job_id,
			static function ( array $current ) use ( $token ): array {
				if ( hash_equals( $token, (string) ( $current['batch_recovery_owner']['token'] ?? '' ) ) ) {
					$current['batch_recovery_owner']['scheduled_at'] = current_time( 'mysql', true );
				}
				return $current;
			},
			'batch_recovery_scheduled'
		);
		return true;
	}

	/** Schedule a recovered chunk through the exact v2 chunk identity. */
	private static function schedule( string $hook, int $parent_job_id, int $offset ): bool {
		return BatchScheduler::scheduleChunk( $hook, $parent_job_id, $offset, time() );
	}
}
