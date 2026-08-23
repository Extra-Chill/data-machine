<?php
// phpcs:disable Universal.Operators.DisallowShortTernary.Found,PSR2.Files.EndFileNewline.TooMany -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reviewed legacy SQL identifiers and trusted renderer output; dynamic values remain prepared and fields escaped.
/**
 * Event Dates Table
 *
 * Manages the dedicated datamachine_event_dates table — the single query
 * source of truth for event datetimes. Provides schema creation via
 * dbDelta(), one-time backfill from legacy postmeta, and helper read/write
 * functions.
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
	 * The date portion of each datetime is validated with
	 * DateTimeParser::isValidYmd() before any write. A malformed or
	 * placeholder value (e.g. "2026-07-??", "0000-00-00") is rejected —
	 * logged and skipped — rather than passed to MySQL, which under
	 * non-strict sql_mode silently coerces such strings to
	 * "0000-00-00 00:00:00". The save_post hook already validates upstream
	 * (#394); this guard is defense-in-depth at the storage primitive so
	 * that backfill, CLI, or any future caller can never produce a zero-date
	 * row. See #395.
	 *
	 * @param int         $post_id        Post ID.
	 * @param string      $start_datetime MySQL datetime string.
	 * @param string|null $end_datetime   MySQL datetime string or null.
	 * @param string|null $post_status    Deprecated hint; canonical post status always wins.
	 * @return bool True on success, false if a malformed date was rejected.
	 */
	public static function upsert( int $post_id, string $start_datetime, ?string $end_datetime = null, ?string $post_status = null ): bool {
		global $wpdb;
		$post = get_post( $post_id );

		// The canonical event post always owns status. Never create an orphaned
		// row or infer that a missing post is published.
		if ( ! $post instanceof \WP_Post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return false;
		}

		if ( ! self::is_valid_datetime( $start_datetime ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'EventDatesTable::upsert rejected malformed start_datetime',
				array(
					'post_id'        => $post_id,
					'start_datetime' => $start_datetime,
				)
			);
			return false;
		}

		if ( null !== $end_datetime && ! self::is_valid_datetime( $end_datetime ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'EventDatesTable::upsert rejected malformed end_datetime',
				array(
					'post_id'      => $post_id,
					'end_datetime' => $end_datetime,
				)
			);
			return false;
		}

		$post_status = $post->post_status;

		$result = $wpdb->replace(
			self::table_name(),
			array(
				'post_id'        => $post_id,
				'start_datetime' => $start_datetime,
				'end_datetime'   => $end_datetime,
				'post_status'    => $post_status,
			),
			array( '%d', '%s', $end_datetime ? '%s' : null, '%s' )
		);

		return false !== $result;
	}

	/**
	 * Validate that a datetime string has a real Y-m-d date prefix.
	 *
	 * Rejects placeholder/TBD strings ("2026-07-??"), the MySQL zero date
	 * ("0000-00-00 00:00:00"), out-of-range values, and impossible calendar
	 * dates. The time-of-day suffix is not validated — only the leading
	 * Y-m-d must be a genuine calendar date.
	 *
	 * @param string $datetime Datetime string (at least 10 chars of Y-m-d).
	 * @return bool True when the date portion is a valid calendar date.
	 */
	private static function is_valid_datetime( string $datetime ): bool {
		$date = substr( $datetime, 0, 10 );

		return DateTimeParser::isValidYmd( $date );
	}

	/**
	 * Update the post_status column for an event.
	 *
	 * Called from transition_post_status hook to keep denormalized status in sync.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $post_status New post status.
	 */
	public static function update_status( int $post_id, string $post_status ): bool {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return false;
		}
		$post_status = $post->post_status;

		$result = $wpdb->update(
			self::table_name(),
			array( 'post_status' => $post_status ),
			array( 'post_id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete an event's dates from the table.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when the delete was confirmed.
	 */
	public static function delete( int $post_id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			self::table_name(),
			array( 'post_id' => $post_id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			do_action( 'datamachine_event_dates_deleted', $post_id );
			return true;
		}

		return false;
	}

	/**
	 * Get event dates for a single post.
	 *
	 * @param int $post_id Post ID.
	 * @return object{start_datetime:string,end_datetime:string|null,post_status:string}|null Event dates, or null.
	 */
	public static function get( int $post_id ): ?object {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally by table_name(); query contains no request values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT start_datetime, end_datetime, post_status FROM {$table} WHERE post_id = %d", $post_id )
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Subsequent queries use the same internally generated table identifier.
		return $row ?: null;
	}

	/**
	 * Find one keyset-paginated batch of status mismatches and orphans.
	 *
	 * @param int $after_id   Only inspect rows after this post ID.
	 * @param int $batch_size Maximum rows returned.
	 * @return array{rows:array<int,array<string,mixed>>,next_cursor:int,has_more:bool}
	 */
	public static function find_status_drift_batch( int $after_id = 0, int $batch_size = 100 ): array {
		global $wpdb;

		$table      = self::table_name();
		$batch_size = max( 1, min( 1000, $batch_size ) );
		$query_size = $batch_size + 1;

		// Keyset pagination follows the event-dates primary key. The query reads
		// only mismatches and does not hold a transaction or lock across repairs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ed.post_id, ed.post_status AS indexed_status,
					p.post_status AS canonical_status, p.post_type AS canonical_post_type
				FROM {$table} ed
				LEFT JOIN {$wpdb->posts} p ON p.ID = ed.post_id
				WHERE ed.post_id > %d
					AND (p.ID IS NULL OR p.post_type <> %s OR ed.post_status <> p.post_status)
				ORDER BY ed.post_id ASC
				LIMIT %d",
				$after_id,
				Event_Post_Type::POST_TYPE,
				$query_size
			),
			ARRAY_A
		);

		$has_more = count( $rows ) > $batch_size;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $batch_size );
		}

		$rows = array_map(
			static function ( array $row ): array {
				$row['post_id'] = (int) $row['post_id'];
				$row['action']  = empty( $row['canonical_post_type'] ) || Event_Post_Type::POST_TYPE !== $row['canonical_post_type']
					? 'delete_orphan'
					: 'update_status';
				return $row;
			},
			$rows
		);

		$last = end( $rows );

		return array(
			'rows'        => $rows,
			'next_cursor' => false === $last ? $after_id : (int) $last['post_id'],
			'has_more'    => $has_more,
		);
	}

	/**
	 * Reconcile one candidate against its current canonical post state.
	 *
	 * @param int $post_id Event-date row post ID.
	 * @return string One of status_updated, orphan_deleted, unchanged, or failed.
	 */
	public static function repair_status_drift_row( int $post_id ): string {
		global $wpdb;
		$table          = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexed_status = $wpdb->get_var(
			$wpdb->prepare( "SELECT post_status FROM {$table} WHERE post_id = %d", $post_id )
		);

		if ( null === $indexed_status ) {
			return 'unchanged';
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return self::delete( $post_id ) ? 'orphan_deleted' : 'failed';
		}

		if ( $post->post_status === $indexed_status ) {
			return 'unchanged';
		}

		return self::update_status( $post_id, $post->post_status ) ? 'status_updated' : 'failed';
	}

	/**
	 * Find rows with a MySQL zero date in the event_dates table.
	 *
	 * Under non-strict sql_mode, malformed date strings (e.g. "2026-07-??")
	 * are silently coerced to "0000-00-00 00:00:00". This method surfaces
	 * those rows for the audit/cleanup command. See #395.
	 *
	 * @return array<array{post_id: int, start_datetime: string, end_datetime: string|null}> Rows with a zero start_datetime.
	 */
	public static function find_zero_date_rows(): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally by table_name(); query contains no request values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT post_id, start_datetime, end_datetime
			FROM {$table}
			WHERE start_datetime = '0000-00-00 00:00:00'
				OR start_datetime LIKE '0000-%'",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ) {
				return array(
					'post_id'        => (int) $row['post_id'],
					'start_datetime' => $row['start_datetime'],
					'end_datetime'   => isset( $row['end_datetime'] ) ? (string) $row['end_datetime'] : null,
				);
			},
			$rows
		);
	}

	/**
	 * Find published event posts without an event-dates index row.
	 *
	 * @param string $post_type Event post type.
	 * @return array<array{id: int, title: string}> Unindexed published events.
	 */
	public static function find_missing_rows( string $post_type ): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally by table_name(); values remain prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p LEFT JOIN {$table} ed ON p.ID = ed.post_id WHERE p.post_type = %s AND p.post_status = 'publish' AND ed.post_id IS NULL ORDER BY p.ID ASC",
				$post_type
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'    => (int) $row['ID'],
					'title' => (string) $row['post_title'],
				);
			},
			$rows
		);
	}

	/**
	 * Backfill the event dates table from legacy postmeta.
	 *
	 * One-time migration: populates the table for events that still have
	 * `_datamachine_event_datetime` postmeta but no row in the table yet.
	 * New events are written directly to the table by save_post; this method
	 * only catches events created before the table existed.
	 *
	 * @param int           $batch_size Events per batch.
	 * @param callable|null $progress   Progress callback (receives total processed count).
	 * @return int Total events backfilled.
	 */
	public static function backfill( int $batch_size = 500, ?callable $progress = null ): int {
		global $wpdb;

		$table  = self::table_name();
		$total  = 0;
		$offset = 0;

		while ( true ) {
			// Find events with postmeta datetime but no row in event_dates table.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally by table_name(); batch size remains prepared.
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
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$written = self::upsert(
					(int) $row->post_id,
					$row->start_datetime,
					$row->end_datetime ?: null,
					$row->post_status
				);
				if ( $written ) {
					++$total;
				}
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
