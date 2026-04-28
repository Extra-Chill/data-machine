<?php

namespace DataMachine\Core\Database\Flows;

use DataMachine\Core\Database\BaseRepository;

/**
 * Flows Database Class
 *
 * Manages flow instances that execute pipeline configurations with specific handler settings
 * and scheduling. Flow-level scheduling only - no pipeline-level scheduling.
 * Admin-only implementation.
 */
class Flows extends BaseRepository {

	const TABLE_NAME = 'datamachine_flows';

	public static function create_table(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'datamachine_flows';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            flow_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            pipeline_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            agent_id bigint(20) unsigned DEFAULT NULL,
            flow_name varchar(255) NOT NULL,
            portable_slug varchar(191) DEFAULT NULL,
            flow_config longtext NOT NULL,
            scheduling_config longtext NOT NULL,
            PRIMARY KEY (flow_id),
            KEY pipeline_id (pipeline_id),
            KEY user_id (user_id),
            KEY agent_id (agent_id),
            UNIQUE KEY pipeline_portable_slug (pipeline_id, portable_slug)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$result = dbDelta( $sql );

		do_action(
			'datamachine_log',
			'debug',
			'Flows table creation completed',
			array(
				'table_name' => $table_name,
				'result'     => $result,
			)
		);
	}

	/**
	 * Migrate existing table columns to current schema.
	 *
	 * Handles:
	 * - user_id column: added for multi-agent support
	 *
	 * Safe to run multiple times - only executes if columns need updating.
	 */
	public function migrate_columns(): void {
		// Check if user_id column already exists.
		if ( ! self::column_exists( $this->table_name, 'user_id', $this->wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
			$result = $this->wpdb->query(
				"ALTER TABLE {$this->table_name}
				 ADD COLUMN user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				 ADD KEY user_id (user_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add user_id column to flows table',
					array(
						'table_name' => $this->table_name,
						'db_error'   => $this->wpdb->last_error,
					)
				);
				return;
			}

			do_action(
				'datamachine_log',
				'info',
				'Added user_id column to flows table for multi-agent support',
				array( 'table_name' => $this->table_name )
			);
		}

		// Add agent_id column for agent-first scoping (#735).
		if ( ! self::column_exists( $this->table_name, 'agent_id', $this->wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
			$result = $this->wpdb->query(
				"ALTER TABLE {$this->table_name}
				 ADD COLUMN agent_id bigint(20) unsigned DEFAULT NULL,
				 ADD KEY agent_id (agent_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add agent_id column to flows table',
					array(
						'table_name' => $this->table_name,
						'db_error'   => $this->wpdb->last_error,
					)
				);
				return;
			}

			do_action(
				'datamachine_log',
				'info',
				'Added agent_id column to flows table for agent-first scoping',
				array( 'table_name' => $this->table_name )
			);
		}

		// Stable bundle filename/reference within a pipeline (#1303).
		if ( ! self::column_exists( $this->table_name, 'portable_slug', $this->wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
			$result = $this->wpdb->query(
				"ALTER TABLE {$this->table_name}
				 ADD COLUMN portable_slug varchar(191) DEFAULT NULL,
				 ADD UNIQUE KEY pipeline_portable_slug (pipeline_id, portable_slug)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add portable_slug column to flows table',
					array(
						'table_name' => $this->table_name,
						'db_error'   => $this->wpdb->last_error,
					)
				);
				return;
			}

			do_action(
				'datamachine_log',
				'info',
				'Added portable_slug column to flows table for agent bundles',
				array( 'table_name' => $this->table_name )
			);
		}
	}

	public function create_flow( array $flow_data ) {

		// Validate required fields
		$required_fields = array( 'pipeline_id', 'flow_name', 'flow_config', 'scheduling_config' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $flow_data[ $field ] ) ) {
				do_action(
					'datamachine_log',
					'error',
					'Missing required field for flow creation',
					array(
						'missing_field' => $field,
						'provided_data' => array_keys( $flow_data ),
					)
				);
				return false;
			}
		}

		$flow_config       = wp_json_encode( $flow_data['flow_config'] );
		$scheduling_config = wp_json_encode( $flow_data['scheduling_config'] );

		$user_id  = isset( $flow_data['user_id'] ) ? absint( $flow_data['user_id'] ) : 0;
		$agent_id = isset( $flow_data['agent_id'] ) ? absint( $flow_data['agent_id'] ) : null;

		$insert_data = array(
			'pipeline_id'       => intval( $flow_data['pipeline_id'] ),
			'user_id'           => $user_id,
			'flow_name'         => sanitize_text_field( $flow_data['flow_name'] ),
			'flow_config'       => $flow_config,
			'scheduling_config' => $scheduling_config,
		);

		$insert_format = array(
			'%d', // pipeline_id
			'%d', // user_id
			'%s', // flow_name
			'%s', // flow_config
			'%s',  // scheduling_config
		);

		if ( null !== $agent_id && $agent_id > 0 ) {
			$insert_data['agent_id'] = $agent_id;
			$insert_format[]         = '%d';
		}

		if ( isset( $flow_data['portable_slug'] ) && '' !== trim( (string) $flow_data['portable_slug'] ) ) {
			$insert_data['portable_slug'] = sanitize_title( (string) $flow_data['portable_slug'] );
			$insert_format[]              = '%s';
		}

		$result = $this->wpdb->insert(
			$this->table_name,
			$insert_data,
			$insert_format
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to create flow',
				array(
					'wpdb_error' => $this->wpdb->last_error,
					'flow_data'  => $flow_data,
				)
			);
			return false;
		}

