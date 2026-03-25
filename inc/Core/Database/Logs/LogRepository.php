<?php
/**
 * Log Repository
 *
 * Database-backed log storage replacing Monolog file-based logging.
 * All operational logs are stored in a single table with agent_id scoping,
 * structured context as JSON, and proper SQL-based filtering/pagination.
 *
 * @package DataMachine\Core\Database\Logs
 * @since   0.43.0
 */

namespace DataMachine\Core\Database\Logs;

use DataMachine\Core\Database\BaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogRepository extends BaseRepository {

	/**
	 * Table name (without prefix).
	 */
	const TABLE_NAME = 'datamachine_logs';

	/**
	 * Valid log levels.
	 */
	const VALID_LEVELS = array( 'debug', 'info', 'warning', 'error', 'critical' );

	/**
	 * Create the logs table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_id BIGINT(20) UNSIGNED DEFAULT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT NOT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_agent_time (agent_id, created_at),
			KEY idx_level_time (level, created_at),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a log entry.
	 *
	 * @param string   $level    Log level (debug, info, warning, error, critical).
	 * @param string   $message  Log message.
	 * @param array    $context  Context data (stored as JSON).
	 * @param int|null $agent_id Agent ID (null for unscoped/system).
	 * @param int|null $user_id  Acting user ID.
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public function log( string $level, string $message, array $context = array(), ?int $agent_id = null, ?int $user_id = null ) {
		if ( ! in_array( $level, self::VALID_LEVELS, true ) ) {
			$level = 'info';
		}

		$data = array(
			'level'      => $level,
			'message'    => $message,
			'created_at' => current_time( 'mysql', true ),
		);

		$formats = array( '%s', '%s', '%s' );

		if ( null !== $agent_id ) {
			$data['agent_id'] = $agent_id;
			$formats[]        = '%d';
		}

		if ( null !== $user_id && $user_id > 0 ) {
			$data['user_id'] = $user_id;
			$formats[]       = '%d';
		}

		if ( ! empty( $context ) ) {
			$data['context'] = wp_json_encode( $context );
			$formats[]       = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $this->wpdb->insert( $this->table_name, $data, $formats );

		if ( false === $inserted ) {
			// Cannot use log_db_error() here — would cause infinite recursion.
			return false;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Get log entries with filters and pagination.
	 *
	 * @param array $filters {
	 *     Optional filters.
	 *
	 *     @type int|null $agent_id    Filter by agent ID. Null = all agents.
	 *     @type string   $level       Filter by level (exact match).
	 *     @type string   $since       ISO datetime — entries after this time.
	 *     @type string   $before      ISO datetime — entries before this time.
	 *     @type int      $job_id      Filter by job_id in context JSON.
	 *     @type int      $flow_id     Filter by flow_id in context JSON.
	 *     @type int      $pipeline_id Filter by pipeline_id in context JSON.
	 *     @type string   $search      Free-text search in message.
	 *     @type int      $per_page    Items per page (default 50, max 500).
	 *     @type int      $page        Page number (1-indexed, default 1).
	 * }
	 * @return array {
	 *     @type array[] $items   Log entries.
	 *     @type int     $total   Total matching entries.
	 *     @type int     $page    Current page.
	 *     @type int     $pages   Total pages.
	 * }
	 */
	public function get_logs( array $filters = array() ): array {
		$where    = array( '1=1' );
		$params   = array();
		$per_page = min( max( (int) ( $filters['per_page'] ?? 50 ), 1 ), 500 );
		$page     = max( (int) ( $filters['page'] ?? 1 ), 1 );
		$offset   = ( $page - 1 ) * $per_page;

		if ( isset( $filters['agent_id'] ) ) {
			if ( null === $filters['agent_id'] ) {
				$where[] = 'agent_id IS NULL';
			} else {
				$where[]  = 'agent_id = %d';
				$params[] = (int) $filters['agent_id'];
			}
		}

		if ( ! empty( $filters['level'] ) && in_array( $filters['level'], self::VALID_LEVELS, true ) ) {
			$where[]  = 'level = %s';
			$params[] = $filters['level'];
		}

		if ( ! empty( $filters['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['since'];
		}

		if ( ! empty( $filters['before'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['before'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $this->wpdb->esc_like( $filters['search'] ) . '%';
		}

		// JSON context filters — use MySQL JSON_EXTRACT or LIKE fallback.
		foreach ( array( 'job_id', 'flow_id', 'pipeline_id' ) as $context_key ) {
			if ( ! empty( $filters[ $context_key ] ) ) {
				$where[]  = 'context LIKE %s';
				$params[] = '%"' . $context_key . '":' . (int) $filters[ $context_key ] . '%';
			}
		}

		$where_sql = implode( ' AND ', $where );

		// Count total.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $params ) ) {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Dynamic query construction with safe values.
			$total = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}",
					...$params
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders
		} else {
			$total = (int) $this->wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}"
			);
		}

		// Fetch items.
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Dynamic query construction with safe values.
		$items        = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				...$query_params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Decode context JSON.
		if ( $items ) {
			foreach ( $items as &$item ) {
				if ( ! empty( $item['context'] ) ) {
					$decoded         = json_decode( $item['context'], true );
					$item['context'] = is_array( $decoded ) ? $decoded : array();
				} else {
					$item['context'] = array();
				}
			}
			unset( $item );
		}

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
			'page'  => $page,
			'pages' => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * Delete log entries older than a given datetime.
	 *
	 * @param string $before_datetime ISO datetime string (UTC).
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function prune_before( string $before_datetime ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		return $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE created_at < %s",
				$before_datetime
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Delete all log entries for a specific agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function clear_for_agent( int $agent_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->delete(
			$this->table_name,
			array( 'agent_id' => $agent_id ),
			array( '%d' )
		);
	}

	/**
	 * Delete all log entries.
	 *
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function clear_all() {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		return $this->wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Get log metadata (row counts and time range).
	 *
	 * @param int|null $agent_id Optional agent ID filter. Null = all.
	 * @return array {
	 *     @type int         $total_entries Total log entry count.
	 *     @type string|null $oldest        Oldest entry datetime (or null).
	 *     @type string|null $newest        Newest entry datetime (or null).
	 * }
	 */
	public function get_metadata( ?int $agent_id = null ): array {
		$where  = '1=1';
		$params = array();

		if ( null !== $agent_id ) {
			$where    = 'agent_id = %d';
			$params[] = $agent_id;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $params ) ) {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Dynamic query construction with safe values.
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT COUNT(*) AS total_entries, MIN(created_at) AS oldest, MAX(created_at) AS newest FROM {$this->table_name} WHERE {$where}",
					...$params
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders
		} else {
			$row = $this->wpdb->get_row(
				"SELECT COUNT(*) AS total_entries, MIN(created_at) AS oldest, MAX(created_at) AS newest FROM {$this->table_name} WHERE {$where}",
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'total_entries' => (int) ( $row['total_entries'] ?? 0 ),
			'oldest'        => $row['oldest'] ?? null,
			'newest'        => $row['newest'] ?? null,
		);
	}

	/**
	 * Get level distribution counts.
	 *
	 * @param int|null $agent_id Optional agent ID filter. Null = all.
	 * @return array<string, int> Level => count mapping.
	 */
	public function get_level_counts( ?int $agent_id = null ): array {
		$where  = '1=1';
		$params = array();

		if ( null !== $agent_id ) {
			$where    = 'agent_id = %d';
			$params[] = $agent_id;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $params ) ) {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Dynamic query construction with safe values.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT level, COUNT(*) AS cnt FROM {$this->table_name} WHERE {$where} GROUP BY level",
					...$params
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders
		} else {
			$rows = $this->wpdb->get_results(
				"SELECT level, COUNT(*) AS cnt FROM {$this->table_name} WHERE {$where} GROUP BY level",
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counts = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$counts[ $row['level'] ] = (int) $row['cnt'];
			}
		}

		return $counts;
	}
}
