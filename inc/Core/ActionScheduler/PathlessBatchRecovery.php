<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery requires fresh scheduler and child-job evidence.
/**
 * Pathless pipeline batch recovery.
 *
 * @package DataMachine\Core\ActionScheduler
 */

namespace DataMachine\Core\ActionScheduler;

use DataMachine\Core\Database\BatchItems\BatchItems;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DirectJobEnqueuer;
use DataMachine\Core\EngineData;
use DataMachine\Engine\AI\AIConcurrencyBackpressure;

defined( 'ABSPATH' ) || exit;

class PathlessBatchRecovery {
	private const CLAIM_TTL          = 300;
	private const ACTION_QUERY_LIMIT = 100;
	private const CHILD_QUERY_LIMIT  = 100;

	/** Whether a v2 batch still has work that can be requeued. */
	public static function isRecoverable( array $engine_data ): bool {
		return BatchScheduler::STORAGE_VERSION === (int) ( $engine_data['batch_storage_version'] ?? 0 )
			&& empty( $engine_data['batch_state']['worklist_complete'] );
	}

	/** Check whether a batch parent still has scheduled chunk or child work. */
	public static function hasActiveWork( int $parent_job_id, array $engine_data, int $timeout_hours ): bool {
		return ! empty( self::diagnoseActiveWork( $parent_job_id, $engine_data, $timeout_hours )['owned'] );
	}

	/** Describe the scheduler action or fresh child rows that currently own a batch. */
	public static function diagnoseActiveWork( int $parent_job_id, array $engine_data, int $timeout_hours ): array {
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
		if ( self::hasActiveAction( $parent_job_id, $engine_data, $timeout_hours ) ) {
			$diagnosis['owned']        = true;
			$diagnosis['chunk_action'] = true;
			return $diagnosis;
		}

		$diagnosis = self::diagnoseChildWork( $parent_job_id, max( 1, $timeout_hours ) * HOUR_IN_SECONDS, time() );

		return array(
			'owned'                => ! $diagnosis['evidence_complete'] || ! empty( $diagnosis['active_job_ids'] ),
			'chunk_action'         => false,
			'active_child_job_ids' => $diagnosis['active_job_ids'],
			'stale_child_job_ids'  => $diagnosis['stale_job_ids'],
			'child_action_ids'     => $diagnosis['active_action_ids'],
			'evidence_complete'    => $diagnosis['evidence_complete'],
		);
	}