		$flow_id = $this->wpdb->insert_id;

		do_action(
			'datamachine_log',
			'debug',
			'Flow created successfully',
			array(
				'flow_id'     => $flow_id,
				'pipeline_id' => $flow_data['pipeline_id'],
				'flow_name'   => $flow_data['flow_name'],
			)
		);

		return $flow_id;
	}

	public function get_flow( int $flow_id ): ?array {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$flow = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE flow_id = %d', $this->table_name, $flow_id ), ARRAY_A );

		if ( null === $flow ) {
			do_action(
				'datamachine_log',
				'warning',
				'Flow not found',
				array(
					'flow_id' => $flow_id,
				)
			);
			return null;
		}

		$flow['flow_config']       = json_decode( $flow['flow_config'], true ) ?? array();
		$flow['scheduling_config'] = json_decode( $flow['scheduling_config'], true ) ?? array();

		return $flow;
	}

	/**
	 * Get a flow by stable bundle portable slug within a pipeline.
	 */
	public function get_by_portable_slug( int $pipeline_id, string $portable_slug ): ?array {
		$portable_slug = sanitize_title( $portable_slug );
		if ( $pipeline_id <= 0 || '' === $portable_slug ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$flow = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE pipeline_id = %d AND portable_slug = %s LIMIT 1',
				$this->table_name,
				$pipeline_id,
				$portable_slug
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $flow ) {
			return null;
		}

		$flow['flow_config']       = json_decode( $flow['flow_config'], true ) ?? array();
		$flow['scheduling_config'] = json_decode( $flow['scheduling_config'], true ) ?? array();

		return $flow;
	}

	/**
	 * Get the raw flow_config JSON blob for compare-and-swap updates.
	 *
	 * @param int $flow_id Flow ID.
	 * @return string|null Raw JSON string, or null when the flow is missing.
	 */
	public function get_flow_config_json( int $flow_id ): ?string {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT flow_config FROM %i WHERE flow_id = %d',
				$this->table_name,
				$flow_id
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Atomically replace flow_config only if it still matches the expected JSON.
	 *
	 * Used by queue consumers to avoid two workers consuming the same head item
	 * from the longtext-backed flow_config queue slots.
	 *
	 * @param int    $flow_id              Flow ID.
	 * @param string $expected_config_json Raw flow_config JSON read before mutation.
	 * @param array  $new_flow_config      New decoded flow_config to persist.
	 * @return bool True when the compare-and-swap updated one row.
	 */
	public function compare_and_swap_flow_config( int $flow_id, string $expected_config_json, array $new_flow_config ): bool {
		$new_config_json = wp_json_encode( $new_flow_config );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET flow_config = %s WHERE flow_id = %d AND flow_config = %s',
				$this->table_name,
				$new_config_json,
				$flow_id,
				$expected_config_json
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to compare-and-swap flow_config',
				array(
					'flow_id'    => $flow_id,
					'wpdb_error' => $this->wpdb->last_error,
				)
			);
			return false;
		}

		return 1 === (int) $result;
	}

	public function get_flows_for_pipeline( int $pipeline_id ): array {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$flows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i WHERE pipeline_id = %d ORDER BY flow_id ASC', $this->table_name, $pipeline_id ), ARRAY_A );

		if ( null === $flows ) {
			do_action(
				'datamachine_log',
				'warning',
				'No flows found for pipeline',
				array(
					'pipeline_id' => $pipeline_id,
				)
			);
			return array();
		}

		foreach ( $flows as &$flow ) {
			$flow['flow_config']       = json_decode( $flow['flow_config'], true ) ?? array();
			$flow['scheduling_config'] = json_decode( $flow['scheduling_config'], true ) ?? array();
		}

		return $flows;
	}

	/**
	 * Get all flows across all pipelines.
	 *
	 * Used for global operations like handler-based filtering across the entire system.
	 *
	 * @param int|null $user_id  Optional user ID to filter by.
	 * @param int|null $agent_id Optional agent ID to filter by.
	 * @return array All flows with decoded configs.
	 */
	public function get_all_flows( ?int $user_id = null, ?int $agent_id = null ): array {
		$where        = '';
		$where_values = array();

		if ( null !== $agent_id ) {
			$where          = ' WHERE agent_id = %d';
			$where_values[] = $agent_id;
		} elseif ( null !== $user_id ) {
			$where          = ' WHERE user_id = %d';
			$where_values[] = $user_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
		$flows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i{$where} ORDER BY pipeline_id ASC, flow_id ASC",
				array_merge( array( $this->table_name ), $where_values )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared

		if ( null === $flows ) {
			return array();
		}

		foreach ( $flows as &$flow ) {
			$flow['flow_config']       = json_decode( $flow['flow_config'], true ) ?? array();
			$flow['scheduling_config'] = json_decode( $flow['scheduling_config'], true ) ?? array();
		}

		return $flows;
	}

	/**
	 * Get paginated flows for a pipeline
	 *
	 * @param int $pipeline_id Pipeline ID
	 * @param int $per_page    Number of flows per page
	 * @param int $offset      Offset for pagination
	 * @return array Flows array
	 */
	public function get_flows_for_pipeline_paginated( int $pipeline_id, int $per_page = 20, int $offset = 0 ): array {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$flows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE pipeline_id = %d ORDER BY flow_id ASC LIMIT %d OFFSET %d',
				$this->table_name,
				$pipeline_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( null === $flows ) {
			return array();
		}

		foreach ( $flows as &$flow ) {
			$flow['flow_config']       = json_decode( $flow['flow_config'], true ) ?? array();
			$flow['scheduling_config'] = json_decode( $flow['scheduling_config'], true ) ?? array();
		}

		return $flows;
	}

	/**
	 * Count total flows for a pipeline
	 *
	 * @param int $pipeline_id Pipeline ID
	 * @return int Total count
	 */
	public function count_flows_for_pipeline( int $pipeline_id ): int {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE pipeline_id = %d',
				$this->table_name,
				$pipeline_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return (int) ( $count ?? 0 );
	}

	/**
	 * Count flows grouped by pipeline ID.
	 *
	 * Single aggregate query replacing N per-pipeline COUNT queries — used by
	 * the Pipelines admin list to show a flow_count per pipeline without
	 * embedding full flow records.
	 *
	 * @since 0.60.0
	 *
	 * @param array $pipeline_ids Pipeline IDs to count flows for.
	 * @return array<int, int> Map of pipeline_id => flow_count. Missing pipelines return 0.
	 */
	public function count_flows_grouped_by_pipeline( array $pipeline_ids ): array {
		$result = array();
		foreach ( $pipeline_ids as $pid ) {
			$result[ (int) $pid ] = 0;
		}

		if ( empty( $pipeline_ids ) ) {
			return $result;
		}

		$pipeline_ids = array_map( 'intval', $pipeline_ids );
		$placeholders = implode( ',', array_fill( 0, count( $pipeline_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT pipeline_id, COUNT(*) AS flow_count
				FROM %i
				WHERE pipeline_id IN ({$placeholders})
				GROUP BY pipeline_id",
				array_merge( array( $this->table_name ), $pipeline_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared

		if ( null === $rows ) {
			return $result;
		}

		foreach ( $rows as $row ) {
			$result[ (int) $row['pipeline_id'] ] = (int) $row['flow_count'];
		}

		return $result;
	}

	/**
	 * Get all flows with pagination (single query, no per-pipeline loop).
	 *
	 * @since 0.54.2
	 *
	 * @param int      $per_page  Number of flows per page.
	 * @param int      $offset    Offset for pagination.
	 * @param int|null $user_id   Optional user ID filter.
	 * @param int|null $agent_id  Optional agent ID filter (takes priority over user_id).
	 * @return array Paginated flows.
	 */
	public function get_all_flows_paginated( int $per_page = 20, int $offset = 0, ?int $user_id = null, ?int $agent_id = null ): array {
		$where        = '';
		$where_values = array();

		if ( null !== $agent_id ) {
			$where          = ' WHERE agent_id = %d';
			$where_values[] = $agent_id;
		} elseif ( null !== $user_id ) {
			$where          = ' WHERE user_id = %d';
			$where_values[] = $user_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
		$flows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i{$where} ORDER BY pipeline_id ASC, flow_id ASC LIMIT %d OFFSET %d",
				array_merge( array( $this->table_name ), $where_values, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared

		if ( null === $flows ) {
			return array();
		}

		foreach ( $flows as &$flow ) {
			$flow['flow_config']       = json_decode( $flow['flow_config'], true ) ?? array();
			$flow['scheduling_config'] = json_decode( $flow['scheduling_config'], true ) ?? array();
		}

		return $flows;
	}

	/**
	 * Count all flows with optional user/agent filter.
	 *
	 * @since 0.54.2
	 *
	 * @param int|null $user_id  Optional user ID filter.
	 * @param int|null $agent_id Optional agent ID filter (takes priority over user_id).
	 * @return int Total flow count.
	 */
	public function count_all_flows( ?int $user_id = null, ?int $agent_id = null ): int {
		$where        = '';
		$where_values = array();

		if ( null !== $agent_id ) {
			$where          = ' WHERE agent_id = %d';
			$where_values[] = $agent_id;
		} elseif ( null !== $user_id ) {
			$where          = ' WHERE user_id = %d';
			$where_values[] = $user_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM %i{$where}",
				array_merge( array( $this->table_name ), $where_values )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared

		return (int) ( $count ?? 0 );
	}

	/**
	 * Get all flows with only summary columns (no flow_config longtext).
	 *
	 * Returns flow_id, flow_name, pipeline_id, scheduling_config, user_id,
	 * and agent_id. Skips the large flow_config column for significantly
	 * faster queries with many flows.
	 *
	 * @since 0.66.1
	 *
	 * @param int      $per_page Items per page.
	 * @param int      $offset   Pagination offset.
	 * @param int|null $user_id  Optional user ID filter.
	 * @param int|null $agent_id Optional agent ID filter.
	 * @return array Paginated flows without flow_config.
	 */
	public function get_all_flows_summary( int $per_page = 20, int $offset = 0, ?int $user_id = null, ?int $agent_id = null ): array {
		$where        = '';
		$where_values = array();

		if ( null !== $agent_id ) {
			$where          = ' WHERE agent_id = %d';
			$where_values[] = $agent_id;
		} elseif ( null !== $user_id ) {
			$where          = ' WHERE user_id = %d';
			$where_values[] = $user_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
		$flows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT flow_id, flow_name, pipeline_id, scheduling_config, user_id, agent_id FROM %i{$where} ORDER BY pipeline_id ASC, flow_id ASC LIMIT %d OFFSET %d",
				array_merge( array( $this->table_name ), $where_values, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared

		if ( null === $flows ) {
			return array();
		}

		foreach ( $flows as &$flow ) {
			$flow['scheduling_config'] = json_decode( $flow['scheduling_config'], true ) ?? array();
			$flow['flow_config']       = array(); // Not loaded — placeholder for consistent interface.
		}

		return $flows;
	}

	/**
	 * Get flows with consecutive failures or consecutive no-items at or above threshold.
	 *
	 * Returns flows that either:
	 * - Have consecutive_failures >= threshold (something is broken)
	 * - Have consecutive_no_items >= threshold (source is slow/exhausted)
	 *
	 * Consecutive counts are computed from job history (single source of truth).
	 *
	 * @param int $threshold Minimum consecutive count to include
	 * @return array Problem flows with pipeline info and both counters
	 */
	public function get_problem_flows( int $threshold = 3 ): array {
		$db_jobs         = new \DataMachine\Core\Database\Jobs\Jobs();
		$pipelines_table = $this->wpdb->prefix . 'datamachine_pipelines';

		// Get problem flow IDs with counts from jobs table
		$problem_flow_ids = $db_jobs->get_problem_flow_ids( $threshold );

		if ( empty( $problem_flow_ids ) ) {
			return array();
		}

		// Get flow and pipeline details for these flows
		$flow_id_list = array_keys( $problem_flow_ids );
		$placeholders = implode( ',', array_fill( 0, count( $flow_id_list ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT f.flow_id, f.pipeline_id, f.flow_name, p.pipeline_name
             FROM %i f
             LEFT JOIN %i p ON f.pipeline_id = p.pipeline_id
             WHERE f.flow_id IN ({$placeholders})",
			array_merge( array( $this->table_name, $pipelines_table ), $flow_id_list )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		$results = $this->wpdb->get_results( $query, ARRAY_A );

		if ( null === $results ) {
			return array();
		}

		$problem_flows = array();
		foreach ( $results as $row ) {
			$flow_id    = (int) $row['flow_id'];
			$counts     = $problem_flow_ids[ $flow_id ];
			$latest_job = $counts['latest_job'];

			$problem_flows[] = array(
				'flow_id'              => $flow_id,
				'flow_name'            => $row['flow_name'],
				'pipeline_id'          => (int) $row['pipeline_id'],
				'pipeline_name'        => $row['pipeline_name'] ?? 'Unknown',
				'consecutive_failures' => $counts['consecutive_failures'],
				'consecutive_no_items' => $counts['consecutive_no_items'],
				'last_run_at'          => $latest_job['created_at'] ?? null,
				'last_run_status'      => $latest_job['status'] ?? null,
			);
		}

		// Sort by consecutive_failures DESC, then consecutive_no_items DESC
		usort(
			$problem_flows,
			function ( $a, $b ) {
				if ( $a['consecutive_failures'] !== $b['consecutive_failures'] ) {
					return $b['consecutive_failures'] - $a['consecutive_failures'];
				}
				return $b['consecutive_no_items'] - $a['consecutive_no_items'];
			}
		);

		return $problem_flows;
	}

	/**
	 * Update a flow
	 */
	public function update_flow( int $flow_id, array $flow_data ): bool {

		$update_data    = array();
		$update_formats = array();

		if ( isset( $flow_data['flow_name'] ) ) {
			$update_data['flow_name'] = sanitize_text_field( $flow_data['flow_name'] );
			$update_formats[]         = '%s';
		}

		if ( isset( $flow_data['flow_config'] ) ) {
			$update_data['flow_config'] = wp_json_encode( $flow_data['flow_config'] );
			$update_formats[]           = '%s';
		}

		if ( isset( $flow_data['scheduling_config'] ) ) {
			$update_data['scheduling_config'] = wp_json_encode( $flow_data['scheduling_config'] );
			$update_formats[]                 = '%s';
		}

		if ( isset( $flow_data['portable_slug'] ) ) {
			$portable_slug = sanitize_title( (string) $flow_data['portable_slug'] );
			if ( '' !== $portable_slug ) {
				$update_data['portable_slug'] = $portable_slug;
				$update_formats[]             = '%s';
			}
		}

		if ( empty( $update_data ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'No valid update data provided for flow',
				array(
					'flow_id' => $flow_id,
				)
			);
			return false;
		}

		$result = $this->wpdb->update(
			$this->table_name,
			$update_data,
			array( 'flow_id' => $flow_id ),
			$update_formats,
			array( '%d' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to update flow',
				array(
					'flow_id'     => $flow_id,
					'wpdb_error'  => $this->wpdb->last_error,
					'update_data' => array_keys( $update_data ),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Delete a flow
	 */
	public function delete_flow( int $flow_id ): bool {

		$result = $this->wpdb->delete(
			$this->table_name,
			array( 'flow_id' => $flow_id ),
			array( '%d' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to delete flow',
				array(
					'flow_id'    => $flow_id,
					'wpdb_error' => $this->wpdb->last_error,
				)
			);
			return false;
		}

		if ( 0 === $result ) {
			do_action(
				'datamachine_log',
				'warning',
				'Flow not found for deletion',
				array(
					'flow_id' => $flow_id,
				)
			);
			return false;
		}

		do_action(
			'datamachine_log',
			'debug',
			'Flow deleted successfully',
			array(
				'flow_id' => $flow_id,
			)
		);

		return true;
	}

	/**
	 * Update flow scheduling configuration
	 */
	public function update_flow_scheduling( int $flow_id, array $scheduling_config ): bool {

		$result = $this->wpdb->update(
			$this->table_name,
			array( 'scheduling_config' => wp_json_encode( $scheduling_config ) ),
			array( 'flow_id' => $flow_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to update flow scheduling',
				array(
					'flow_id'           => $flow_id,
					'wpdb_error'        => $this->wpdb->last_error,
					'scheduling_config' => $scheduling_config,
				)
			);
			return false;
		}

		return true;
	}

	public function get_flow_scheduling( int $flow_id ): ?array {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$scheduling_config_json = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT scheduling_config FROM %i WHERE flow_id = %d', $this->table_name, $flow_id ) );

		if ( null === $scheduling_config_json ) {
			do_action(
				'datamachine_log',
				'warning',
				'Flow scheduling configuration not found',
				array(
					'flow_id' => $flow_id,
				)
			);
			return null;
		}

		$decoded_config = json_decode( $scheduling_config_json, true );

		if ( null === $decoded_config ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to decode flow scheduling configuration',
				array(
					'flow_id'    => $flow_id,
					'raw_config' => $scheduling_config_json,
				)
			);
			return null;
		}

		return $decoded_config;
	}

	/**
	 * Check if a flow is enabled (not paused).
	 *
	 * A flow is enabled by default. It is disabled when scheduling_config.enabled === false.
	 *
	 * @since 0.59.0
	 *
	 * @param array $scheduling_config Scheduling configuration array.
	 * @return bool True if the flow is enabled.
	 */
	public static function is_flow_enabled( array $scheduling_config ): bool {
		return ! isset( $scheduling_config['enabled'] ) || false !== $scheduling_config['enabled'];
	}

	/**
	 * Get flows ready for execution based on scheduling.
	 *
	 * Uses jobs table to determine last run time (single source of truth).
	 * Skips paused flows (enabled=false in scheduling_config).
	 *
	 * Note: No user_id filter here — the scheduler must run ALL users' flows.
	 * User-scoping happens at the pipeline/flow management level, not execution.
	 */
	public function get_flows_ready_for_execution(): array {

		$current_time = current_time( 'mysql', true );

		// Get all non-manual, enabled flows.
		// Exclude paused flows (enabled=false) and manual flows at the query level.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$flows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i
				WHERE JSON_EXTRACT(scheduling_config, '$.interval') != 'manual'
				AND (JSON_EXTRACT(scheduling_config, '$.enabled') IS NULL OR JSON_EXTRACT(scheduling_config, '$.enabled') != false)
				ORDER BY flow_id ASC",
				$this->table_name
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( null === $flows || empty( $flows ) ) {
			return array();
		}

		// Batch query latest jobs for all flows
		$flow_ids    = array_column( $flows, 'flow_id' );
		$db_jobs     = new \DataMachine\Core\Database\Jobs\Jobs();
		$latest_jobs = $db_jobs->get_latest_jobs_by_flow_ids( array_map( 'intval', $flow_ids ) );

		$ready_flows = array();

		foreach ( $flows as $flow ) {
			$scheduling_config = json_decode( $flow['scheduling_config'], true );
			$flow_id           = (int) $flow['flow_id'];
			$latest_job        = $latest_jobs[ $flow_id ] ?? null;
			$last_run_at       = $latest_job['created_at'] ?? null;

			if ( $this->is_flow_ready_for_execution( $scheduling_config, $current_time, $last_run_at ) ) {
				$flow['flow_config']       = json_decode( $flow['flow_config'], true ) ?? array();
				$flow['scheduling_config'] = $scheduling_config;
				$ready_flows[]             = $flow;
			}
		}

		do_action(
			'datamachine_log',
			'debug',
			'Retrieved flows ready for execution',
			array(
				'ready_flow_count' => count( $ready_flows ),
				'current_time'     => $current_time,
			)
		);

		return $ready_flows;
	}

	/**
	 * Check if a flow is ready for execution based on its scheduling configuration.
	 *
	 * @param array       $scheduling_config Scheduling configuration
	 * @param string      $current_time      Current time in MySQL format
	 * @param string|null $last_run_at       Last run time from jobs table (null if never run)
	 */
	private function is_flow_ready_for_execution( array $scheduling_config, string $current_time, ?string $last_run_at = null ): bool {
		if ( ! isset( $scheduling_config['interval'] ) ) {
			return false;
		}

		if ( 'manual' === $scheduling_config['interval'] ) {
			return false;
		}

		// Skip paused flows.
		if ( ! self::is_flow_enabled( $scheduling_config ) ) {
			return false;
		}

		if ( null === $last_run_at ) {
			return true; // Never run before.
		}

		$last_run_timestamp = ( new \DateTime( $last_run_at, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$current_timestamp  = ( new \DateTime( $current_time, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$interval           = $scheduling_config['interval'];

		// Cron expression scheduling: check if the next run after last_run is in the past.
		if ( 'cron' === $interval && ! empty( $scheduling_config['cron_expression'] ) && class_exists( 'CronExpression' ) ) {
			try {
				$cron     = \CronExpression::factory( $scheduling_config['cron_expression'] );
				$last_run = new \DateTime( $last_run_at, new \DateTimeZone( 'UTC' ) );
				$next_run = $cron->getNextRunDate( $last_run );
				return $current_timestamp >= $next_run->getTimestamp();
			} catch ( \Exception $e ) {
				return false;
			}
		}

		$intervals     = apply_filters( 'datamachine_scheduler_intervals', array() );
		$interval_data = $intervals[ $interval ] ?? null;

		if ( $interval_data && isset( $interval_data['seconds'] ) ) {
			return ( $current_timestamp - $last_run_timestamp ) >= $interval_data['seconds'];
		}

		return false;
	}

	/**
	 * Get flow memory files from flow config.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array Array of memory filenames.
	 */
	public function get_flow_memory_files( int $flow_id ): array {
		$flow = $this->get_flow( $flow_id );
		if ( ! $flow ) {
			return array();
		}
		return $flow['flow_config']['memory_files'] ?? array();
	}

	/**
	 * Update flow memory files in flow config.
	 *
	 * @since 0.71.0 Dropped $daily_memory parameter — daily memory is now
	 *               a virtual memory file governed by MemoryPolicy, not a
	 *               per-flow config.
	 *
	 * @param int   $flow_id       Flow ID.
	 * @param array $memory_files  Array of memory filenames.
	 * @return bool True on success, false on failure.
	 */
	public function update_flow_memory_files( int $flow_id, array $memory_files ): bool {
		if ( empty( $flow_id ) ) {
			return false;
		}

		// Read raw flow_config JSON to avoid normalization side effects.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$raw_config_json = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT flow_config FROM %i WHERE flow_id = %d', $this->table_name, $flow_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( null === $raw_config_json ) {
			return false;
		}

		$flow_config                 = json_decode( $raw_config_json, true ) ?? array();
		$flow_config['memory_files'] = $memory_files;

		// Drop any legacy daily_memory config left over from 0.70.x
		// and earlier. This is a one-time cleanup — the feature is gone.
		unset( $flow_config['daily_memory'] );

		$result = $this->wpdb->update(
			$this->table_name,
			array( 'flow_config' => wp_json_encode( $flow_config ) ),
			array( 'flow_id' => $flow_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get configuration for a specific flow step.
	 *
	 * Dual-mode retrieval: execution context (engine_data) or admin context (database).
	 *
	 * @param string   $flow_step_id       Flow step ID (format: {pipeline_step_id}_{flow_id})
	 * @param int|null $job_id             Job ID for execution context (optional)
	 * @param bool     $require_engine_data Fail fast if engine_data unavailable (default: false)
	 * @return array Step configuration, or empty array on failure
	 */
	public function get_flow_step_config( string $flow_step_id, ?int $job_id = null, bool $require_engine_data = false ): array {
		// Try engine_data first (during execution context)
		if ( $job_id ) {
			$engine_data = datamachine_get_engine_data( $job_id );
			$flow_config = $engine_data['flow_config'] ?? array();
			$step_config = $flow_config[ $flow_step_id ] ?? array();
			if ( ! empty( $step_config ) ) {
				return $step_config;
			}

			if ( $require_engine_data ) {
				do_action(
					'datamachine_log',
					'error',
					'Flow step config not found in engine_data during execution',
					array(
						'flow_step_id' => $flow_step_id,
						'job_id'       => $job_id,
					)
				);
				return array();
			}
		}

		// Fallback: parse flow_step_id and get from flow (admin/REST context only)
		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		if ( $parts && isset( $parts['flow_id'] ) ) {
			$flow = $this->get_flow( (int) $parts['flow_id'] );
			if ( $flow && isset( $flow['flow_config'] ) ) {
				$flow_config = $flow['flow_config'];
				return $flow_config[ $flow_step_id ] ?? array();
			}
		}

		return array();
	}
}
