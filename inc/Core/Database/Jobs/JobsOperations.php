<?php
/**
 * Jobs Database CRUD Operations
 */

namespace DataMachine\Core\Database\Jobs;

use DataMachine\Core\Database\BaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class JobsOperations extends BaseRepository {

	const TABLE_NAME = 'datamachine_jobs';

	/**
	 * Create a new job record.
	 *
	 * Supports three execution modes:
	 * - Direct execution: pipeline_id='direct', flow_id='direct' (chat/API workflows without saved pipeline/flow)
	 * - Database flow: pipeline_id and flow_id are numeric strings (saved pipelines and flows)
	 * - Standalone: pipeline_id=null, flow_id=null (jobs without pipeline/flow context)
	 *
	 * @param array $job_data Job data with optional pipeline_id and flow_id
	 * @return int|false Job ID on success, false on failure
	 */
	public function create_job( array $job_data ): int|false {

		$pipeline_id = $job_data['pipeline_id'] ?? null;
		$flow_id     = $job_data['flow_id'] ?? null;

		// Direct execution: both must be explicitly 'direct'
		$is_direct_execution = ( 'direct' === $pipeline_id && 'direct' === $flow_id );

		// Database flow: both must be valid numeric IDs > 0
		$is_database_flow = ( is_numeric( $pipeline_id ) && (int) $pipeline_id > 0 && is_numeric( $flow_id ) && (int) $flow_id > 0 );

		// No pipeline/flow context: both are null.
		$is_contextless = ( null === $pipeline_id && null === $flow_id );

		if ( ! $is_direct_execution && ! $is_database_flow && ! $is_contextless ) {
			do_action(
				'datamachine_log',
				'error',
				'Invalid job data: must provide both IDs as "direct", both as valid numeric IDs, or both as null',
				array(
					'pipeline_id' => $pipeline_id,
					'flow_id'     => $flow_id,
				)
			);
			return false;
		}

		// Normalize to string for database storage (null stays null when no context).
		if ( $is_database_flow ) {
			$pipeline_id = (string) absint( $pipeline_id );
			$flow_id     = (string) absint( $flow_id );
		}
		// Direct and contextless keep their values ('direct' or null).

		// Sanitize source — accept any string, don't gatekeep values.
		$default_source = $is_contextless ? 'direct' : ( $is_direct_execution ? 'direct' : 'pipeline' );
		$source         = sanitize_key( $job_data['source'] ?? $default_source );

		$label = isset( $job_data['label'] ) ? sanitize_text_field( $job_data['label'] ) : null;

		$parent_job_id = isset( $job_data['parent_job_id'] ) ? absint( $job_data['parent_job_id'] ) : 0;
		$user_id       = isset( $job_data['user_id'] ) ? absint( $job_data['user_id'] ) : 0;
		$agent_id      = isset( $job_data['agent_id'] ) ? absint( $job_data['agent_id'] ) : null;

		$data = array(
			'user_id'     => $user_id,
			'source'      => $source,
			'label'       => $label,
			'status'      => 'pending',
		);

		$format = array( '%d', '%s', '%s', '%s' );

		if ( null !== $agent_id && $agent_id > 0 ) {
			$data['agent_id'] = $agent_id;
			$format[]         = '%d';
		}

		// Only include pipeline_id/flow_id when they have values (NULL omission lets DB default apply).
		if ( ! $is_contextless ) {
			$data['pipeline_id'] = $pipeline_id;
			$data['flow_id']     = $flow_id;
			$format[]            = '%s';
			$format[]            = '%s';
		}

		if ( $parent_job_id > 0 ) {
			$data['parent_job_id'] = $parent_job_id;
			$format[]              = '%d';
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $this->wpdb->insert( $this->table_name, $data, $format );

		if ( false === $inserted ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to insert job',
				array(
					'pipeline_id' => $pipeline_id,
					'flow_id'     => $flow_id,
					'db_error'    => $this->wpdb->last_error,
				)
			);
			return false;
		}

		$job_id = $this->wpdb->insert_id;

		return $job_id;
	}

	public function get_job( int $job_id ): ?array {
		if ( empty( $job_id ) ) {
			return null;
		}

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$job = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %d', $this->table_name, $job_id ), ARRAY_A );

		if ( $job && isset( $job['engine_data'] ) && is_string( $job['engine_data'] ) ) {
			$decoded = json_decode( $job['engine_data'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$job['engine_data'] = $decoded;
			}
		}

		return $job;
	}

	/**
	 * Get jobs count with optional filtering.
	 *
	 * @param array $args Filter arguments:
	 *                    - flow_id: Filter by flow ID or 'direct' (optional)
	 *                    - pipeline_id: Filter by pipeline ID or 'direct' (optional)
	 *                    - status: Filter by status (optional)
	 * @return int Total count
	 */
	public function get_jobs_count( array $args = array() ): int {
		// Build WHERE clauses for filtering
		$where_clauses = array();
		$where_values  = array();

		if ( ! empty( $args['flow_id'] ) ) {
			$where_clauses[] = 'flow_id = %s';
			$where_values[]  = (string) $args['flow_id'];
		}

		if ( ! empty( $args['pipeline_id'] ) ) {
			$where_clauses[] = 'pipeline_id = %s';
			$where_values[]  = (string) $args['pipeline_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$status_value    = sanitize_text_field( $args['status'] );
			$where_clauses[] = 'status LIKE %s';
			$where_values[]  = $this->wpdb->esc_like( $status_value ) . '%';
		}

		if ( ! empty( $args['source'] ) ) {
			$where_clauses[] = 'source = %s';
			$where_values[]  = sanitize_text_field( $args['source'] );
		}

		if ( isset( $args['user_id'] ) ) {
			$where_clauses[] = 'user_id = %d';
			$where_values[]  = absint( $args['user_id'] );
		}

		if ( isset( $args['agent_id'] ) ) {
			$where_clauses[] = 'agent_id = %d';
			$where_values[]  = absint( $args['agent_id'] );
		}

		if ( ! empty( $args['since'] ) ) {
			$where_clauses[] = 'created_at >= %s';
			$where_values[]  = sanitize_text_field( $args['since'] );
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT COUNT(job_id) FROM %i {$where_sql}",
			array_merge( array( $this->table_name ), $where_values )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		$count = $this->wpdb->get_var( $query );

		return (int) $count;
	}

	/**
	 * Get paginated jobs with pipeline and flow names.
	 *
	 * Supports filtering by flow_id, pipeline_id, and status.
	 *
	 * @param array $args Query arguments:
	 *                    - orderby: Column to order by (default: 'j.job_id')
	 *                    - order: ASC or DESC (default: 'DESC')
	 *                    - per_page: Results per page (default: 20)
	 *                    - offset: Pagination offset (default: 0)
	 *                    - flow_id: Filter by flow ID (optional)
	 *                    - pipeline_id: Filter by pipeline ID (optional)
	 *                    - status: Filter by status (optional)
	 * @return array Jobs with pipeline and flow names
	 */
	public function get_jobs_for_list_table( array $args ): array {
		$orderby  = $args['orderby'] ?? 'j.job_id';
		$order    = strtoupper( $args['order'] ?? 'DESC' );
		$per_page = (int) ( $args['per_page'] ?? 20 );
		$offset   = (int) ( $args['offset'] ?? 0 );

		$pipelines_table = $this->wpdb->prefix . 'datamachine_pipelines';
		$flows_table     = $this->wpdb->prefix . 'datamachine_flows';

		// Validate order direction
		$order = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		// Validate orderby column (whitelist approach)
		$valid_orderby = array(
			'j.job_id',
			'j.pipeline_id',
			'j.flow_id',
			'j.status',
			'j.created_at',
			'j.completed_at',
			'p.pipeline_name',
			'f.flow_name',
		);
		if ( ! in_array( $orderby, $valid_orderby, true ) ) {
			$orderby = 'j.job_id';
		}

		// Build WHERE clauses for filtering
		$where_clauses = array();
		$where_values  = array();

		if ( ! empty( $args['flow_id'] ) ) {
			$where_clauses[] = 'j.flow_id = %s';
			$where_values[]  = (string) $args['flow_id'];
		}

		if ( ! empty( $args['pipeline_id'] ) ) {
			$where_clauses[] = 'j.pipeline_id = %s';
			$where_values[]  = (string) $args['pipeline_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$status_value = sanitize_text_field( $args['status'] );
			// Prefix match: --status=failed matches "failed", "failed:reason", etc.
			$where_clauses[] = 'j.status LIKE %s';
			$where_values[]  = $this->wpdb->esc_like( $status_value ) . '%';
		}

		if ( ! empty( $args['source'] ) ) {
			$where_clauses[] = 'j.source = %s';
			$where_values[]  = sanitize_text_field( $args['source'] );
		}

		if ( isset( $args['user_id'] ) ) {
			$where_clauses[] = 'j.user_id = %d';
			$where_values[]  = absint( $args['user_id'] );
		}

		if ( isset( $args['agent_id'] ) ) {
			$where_clauses[] = 'j.agent_id = %d';
			$where_values[]  = absint( $args['agent_id'] );
		}

		if ( ! empty( $args['since'] ) ) {
			$where_clauses[] = 'j.created_at >= %s';
			$where_values[]  = sanitize_text_field( $args['since'] );
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		// Build the full query
		// Note: orderby is validated above, so safe to interpolate
		// For direct execution jobs, LEFT JOINs will return NULL for pipeline_name/flow_name
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT j.*, p.pipeline_name, f.flow_name
             FROM %i j
             LEFT JOIN %i p ON j.pipeline_id = CAST(p.pipeline_id AS CHAR)
             LEFT JOIN %i f ON j.flow_id = CAST(f.flow_id AS CHAR)
             {$where_sql}
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
			array_merge(
				array( $this->table_name, $pipelines_table, $flows_table ),
				$where_values,
				array( $per_page, $offset )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		$results = $this->wpdb->get_results( $query, ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Get all jobs for pipeline deletion impact analysis.
	 */
	public function get_jobs_for_pipeline( int $pipeline_id ): array {
		if ( $pipeline_id <= 0 ) {
			return array();
		}

		$db_flows = new \DataMachine\Core\Database\Flows\Flows();

		$flows = $db_flows->get_flows_for_pipeline( $pipeline_id );
		if ( empty( $flows ) ) {
			return array();
		}

		$all_jobs = array();
		foreach ( $flows as $flow ) {
			$flow_id   = $flow['flow_id'];
			$flow_jobs = $this->get_jobs_for_flow( $flow_id );
			$all_jobs  = array_merge( $all_jobs, $flow_jobs );
		}

		if ( ! empty( $all_jobs ) ) {
			usort(
				$all_jobs,
				function ( $a, $b ) {
					$time_a = is_array( $a ) ? $a['created_at'] : $a->created_at;
					$time_b = is_array( $b ) ? $b['created_at'] : $b->created_at;
					return strcmp( $time_b, $time_a ); // DESC order
				}
			);
		}

		return $all_jobs;
	}

	/**
	 * Get all jobs for a flow.
	 *
	 * @param int|string $flow_id Flow ID or 'direct'
	 * @return array Jobs for the flow
	 */
	public function get_jobs_for_flow( int|string $flow_id ): array {

		if ( empty( $flow_id ) ) {
			return array();
		}

		// Skip if numeric and <= 0 (but allow 'direct' string)
		if ( is_numeric( $flow_id ) && (int) $flow_id <= 0 ) {
			return array();
		}

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i WHERE flow_id = %s ORDER BY created_at DESC', $this->table_name, (string) $flow_id ), ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Get the latest job for each flow in a batch.
	 *
	 * Uses a subquery to efficiently get the most recent job per flow_id.
	 *
	 * @param array $flow_ids Array of flow IDs to query (numeric IDs only, not 'direct')
	 * @return array Map of [flow_id => job_row] for flows that have jobs
	 */
	public function get_latest_jobs_by_flow_ids( array $flow_ids ): array {
		if ( empty( $flow_ids ) ) {
			return array();
		}

		// Filter to numeric IDs only (this method is for database flows, not direct execution)
		$flow_ids = array_filter( $flow_ids, fn( $id ) => is_numeric( $id ) && (int) $id > 0 );
		$flow_ids = array_map( fn( $id ) => (string) $id, $flow_ids );

		if ( empty( $flow_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $flow_ids ), '%s' ) );

		// Subquery to get max job_id per flow, then join to get full row
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT j.* FROM %i j
             INNER JOIN (
                 SELECT flow_id, MAX(job_id) as max_job_id
                 FROM %i
                 WHERE flow_id IN ({$placeholders})
                 GROUP BY flow_id
             ) latest ON j.job_id = latest.max_job_id",
			array_merge( array( $this->table_name, $this->table_name ), $flow_ids )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		$results = $this->wpdb->get_results( $query, ARRAY_A );

		if ( ! $results ) {
			return array();
		}

		// Key by flow_id for easy lookup (keep as string for consistency)
		$jobs_by_flow = array();
		foreach ( $results as $job ) {
			$jobs_by_flow[ $job['flow_id'] ] = $job;
		}

		return $jobs_by_flow;
	}

	/**
	 * Delete old jobs by status and age.
	 *
	 * Removes jobs matching the given status pattern that are older than
	 * the specified number of days. Uses LIKE matching to handle compound
	 * statuses (e.g., "failed - timeout").
	 *
	 * @since 0.28.0
	 *
	 * @param string $status_pattern Base status to match (e.g., 'failed'). Uses LIKE prefix matching.
	 * @param int    $older_than_days Delete jobs older than this many days.
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function delete_old_jobs( string $status_pattern, int $older_than_days ): int|false {
		if ( empty( $status_pattern ) || $older_than_days < 1 ) {
			return false;
		}

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
		$like_pattern    = $this->wpdb->esc_like( $status_pattern ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE status LIKE %s AND created_at < %s',
				$this->table_name,
				$like_pattern,
				$cutoff_datetime
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		do_action(
			'datamachine_log',
			'info',
			'Deleted old jobs',
			array(
				'status_pattern'  => $status_pattern,
				'older_than_days' => $older_than_days,
				'cutoff_datetime' => $cutoff_datetime,
				'jobs_deleted'    => false !== $result ? $result : 0,
				'success'         => false !== $result,
			)
		);

		return $result;
	}

	/**
	 * Count jobs matching a status pattern older than a given age.
	 *
	 * @since 0.28.0
	 *
	 * @param string $status_pattern Base status to match (e.g., 'failed').
	 * @param int    $older_than_days Count jobs older than this many days.
	 * @return int Number of matching jobs.
	 */
	public function count_old_jobs( string $status_pattern, int $older_than_days ): int {
		if ( empty( $status_pattern ) || $older_than_days < 1 ) {
			return 0;
		}

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
		$like_pattern    = $this->wpdb->esc_like( $status_pattern ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status LIKE %s AND created_at < %s',
				$this->table_name,
				$like_pattern,
				$cutoff_datetime
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return (int) $count;
	}

	/**
	 * Delete jobs by status criteria or all jobs.
	 */
	public function delete_jobs( array $criteria = array() ): int|false {

		if ( empty( $criteria ) ) {
			do_action( 'datamachine_log', 'warning', 'No criteria provided for jobs deletion' );
			return false;
		}

		if ( ! empty( $criteria['failed'] ) ) {
			$failed_pattern = $this->wpdb->esc_like( 'failed' ) . '%';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE status LIKE %s', $this->table_name, $failed_pattern ) );
		} else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i', $this->table_name ) );
		}

		do_action(
			'datamachine_log',
			'debug',
			'Deleted jobs',
			array(
				'criteria'     => $criteria,
				'jobs_deleted' => false !== $result ? $result : 0,
				'success'      => false !== $result,
			)
		);

		return $result;
	}

	/**
	 * Store engine data for centralized access via datamachine_engine_data filter.
	 */
	public function store_engine_data( int $job_id, array $data ): bool {
		if ( $job_id <= 0 ) {
			do_action( 'datamachine_log', 'error', 'Invalid job ID for engine_data storage', array( 'job_id' => $job_id ) );
			return false;
		}

		// Encode data as JSON for database storage
		$encoded = wp_json_encode( $data );
		$result  = $this->wpdb->update(
			$this->table_name,
			array( 'engine_data' => $encoded ),
			array( 'job_id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to store engine_data',
				array(
					'job_id'   => $job_id,
					'db_error' => $this->wpdb->last_error,
				)
			);
			return false;
		}

		do_action(
			'datamachine_log',
			'debug',
			'Stored engine_data successfully',
			array(
				'job_id'    => $job_id,
				'data_keys' => array_keys( $data ),
			)
		);

		return true;
	}

	/**
	 * Retrieve stored engine data for datamachine_engine_data filter access.
	 */
	public function retrieve_engine_data( int $job_id ): array {
		$job = $this->get_job( $job_id );

		if ( $job && isset( $job['engine_data'] ) && is_array( $job['engine_data'] ) ) {
			return $job['engine_data'];
		}

		return array();
	}

	/**
	 * Get flows with consecutive failures or no_items above threshold.
	 *
	 * Uses cached flow health data when available.
	 * Only checks database flows (numeric IDs), not direct execution jobs.
	 *
	 * @param int $threshold Minimum consecutive count to flag as problem.
	 * @return array Array of [flow_id => counts_array] for problem flows.
	 */
	public function get_problem_flow_ids( int $threshold = 3 ): array {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query = $this->wpdb->prepare(
			"SELECT DISTINCT flow_id FROM %i WHERE flow_id != 'direct' AND flow_id REGEXP '^[0-9]+$'",
			$this->table_name
		);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$flow_ids = $this->wpdb->get_col( $query );

		if ( empty( $flow_ids ) ) {
			return array();
		}

		$problem_flows = array();

		foreach ( $flow_ids as $flow_id ) {
			$counts = $this->get_flow_health( $flow_id );

			if ( $counts['consecutive_failures'] >= $threshold || $counts['consecutive_no_items'] >= $threshold ) {
				$problem_flows[ $flow_id ] = $counts;
			}
		}

		return $problem_flows;
	}

	/**
	 * Get recent jobs for a flow (limited).
	 *
	 * @param int|string $flow_id Flow ID.
	 * @param int        $limit   Max jobs to return.
	 * @return array Recent jobs, newest first.
	 */
	public function get_recent_jobs_for_flow( int|string $flow_id, int $limit = 10 ): array {
		if ( empty( $flow_id ) ) {
			return array();
		}

		if ( is_numeric( $flow_id ) && (int) $flow_id <= 0 ) {
			return array();
		}

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE flow_id = %s ORDER BY created_at DESC LIMIT %d',
				$this->table_name,
				(string) $flow_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return $results ? $results : array();
	}

	/**
	 * Get cached flow health or compute if missing.
	 *
	 * @param int|string $flow_id Flow ID.
	 * @return array Counts array.
	 */
	public function get_flow_health( int|string $flow_id ): array {
		$cached = $this->get_flow_health_cache( $flow_id );
		if ( false !== $cached ) {
			return $cached;
		}

		$counts = $this->compute_consecutive_counts( $flow_id );
		$this->set_flow_health_cache( $flow_id, $counts );

		return $counts;
	}

	/**
	 * Update flow health cache when a job completes.
	 *
	 * Called via datamachine_job_complete hook.
	 *
	 * @param int    $job_id Job ID that completed.
	 * @param string $status Final status.
	 */
	public function update_flow_health_cache( int $job_id, string $status ): void {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return;
		}

		$flow_id = $job['flow_id'];

		// Skip direct execution jobs
		if ( 'direct' === $flow_id || ! is_numeric( $flow_id ) ) {
			return;
		}

		// Compute fresh counts and cache
		$counts = $this->compute_consecutive_counts( $flow_id );
		$this->set_flow_health_cache( $flow_id, $counts );
	}

	/**
	 * Get flow health from transient cache.
	 *
	 * @param int|string $flow_id Flow ID.
	 * @return array|false Cached counts or false if not cached.
	 */
	private function get_flow_health_cache( int|string $flow_id ): array|false {
		return get_transient( "datamachine_flow_health_{$flow_id}" );
	}

	/**
	 * Set flow health in transient cache.
	 *
	 * @param int|string $flow_id Flow ID.
	 * @param array      $counts  Counts to cache.
	 */
	private function set_flow_health_cache( int|string $flow_id, array $counts ): void {
		set_transient( "datamachine_flow_health_{$flow_id}", $counts, DAY_IN_SECONDS );
	}

	/**
	 * Compute consecutive counts from recent job history.
	 *
	 * @param int|string $flow_id Flow ID.
	 * @return array Counts array.
	 */
	private function compute_consecutive_counts( int|string $flow_id ): array {
		$jobs = $this->get_recent_jobs_for_flow( $flow_id, 10 );

		$result = array(
			'consecutive_failures' => 0,
			'consecutive_no_items' => 0,
			'latest_job'           => $jobs[0] ?? null,
		);

		if ( empty( $jobs ) ) {
			return $result;
		}

		// Count consecutive failures from most recent
		foreach ( $jobs as $job ) {
			if ( \DataMachine\Core\JobStatus::isStatusFailure( $job['status'] ) ) {
				++$result['consecutive_failures'];
			} else {
				break;
			}
		}

		// Count consecutive no_items from most recent
		foreach ( $jobs as $job ) {
			if ( str_starts_with( $job['status'], 'completed_no_items' ) ) {
				++$result['consecutive_no_items'];
			} else {
				break;
			}
		}

		return $result;
	}
}
