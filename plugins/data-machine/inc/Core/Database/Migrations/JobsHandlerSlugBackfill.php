<?php
/**
 * Bounded handler_slug backfill for the jobs table.
 *
 * @package DataMachine\Core\Database\Migrations
 */

namespace DataMachine\Core\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Promotes the first handler_slug in engine_data onto the indexed column.
 */
class JobsHandlerSlugBackfill extends CursorJobBackfill {

	public const STATE_OPTION = 'datamachine_jobs_handler_slug_cursor_v1';

	public function id(): string {
		return 'jobs.handler-slug-backfill';
	}

	public function description(): string {
		return 'Copy engine_data.handler_slug onto the indexed handler_slug column.';
	}

	protected function stateOption(): string {
		return self::STATE_OPTION;
	}

	protected function legacyCompleteOption(): string {
		return '';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected function fetchBatch( int $cursor, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL -- Operator-run jobs backfill.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT job_id, engine_data FROM %i
				 WHERE job_id > %d
				 AND engine_data IS NOT NULL
				 AND engine_data LIKE %s
				 AND handler_slug IS NULL
				 ORDER BY job_id ASC
				 LIMIT %d',
				$this->table(),
				$cursor,
				'%' . $wpdb->esc_like( '"handler_slug"' ) . '%',
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL

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

		$slug = self::extractHandlerSlug( (string) ( $row['engine_data'] ?? '' ) );
		if ( '' === $slug ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Operator-run jobs backfill of one row.
		$wpdb->update(
			$this->table(),
			array( 'handler_slug' => $slug ),
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
				'SELECT COUNT(*) FROM %i
				 WHERE engine_data IS NOT NULL
				 AND engine_data LIKE %s
				 AND handler_slug IS NULL',
				$this->table(),
				'%' . $wpdb->esc_like( '"handler_slug"' ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL

		return (int) $count;
	}

	private static function extractHandlerSlug( string $encoded ): string {
		if ( '' === $encoded || ! str_contains( $encoded, '"handler_slug"' ) ) {
			return '';
		}

		if ( preg_match( '/"handler_slug":"([^"]+)"/', $encoded, $matches ) ) {
			return sanitize_key( $matches[1] );
		}

		return '';
	}
}
