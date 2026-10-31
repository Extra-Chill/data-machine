<?php
/**
 * Exact processed-item claim ownership operations used by scheduled fanout.
 *
 * @package DataMachine\Core\Database\ProcessedItems
 */

namespace DataMachine\Core\Database\ProcessedItems;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\TransactionScope;

defined( 'ABSPATH' ) || exit;

/** Internal repository for reconstructing and adopting fanout claim ownership. */
final class FanoutClaimOwnership extends BaseRepository {

	public const TABLE_NAME = ProcessedItems::TABLE_NAME;

	/** Classify exact terminal ownership without treating lease expiry as resolution. */
	public function terminal_claim_state( array $claim, int $job_id ): string {
		$identity_scope  = (string) ( $claim['identity_scope'] ?? '' );
		$source_type     = (string) ( $claim['source_type'] ?? '' );
		$item_identifier = (string) ( $claim['item_identifier'] ?? '' );
		$token           = (string) ( $claim['ownership_token'] ?? '' );
		if ( $job_id <= 0 || '' === $identity_scope || '' === $source_type || '' === $item_identifier || '' === $token ) {
			return 'conflict';
		}

		$query = $this->wpdb->prepare(
			'SELECT job_id, status, claim_token FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s LIMIT 1',
			$this->table_name,
			$identity_scope,
			$source_type,
			$item_identifier
		);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above with an escaped identifier and typed values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact terminal ownership evidence.
		$row = $this->wpdb->get_row( $query, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $row ) || ProcessedItems::STATUS_PROCESSED === (string) ( $row['status'] ?? '' ) ) {
			return 'resolved';
		}
		if ( ProcessedItems::STATUS_CLAIMED === (string) ( $row['status'] ?? '' )
			&& (int) ( $row['job_id'] ?? 0 ) === $job_id
			&& hash_equals( $token, (string) ( $row['claim_token'] ?? '' ) ) ) {
			return 'owned';
		}

		return 'conflict';
	}

	/** Return active token-owned claim descriptors for one job. */
	public function active_claims_for_job( int $job_id ): array {
		if ( $job_id <= 0 ) {
			return array();
		}

		$query = $this->wpdb->prepare(
			'SELECT flow_step_id, source_type, item_identifier, claim_token FROM %i WHERE job_id = %d AND status = %s AND claim_token IS NOT NULL AND claim_token != %s AND claim_expires_at > %s',
			$this->table_name,
			$job_id,
			ProcessedItems::STATUS_CLAIMED,
			'',
			current_time( 'mysql', true )
		);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above with an escaped identifier and typed values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact lifecycle ownership lookup.
		$rows = $this->wpdb->get_results( $query, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$claims = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$claim                   = array(
				'identity_scope'  => (string) ( $row['flow_step_id'] ?? '' ),
				'source_type'     => (string) ( $row['source_type'] ?? '' ),
				'item_identifier' => (string) ( $row['item_identifier'] ?? '' ),
				'ownership_token' => (string) ( $row['claim_token'] ?? '' ),
			);
			$claim['disposition_id'] = ProcessedItems::disposition_identity( $claim['identity_scope'], $claim['source_type'], $claim['item_identifier'] );
			$claims[]                = $claim;
		}

		return $claims;
	}

	/** Atomically move exact token-owned claims from a fanout parent to its child. */
	public function adopt_owned_claims( array $claims, int $parent_job_id, int $child_job_id, bool $allow_resolved = false ): bool {
		if ( $parent_job_id <= 0 || $child_job_id <= 0 ) {
			return false;
		}
		if ( empty( $claims ) ) {
			return true;
		}

		$scope = TransactionScope::begin( $this->wpdb );
		if ( null === $scope ) {
			return false;
		}

		foreach ( $claims as $claim ) {
			$identity_scope  = (string) ( $claim['identity_scope'] ?? '' );
			$source_type     = (string) ( $claim['source_type'] ?? '' );
			$item_identifier = (string) ( $claim['item_identifier'] ?? '' );
			$token           = (string) ( $claim['ownership_token'] ?? '' );
			if ( '' === $identity_scope || '' === $source_type || '' === $item_identifier || '' === $token ) {
				$scope->rollback();
				return false;
			}

			$query = $this->wpdb->prepare(
				'SELECT job_id, status, claim_token FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s FOR UPDATE',
				$this->table_name,
				$identity_scope,
				$source_type,
				$item_identifier
			);
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above with an escaped identifier and typed values.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional exact ownership transfer.
			$row = $this->wpdb->get_row( $query, ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $row ) ) {
				if ( $allow_resolved ) {
					continue;
				}
				$scope->rollback();
				return false;
			}
			$status = (string) ( $row['status'] ?? '' );
			if ( ProcessedItems::STATUS_PROCESSED === $status && $allow_resolved ) {
				continue;
			}
			if ( ProcessedItems::STATUS_CLAIMED !== $status || ! hash_equals( $token, (string) ( $row['claim_token'] ?? '' ) ) ) {
				$scope->rollback();
				return false;
			}

			$current_job_id = (int) ( $row['job_id'] ?? 0 );
			if ( $child_job_id === $current_job_id ) {
				continue;
			}
			if ( $parent_job_id !== $current_job_id ) {
				$scope->rollback();
				return false;
			}

			$updated = $this->wpdb->update(
				$this->table_name,
				array( 'job_id' => $child_job_id ),
				array(
					'flow_step_id'    => $identity_scope,
					'source_type'     => $source_type,
					'item_identifier' => $item_identifier,
					'claim_token'     => $token,
					'job_id'          => $parent_job_id,
					'status'          => ProcessedItems::STATUS_CLAIMED,
				),
				array( '%d' ),
				array( '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			if ( 1 !== $updated ) {
				$scope->rollback();
				return false;
			}
		}

		return $scope->commit();
	}
}
