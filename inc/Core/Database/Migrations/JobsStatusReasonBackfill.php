<?php
/**
 * Bounded status_reason backfill for the jobs table.
 *
 * @package DataMachine\Core\Database\Migrations
 */

namespace DataMachine\Core\Database\Migrations;

use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Splits compound status values and copies JSON reasons into status_reason.
 *
 * Replaces Jobs::backfill_status_reason_column(), which walked the entire
 * table inside create_table() on every web init and stampeded php-fpm.
 */
class JobsStatusReasonBackfill extends CursorJobBackfill {

	public const STATE_OPTION  = 'datamachine_jobs_status_reason_cursor_v1';
	public const LEGACY_OPTION = 'datamachine_status_reason_backfill_v1';

	public function id(): string {
		return 'jobs.status-reason-backfill';
	}

	public function description(): string {
		return 'Copy compound job statuses and engine_data reasons into status_reason.';
	}

	protected function stateOption(): string {
		return self::STATE_OPTION;
	}

	protected function legacyCompleteOption(): string {
		return self::LEGACY_OPTION;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected function fetchBatch( int $cursor, int $limit ): array {
		global $wpdb;

		$canonical    = JobStatus::ALL_STATUSES;
		$placeholders = implode( ', ', array_fill( 0, count( $canonical ), '%s' ) );
		$like         = '%' . $wpdb->esc_like( '"job_status_reason"' ) . '%';
		$table        = $this->table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Operator-run jobs backfill.
		$query = $wpdb->prepare(
			"SELECT job_id, status, engine_data FROM %i
			 WHERE job_id > %d
			 AND ( status NOT IN ({$placeholders}) OR ( status_reason IS NULL AND engine_data IS NOT NULL AND engine_data LIKE %s ) )
			 ORDER BY job_id ASC
			 LIMIT %d",
			array_merge( array( $table, $cursor ), $canonical, array( $like, $limit ) )
		);
		$rows  = $wpdb->get_results( $query, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	protected function processRow( array $row ): void {
		global $wpdb;

		$job_id = (int) ( $row['job_id'] ?? 0 );
		if ( $job_id <= 0 ) {
			return;
		}

		$parsed = JobStatus::fromString( (string) ( $row['status'] ?? '' ) );
		if ( ! $parsed->isCanonical() ) {
			return;
		}

		$stored_engine = $row['engine_data'] ?? null;
		$engine        = null === $stored_engine || '' === $stored_engine ? array() : json_decode( (string) $stored_engine, true );
		if ( ! is_array( $engine ) ) {
			$engine = array();
		}

		$storage = $parsed->toStorage();
		$reason  = $storage['status_reason'];
		if ( null === $reason || '' === $reason ) {
			$reason = is_string( $engine['job_status_reason'] ?? null ) ? $engine['job_status_reason'] : null;
		}
		if ( null !== $reason && '' !== $reason ) {
			$engine['job_status_reason'] = $reason;
		}

		$encoded = wp_json_encode( $engine );
		if ( ! is_string( $encoded ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Operator-run jobs backfill of one row.
		$wpdb->update(
			$this->table(),
			array(
				'status'        => $storage['status'],
				'status_reason' => ( null !== $reason && '' !== $reason ) ? $reason : null,
				'engine_data'   => $encoded,
			),
			array( 'job_id' => $job_id ),
			array( '%s', ( null !== $reason && '' !== $reason ) ? '%s' : null, '%s' ),
			array( '%d' )
		);
	}

	protected function countRemaining(): int {
		global $wpdb;

		$canonical    = JobStatus::ALL_STATUSES;
		$placeholders = implode( ', ', array_fill( 0, count( $canonical ), '%s' ) );
		$like         = '%' . $wpdb->esc_like( '"job_status_reason"' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders -- Operator-run remaining-count.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE status NOT IN ({$placeholders})
				 OR ( status_reason IS NULL AND engine_data IS NOT NULL AND engine_data LIKE %s )",
				array_merge( array( $this->table() ), $canonical, array( $like ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders

		return (int) $count;
	}
}
