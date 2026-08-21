<?php
/** Durable bounded-deferral behavior for the processed-item ledger. */

namespace DataMachine\Core\Database\ProcessedItems;

use DataMachine\Core\Database\TransactionScope;

defined( 'ABSPATH' ) || exit;

trait ProcessedItemDeferrals {
	/** Persist a job-idempotent paid deferral attempt for the exact owned claim. */
	public function record_owned_deferral_attempt( array $claim, int $job_id ): array|false {
		if ( $job_id <= 0 ) {
			return false;
		}
		$scope = TransactionScope::begin( $this->wpdb );
		if ( null === $scope ) {
			return false;
		}
		$query = $this->wpdb->prepare(
			'SELECT deferral_count, last_deferral_job_id FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s FOR UPDATE',
			$this->table_name,
			(string) ( $claim['identity_scope'] ?? '' ),
			(string) ( $claim['source_type'] ?? '' ),
			(string) ( $claim['item_identifier'] ?? '' ),
			(string) ( $claim['ownership_token'] ?? '' ),
			self::STATUS_CLAIMED
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact token-owned row lock.
		$row = $this->wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) ) {
			$scope->rollback();
			return false;
		}
		$attempts = min( self::MAX_DEFERRAL_ATTEMPTS, max( 0, (int) ( $row['deferral_count'] ?? 0 ) ) );
		if ( (int) ( $row['last_deferral_job_id'] ?? 0 ) !== $job_id ) {
			$attempts = min( self::MAX_DEFERRAL_ATTEMPTS, $attempts + 1 );
			$updated = $this->wpdb->update(
				$this->table_name,
				array(
					'deferral_count'       => $attempts,
					'last_deferral_job_id' => $job_id,
				),
				array(
					'flow_step_id'    => $claim['identity_scope'],
					'source_type'     => $claim['source_type'],
					'item_identifier' => $claim['item_identifier'],
					'claim_token'     => $claim['ownership_token'],
					'status'          => self::STATUS_CLAIMED,
				),
				array( '%d', '%d' ),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
			if ( 1 !== $updated ) {
				$scope->rollback();
				return false;
			}
		}
		if ( ! $scope->commit() ) {
			$scope->rollback();
			return false;
		}
		return array(
			'attempts'  => $attempts,
			'exhausted' => $attempts >= self::MAX_DEFERRAL_ATTEMPTS,
		);
	}

	/** Finalize the paid attempt while the caller owns the surrounding transaction. */
	public function finalize_owned_deferral_in_transaction( array $claim, int $job_id, ?callable $completion = null ): array|false {
		$query = $this->wpdb->prepare(
			'SELECT deferral_count, last_deferral_job_id FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s FOR UPDATE',
			$this->table_name,
			(string) ( $claim['identity_scope'] ?? '' ),
			(string) ( $claim['source_type'] ?? '' ),
			(string) ( $claim['item_identifier'] ?? '' ),
			(string) ( $claim['ownership_token'] ?? '' ),
			self::STATUS_CLAIMED
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Caller owns the transaction.
		$row = $this->wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return false;
		}
		$attempts  = min( self::MAX_DEFERRAL_ATTEMPTS, max( 0, (int) $row['deferral_count'] ) );
		$exhausted = $attempts >= self::MAX_DEFERRAL_ATTEMPTS;
		if ( ! $exhausted && (int) ( $row['last_deferral_job_id'] ?? 0 ) !== $job_id ) {
			return false;
		}
		if ( $exhausted && null !== $completion ) {
			try {
				if ( true !== $completion() ) {
					return false;
				}
			} catch ( \Throwable $exception ) {
				do_action( 'datamachine_log', 'error', 'Deferred item completion callback failed.', array( 'exception' => $exception->getMessage() ) );
				return false;
			}
		}
		$now     = current_time( 'mysql', true );
		$updated = $this->wpdb->update(
			$this->table_name,
			array(
				'job_id'              => $job_id,
				'status'              => $exhausted ? self::STATUS_PROCESSED : self::STATUS_DEFERRED,
				'processed_timestamp' => $now,
				'deferred_at'         => $now,
				'claim_expires_at'    => null,
				'claim_token'         => null,
			),
			array(
				'flow_step_id'    => $claim['identity_scope'],
				'source_type'     => $claim['source_type'],
				'item_identifier' => $claim['item_identifier'],
				'claim_token'     => $claim['ownership_token'],
				'status'          => self::STATUS_CLAIMED,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return 1 === $updated
			? array(
				'attempts'  => $attempts,
				'exhausted' => $exhausted,
			)
			: false;
	}

	/** Read bounded deferral state for an exact claim already locked by the caller. */
	public function owned_deferral_state_in_transaction( array $claim ): array|false {
		$query = $this->wpdb->prepare(
			'SELECT deferral_count FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s',
			$this->table_name,
			(string) ( $claim['identity_scope'] ?? '' ),
			(string) ( $claim['source_type'] ?? '' ),
			(string) ( $claim['item_identifier'] ?? '' ),
			(string) ( $claim['ownership_token'] ?? '' ),
			self::STATUS_CLAIMED
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Caller already locked the exact token-owned row.
		$row = $this->wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return false;
		}
		$attempts = min( self::MAX_DEFERRAL_ATTEMPTS, max( 0, (int) ( $row['deferral_count'] ?? 0 ) ) );
		return array(
			'attempts'  => $attempts,
			'exhausted' => $attempts >= self::MAX_DEFERRAL_ATTEMPTS,
		);
	}

	/** Return one bounded page of stale deferred identities. */
	public function find_stale_deferrals( int $max_age_hours, int $limit = 100, int $after_id = 0 ): array {
		if ( $max_age_hours < 1 || $limit < 1 || $after_id < 0 ) {
			return array(
				'items'         => array(),
				'has_more'      => false,
				'next_after_id' => 0,
			);
		}
		$limit       = min( 500, $limit );
		$query_limit = $limit + 1;
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_hours * HOUR_IN_SECONDS ) );
		$query       = $this->wpdb->prepare(
			'SELECT id, flow_step_id, source_type, item_identifier, job_id, deferral_count, deferred_at, last_seen_at FROM %i WHERE status = %s AND deferred_at < %s AND id > %d ORDER BY id ASC LIMIT %d',
			$this->table_name,
			self::STATUS_DEFERRED,
			$cutoff,
			$after_id,
			$query_limit
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded operational ledger read.
		$items    = (array) $this->wpdb->get_results( $query, ARRAY_A );
		$has_more = count( $items ) > $limit;
		if ( $has_more ) {
			array_pop( $items );
		}
		$last_item = ! empty( $items ) ? end( $items ) : array();
		return array(
			'items'         => $items,
			'has_more'      => $has_more,
			'next_after_id' => $has_more ? (int) ( $last_item['id'] ?? 0 ) : 0,
		);
	}
}
