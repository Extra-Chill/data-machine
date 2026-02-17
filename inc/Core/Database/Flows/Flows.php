<?php

namespace DataMachine\Core\Database\Flows;

use DataMachine\Abilities\FlowStep\FlowStepHelpers;
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
            flow_name varchar(255) NOT NULL,
            flow_config longtext NOT NULL,
            scheduling_config longtext NOT NULL,
            PRIMARY KEY (flow_id),
            KEY pipeline_id (pipeline_id)
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

		$insert_data = array(
			'pipeline_id'       => intval( $flow_data['pipeline_id'] ),
			'flow_name'         => sanitize_text_field( $flow_data['flow_name'] ),
			'flow_config'       => $flow_config,
			'scheduling_config' => $scheduling_config,
		);

		$insert_format = array(
			'%d', // pipeline_id
			'%s', // flow_name
			'%s', // flow_config
			'%s',  // scheduling_config
		);

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

	/**
	 * Normalize handler fields in all steps of a flow config.
	 *
	 * Ensures every step uses handler_slugs/handler_configs as the canonical format.
	 * Called at the data access layer so all consumers receive normalized data.
	 *
	 * @param array $flow_config Decoded flow config from database.
	 * @return array Normalized flow config.
	 */
	private function normalizeFlowConfig( array $flow_config ): array {
		foreach ( $flow_config as $step_id => $step_config ) {
			$flow_config[ $step_id ] = FlowStepHelpers::normalizeHandlerFields( $step_config );
		}
		return $flow_config;
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

		$flow['flow_config']       = $this->normalizeFlowConfig( json_decode( $flow['flow_config'], true ) ?? array() );
		$flow['scheduling_config'] = json_decode( $flow['scheduling_config'], true ) ?? array();

		return $flow;
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
			$flow['flow_config']       = $this->normalizeFlowConfig( json_decode( $flow['flow_config'], true ) ?? array() );
			$flow['scheduling_config'] = json_decode( $flow['scheduling_config'], true ) ?? array();
		}

		return $flows;
	}

	/**
	 * Get all flows across all pipelines.
	 *
	 * Used for global operations like handler-based filtering across the entire system.
	 *
	 * @return array All flows with decoded configs.
	 */
	public function get_all_flows(): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$flows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i ORDER BY pipeline_id ASC, flow_id ASC', $this->table_name ),
			ARRAY_A
		);

		if ( null === $flows ) {
			return array();
		}

		foreach ( $flows as &$flow ) {
			$flow['flow_config']       = $this->normalizeFlowConfig( json_decode( $flow['flow_config'], true ) ?? array() );
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

		if ( null === $flows ) {
			return array();
		}

		foreach ( $flows as &$flow ) {
			$flow['flow_config']       = $this->normalizeFlowConfig( json_decode( $flow['flow_config'], true ) ?? array() );
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
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE pipeline_id = %d',
				$this->table_name,
				$pipeline_id
			)
		);

		return (int) ( $count ?? 0 );
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
	 * Get flows ready for execution based on scheduling.
	 *
	 * Uses jobs table to determine last run time (single source of truth).
	 */
	public function get_flows_ready_for_execution(): array {

		$current_time = current_time( 'mysql', true );

		// Get all non-manual flows
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$flows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE JSON_EXTRACT(scheduling_config, '$.interval') != 'manual' ORDER BY flow_id ASC",
				$this->table_name
			),
			ARRAY_A
		);

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
				$flow['flow_config']       = $this->normalizeFlowConfig( json_decode( $flow['flow_config'], true ) ?? array() );
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

		if ( null === $last_run_at ) {
			return true; // Never run before
		}

		$last_run_timestamp = ( new \DateTime( $last_run_at, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$current_timestamp  = ( new \DateTime( $current_time, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$interval           = $scheduling_config['interval'];

		$intervals     = apply_filters( 'datamachine_scheduler_intervals', array() );
		$interval_data = $intervals[ $interval ] ?? null;

		if ( $interval_data && isset( $interval_data['seconds'] ) ) {
			return ( $current_timestamp - $last_run_timestamp ) >= $interval_data['seconds'];
		}

		return false;
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
				return FlowStepHelpers::normalizeHandlerFields( $step_config );
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