	/** Query child rows and their active scheduler actions without N+1 scans. */
	public static function diagnoseChildWork( int $parent_job_id, int $timeout_seconds, int $now ): array {
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

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$clauses       = array();
		$query_args    = array( DirectJobEnqueuer::HOOK, AIConcurrencyBackpressure::RESUME_HOOK, 'pending', 'in-progress' );
		foreach ( $stale_job_ids as $job_id ) {
			$clauses[]    = '(args LIKE %s OR args LIKE %s)';
			$query_args[] = '%"job_id":' . $wpdb->esc_like( (string) $job_id ) . ',%';
			$query_args[] = '%"job_id":' . $wpdb->esc_like( (string) $job_id ) . '}%';
		}
		$query_args[] = self::ACTION_QUERY_LIMIT + 1;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name and placeholder clauses are generated above; values remain prepared.
		$actions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, args, status, scheduled_date_gmt, last_attempt_gmt
				 FROM {$actions_table}
				 WHERE hook IN ( %s, %s )
				 AND status IN ( %s, %s )
				 AND (" . implode( ' OR ', $clauses ) . ') ORDER BY action_id DESC LIMIT %d',
				$query_args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$complete = is_array( $actions )
			&& '' === (string) $wpdb->last_error
			&& count( $actions ) <= self::ACTION_QUERY_LIMIT;

		$diagnosis                   = self::diagnoseChildRows(
			$children,
			$timeout_seconds,
			$now,
			is_array( $actions ) ? array_slice( $actions, 0, self::ACTION_QUERY_LIMIT ) : array(),
			$complete
		);
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
			'active_job_ids'   => $active_job_ids,
			'stale_job_ids'    => $stale_job_ids,
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
				$owner      = is_array( $current['batch_recovery_owner'] ?? null ) ? $current['batch_recovery_owner'] : array();
				$claimed_at = strtotime( (string) ( $owner['claimed_at'] ?? '' ) . ' UTC' );
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

	/** Check exact pending or fresh in-progress chunk actions for one parent. */
	private static function hasActiveAction( int $parent_job_id, array $engine_data, int $timeout_hours ): bool {
		global $wpdb;
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$state         = is_array( $engine_data['batch_state'] ?? null ) ? $engine_data['batch_state'] : array();
		$offset        = (int) ( $state['offset'] ?? $engine_data['batch_offset'] ?? 0 );
		$canonical     = wp_json_encode(
			array(
				'parent_job_id' => $parent_job_id,
				'offset'        => $offset,
			)
		);
		$query_limit   = self::ACTION_QUERY_LIMIT + 1;

		// Current producers have a complete identity in Action Scheduler's indexed args column.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WordPress prefix.
		$actions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT args, status, scheduled_date_gmt, last_attempt_gmt FROM {$actions_table} WHERE args = %s AND hook = %s AND status IN ( %s, %s ) ORDER BY action_id DESC LIMIT %d",
				$canonical,
				'datamachine_pipeline_batch_chunk',
				'pending',
				'in-progress',
				$query_limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$timeout_seconds = max( 1, $timeout_hours ) * HOUR_IN_SECONDS;
		$now_gmt         = strtotime( current_time( 'mysql', true ) );
		if ( self::boundedEvidenceBlocksRecovery( $actions, $parent_job_id, $timeout_seconds, $now_gmt ) ) {
			return true;
		}

		// Historical argument shapes cannot use the exact key. Inspect a bounded,
		// index-ordered window per status and refuse to infer absence if truncated.
		foreach ( array( 'pending', 'in-progress' ) as $status ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WordPress prefix.
			$actions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT args, status, scheduled_date_gmt, last_attempt_gmt FROM {$actions_table} WHERE hook = %s AND status = %s ORDER BY scheduled_date_gmt DESC LIMIT %d",
					'datamachine_pipeline_batch_chunk',
					$status,
					$query_limit
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( self::boundedEvidenceBlocksRecovery( $actions, $parent_job_id, $timeout_seconds, $now_gmt ) ) {
				return true;
			}
		}
		return false;
	}

	/** Treat active, truncated, or failed scheduler evidence as blocking recovery. */
	private static function boundedEvidenceBlocksRecovery( mixed $actions, int $parent_job_id, int $timeout_seconds, int|false $now_gmt ): bool {
		global $wpdb;
		if ( ! is_array( $actions ) || '' !== (string) $wpdb->last_error ) {
			return true;
		}
		return self::containsActiveAction( array_slice( $actions, 0, self::ACTION_QUERY_LIMIT ), $parent_job_id, $timeout_seconds, $now_gmt )
			|| count( $actions ) > self::ACTION_QUERY_LIMIT;
	}

	/** Check exact parent matches in one bounded scheduler result. */
	private static function containsActiveAction( array $actions, int $parent_job_id, int $timeout_seconds, int|false $now_gmt ): bool {
		foreach ( $actions as $action ) {
			if ( ! hash_equals( (string) $parent_job_id, (string) self::extractParentJobId( (string) ( $action->args ?? '' ) ) ) ) {
				continue;
			}
			if ( 'pending' === (string) $action->status ) {
				return true;
			}
			$last_attempt = (string) ( $action->last_attempt_gmt ?? '' );
			$scheduled    = (string) ( $action->scheduled_date_gmt ?? '' );
			$reference    = $last_attempt && '0000-00-00 00:00:00' !== $last_attempt ? $last_attempt : $scheduled;
			$started_at   = $reference ? strtotime( $reference ) : false;
			if ( false === $started_at || false === $now_gmt || ( $now_gmt - $started_at ) < $timeout_seconds ) {
				return true;
			}
		}
		return false;
	}

	/** Extract a parent ID from keyed, nested, JSON, or serialized action args. */
	private static function extractParentJobId( string $args ): int {
		$decoded = json_decode( $args, true );
		if ( is_array( $decoded ) ) {
			$parent_job_id = self::extractParentJobIdFromArray( $decoded );
			if ( 0 !== $parent_job_id ) {
				return $parent_job_id;
			}
		}

		$unserialized = maybe_unserialize( $args );
		return is_array( $unserialized ) ? self::extractParentJobIdFromArray( $unserialized ) : 0;
	}

	/** Extract a parent ID from keyed or one-level nested action arguments. */
	private static function extractParentJobIdFromArray( array $args ): int {
		if ( isset( $args['parent_job_id'] ) && is_numeric( $args['parent_job_id'] ) ) {
			return (int) $args['parent_job_id'];
		}
		foreach ( $args as $value ) {
			if ( is_array( $value ) && isset( $value['parent_job_id'] ) && is_numeric( $value['parent_job_id'] ) ) {
				return (int) $value['parent_job_id'];
			}
		}
		return 0;
	}

	/** Schedule a recovered chunk without scanning the scheduler table. */
	private static function schedule( string $hook, int $parent_job_id, int $offset ): bool {
		$args = array(
			'parent_job_id' => $parent_job_id,
			'offset'        => $offset,
		);
		try {
			if ( as_schedule_single_action( time(), $hook, $args, GroupRegistrar::GROUP ) ) {
				return true;
			}
		} catch ( \Throwable $exception ) {
			do_action(
				'datamachine_log',
				'error',
				'Pathless batch recovery scheduling failed',
				array(
					'parent_job_id' => $parent_job_id,
					'exception'     => $exception->getMessage(),
				)
			);
		}

		$result = wp_schedule_single_event( time(), $hook, array( $parent_job_id, $offset ), true );
		return ! is_wp_error( $result ) && true === $result;
	}
}
