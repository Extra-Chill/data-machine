<?php
/**
 * Operator-controlled normalization of legacy compound job statuses.
 *
 * @package DataMachine\Core\Database\Jobs
 */

namespace DataMachine\Core\Database\Jobs;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit operator migration reads and updates the plugin-owned jobs table in bounded batches.

use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class JobStatusMigration {

	public const STATE_OPTION = 'datamachine_job_status_normalization_v1';
	private const MAX_BATCH   = 1000;

	/** @var \wpdb */
	private $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . Jobs::TABLE_NAME;
	}

	/** Inspect durable progress without mutating job rows. */
	public function inspect(): array {
		$state     = $this->state();
		$remaining = $this->countNoncanonicalRows();

		return array_merge(
			$state,
			array(
				'success'            => true,
				'table'              => $this->table,
				'remaining'          => $remaining,
				'complete'           => 0 === $remaining,
				'status'             => 0 === $remaining ? 'complete' : ( (int) $state['cursor'] > 0 ? 'in_progress' : 'migration_required' ),
				'canonical_statuses' => JobStatus::ALL_STATUSES,
			)
		);
	}

	/** Process one bounded, resumable batch. */
	public function apply( int $limit = 250 ): array {
		$limit             = max( 1, min( self::MAX_BATCH, $limit ) );
		$state             = $this->state();
		$state['complete'] = false;
		$rows              = $this->candidateRows( (int) $state['cursor'], $limit );

		foreach ( $rows as $row ) {
			$job_id           = (int) $row['job_id'];
			$status           = (string) $row['status'];
			$state['cursor']  = max( (int) $state['cursor'], $job_id );
			$state['scanned'] = (int) $state['scanned'] + 1;
			$parsed           = JobStatus::fromString( $status );

			if ( ! $parsed->isCanonical() ) {
				$state['unknown'] = (int) $state['unknown'] + 1;
				continue;
			}

			$result = $this->normalizeRow( $job_id, $status );
			if ( 'migrated' === $result ) {
				$state['migrated'] = (int) $state['migrated'] + 1;
			} elseif ( 'conflict' === $result ) {
				$state['conflicts'] = (int) $state['conflicts'] + 1;
			} else {
				$state['errors'] = (int) $state['errors'] + 1;
				break;
			}
		}

		$state['updated_at'] = gmdate( 'c' );
		update_option( self::STATE_OPTION, $state, false );
		$result = array_merge(
			$state,
			array(
				'success'            => true,
				'table'              => $this->table,
				'batch_size'         => count( $rows ),
				'canonical_statuses' => JobStatus::ALL_STATUSES,
			)
		);
		if ( count( $rows ) < $limit ) {
			$result = $this->inspect();
		}
		if ( ! empty( $result['complete'] ) ) {
			$state['complete'] = true;
			update_option( self::STATE_OPTION, $state, false );
			$result = array_merge( $result, $state );
		} elseif ( count( $rows ) < $limit && (int) ( $result['remaining'] ?? 0 ) > 0 ) {
			$state['cursor'] = 0;
			update_option( self::STATE_OPTION, $state, false );
			$result['cursor'] = 0;
		}
		$result['batch_size'] = count( $rows );
		$result['status']     = ! empty( $result['complete'] ) ? 'complete' : 'in_progress';
		return $result;
	}

	public static function isComplete(): bool {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) && ! empty( $state['complete'] );
	}

	private function state(): array {
		$stored = get_option( self::STATE_OPTION, array() );
		return array_merge(
			array(
				'cursor'     => 0,
				'scanned'    => 0,
				'migrated'   => 0,
				'unknown'    => 0,
				'conflicts'  => 0,
				'errors'     => 0,
				'updated_at' => null,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	private function candidateRows( int $cursor, int $limit ): array {
		$query = $this->wpdb->prepare(
			'SELECT job_id, status FROM %i WHERE job_id > %d AND status NOT IN (%s, %s, %s, %s, %s, %s, %s, %s) ORDER BY job_id ASC LIMIT %d',
			$this->table,
			$cursor,
			JobStatus::PENDING,
			JobStatus::PROCESSING,
			JobStatus::WAITING,
			JobStatus::COMPLETED,
			JobStatus::FAILED,
			JobStatus::CANCELLED,
			JobStatus::COMPLETED_NO_ITEMS,
			JobStatus::AGENT_SKIPPED,
			$limit
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above with typed identifier and scalar placeholders.
		$rows = $this->wpdb->get_results( $query, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private function countNoncanonicalRows(): int {
		$query = $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE status NOT IN (%s, %s, %s, %s, %s, %s, %s, %s)',
			$this->table,
			JobStatus::PENDING,
			JobStatus::PROCESSING,
			JobStatus::WAITING,
			JobStatus::COMPLETED,
			JobStatus::FAILED,
			JobStatus::CANCELLED,
			JobStatus::COMPLETED_NO_ITEMS,
			JobStatus::AGENT_SKIPPED
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above from fixed placeholders and canonical constants.
		return (int) $this->wpdb->get_var( $query );
	}

	private function normalizeRow( int $job_id, string $expected_status ): string {
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return 'error';
		}

		$query = $this->wpdb->prepare( 'SELECT status, engine_data FROM %i WHERE job_id = %d FOR UPDATE', $this->table, $job_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above with typed identifier and integer placeholders.
		$row = $this->wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) || (string) $row['status'] !== $expected_status ) {
			$this->wpdb->query( 'ROLLBACK' );
			return 'conflict';
		}

		$parsed = JobStatus::fromString( $expected_status );
		if ( ! $parsed->isCanonical() ) {
			$this->wpdb->query( 'ROLLBACK' );
			return 'conflict';
		}

		$stored_engine = $row['engine_data'] ?? null;
		$engine        = null === $stored_engine || '' === $stored_engine ? array() : json_decode( (string) $stored_engine, true );
		if ( ! is_array( $engine ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return 'error';
		}
		if ( $parsed->hasReason() ) {
			$engine['job_status_reason'] = $parsed->getReason();
		}
		$encoded = wp_json_encode( $engine );
		if ( ! is_string( $encoded ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return 'error';
		}
		$updated = $this->wpdb->update(
			$this->table,
			array(
				'status'      => $parsed->getBaseStatus(),
				'engine_data' => $encoded,
			),
			array(
				'job_id' => $job_id,
				'status' => $expected_status,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== (int) $updated || false === $this->wpdb->query( 'COMMIT' ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return false === $updated ? 'error' : 'conflict';
		}
		wp_cache_delete( $job_id, 'datamachine_engine_data' );
		return 'migrated';
	}
}
