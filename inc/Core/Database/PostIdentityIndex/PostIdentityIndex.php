<?php
/**
 * Post Identity Index — indexed lookup table for fast post deduplication.
 *
 * Replaces slow wp_postmeta LIKE scans with purpose-built indexed columns.
 * Extensions register identity fields and write identity rows; the core
 * provides schema, CRUD, and query primitives.
 *
 * The table stores denormalized identity columns (event_date, venue_term_id,
 * ticket_url, title_hash, source_url) that map back to a post_id. Each post
 * has at most one identity row. Queries hit composite indexes instead of
 * scanning the generic postmeta EAV table.
 *
 * @package    DataMachine\Core\Database\PostIdentityIndex
 * @since      0.50.0
 */

namespace DataMachine\Core\Database\PostIdentityIndex;

use DataMachine\Core\Database\BaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostIdentityIndex extends BaseRepository {

	const TABLE_NAME = 'datamachine_post_identity';

	/**
	 * Create or update the table schema.
	 *
	 * Called on plugin activation and version migrations.
	 */
	public function create_table(): void {
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table_name} (
			post_id BIGINT(20) UNSIGNED NOT NULL,
			post_type VARCHAR(20) NOT NULL DEFAULT '',
			event_date DATE DEFAULT NULL,
			venue_term_id BIGINT(20) UNSIGNED DEFAULT NULL,
			ticket_url VARCHAR(512) DEFAULT NULL,
			title_hash VARCHAR(32) NOT NULL DEFAULT '',
			source_url VARCHAR(512) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (post_id),
			KEY idx_date_venue (event_date, venue_term_id),
			KEY idx_date_title (event_date, title_hash),
			KEY idx_ticket_date (ticket_url(191), event_date),
			KEY idx_source_url (source_url(191)),
			KEY idx_post_type (post_type)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		do_action(
			'datamachine_log',
			'debug',
			'PostIdentityIndex: table created/updated',
			array(
				'table_name' => $this->table_name,
			)
		);
	}

	// -----------------------------------------------------------------------
	// Write operations
	// -----------------------------------------------------------------------

	/**
	 * Insert or update an identity row for a post.
	 *
	 * Uses REPLACE INTO for atomic upsert — if the post_id already exists,
	 * the row is replaced entirely.
	 *
	 * @param int   $post_id Post ID (primary key).
	 * @param array $fields  Identity fields: event_date, venue_term_id,
	 *                       ticket_url, title_hash, source_url, post_type.
	 * @return bool True on success.
	 */
	public function upsert( int $post_id, array $fields ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$data = array(
			'post_id'   => $post_id,
			'post_type' => $fields['post_type'] ?? '',
		);

		$formats = array( '%d', '%s' );

		if ( isset( $fields['event_date'] ) && '' !== $fields['event_date'] ) {
			$data['event_date'] = $fields['event_date'];
			$formats[]          = '%s';
		}

		if ( isset( $fields['venue_term_id'] ) && $fields['venue_term_id'] > 0 ) {
			$data['venue_term_id'] = (int) $fields['venue_term_id'];
			$formats[]             = '%d';
		}

		if ( isset( $fields['ticket_url'] ) && '' !== $fields['ticket_url'] ) {
			$data['ticket_url'] = $fields['ticket_url'];
			$formats[]          = '%s';
		}

		if ( isset( $fields['title_hash'] ) && '' !== $fields['title_hash'] ) {
			$data['title_hash'] = $fields['title_hash'];
			$formats[]          = '%s';
		}

		if ( isset( $fields['source_url'] ) && '' !== $fields['source_url'] ) {
			$data['source_url'] = $fields['source_url'];
			$formats[]          = '%s';
		}

		// Build REPLACE INTO for atomic upsert.
		$columns      = implode( ', ', array_keys( $data ) );
		$placeholders = implode( ', ', $formats );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->wpdb->prepare(
				"REPLACE INTO {$this->table_name} ({$columns}) VALUES ({$placeholders})",
				...array_values( $data )
			)
		);

		if ( false === $result ) {
			$this->log_db_error( 'PostIdentityIndex::upsert', array( 'post_id' => $post_id ) );
			return false;
		}

		return true;
	}

	/**
	 * Delete the identity row for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True on success.
	 */
	public function delete( int $post_id ): bool {
		return $this->delete_by_id( 'post_id', $post_id );
	}

	/**
	 * Get the identity row for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Identity fields or null.
	 */
	public function get( int $post_id ): ?array {
		return $this->find_by_id( 'post_id', $post_id );
	}

	// -----------------------------------------------------------------------
	// Query operations — used by dedup strategies
	// -----------------------------------------------------------------------

	/**
	 * Find posts by event date and venue.
	 *
	 * Primary dedup query for events with known venue.
	 * Uses idx_date_venue composite index.
	 *
	 * @param string   $event_date    Date in YYYY-MM-DD format.
	 * @param int|null $venue_term_id Venue taxonomy term ID (null for any venue).
	 * @param int      $limit         Max results.
	 * @return array Array of identity rows.
	 */
	public function find_by_date_and_venue( string $event_date, ?int $venue_term_id = null, int $limit = 20 ): array {
		if ( empty( $event_date ) ) {
			return array();
		}

		if ( null !== $venue_term_id && $venue_term_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table_name} WHERE event_date = %s AND venue_term_id = %d LIMIT %d",
					$event_date,
					$venue_term_id,
					$limit
				),
				ARRAY_A
			) ?: array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE event_date = %s LIMIT %d",
				$event_date,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Find posts by event date and title hash.
	 *
	 * For exact title dedup. Uses idx_date_title composite index.
	 *
	 * @param string $event_date Date in YYYY-MM-DD format.
	 * @param string $title_hash MD5 hash of normalized title.
	 * @return array|null First matching identity row or null.
	 */
	public function find_by_date_and_title_hash( string $event_date, string $title_hash ): ?array {
		if ( empty( $event_date ) || empty( $title_hash ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE event_date = %s AND title_hash = %s LIMIT 1",
				$event_date,
				$title_hash
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Find posts by ticket URL and event date.
	 *
	 * Most reliable dedup — same ticket URL on same date is definitively the same event.
	 * Uses idx_ticket_date composite index.
	 *
	 * @param string $ticket_url Normalized ticket URL.
	 * @param string $event_date Date in YYYY-MM-DD format.
	 * @return array|null First matching identity row or null.
	 */
	public function find_by_ticket_url_and_date( string $ticket_url, string $event_date ): ?array {
		if ( empty( $ticket_url ) || empty( $event_date ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE ticket_url = %s AND event_date = %s LIMIT 1",
				$ticket_url,
				$event_date
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Find posts by source URL.
	 *
	 * Used by wire/publish dedup. Uses idx_source_url index.
	 *
	 * @param string $source_url Source URL to match.
	 * @return array|null First matching identity row or null.
	 */
	public function find_by_source_url( string $source_url ): ?array {
		if ( empty( $source_url ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE source_url = %s LIMIT 1",
				$source_url
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Find all posts with ticket URLs on a given date.
	 *
	 * Used for canonical ticket identity comparison (affiliate URL unwrapping).
	 *
	 * @param string $event_date Date in YYYY-MM-DD format.
	 * @param int    $limit      Max results.
	 * @return array Array of identity rows that have ticket_url set.
	 */
	public function find_with_ticket_url_on_date( string $event_date, int $limit = 50 ): array {
		if ( empty( $event_date ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE event_date = %s AND ticket_url IS NOT NULL AND ticket_url != '' LIMIT %d",
				$event_date,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Find posts by event date only.
	 *
	 * Broadest date query — used for venue-agnostic fuzzy title fallback.
	 *
	 * @param string $event_date Date in YYYY-MM-DD format.
	 * @param int    $limit      Max results.
	 * @return array Array of identity rows.
	 */
	public function find_by_date( string $event_date, int $limit = 50 ): array {
		if ( empty( $event_date ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE event_date = %s LIMIT %d",
				$event_date,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	// -----------------------------------------------------------------------
	// Bulk operations — used by backfill/audit CLI
	// -----------------------------------------------------------------------

	/**
	 * Count total identity rows.
	 *
	 * @param string $post_type Optional post type filter.
	 * @return int Row count.
	 */
	public function count( string $post_type = '' ): int {
		if ( '' !== $post_type ) {
			return $this->count_rows( 'post_type = %s', array( $post_type ) );
		}
		return $this->count_rows();
	}

	/**
	 * Get post IDs that are missing from the identity index.
	 *
	 * Used by backfill to find posts that need identity rows.
	 *
	 * @param string $post_type Post type to check.
	 * @param int    $limit     Max results.
	 * @param int    $offset    Offset for pagination.
	 * @return array Array of post IDs.
	 */
	public function find_missing_post_ids( string $post_type, int $limit = 500, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$this->table_name} idx ON p.ID = idx.post_id
				WHERE p.post_type = %s
				AND p.post_status IN ('publish', 'draft', 'pending')
				AND idx.post_id IS NULL
				ORDER BY p.ID ASC
				LIMIT %d OFFSET %d",
				$post_type,
				$limit,
				$offset
			)
		);

		return array_map( 'intval', $results ?: array() );
	}

	/**
	 * Bulk delete identity rows for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return int Number of rows deleted.
	 */
	public function delete_by_post_type( string $post_type ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE post_type = %s",
				$post_type
			)
		);

		return false === $result ? 0 : $result;
	}
}
