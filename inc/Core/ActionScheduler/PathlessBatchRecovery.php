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
use DataMachine\Core\EngineData;

defined( 'ABSPATH' ) || exit;

class PathlessBatchRecovery {
	private const CLAIM_TTL          = 300;
	private const ACTION_QUERY_LIMIT = 100;

	/** Whether a v2 batch still has work that can be requeued. */
	public static function isRecoverable( array $engine_data ): bool {
		return BatchScheduler::STORAGE_VERSION === (int) ( $engine_data['batch_storage_version'] ?? 0 )
			&& empty( $engine_data['batch_state']['worklist_complete'] );
	}

	/** Check whether a batch parent still has scheduled chunk or child work. */
	public static function hasActiveWork( int $parent_job_id, array $engine_data, int $timeout_hours ): bool {
		if ( $parent_job_id <= 0 || empty( $engine_data['batch'] ) ) {
			return false;
		}
		if ( self::hasActiveAction( $parent_job_id, $engine_data, $timeout_hours ) ) {
			return true;
		}

		global $wpdb;
		$jobs_table = $wpdb->prefix . Jobs::TABLE_NAME;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WordPress prefix.
		$active_children = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE parent_job_id = %d AND status IN ( %s, %s )", $parent_job_id, 'pending', 'processing' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $active_children > 0;
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
