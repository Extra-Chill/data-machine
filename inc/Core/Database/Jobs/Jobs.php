<?php
/**
 * Jobs Database Operations - Job lifecycle management with engine data storage
 *
 * @package DataMachine
 * @subpackage Core\Database\Jobs
 */

namespace DataMachine\Core\Database\Jobs;

use DataMachine\Core\Database\BaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jobs {

	private $table_name;
	private $operations;
	private $status;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'datamachine_jobs';

		$this->operations = new JobsOperations();
		$this->status     = new JobsStatus();
	}


	public function create_job( array $job_data ): int|false {
		return $this->operations->create_job( $job_data );
	}


	public function get_jobs_count( array $args = array() ): int {
		return $this->operations->get_jobs_count( $args );
	}

	public function get_jobs_for_list_table( array $args ): array {
		return $this->operations->get_jobs_for_list_table( $args );
	}

	public function start_job( int $job_id, string $status = 'processing' ): bool {
		return $this->status->start_job( $job_id, $status );
	}

	public function complete_job( int $job_id, string $status ): bool {
		return $this->status->complete_job( $job_id, $status );
	}

	public function update_job_status( int $job_id, string $status ): bool {
		return $this->status->update_job_status( $job_id, $status );
	}

	public function get_jobs_for_pipeline( int $pipeline_id ): array {
		return $this->operations->get_jobs_for_pipeline( $pipeline_id );
	}

	public function get_jobs_for_flow( int|string $flow_id ): array {
		return $this->operations->get_jobs_for_flow( $flow_id );
	}

	public function get_latest_jobs_by_flow_ids( array $flow_ids ): array {
		return $this->operations->get_latest_jobs_by_flow_ids( $flow_ids );
	}

	public function delete_jobs( array $criteria = array() ): int|false {
		return $this->operations->delete_jobs( $criteria );
	}

	/**
	 * Delete old jobs by status and age.
	 *
	 * @since 0.28.0
	 *
	 * @param string $status_pattern Base status to match (e.g., 'failed').
	 * @param int    $older_than_days Delete jobs older than this many days.
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function delete_old_jobs( string $status_pattern, int $older_than_days ): int|false {
		return $this->operations->delete_old_jobs( $status_pattern, $older_than_days );
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
		return $this->operations->count_old_jobs( $status_pattern, $older_than_days );
	}

	public function store_engine_data( int $job_id, array $data ): bool {
		return $this->operations->store_engine_data( $job_id, $data );
	}

	public function retrieve_engine_data( int $job_id ): array {
		return $this->operations->retrieve_engine_data( $job_id );
	}

	public function get_job( int $job_id ): ?array {
		return $this->operations->get_job( $job_id );
	}

	public function get_flow_health( int|string $flow_id ): array {
		return $this->operations->get_flow_health( $flow_id );
	}

	public function get_problem_flow_ids( int $threshold = 3 ): array {
		return $this->operations->get_problem_flow_ids( $threshold );
	}


	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'datamachine_jobs';
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// pipeline_id and flow_id are VARCHAR to support multiple execution modes:
		// - Numeric string: database flow execution (e.g. '123')
		// - 'direct': ephemeral workflow execution
		// - NULL: job execution without pipeline/flow context
		// status is VARCHAR(255) to support compound statuses with reasons
		$sql = "CREATE TABLE $table_name (
            job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            pipeline_id varchar(20) NULL DEFAULT NULL,
            flow_id varchar(20) NULL DEFAULT NULL,
            source varchar(50) NOT NULL DEFAULT 'pipeline',
            label varchar(255) NULL DEFAULT NULL,
            parent_job_id bigint(20) unsigned NULL DEFAULT NULL,
            status varchar(255) NOT NULL,
            engine_data longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (job_id),
            KEY status (status),
            KEY pipeline_id (pipeline_id),
            KEY flow_id (flow_id),
            KEY source (source),
            KEY parent_job_id (parent_job_id),
            KEY user_id (user_id),
            KEY idx_created_at (created_at),
            KEY idx_flow_created (flow_id, created_at),
            KEY idx_status_created (status(50), created_at),
            KEY idx_source_created (source, created_at)
        ) $charset_collate;";

		dbDelta( $sql );

		self::migrate_columns( $table_name );
		self::migrate_task_type_column( $table_name );
		self::migrate_indexes( $table_name );

		do_action(
			'datamachine_log',
			'debug',
			'Created jobs database table with pipeline+flow architecture',
			array(
				'table_name' => $table_name,
				'action'     => 'create_table',
			)
		);
	}

	/**
	 * Migrate existing table columns to current schema.
	 *
	 * Handles:
	 * - status column: varchar(20/100) -> varchar(255) for compound statuses with reasons
	 * - pipeline_id column: bigint -> varchar(20) for 'direct' execution support
	 * - flow_id column: bigint -> varchar(20) for 'direct' execution support
	 *
	 * Safe to run multiple times - only executes if columns need updating.
	 */
	private static function migrate_columns( string $table_name ): void {
		global $wpdb;

		// Column type migrations (MODIFY COLUMN) are MySQL-only — SQLite does
		// not support ALTER TABLE MODIFY and the columns are created with the
		// correct types from the start via CREATE TABLE / dbDelta.
		$columns = BaseRepository::get_column_meta(
			$table_name,
			array( 'status', 'pipeline_id', 'flow_id', 'source', 'parent_job_id', 'user_id' ),
			$wpdb
		);

		if ( ! empty( $columns ) ) {
			// Migrate status column: varchar(20/100) -> varchar(255)
			if ( isset( $columns['status'] ) && (int) $columns['status']->CHARACTER_MAXIMUM_LENGTH < 255 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY status varchar(255) NOT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
				do_action(
					'datamachine_log',
					'info',
					'Migrated jobs.status column to varchar(255)',
					array(
						'table_name'    => $table_name,
						'previous_size' => $columns['status']->CHARACTER_MAXIMUM_LENGTH,
					)
				);
			}

			// Migrate pipeline_id column: bigint -> varchar(20) NULL for contextless job support
			if ( isset( $columns['pipeline_id'] ) && 'bigint' === $columns['pipeline_id']->DATA_TYPE ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY pipeline_id varchar(20) NULL DEFAULT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
				do_action(
					'datamachine_log',
					'info',
					'Migrated jobs.pipeline_id column to varchar(20) NULL',
					array(
						'table_name' => $table_name,
					)
				);
			}

			// Migrate flow_id column: bigint -> varchar(20) NULL for contextless job support
			if ( isset( $columns['flow_id'] ) && 'bigint' === $columns['flow_id']->DATA_TYPE ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY flow_id varchar(20) NULL DEFAULT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
				do_action(
					'datamachine_log',
					'info',
					'Migrated jobs.flow_id column to varchar(20) NULL',
					array(
						'table_name' => $table_name,
					)
				);
			}

			// Migrate pipeline_id/flow_id from NOT NULL to NULL for contextless job support.
			// Runs on existing varchar(20) installs that haven't been updated yet.
			if ( isset( $columns['pipeline_id'] ) && 'varchar' === $columns['pipeline_id']->DATA_TYPE && 'NO' === $columns['pipeline_id']->IS_NULLABLE ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY pipeline_id varchar(20) NULL DEFAULT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
			}
			if ( isset( $columns['flow_id'] ) && 'varchar' === $columns['flow_id']->DATA_TYPE && 'NO' === $columns['flow_id']->IS_NULLABLE ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY flow_id varchar(20) NULL DEFAULT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
			}
		}

		// Add source and label columns for pipeline decoupling.
		if ( ! BaseRepository::column_exists( $table_name, 'source', $wpdb ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN source varchar(50) NOT NULL DEFAULT 'pipeline' AFTER flow_id,
				 ADD COLUMN label varchar(255) NULL DEFAULT NULL AFTER source,
				 ADD KEY source (source)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add source/label columns to jobs table',
					array(
						'table_name' => $table_name,
						'db_error'   => $wpdb->last_error,
					)
				);
				return;
			}

			// Backfill existing rows.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$wpdb->query( "UPDATE {$table_name} SET source = 'direct' WHERE pipeline_id = 'direct'" );
			// phpcs:enable WordPress.DB.PreparedSQL

			do_action(
				'datamachine_log',
				'info',
				'Added source and label columns to jobs table for pipeline decoupling',
				array( 'table_name' => $table_name )
			);
		}

		// Add parent_job_id column for job hierarchy (batch parents, pipeline sub-jobs).
		if ( ! BaseRepository::column_exists( $table_name, 'parent_job_id', $wpdb ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN parent_job_id bigint(20) unsigned NULL DEFAULT NULL AFTER label,
				 ADD KEY parent_job_id (parent_job_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $result ) {
				do_action(
					'datamachine_log',
					'info',
					'Added parent_job_id column to jobs table for job hierarchy',
					array( 'table_name' => $table_name )
				);
			}
		}

		// Add user_id column for multi-agent support.
		if ( ! BaseRepository::column_exists( $table_name, 'user_id', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN user_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER job_id,
				 ADD KEY user_id (user_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $result ) {
				do_action(
					'datamachine_log',
					'info',
					'Added user_id column to jobs table for multi-agent support',
					array( 'table_name' => $table_name )
				);
			}
		}

		// Add agent_id column for agent-first scoping (#735).
		if ( ! BaseRepository::column_exists( $table_name, 'agent_id', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN agent_id bigint(20) unsigned DEFAULT NULL AFTER user_id,
				 ADD KEY agent_id (agent_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $result ) {
				do_action(
					'datamachine_log',
					'info',
					'Added agent_id column to jobs table for agent-first scoping',
					array( 'table_name' => $table_name )
				);
			}
		}
	}

	/**
	 * Add task_type column for indexed system task lookups.
	 *
	 * Replaces JSON_EXTRACT(engine_data, '$.task_type') queries with a proper
	 * indexed column. The column is populated by store_engine_data() when the
	 * engine snapshot contains a task_type key, and backfilled from existing
	 * engine_data on migration.
	 *
	 * @since 0.30.0
	 *
	 * @param string $table_name Fully qualified table name.
	 */
	private static function migrate_task_type_column( string $table_name ): void {
		global $wpdb;

		if ( BaseRepository::column_exists( $table_name, 'task_type', $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$result = $wpdb->query(
			"ALTER TABLE {$table_name}
			 ADD COLUMN task_type varchar(100) NULL DEFAULT NULL AFTER agent_id,
			 ADD KEY idx_task_type (task_type, source)"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to add task_type column to jobs table',
				array(
					'table_name' => $table_name,
					'db_error'   => $wpdb->last_error,
				)
			);
			return;
		}

		// Backfill task_type from engine_data for existing system/pipeline_system_task jobs.
		// Uses PHP json_decode instead of MySQL JSON_UNQUOTE for SQLite compatibility.
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$backfill_rows = $wpdb->get_results(
			"SELECT job_id, engine_data
			 FROM {$table_name}
			 WHERE source IN ('system', 'pipeline_system_task')
			 AND engine_data IS NOT NULL
			 AND task_type IS NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		foreach ( $backfill_rows as $row ) {
			$engine_data = json_decode( $row->engine_data, true );
			$task_type   = $engine_data['task_type'] ?? null;

			if ( $task_type ) {
				$wpdb->update(
					$table_name,
					array( 'task_type' => $task_type ),
					array( 'job_id' => $row->job_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'Added task_type column to jobs table for indexed system task lookups',
			array( 'table_name' => $table_name )
		);
	}

	/**
	 * Ensure performance indexes exist on the jobs table.
	 *
	 * dbDelta() is unreliable at adding indexes to existing tables, so this
	 * method checks for each index via SHOW INDEX and adds any that are
	 * missing. Safe to run on every activation/deploy.
	 *
	 * These indexes become critical once the jobs table grows past ~10k rows.
	 * Without them, common queries (flow listing, status filtering, retention
	 * cleanup, system task lookups) degrade from milliseconds to seconds.
	 *
	 * @param string $table_name Fully qualified table name.
	 */
	private static function migrate_indexes( string $table_name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$existing = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $existing ) ) {
			return;
		}

		$index_names = array_unique( array_column( $existing, 'Key_name' ) );

		$indexes_to_add = array(
			'idx_created_at'     => '(created_at)',
			'idx_flow_created'   => '(flow_id, created_at)',
			'idx_status_created' => '(status(50), created_at)',
			'idx_source_created' => '(source, created_at)',
		);

		$added = array();

		foreach ( $indexes_to_add as $index_name => $index_def ) {
			if ( in_array( $index_name, $index_names, true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table/index names from plugin constants, not user input.
			$result = $wpdb->query( "ALTER TABLE {$table_name} ADD INDEX {$index_name} {$index_def}" );
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $result ) {
				$added[] = $index_name;
			}
		}

		if ( ! empty( $added ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Added performance indexes to jobs table',
				array(
					'table_name' => $table_name,
					'indexes'    => $added,
				)
			);
		}
	}
}
