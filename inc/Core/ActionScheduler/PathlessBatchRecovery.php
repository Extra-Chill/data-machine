<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery requires fresh scheduler and child-job evidence.
/**
 * Pathless pipeline batch recovery.
 *
 * @package DataMachine\Core\ActionScheduler
 */

namespace DataMachine\Core\ActionScheduler;

use DataMachine\Core\Database\BatchItems\BatchItems;
use DataMachine\Core\EngineData;

defined( 'ABSPATH' ) || exit;

class PathlessBatchRecovery {
	private const CLAIM_TTL = 300;

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
		if ( self::hasActiveAction( $parent_job_id, $timeout_hours ) ) {
			return true;
		}

		global $wpdb;
		$jobs_table = $wpdb->prefix . 'datamachine_jobs';
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
	private static function hasActiveAction( int $parent_job_id, int $timeout_hours ): bool {
		global $wpdb;
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$like_parent   = '%"parent_job_id":' . $wpdb->esc_like( (string) $parent_job_id ) . '%';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WordPress prefix.
		$actions = $wpdb->get_results(
			$wpdb->prepare( "SELECT args, status, scheduled_date_gmt, last_attempt_gmt FROM {$actions_table} WHERE hook = %s AND status IN ( %s, %s ) AND args LIKE %s", 'datamachine_pipeline_batch_chunk', 'pending', 'in-progress', $like_parent )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$timeout_seconds = max( 1, $timeout_hours ) * HOUR_IN_SECONDS;
		$now_gmt         = strtotime( current_time( 'mysql', true ) );
		foreach ( $actions as $action ) {
			$args = json_decode( (string) ( $action->args ?? '' ), true );
			if ( $parent_job_id !== (int) ( $args['parent_job_id'] ?? 0 ) ) {
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
			do_action( 'datamachine_log', 'error', 'Pathless batch recovery scheduling failed', array( 'parent_job_id' => $parent_job_id, 'exception' => $exception->getMessage() ) );
		}

		$result = wp_schedule_single_event( time(), $hook, array( $parent_job_id, $offset ), true );
		return ! is_wp_error( $result ) && true === $result;
	}
}
