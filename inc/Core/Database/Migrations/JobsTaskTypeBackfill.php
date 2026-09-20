<?php
/**
 * Bounded task_type backfill for the jobs table.
 *
 * @package DataMachine\Core\Database\Migrations
 */

namespace DataMachine\Core\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Promotes engine_data.task_type onto the indexed column.
 */
class JobsTaskTypeBackfill extends CursorJobBackfill {

	public const STATE_OPTION = 'datamachine_jobs_task_type_cursor_v1';

	public function id(): string {
		return 'jobs.task-type-backfill';
	}

	public function description(): string {
		return 'Copy engine_data.task_type onto the indexed task_type column.';
	}

	protected function stateOption(): string {
		return self::STATE_OPTION;
	}

	protected function legacyCompleteOption(): string {
		return '';
	}

	protected function fetchBatch( int $cursor, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL -- Operator-run jobs backfill.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, engine_data FROM %i
				 WHERE job_id > %d
				 AND source IN ('system', 'pipeline_system_task')
				 AND engine_data IS NOT NULL
				 AND task_type IS NULL
				 ORDER BY job_id ASC
				 LIMIT %d",
				$this->table(),
				$cursor,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL

		return is_array( $rows ) ? $rows : array();
	}

	protected function processRow( array $row ): void {
		global $wpdb;

		$job_id = (int) ( $row['job_id'] ?? 0 );
		if ( $job_id <= 0 ) {
			return;
		}

		$engine    = json_decode( (string) ( $row['engine_data'] ?? '' ), true );
		$task_type = is_array( $engine ) ? ( $engine['task_type'] ?? null ) : null;
		if ( ! is_string( $task_type ) || '' === $task_type ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Operator-run jobs backfill of one row.
		$wpdb->update(
			$this->table(),
			array( 'task_type' => $task_type ),
			array( 'job_id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	protected function countRemaining(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL -- Operator-run remaining-count.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE source IN ('system', 'pipeline_system_task')
				 AND engine_data IS NOT NULL
				 AND task_type IS NULL",
				$this->table()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL

		return (int) $count;
	}
}
