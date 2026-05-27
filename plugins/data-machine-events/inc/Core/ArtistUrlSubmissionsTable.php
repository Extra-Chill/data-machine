<?php
/**
 * Artist URL Submissions Table
 *
 * Stores the moderation queue for URL-based artist tour imports submitted
 * via the event-submission block (extrachill-events#320). A row is
 * inserted when a logged-in user submits an artist tour URL; an admin
 * reviews the row and either approves it — at which point a Data Machine
 * pipeline + flow are created via `datamachine/create-flow` — or rejects
 * it. Failed scrapes are recorded with `status = 'scraping_failed'` so
 * admins can review URLs that need handler-format expansion.
 *
 * Table lives under `$wpdb->base_prefix` (network-scoped) so the
 * moderation queue is global across the multisite, matching the prompt's
 * dedupe contract ("if user A submits the same URL as user B already
 * did, the second submission returns 'this URL is already being
 * tracked'"). DME itself is per-site activated, so we install lazily on
 * `plugins_loaded` via a version option check — same shape Data Machine
 * core uses for its own network-scoped tables.
 *
 * @package DataMachineEvents\Core
 * @since   0.40.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArtistUrlSubmissionsTable {

	/**
	 * Schema version. Bump when CREATE TABLE definition changes.
	 */
	const SCHEMA_VERSION = '1';

	/**
	 * Site option key that stores the installed schema version.
	 */
	const VERSION_OPTION = 'datamachine_events_artist_url_submissions_db_version';

	/**
	 * Submission statuses.
	 */
	const STATUS_PENDING_REVIEW  = 'pending_review';
	const STATUS_APPROVED        = 'approved';
	const STATUS_REJECTED        = 'rejected';
	const STATUS_SCRAPING_FAILED = 'scraping_failed';

	/**
	 * Get the full table name. Uses base prefix so the moderation queue
	 * is shared across the multisite network (see class docblock).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'artist_url_submissions';
	}

	/**
	 * Install / upgrade the table via dbDelta. Idempotent — safe to call
	 * on every request once the version guard is in place.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NULL,
			contact_email varchar(255) NULL,
			contact_name varchar(255) NULL,
			url varchar(2048) NOT NULL,
			url_hash char(64) NOT NULL,
			suggested_artist_name varchar(255) NULL,
			suggested_artist_term_id bigint(20) unsigned NULL,
			detected_format varchar(64) NULL,
			events_found_count int unsigned NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT 'pending_review',
			admin_notes text NULL,
			rejection_reason text NULL,
			pipeline_id bigint(20) unsigned NULL,
			flow_id bigint(20) unsigned NULL,
			artist_term_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			reviewed_at datetime NULL,
			reviewed_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY idx_status (status, created_at),
			KEY idx_user (user_id, created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_site_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Check whether the installed schema is current. Called on every
	 * request via the install hook below; only triggers dbDelta when the
	 * stored version mismatches the class constant.
	 */
	public static function maybe_install(): void {
		$installed = get_site_option( self::VERSION_OPTION, '' );
		if ( self::SCHEMA_VERSION === $installed ) {
			return;
		}
		self::create_table();
	}

	/**
	 * Check if the table exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Normalize a URL for hashing/dedupe.
	 *
	 * Lowercases scheme + host, strips fragment, trims trailing slash
	 * (except for root paths), and removes default ports.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalized URL, or empty string if the input is not parseable.
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$port   = $parts['port'] ?? '';
		$path   = $parts['path'] ?? '/';
		$query  = $parts['query'] ?? '';

		// Strip default ports.
		if ( ( 'http' === $scheme && 80 === (int) $port ) || ( 'https' === $scheme && 443 === (int) $port ) ) {
			$port = '';
		}

		// Trim trailing slash on non-root paths.
		if ( strlen( $path ) > 1 && '/' === substr( $path, -1 ) ) {
			$path = rtrim( $path, '/' );
		}
		if ( '' === $path ) {
			$path = '/';
		}

		$normalized = $scheme . '://' . $host;
		if ( '' !== (string) $port ) {
			$normalized .= ':' . $port;
		}
		$normalized .= $path;
		if ( '' !== $query ) {
			$normalized .= '?' . $query;
		}

		return $normalized;
	}

	/**
	 * Compute the dedupe hash for a normalized URL.
	 *
	 * @param string $normalized_url URL already passed through normalize_url().
	 * @return string sha256 hex.
	 */
	public static function url_hash( string $normalized_url ): string {
		return hash( 'sha256', $normalized_url );
	}

	/**
	 * Look up a submission by its URL hash.
	 *
	 * @param string $url_hash sha256 hex.
	 * @return array|null Submission row as assoc array, or null.
	 */
	public static function find_by_hash( string $url_hash ): ?array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s LIMIT 1", $url_hash ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Look up a submission by ID.
	 *
	 * @param int $id Submission ID.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Insert a new submission row. Returns the new ID, or null on failure.
	 *
	 * @param array $data Row data.
	 * @return int|null
	 */
	public static function insert( array $data ): ?int {
		global $wpdb;
		$table = self::table_name();

		$defaults = array(
			'user_id'                  => null,
			'contact_email'            => null,
			'contact_name'             => null,
			'url'                      => '',
			'url_hash'                 => '',
			'suggested_artist_name'    => null,
			'suggested_artist_term_id' => null,
			'detected_format'          => null,
			'events_found_count'       => 0,
			'status'                   => self::STATUS_PENDING_REVIEW,
			'admin_notes'              => null,
			'rejection_reason'         => null,
			'pipeline_id'              => null,
			'flow_id'                  => null,
			'artist_term_id'           => null,
		);

		$row = array_merge( $defaults, array_intersect_key( $data, $defaults ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $table, $row );
		if ( false === $result ) {
			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a submission row by ID.
	 *
	 * @param int   $id   Submission ID.
	 * @param array $data Columns to update.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$table = self::table_name();

		$allowed = array(
			'status',
			'admin_notes',
			'rejection_reason',
			'pipeline_id',
			'flow_id',
			'artist_term_id',
			'suggested_artist_name',
			'suggested_artist_term_id',
			'detected_format',
			'events_found_count',
			'reviewed_at',
			'reviewed_by',
		);

		$update = array_intersect_key( $data, array_flip( $allowed ) );
		if ( empty( $update ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table, $update, array( 'id' => $id ) );
		return false !== $result;
	}

	/**
	 * List submissions filtered by status.
	 *
	 * @param string $status   One of the STATUS_* constants, or 'all'.
	 * @param int    $per_page Page size.
	 * @param int    $offset   Offset for pagination.
	 * @return array<int, array>
	 */
	public static function list_by_status( string $status, int $per_page = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table_name();

		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = max( 0, $offset );

		if ( 'all' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return $rows ?: array();
	}

	/**
	 * Count submissions by status. Returns an associative array keyed by
	 * status with row counts.
	 *
	 * @return array<string,int>
	 */
	public static function counts_by_status(): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status",
			ARRAY_A
		);

		$out = array(
			self::STATUS_PENDING_REVIEW  => 0,
			self::STATUS_APPROVED        => 0,
			self::STATUS_REJECTED        => 0,
			self::STATUS_SCRAPING_FAILED => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['c'];
		}
		return $out;
	}
}
