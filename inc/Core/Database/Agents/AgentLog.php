<?php
/**
 * Agent Audit Log Repository
 *
 * Structured audit trail for agent actions. Every ability invocation,
 * permission check, and resource mutation by an agent is recorded here.
 *
 * Separate from Monolog operational logs — this is queryable by agent,
 * action, time range, and result for compliance and debugging.
 *
 * @package DataMachine\Core\Database\Agents
 * @since 0.42.0
 */

namespace DataMachine\Core\Database\Agents;

use DataMachine\Core\Database\BaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentLog extends BaseRepository {

	/**
	 * Table name (without prefix).
	 */
	const TABLE_NAME = 'datamachine_agent_log';

	/**
	 * Valid result values.
	 */
	const VALID_RESULTS = array( 'allowed', 'denied', 'error' );

	/**
	 * Create agent_log table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			action VARCHAR(255) NOT NULL,
			resource_type VARCHAR(100) DEFAULT NULL,
			resource_id BIGINT(20) UNSIGNED DEFAULT NULL,
			result VARCHAR(20) NOT NULL DEFAULT 'allowed',
			metadata LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_agent_time (agent_id, created_at),
			KEY idx_action (action),
			KEY idx_result_time (result, created_at),
			KEY idx_resource (resource_type, resource_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record an audit log entry.
	 *
	 * @param int    $agent_id      Agent ID.
	 * @param string $action        Action identifier (e.g. 'flow.run', 'pipeline.create').
	 * @param string $result        Result: 'allowed', 'denied', or 'error'.
	 * @param array  $options {
	 *     Optional parameters.
	 *
	 *     @type int    $user_id       Acting user ID.
	 *     @type string $resource_type Resource type (e.g. 'flow', 'pipeline', 'job').
	 *     @type int    $resource_id   Resource ID.
	 *     @type array  $metadata      Additional context (stored as JSON).
	 * }
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public function log( int $agent_id, string $action, string $result = 'allowed', array $options = array() ) {
		if ( ! in_array( $result, self::VALID_RESULTS, true ) ) {
			$result = 'allowed';
		}

		$data = array(
			'agent_id'   => $agent_id,
			'action'     => mb_substr( $action, 0, 255 ),
			'result'     => $result,
			'created_at' => current_time( 'mysql', true ),
		);

		$formats = array( '%d', '%s', '%s', '%s' );

		// Optional user_id.
		if ( isset( $options['user_id'] ) && $options['user_id'] > 0 ) {
			$data['user_id'] = (int) $options['user_id'];
			$formats[]       = '%d';
		}

		// Optional resource_type.
		if ( ! empty( $options['resource_type'] ) ) {
			$data['resource_type'] = mb_substr( sanitize_text_field( $options['resource_type'] ), 0, 100 );
			$formats[]             = '%s';
		}

		// Optional resource_id.
		if ( isset( $options['resource_id'] ) && $options['resource_id'] > 0 ) {
			$data['resource_id'] = (int) $options['resource_id'];
			$formats[]           = '%d';
		}

		// Optional metadata.
		if ( ! empty( $options['metadata'] ) && is_array( $options['metadata'] ) ) {
			$data['metadata'] = wp_json_encode( $options['metadata'] );
			$formats[]        = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $this->wpdb->insert( $this->table_name, $data, $formats );

		if ( false === $inserted ) {
			$this->log_db_error( 'AgentLog::log insert failed', array( 'agent_id' => $agent_id, 'action' => $action ) );
			return false;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Get audit log entries for an agent with optional filters.
	 *
	 * @param int   $agent_id Agent ID.
	 * @param array $filters {
	 *     Optional filters.
	 *
	 *     @type string $action        Filter by action (exact match).
	 *     @type string $result        Filter by result.
	 *     @type string $resource_type Filter by resource type.
	 *     @type string $since         ISO datetime — only entries after this time.
	 *     @type string $before        ISO datetime — only entries before this time.
	 *     @type int    $per_page      Items per page (default 50, max 200).
	 *     @type int    $page          Page number (1-indexed, default 1).
	 * }
	 * @return array {
	 *     @type array[] $items   Log entries.
	 *     @type int     $total   Total matching entries.
	 *     @type int     $page    Current page.
	 *     @type int     $pages   Total pages.
	 * }
	 */
	public function get_for_agent( int $agent_id, array $filters = array() ): array {
		$where      = array( 'agent_id = %d' );
		$params     = array( $agent_id );
		$per_page   = min( max( (int) ( $filters['per_page'] ?? 50 ), 1 ), 200 );
		$page       = max( (int) ( $filters['page'] ?? 1 ), 1 );
		$offset     = ( $page - 1 ) * $per_page;

		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = $filters['action'];
		}

		if ( ! empty( $filters['result'] ) && in_array( $filters['result'], self::VALID_RESULTS, true ) ) {
			$where[]  = 'result = %s';
			$params[] = $filters['result'];
		}

		if ( ! empty( $filters['resource_type'] ) ) {
			$where[]  = 'resource_type = %s';
			$params[] = $filters['resource_type'];
		}

		if ( ! empty( $filters['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['since'];
		}

		if ( ! empty( $filters['before'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['before'];
		}

		$where_sql = implode( ' AND ', $where );

		// Count total.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}",
				...$params
			)
		);

		// Fetch items.
		$items = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Decode metadata JSON.
		if ( $items ) {
			foreach ( $items as &$item ) {
				if ( ! empty( $item['metadata'] ) ) {
					$decoded          = json_decode( $item['metadata'], true );
					$item['metadata'] = is_array( $decoded ) ? $decoded : array();
				} else {
					$item['metadata'] = array();
				}
			}
			unset( $item );
		}

		return array(
			'items' => $items ?: array(),
			'total' => $total,
			'page'  => $page,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Get recent log entries for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $limit    Max entries to return (default 20).
	 * @return array[] Log entries.
	 */
	public function get_recent( int $agent_id, int $limit = 20 ): array {
		$result = $this->get_for_agent( $agent_id, array( 'per_page' => $limit ) );
		return $result['items'];
	}

	/**
	 * Count log entries for an agent.
	 *
	 * @param int   $agent_id Agent ID.
	 * @param array $filters  Same filters as get_for_agent().
	 * @return int Entry count.
	 */
	public function count_for_agent( int $agent_id, array $filters = array() ): int {
		$result = $this->get_for_agent( $agent_id, array_merge( $filters, array( 'per_page' => 1 ) ) );
		return $result['total'];
	}

	/**
	 * Delete log entries older than a given datetime.
	 *
	 * @param string $before_datetime ISO datetime string (UTC).
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function prune_before( string $before_datetime ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE created_at < %s",
				$before_datetime
			)
		);

		return $deleted;
	}
}
