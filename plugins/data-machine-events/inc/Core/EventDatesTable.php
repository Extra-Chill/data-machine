<?php
/**
 * Event Dates Table
 *
 * Manages the dedicated datamachine_event_dates table that replaces
 * postmeta-based event datetime storage. Provides schema creation via
 * dbDelta(), backfill from postmeta, and helper read/write functions.
 *
 * The table includes a denormalized post_status column so that queries
 * can filter to published events without joining the posts table (which
 * is the primary bottleneck on sites with 30K+ events).
 *
 * @package DataMachineEvents\Core
 * @since   0.23.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventDatesTable {

	/**
	 * Get the full table name for the current site.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'datamachine_event_dates';
	}

	/**
	 * Create the event dates table via dbDelta.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			post_id        BIGINT UNSIGNED NOT NULL,
			start_datetime DATETIME NOT NULL,
			end_datetime   DATETIME DEFAULT NULL,
			post_status    VARCHAR(20) NOT NULL DEFAULT 'publish',
			PRIMARY KEY (post_id),
			KEY start_datetime (start_datetime),
			KEY end_datetime (end_datetime),
			KEY status_start (post_status, start_datetime)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Check if the table exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
	}

	/**
	 * Upsert an event's dates into the table.
	 *
	 * @param int         $post_id        Post ID.
	 * @param string      $start_datetime MySQL datetime string.
	 * @param string|null $end_datetime   MySQL datetime string or null.
	 * @param string|null $post_status    Post status (auto-detected from post if null).
	 */
	public static function upsert( int $post_id, string $start_datetime, ?string $end_datetime = null, ?string $post_status = null ): void {
		global $wpdb;

		if ( null === $post_status ) {
			$post_status = get_post_status( $post_id ) ?: 'publish';
		}

		$wpdb->replace(
			self::table_name(),
			array(
				'post_id'        => $post_id,
				'start_datetime' => $start_datetime,
				'end_datetime'   => $end_datetime,
				'post_status'    => $post_status,
			),
			array( '%d', '%s', $end_datetime ? '%s' : null, '%s' )
		);
	}

	/**
	 * Update the post_status column for an event.
	 *
	 * Called from transition_post_status hook to keep denormalized status in sync.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $post_status New post status.
	 */
	public static function update_status( int $post_id, string $post_status ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array( 'post_status' => $post_status ),
			array( 'post_id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete an event's dates from the table.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete( int $post_id ): void {
		global $wpdb;

		$wpdb->delete(
			self::table_name(),
			array( 'post_id' => $post_id ),
			array( '%d' )
		);
	}

	/**
	 * Get event dates for a single post.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null Object with start_datetime and end_datetime, or null.
	 */
	public static function get( int $post_id ): ?object {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT start_datetime, end_datetime FROM {$table} WHERE post_id = %d", $post_id )
		);

		return $row ?: null;
	}

	/**
	 * Backfill the event dates table from postmeta.
	 *
	 * @param int           $batch_size Events per batch.
	 * @param callable|null $progress   Progress callback (receives total processed count).
	 * @return int Total events backfilled.
	 */
	public static function backfill( int $batch_size = 500, ?callable $progress = null ): int {
		global $wpdb;

		$table    = self::table_name();
		$total    = 0;
		$offset   = 0;

		while ( true ) {
			// Find events with postmeta datetime but no row in event_dates table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm_start.post_id,
							pm_start.meta_value AS start_datetime,
							pm_end.meta_value AS end_datetime,
							p.post_status
					FROM {$wpdb->postmeta} pm_start
					INNER JOIN {$wpdb->posts} p ON pm_start.post_id = p.ID
					LEFT JOIN {$table} ed ON pm_start.post_id = ed.post_id
					LEFT JOIN {$wpdb->postmeta} pm_end
						ON pm_start.post_id = pm_end.post_id
						AND pm_end.meta_key = '_datamachine_event_end_datetime'
					WHERE pm_start.meta_key = '_datamachine_event_datetime'
						AND ed.post_id IS NULL
					LIMIT %d",
					$batch_size
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				self::upsert(
					(int) $row->post_id,
					$row->start_datetime,
					$row->end_datetime ?: null,
					$row->post_status
				);
				++$total;
			}

			if ( $progress ) {
				$progress( $total );
			}

			if ( count( $rows ) < $batch_size ) {
				break;
			}
		}

		return $total;
	}
}
